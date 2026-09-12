<?php
/**
 * Job runner.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Jobs;

use SuperAbilities\Install;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Time;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Executes the items of one job inside a single cron run.
 *
 * The runner takes an exclusive lock on the job, becomes the user who queued it and
 * then calls `WP_Ability::execute()` per item, which re-validates the input and
 * re-checks the ability's permission callback every single time. When the time budget
 * runs out it releases the lock and reschedules itself, so a long job is finished
 * across as many cron runs as it needs.
 *
 * @since 0.1.0
 */
class Runner {

	/**
	 * Seconds added to the time budget when computing the lock expiry.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const LOCK_GRACE = 60;

	/**
	 * Largest JSON payload stored for a single item result, in bytes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_RESULT_BYTES = 65536;

	/**
	 * Largest error message stored against an item or a job, in bytes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_ERROR_BYTES = 5000;

	/**
	 * Runs as much of a job as the time budget allows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function run( int $job_id ) {
		if ( $job_id < 1 ) {
			return;
		}

		$budget = self::budget();

		if ( ! self::acquire( $job_id, $budget ) ) {
			self::resolve_abandoned_cancel( $job_id );

			return;
		}

		$job = Queue::find( $job_id );

		if ( null === $job ) {
			self::release( $job_id );

			return;
		}

		$owner = $job->user_id();

		if ( $owner < 1 || ! get_userdata( $owner ) ) {
			self::fail_job(
				$job,
				'owner_missing',
				__( 'The user who queued this job no longer exists, so it cannot be run on their behalf.', 'super-abilities' )
			);

			return;
		}

		$wp_ability = wp_get_ability( $job->ability() );

		if ( ! $wp_ability instanceof \WP_Ability ) {
			self::fail_job(
				$job,
				'ability_missing',
				sprintf(
					/* translators: %s: Ability name. */
					__( 'The ability "%s" is no longer registered on this site.', 'super-abilities' ),
					$job->ability()
				)
			);

			return;
		}

		$previous_user = get_current_user_id();

		wp_set_current_user( $owner );

		/**
		 * Fires before a job starts executing items, with the job's identity.
		 *
		 * The audit module listens for this so that every ability call made by the
		 * runner is attributed to the job. Always paired with
		 * `super_abilities_job_context_end`.
		 *
		 * @since 0.1.0
		 *
		 * @param array{job_id: int, uuid: string, user_id: int, app_password_uuid: string} $context Job context.
		 */
		do_action(
			'super_abilities_job_context',
			array(
				'job_id'            => $job->id(),
				'uuid'              => $job->uuid(),
				'user_id'           => $owner,
				'app_password_uuid' => $job->app_password_uuid(),
			)
		);

		try {
			self::recover_interrupted_items( $job, $wp_ability );
			self::process( $job, $wp_ability, $budget );
		} finally {
			/**
			 * Fires when a job stops executing items, whatever the reason.
			 *
			 * @since 0.1.0
			 */
			do_action( 'super_abilities_job_context_end' );

			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Takes the exclusive lock on a job.
	 *
	 * A single conditional `UPDATE` is the whole mutual exclusion mechanism: only the
	 * worker whose statement changed the row may proceed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @param int $budget Time budget in seconds.
	 * @return bool Whether the lock was taken.
	 */
	protected static function acquire( int $job_id, int $budget ) {
		global $wpdb;

		$table   = Install::table( 'jobs' );
		$now     = Time::mysql( Time::now() );
		$expires = Time::mysql( Time::now() + $budget + self::LOCK_GRACE );
		$token   = md5( wp_generate_uuid4() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and this conditional write is the job lock.
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"UPDATE {$table}
				SET lock_token = %s, lock_expires_at = %s, status = 'running', started_at = COALESCE( started_at, %s ), updated_at = %s
				WHERE id = %d
					AND status IN ( 'queued', 'running' )
					AND cancel_requested = 0
					AND ( lock_token IS NULL OR lock_expires_at IS NULL OR lock_expires_at < %s )",
				$token,
				$expires,
				$now,
				$now,
				$job_id,
				$now
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Finishes a job that was cancelled while nobody was holding its lock.
	 *
	 * The lock statement refuses jobs with `cancel_requested`, so a running job whose
	 * worker died after the cancel arrived would otherwise stay `running` forever.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	protected static function resolve_abandoned_cancel( int $job_id ) {
		$job = Queue::find( $job_id );

		if ( null === $job || $job->is_finished() || ! $job->cancel_requested() || $job->is_locked() ) {
			return;
		}

		Queue::cancel_open_items( $job_id );
		self::write_job( $job_id, "status = 'cancelled', finished_at = %s, updated_at = %s, lock_token = NULL, lock_expires_at = NULL", array( Time::mysql( Time::now() ), Time::mysql( Time::now() ) ) );
	}

	/**
	 * The number of seconds this run may spend on items.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	protected static function budget() {
		$configured = (int) Plugin::instance()->options()->get( 'jobs_time_budget', 20 );
		$configured = max( 1, $configured );

		$max_execution = (int) ini_get( 'max_execution_time' );

		if ( $max_execution <= 0 ) {
			return $configured;
		}

		return min( $configured, max( 5, $max_execution - 5 ) );
	}

	/**
	 * Deals with items that were left `running` by a worker that died.
	 *
	 * Re-running an item is only safe when the target ability promises to be
	 * idempotent; otherwise the item may already have had its effect and is failed
	 * with the `interrupted` code so that a human can decide what to do.
	 *
	 * @since 0.1.0
	 *
	 * @param Job         $job        Job being run.
	 * @param \WP_Ability $wp_ability Target ability.
	 * @return void
	 */
	protected static function recover_interrupted_items( Job $job, \WP_Ability $wp_ability ) {
		global $wpdb;

		$table = Install::table( 'job_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table and this looks for rows left behind by a dead worker.
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT id FROM {$table} WHERE job_id = %d AND status = 'running' ORDER BY position ASC",
				$job->id()
			)
		);

		$stale = array_values( array_filter( array_map( 'intval', (array) $stale ) ) );

		if ( array() === $stale ) {
			return;
		}

		$annotations = $wp_ability->get_meta_item( 'annotations' );
		$idempotent  = is_array( $annotations ) && isset( $annotations['idempotent'] ) && true === $annotations['idempotent'];
		$now         = Time::mysql( Time::now() );

		foreach ( $stale as $item_id ) {
			if ( $idempotent ) {
				self::write_item( $item_id, "status = 'pending', started_at = NULL, finished_at = NULL", array() );

				continue;
			}

			self::write_item(
				$item_id,
				"status = 'failed', error_code = %s, error_message = %s, finished_at = %s",
				array(
					'interrupted',
					__( 'This item was interrupted while it was running. The target ability is not idempotent, so it was not retried automatically.', 'super-abilities' ),
					$now,
				)
			);
		}

		self::sync_counters( $job->id() );
	}

	/**
	 * Runs pending items until the job ends, is cancelled, or the budget runs out.
	 *
	 * @since 0.1.0
	 *
	 * @param Job         $job        Job being run.
	 * @param \WP_Ability $wp_ability Target ability.
	 * @param int         $budget     Time budget in seconds.
	 * @return void
	 */
	protected static function process( Job $job, \WP_Ability $wp_ability, int $budget ) {
		$job_id   = $job->id();
		$deadline = microtime( true ) + $budget;

		while ( true ) {
			if ( self::cancel_requested( $job_id ) ) {
				Queue::cancel_open_items( $job_id );
				self::sync_counters( $job_id );

				$now = Time::mysql( Time::now() );

				self::write_job(
					$job_id,
					"status = 'cancelled', finished_at = %s, updated_at = %s, lock_token = NULL, lock_expires_at = NULL",
					array( $now, $now )
				);

				return;
			}

			if ( microtime( true ) >= $deadline ) {
				self::release( $job_id );
				Queue::schedule( $job_id );

				return;
			}

			$item = self::next_item( $job_id );

			if ( null === $item ) {
				self::finish( $job_id );

				return;
			}

			$failed = self::run_item( $job, $wp_ability, $item );

			if ( $failed && $job->stop_on_error() ) {
				self::skip_remaining( $job_id );
				self::finish( $job_id );

				return;
			}
		}
	}

	/**
	 * Executes one item and records the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param Job                  $job        Job being run.
	 * @param \WP_Ability          $wp_ability Target ability.
	 * @param array<string, mixed> $item       Item row.
	 * @return bool Whether the item failed.
	 */
	protected static function run_item( Job $job, \WP_Ability $wp_ability, array $item ) {
		$item_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$now     = Time::mysql( Time::now() );

		self::write_item( $item_id, "status = 'running', attempts = attempts + 1, started_at = %s, finished_at = NULL", array( $now ) );

		$input = json_decode( isset( $item['input'] ) ? (string) $item['input'] : '', true );
		$input = is_array( $input ) ? $input : array();

		try {
			$result = $wp_ability->execute( $input );
		} catch ( Throwable $throwable ) {
			$result = new WP_Error( 'super_abilities_exception', $throwable->getMessage() );
		}

		$finished_at = Time::mysql( Time::now() );

		if ( is_wp_error( $result ) ) {
			$message = self::clip( $result->get_error_message(), self::MAX_ERROR_BYTES );

			self::write_item(
				$item_id,
				"status = 'failed', error_code = %s, error_message = %s, result = NULL, result_truncated = 0, finished_at = %s",
				array(
					self::clip( $result->get_error_code(), 100 ),
					$message,
					$finished_at,
				)
			);

			self::bump( $job->id(), 'failed_items', $message );

			return true;
		}

		$stored    = null;
		$truncated = 0;

		if ( $job->keep_results() ) {
			$json = wp_json_encode( $result );

			if ( ! is_string( $json ) ) {
				$json = 'null';
			}

			if ( strlen( $json ) > self::MAX_RESULT_BYTES ) {
				$json      = substr( $json, 0, self::MAX_RESULT_BYTES );
				$truncated = 1;
			}

			$stored = $json;
		}

		if ( null === $stored ) {
			self::write_item(
				$item_id,
				"status = 'done', error_code = '', error_message = NULL, result = NULL, result_truncated = 0, finished_at = %s",
				array( $finished_at )
			);
		} else {
			self::write_item(
				$item_id,
				"status = 'done', error_code = '', error_message = NULL, result = %s, result_truncated = %d, finished_at = %s",
				array( $stored, $truncated, $finished_at )
			);
		}

		self::bump( $job->id(), 'done_items', '' );

		return false;
	}

	/**
	 * Reads the next item waiting to run.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return array<string, mixed>|null
	 */
	protected static function next_item( int $job_id ) {
		global $wpdb;

		$table = Install::table( 'job_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table and this row is claimed immediately afterwards.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT * FROM {$table} WHERE job_id = %d AND status = 'pending' ORDER BY position ASC LIMIT 1",
				$job_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
			return null;
		}

		$columns = array();

		foreach ( $row as $column => $value ) {
			$columns[ (string) $column ] = $value;
		}

		return $columns;
	}

	/**
	 * Re-reads the cancel flag straight from the database.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return bool
	 */
	protected static function cancel_requested( int $job_id ) {
		global $wpdb;

		$table = Install::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point of this read is to see a write made by another request.
		$flag = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT cancel_requested FROM {$table} WHERE id = %d",
				$job_id
			)
		);

		return 1 === (int) $flag;
	}

	/**
	 * Marks every item that never started as skipped.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	protected static function skip_remaining( int $job_id ) {
		global $wpdb;

		$table = Install::table( 'job_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table; this is a bulk write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"UPDATE {$table} SET status = 'skipped', finished_at = %s WHERE job_id = %d AND status = 'pending'",
				Time::mysql( Time::now() ),
				$job_id
			)
		);
	}

	/**
	 * Writes the final status of a job and releases its lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	protected static function finish( int $job_id ) {
		$counts = Queue::item_counts( $job_id );
		$done   = (int) $counts['done'];
		$failed = (int) $counts['failed'];

		if ( 0 === $failed ) {
			$status = 'completed';
		} elseif ( $done > 0 ) {
			$status = 'partial';
		} else {
			$status = 'failed';
		}

		$now = Time::mysql( Time::now() );

		self::write_job(
			$job_id,
			'status = %s, done_items = %d, failed_items = %d, finished_at = %s, updated_at = %s, lock_token = NULL, lock_expires_at = NULL',
			array( $status, $done, $failed, $now, $now )
		);

		$job = Queue::find( $job_id );

		if ( $job instanceof Job ) {
			/**
			 * Fires when a job reaches a final status.
			 *
			 * @since 0.1.0
			 *
			 * @param Job $job The finished job.
			 */
			do_action( 'super_abilities_job_finished', $job );
		}
	}

	/**
	 * Fails the whole job without touching the ability.
	 *
	 * @since 0.1.0
	 *
	 * @param Job    $job     Job to fail.
	 * @param string $code    Machine readable reason.
	 * @param string $message Human readable reason.
	 * @return void
	 */
	protected static function fail_job( Job $job, $code, $message ) {
		global $wpdb;

		$table = Install::table( 'job_items' );
		$now   = Time::mysql( Time::now() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table; this is a bulk write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"UPDATE {$table}
				SET status = 'failed', error_code = %s, error_message = %s, finished_at = %s
				WHERE job_id = %d AND status IN ( 'pending', 'running' )",
				self::clip( $code, 100 ),
				self::clip( $message, self::MAX_ERROR_BYTES ),
				$now,
				$job->id()
			)
		);

		$counts = Queue::item_counts( $job->id() );

		self::write_job(
			$job->id(),
			"status = 'failed', done_items = %d, failed_items = %d, last_error = %s, finished_at = %s, updated_at = %s, lock_token = NULL, lock_expires_at = NULL",
			array( (int) $counts['done'], (int) $counts['failed'], self::clip( $message, self::MAX_ERROR_BYTES ), $now, $now )
		);
	}

	/**
	 * Increments a job counter after an item finished.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $job_id Job id.
	 * @param string $column Either `done_items` or `failed_items`.
	 * @param string $error  Optional. Error message to record on the job. Default empty.
	 * @return void
	 */
	protected static function bump( int $job_id, $column, $error = '' ) {
		$column = 'failed_items' === $column ? 'failed_items' : 'done_items';
		$now    = Time::mysql( Time::now() );

		if ( '' !== $error ) {
			self::write_job( $job_id, "{$column} = {$column} + 1, last_error = %s, updated_at = %s", array( $error, $now ) );

			return;
		}

		self::write_job( $job_id, "{$column} = {$column} + 1, updated_at = %s", array( $now ) );
	}

	/**
	 * Cuts a string down to what the column can hold.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $text   Value to store.
	 * @param int   $length Maximum number of bytes.
	 * @return string
	 */
	protected static function clip( $text, $length ) {
		$text = is_scalar( $text ) ? (string) $text : '';

		return strlen( $text ) > $length ? substr( $text, 0, (int) $length ) : $text;
	}

	/**
	 * Recomputes the job counters from the item rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	protected static function sync_counters( int $job_id ) {
		$counts = Queue::item_counts( $job_id );

		self::write_job(
			$job_id,
			'done_items = %d, failed_items = %d, updated_at = %s',
			array( (int) $counts['done'], (int) $counts['failed'], Time::mysql( Time::now() ) )
		);
	}

	/**
	 * Releases the lock on a job without changing its status.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	protected static function release( int $job_id ) {
		self::write_job(
			$job_id,
			'lock_token = NULL, lock_expires_at = NULL, updated_at = %s',
			array( Time::mysql( Time::now() ) )
		);
	}

	/**
	 * Runs an `UPDATE` against the jobs table.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $job_id Job id.
	 * @param string            $set    The `SET` clause, with `%s` and `%d` placeholders.
	 * @param array<int, mixed> $values Values for the placeholders in `$set`.
	 * @return void
	 */
	protected static function write_job( int $job_id, $set, array $values ) {
		global $wpdb;

		$table  = Install::table( 'jobs' );
		$values = array_merge( $values, array( $job_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table; there is nothing to cache on a write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from Install::table() and the SET clause is a fixed string of placeholders written in this class.
				"UPDATE {$table} SET {$set} WHERE id = %d",
				$values
			)
		);
	}

	/**
	 * Runs an `UPDATE` against the job items table.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $item_id Item id.
	 * @param string            $set     The `SET` clause, with `%s` and `%d` placeholders.
	 * @param array<int, mixed> $values  Values for the placeholders in `$set`.
	 * @return void
	 */
	protected static function write_item( int $item_id, $set, array $values ) {
		global $wpdb;

		$table  = Install::table( 'job_items' );
		$values = array_merge( $values, array( $item_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table; there is nothing to cache on a write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from Install::table() and the SET clause is a fixed string of placeholders written in this class.
				"UPDATE {$table} SET {$set} WHERE id = %d",
				$values
			)
		);
	}
}
