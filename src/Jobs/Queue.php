<?php
/**
 * Job queue.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Jobs;

use SuperAbilities\Install;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Error;
use SuperAbilities\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Queues, reads, cancels and prunes background jobs.
 *
 * A job is a list of inputs for one ability. Every item is executed as the user who
 * queued it, through the ability's own permission and validation, so a job can never
 * do more than its owner could do synchronously.
 *
 * @since 0.1.0
 */
class Queue {

	/**
	 * Cron hook that runs a single job.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const RUN_HOOK = 'super_abilities_run_job';

	/**
	 * Prefix no job may target, because those abilities control jobs themselves.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const RESERVED_PREFIX = 'super-abilities/job-';

	/**
	 * Seconds a job may sit untouched before a kick is allowed.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const STALE_AFTER = 30;

	/**
	 * Number of item rows written per insert statement.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const INSERT_CHUNK = 50;

	/**
	 * Queues a job.
	 *
	 * Refuses the job control abilities and anything on the denylist filter, checks
	 * the item count against the `jobs_max_items` setting, and pre-flights every item
	 * against the target ability's input schema and permission callback as the current
	 * user so that an impossible job never reaches the queue.
	 *
	 * @since 0.1.0
	 *
	 * @param string                           $ability           Fully namespaced ability name.
	 * @param array<int, array<string, mixed>> $inputs            One input object per item.
	 * @param array<string, mixed>             $options           Optional. Job options: `stop_on_error`, `keep_results`, `label`.
	 * @param int                              $user_id           Optional. User the job runs as. Default the current user.
	 * @param string|null                      $app_password_uuid Optional. Application password the caller authenticated with.
	 * @return Job|WP_Error The queued job, or an error explaining why it was refused.
	 */
	public static function enqueue( string $ability, array $inputs, array $options = array(), int $user_id = 0, ?string $app_password_uuid = null ) {
		global $wpdb;

		$ability = trim( $ability );

		if ( 0 === strpos( $ability, self::RESERVED_PREFIX ) ) {
			return Error::make(
				'invalid_input',
				__( 'The job control abilities cannot be run as a background job.', 'super-abilities' )
			);
		}

		/**
		 * Filters the abilities that may never be run as a background job.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string> $denied Fully namespaced ability names.
		 */
		$denied = (array) apply_filters( 'super_abilities_jobs_denied_abilities', array() );

		if ( in_array( $ability, array_map( 'strval', $denied ), true ) ) {
			return Error::make(
				'forbidden',
				sprintf(
					/* translators: %s: Ability name. */
					__( 'The ability "%s" is not allowed to run as a background job on this site.', 'super-abilities' ),
					$ability
				)
			);
		}

		$items = array();

		foreach ( $inputs as $input ) {
			if ( is_object( $input ) ) {
				$input = get_object_vars( $input );
			}

			if ( ! is_array( $input ) ) {
				return Error::make(
					'invalid_input',
					__( 'Every job item must be an object of input values for the target ability.', 'super-abilities' )
				);
			}

			$items[] = $input;
		}

		$count = count( $items );
		$max   = self::max_items();

		if ( $count < 1 ) {
			return Error::make(
				'invalid_input',
				__( 'A job needs at least one item.', 'super-abilities' )
			);
		}

		if ( $count > $max ) {
			return Error::make(
				'invalid_input',
				sprintf(
					/* translators: %d: Maximum number of items per job. */
					__( 'A job may hold at most %d items. Split the work across several jobs.', 'super-abilities' ),
					$max
				),
				array( 'max_items' => $max )
			);
		}

		$wp_ability = wp_get_ability( $ability );

		if ( ! $wp_ability instanceof \WP_Ability ) {
			return Error::make(
				'not_found',
				sprintf(
					/* translators: %s: Ability name. */
					__( 'The ability "%s" is not registered on this site.', 'super-abilities' ),
					$ability
				)
			);
		}

		$preflight = self::preflight( $wp_ability, $items );

		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		$job_options = self::normalize_options( $options );
		$now         = Time::mysql( Time::now() );
		$uuid        = wp_generate_uuid4();

		$data = array(
			'uuid'             => $uuid,
			'ability'          => $ability,
			'label'            => (string) $job_options['label'],
			'user_id'          => $user_id,
			'status'           => 'queued',
			'cancel_requested' => 0,
			'total_items'      => $count,
			'done_items'       => 0,
			'failed_items'     => 0,
			'options'          => (string) wp_json_encode( $job_options ),
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$formats = array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );

		if ( null !== $app_password_uuid && '' !== $app_password_uuid ) {
			$data['app_password_uuid'] = $app_password_uuid;
			$formats[]                 = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table; there is nothing to cache on a write.
		$inserted = $wpdb->insert( Install::table( 'jobs' ), $data, $formats );

		if ( ! $inserted ) {
			return Error::make(
				'exception',
				__( 'The job could not be written to the database.', 'super-abilities' )
			);
		}

		$job_id = (int) $wpdb->insert_id;

		if ( ! self::insert_items( $job_id, $items ) ) {
			self::delete( $job_id );

			return Error::make(
				'exception',
				__( 'The job items could not be written to the database.', 'super-abilities' )
			);
		}

		self::schedule( $job_id );

		$job = self::find( $job_id );

		if ( null === $job ) {
			return Error::make(
				'exception',
				__( 'The job was queued but could not be read back.', 'super-abilities' )
			);
		}

		/**
		 * Fires after a background job has been queued and its cron event scheduled.
		 *
		 * @since 0.1.0
		 *
		 * @param Job $job The queued job.
		 */
		do_action( 'super_abilities_job_queued', $job );

		return $job;
	}

	/**
	 * Nudges a job that should be running but is not.
	 *
	 * Loopbacks are blocked on some hosts, so a queued job can sit there until
	 * something asks for it again. This re-arms the cron event and tries to spawn a
	 * cron run, but only for jobs that have been quiet for a while, so that polling
	 * `job-status` in a loop cannot be turned into a cron hammer.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Job to kick.
	 * @return bool Whether the job was kicked.
	 */
	public static function kick( Job $job ) {
		if ( $job->is_finished() ) {
			return false;
		}

		$status = $job->status();

		if ( 'running' === $status ) {
			if ( $job->is_locked() ) {
				return false;
			}
		} elseif ( 'queued' !== $status ) {
			return false;
		}

		if ( ( Time::now() - $job->touched_at() ) < self::STALE_AFTER ) {
			return false;
		}

		self::schedule( $job->id() );

		/**
		 * Fires when a stuck job has been re-armed.
		 *
		 * @since 0.1.0
		 *
		 * @param Job $job The job that was kicked.
		 */
		do_action( 'super_abilities_job_kicked', $job );

		return true;
	}

	/**
	 * Jobs that look stuck and are worth kicking.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit Optional. Maximum number of jobs to return. Default 5.
	 * @return array<int, Job>
	 */
	public static function stuck( $limit = 5 ) {
		global $wpdb;

		$limit  = max( 1, (int) $limit );
		$table  = Install::table( 'jobs' );
		$now    = Time::mysql( Time::now() );
		$cutoff = Time::mysql( Time::now() - self::STALE_AFTER );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and this query looks for rows that are changing.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT * FROM {$table}
				WHERE cancel_requested = 0
					AND updated_at < %s
					AND (
						status = 'queued'
						OR ( status = 'running' AND ( lock_token IS NULL OR lock_expires_at IS NULL OR lock_expires_at < %s ) )
					)
				ORDER BY id ASC
				LIMIT %d",
				$cutoff,
				$now,
				$limit
			)
		);

		return self::hydrate( $rows );
	}

	/**
	 * Reads one job by its public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid Job UUID.
	 * @return Job|null
	 */
	public static function get( string $uuid ) {
		global $wpdb;

		$uuid = trim( $uuid );

		if ( '' === $uuid ) {
			return null;
		}

		$table = Install::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and callers poll for fresh state.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT * FROM {$table} WHERE uuid = %s",
				$uuid
			)
		);

		return Job::from_row( $row );
	}

	/**
	 * Reads one job by its primary key.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public static function find( int $id ) {
		global $wpdb;

		if ( $id < 1 ) {
			return null;
		}

		$table = Install::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and callers poll for fresh state.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);

		return Job::from_row( $row );
	}

	/**
	 * Lists jobs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters  Optional. Accepts `status`, `ability` and `user_id`.
	 * @param int                  $page     Optional. One based page number. Default 1.
	 * @param int                  $per_page Optional. Items per page, capped at 100. Default 20.
	 * @return array{total: int, page: int, per_page: int, jobs: array<int, Job>}
	 */
	public static function list( array $filters = array(), int $page = 1, int $per_page = 20 ) {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$table    = Install::table( 'jobs' );

		// The always true first clause keeps at least one placeholder in the statement.
		$where  = array( 'id > %d' );
		$values = array( 0 );

		if ( ! empty( $filters['status'] ) ) {
			$statuses = array_values(
				array_intersect(
					array_map( 'strval', (array) $filters['status'] ),
					Job::STATUSES
				)
			);

			if ( array() === $statuses ) {
				return array(
					'total'    => 0,
					'page'     => $page,
					'per_page' => $per_page,
					'jobs'     => array(),
				);
			}

			$where[] = 'status IN ( ' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';
			$values  = array_merge( $values, $statuses );
		}

		if ( ! empty( $filters['ability'] ) ) {
			$where[]  = 'ability = %s';
			$values[] = (string) $filters['ability'];
		}

		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $filters['user_id'];
		}

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and callers poll for fresh state.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every value is a placeholder.
				"SELECT COUNT(*) FROM {$table} WHERE {$clause}",
				$values
			)
		);

		$page_values = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and callers poll for fresh state.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The LIMIT and OFFSET placeholders are counted twice because the WHERE clause placeholders are built above.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table() and every value is a placeholder.
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				$page_values
			)
		);

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'jobs'     => self::hydrate( $rows ),
		);
	}

	/**
	 * Asks a job to stop.
	 *
	 * A queued job is cancelled straight away, together with its items. A running job
	 * only gets `cancel_requested`, which the runner re-reads before every item.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Job to cancel.
	 * @return Job|WP_Error The refreshed job.
	 */
	public static function request_cancel( Job $job ) {
		global $wpdb;

		if ( $job->is_finished() ) {
			return $job;
		}

		$table = Install::table( 'jobs' );
		$now   = Time::mysql( Time::now() );

		if ( 'queued' === $job->status() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table; this is a conditional write.
			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
					"UPDATE {$table}
					SET status = 'cancelled', cancel_requested = 1, finished_at = %s, updated_at = %s, lock_token = NULL, lock_expires_at = NULL
					WHERE id = %d AND status = 'queued'",
					$now,
					$now,
					$job->id()
				)
			);

			if ( 1 === $affected ) {
				self::cancel_open_items( $job->id() );

				$refreshed = self::find( $job->id() );

				return null === $refreshed ? $job : $refreshed;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table; this is a conditional write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"UPDATE {$table}
				SET cancel_requested = 1, updated_at = %s
				WHERE id = %d AND status IN ( 'queued', 'running' )",
				$now,
				$job->id()
			)
		);

		$refreshed = self::find( $job->id() );

		return null === $refreshed ? $job : $refreshed;
	}

	/**
	 * Marks every item that has not started yet as cancelled.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return int Number of items cancelled.
	 */
	public static function cancel_open_items( int $job_id ) {
		global $wpdb;

		$table = Install::table( 'job_items' );
		$now   = Time::mysql( Time::now() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table; this is a bulk write.
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"UPDATE {$table}
				SET status = 'cancelled', finished_at = %s
				WHERE job_id = %d AND status IN ( 'pending', 'running' )",
				$now,
				$job_id
			)
		);
	}

	/**
	 * Deletes finished jobs that are older than the retention window.
	 *
	 * @since 0.1.0
	 *
	 * @param int $days Number of days to keep finished jobs for.
	 * @return int Number of jobs deleted.
	 */
	public static function prune( int $days ) {
		global $wpdb;

		$days   = max( 1, $days );
		$cutoff = Time::mysql( Time::now() - ( $days * DAY_IN_SECONDS ) );
		$jobs   = Install::table( 'jobs' );
		$items  = Install::table( 'job_items' );
		$total  = 0;

		for ( $batch = 0; $batch < 50; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and this maintenance query must not be cached.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
					"SELECT id FROM {$jobs}
					WHERE status IN ( 'completed', 'partial', 'failed', 'cancelled' )
						AND COALESCE( finished_at, updated_at ) < %s
					ORDER BY id ASC
					LIMIT 200",
					$cutoff
				)
			);

			$ids = array_map( 'intval', (array) $ids );
			$ids = array_values( array_filter( $ids ) );

			if ( array() === $ids ) {
				break;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table and this maintenance query must not be cached.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every id is a placeholder.
					"DELETE FROM {$items} WHERE job_id IN ( {$placeholders} )",
					$ids
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table and this maintenance query must not be cached.
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every id is a placeholder.
					"DELETE FROM {$jobs} WHERE id IN ( {$placeholders} )",
					$ids
				)
			);

			$total += $deleted;

			if ( count( $ids ) < 200 ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Deletes one job and its items.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function delete( int $job_id ) {
		global $wpdb;

		if ( $job_id < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table; there is nothing to cache on a delete.
		$wpdb->delete( Install::table( 'job_items' ), array( 'job_id' => $job_id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Jobs live in a custom table; there is nothing to cache on a delete.
		$wpdb->delete( Install::table( 'jobs' ), array( 'id' => $job_id ), array( '%d' ) );
	}

	/**
	 * Reads a page of items belonging to a job.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $job_id   Job id.
	 * @param string|null $status   Optional. Limit to one item status. Default null.
	 * @param int         $page     Optional. One based page number. Default 1.
	 * @param int         $per_page Optional. Items per page, capped at 100. Default 20.
	 * @return array{total: int, page: int, per_page: int, items: array<int, array<string, mixed>>}
	 */
	public static function items( int $job_id, $status = null, int $page = 1, int $per_page = 20 ) {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$table    = Install::table( 'job_items' );

		$clause = 'job_id = %d';
		$values = array( $job_id );

		if ( null !== $status && '' !== (string) $status ) {
			$clause  .= ' AND status = %s';
			$values[] = (string) $status;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table and callers poll for fresh state.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every value is a placeholder.
				"SELECT COUNT(*) FROM {$table} WHERE {$clause}",
				$values
			)
		);

		$page_values = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table and callers poll for fresh state.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The LIMIT and OFFSET placeholders are counted twice because the WHERE clause placeholders are built above.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table() and every value is a placeholder.
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY position ASC LIMIT %d OFFSET %d",
				$page_values
			),
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$columns = array();

			foreach ( $row as $column => $value ) {
				$columns[ (string) $column ] = $value;
			}

			$items[] = $columns;
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		);
	}

	/**
	 * Counts a job's items per status.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return array<string, int> Counts keyed by item status, including zeroes.
	 */
	public static function item_counts( int $job_id ) {
		global $wpdb;

		$table  = Install::table( 'job_items' );
		$counts = array_fill_keys( Job::ITEM_STATUSES, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table and these counts change while a job runs.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE job_id = %d GROUP BY status",
				$job_id
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['status'] ) ) {
				continue;
			}

			$counts[ (string) $row['status'] ] = isset( $row['total'] ) ? (int) $row['total'] : 0;
		}

		return $counts;
	}

	/**
	 * Whether the current user may see and control a job.
	 *
	 * The owner may always do so; everybody else needs `manage_options`.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Job to test.
	 * @return bool
	 */
	public static function can_access( Job $job ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 && $user_id === $job->user_id() ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * The largest number of items a single job may hold.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function max_items() {
		$max = (int) Plugin::instance()->options()->get( 'jobs_max_items', 500 );

		return max( 1, $max );
	}

	/**
	 * Schedules the runner for a job and tries to spawn a cron run.
	 *
	 * @since 0.1.0
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function schedule( int $job_id ) {
		if ( $job_id < 1 ) {
			return;
		}

		wp_schedule_single_event( Time::now(), self::RUN_HOOK, array( $job_id ) );

		spawn_cron();
	}

	/**
	 * Normalizes the caller supplied job options.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $options Raw options.
	 * @return array{stop_on_error: bool, keep_results: bool, label: string}
	 */
	public static function normalize_options( array $options ) {
		$label = isset( $options['label'] ) && ! is_array( $options['label'] ) ? (string) $options['label'] : '';
		$label = sanitize_text_field( $label );

		if ( strlen( $label ) > 191 ) {
			$label = substr( $label, 0, 191 );
		}

		return array(
			'stop_on_error' => ! empty( $options['stop_on_error'] ),
			'keep_results'  => ! array_key_exists( 'keep_results', $options ) || ! empty( $options['keep_results'] ),
			'label'         => $label,
		);
	}

	/**
	 * Validates and permission checks every item before the job is written.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Ability                      $wp_ability Target ability.
	 * @param array<int, array<string, mixed>> $items      Item inputs.
	 * @return true|WP_Error
	 */
	protected static function preflight( \WP_Ability $wp_ability, array $items ) {
		foreach ( $items as $position => $input ) {
			$valid = $wp_ability->validate_input( $input );

			if ( is_wp_error( $valid ) ) {
				return Error::make(
					'invalid_input',
					sprintf(
						/* translators: 1: Zero based item position, 2: Validation error message. */
						__( 'Item %1$d is not valid input for the target ability: %2$s', 'super-abilities' ),
						(int) $position,
						$valid->get_error_message()
					),
					array( 'item' => (int) $position )
				);
			}

			$allowed = $wp_ability->check_permissions( $input );

			if ( true !== $allowed ) {
				$message = is_wp_error( $allowed ) ? $allowed->get_error_message() : '';

				return Error::make(
					'forbidden',
					'' !== $message
						? sprintf(
							/* translators: 1: Zero based item position, 2: Permission error message. */
							__( 'You are not allowed to run item %1$d of this job: %2$s', 'super-abilities' ),
							(int) $position,
							$message
						)
						: sprintf(
							/* translators: %d: Zero based item position. */
							__( 'You are not allowed to run item %d of this job.', 'super-abilities' ),
							(int) $position
						),
					array( 'item' => (int) $position )
				);
			}
		}

		return true;
	}

	/**
	 * Writes the item rows for a job.
	 *
	 * @since 0.1.0
	 *
	 * @param int                              $job_id Job id.
	 * @param array<int, array<string, mixed>> $items  Item inputs, in order.
	 * @return bool Whether every chunk was written.
	 */
	protected static function insert_items( int $job_id, array $items ) {
		global $wpdb;

		$table    = Install::table( 'job_items' );
		$position = 0;

		foreach ( array_chunk( $items, self::INSERT_CHUNK ) as $chunk ) {
			$rows   = array();
			$values = array();

			foreach ( $chunk as $input ) {
				$rows[]   = '( %d, %d, %s, %s )';
				$values[] = $job_id;
				$values[] = $position;
				$values[] = 'pending';
				$values[] = (string) wp_json_encode( $input );

				++$position;
			}

			$placeholders = implode( ', ', $rows );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job items live in a custom table; there is nothing to cache on a write.
			$written = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every value is a placeholder.
					"INSERT INTO {$table} ( job_id, position, status, input ) VALUES {$placeholders}",
					$values
				)
			);

			if ( false === $written ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Turns database rows into job objects.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $rows Rows returned by `$wpdb`.
	 * @return array<int, Job>
	 */
	protected static function hydrate( $rows ) {
		$jobs = array();

		foreach ( (array) $rows as $row ) {
			$job = Job::from_row( $row );

			if ( $job instanceof Job ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}
}
