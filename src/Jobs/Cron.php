<?php
/**
 * Job cron wiring.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Jobs;

use SuperAbilities\Plugin;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the jobs subsystem to WP-Cron.
 *
 * Three things happen here: the single event that runs one job, the daily prune, and
 * a throttled admin side rescue for jobs that were queued on a host where the cron
 * loopback does not work.
 *
 * @since 0.1.0
 */
class Cron {

	/**
	 * Transient that throttles the admin side rescue.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const KICK_TRANSIENT = 'super_abilities_jobs_kicked';

	/**
	 * Seconds between two admin side rescue passes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const KICK_INTERVAL = 60;

	/**
	 * Number of stuck jobs looked at per rescue pass.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const KICK_BATCH = 5;

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( Queue::RUN_HOOK, array( $this, 'run_job' ) );
		add_action( 'super_abilities_jobs_prune', array( $this, 'prune' ) );
		add_action( 'admin_init', array( $this, 'kick_stuck_jobs' ) );
	}

	/**
	 * Runs one job. Bound to the `super_abilities_run_job` single event.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $job_id Job id, as stored in the cron argument array.
	 * @return void
	 */
	public function run_job( $job_id = 0 ) {
		if ( is_array( $job_id ) || is_object( $job_id ) ) {
			return;
		}

		Runner::run( (int) $job_id );
	}

	/**
	 * Deletes finished jobs past the retention window. Runs daily.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function prune() {
		$days = (int) Plugin::instance()->options()->get( 'jobs_retention_days', 30 );

		Queue::prune( $days );
	}

	/**
	 * Re-arms jobs that should be running but are not.
	 *
	 * Bound to `admin_init` and throttled with a transient, because on hosts where the
	 * cron loopback is blocked a queued job would otherwise never start until somebody
	 * polled `job-status`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function kick_stuck_jobs() {
		if ( wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}

		if ( false !== get_transient( self::KICK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::KICK_TRANSIENT, Time::now(), self::KICK_INTERVAL );

		foreach ( Queue::stuck( self::KICK_BATCH ) as $job ) {
			Queue::kick( $job );
		}
	}
}
