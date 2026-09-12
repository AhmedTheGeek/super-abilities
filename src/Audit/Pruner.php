<?php
/**
 * Audit log retention.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Audit;

use SuperAbilities\Install;
use SuperAbilities\Options;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes audit rows that fell out of the retention window.
 *
 * Deletes run in small batches with a hard cap per run, so that a log that grew
 * for years never turns one cron tick into a long lock on the table.
 *
 * @since 0.1.0
 */
class Pruner {

	/**
	 * Cron hook that triggers a prune.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const HOOK = 'super_abilities_audit_prune';

	/**
	 * Rows deleted per statement.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const BATCH_SIZE = 1000;

	/**
	 * Statements per cron run.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_BATCHES = 20;

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 * @var Options
	 */
	protected $options;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Options $options Settings.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Hooks the prune onto its cron event.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( self::HOOK, array( $this, 'on_cron' ) );
	}

	/**
	 * Runs a prune from cron, discarding the return value.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_cron() {
		$this->prune();
	}

	/**
	 * Deletes expired rows.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of rows deleted.
	 */
	public function prune() {
		global $wpdb;

		$days = (int) $this->options->get( 'audit_retention_days', 90 );

		if ( $days < 1 ) {
			return 0;
		}

		$cutoff  = Time::mysql( Time::now() - ( $days * DAY_IN_SECONDS ) );
		$table   = Install::table( 'audit_log' );
		$deleted = 0;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deleting from our own table; the table name comes from Install::table() and every value is prepared.
			$rows = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE created_at < %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Install::table().
					$cutoff,
					self::BATCH_SIZE
				)
			);

			if ( ! is_int( $rows ) || $rows < 1 ) {
				break;
			}

			$deleted += $rows;

			if ( $rows < self::BATCH_SIZE ) {
				break;
			}
		}

		if ( $deleted > 0 ) {
			/**
			 * Fires after expired audit rows were deleted.
			 *
			 * @since 0.1.0
			 *
			 * @param int    $deleted Number of rows deleted.
			 * @param string $cutoff  MySQL datetime rows were deleted before.
			 */
			do_action( 'super_abilities_audit_pruned', $deleted, $cutoff );
		}

		return $deleted;
	}
}
