<?php
/**
 * Background jobs module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Jobs\Job_Cancel;
use SuperAbilities\Abilities\Jobs\Job_List;
use SuperAbilities\Abilities\Jobs\Job_Start;
use SuperAbilities\Abilities\Jobs\Job_Status;
use SuperAbilities\Abstract_Module;
use SuperAbilities\Jobs\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Runs any other ability in the background, one input at a time.
 *
 * @since 0.1.0
 */
class Jobs_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id() {
		return 'jobs';
	}

	/**
	 * Module label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Background jobs', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lets an agent queue any other ability to run in the background through WP-Cron, one input object at a time, and poll it for progress. Work that would time out in a single request, such as regenerating every thumbnail or checksumming a long list of plugins, becomes a job with per item results and a cancel switch. A job adds no permissions of its own: every item is validated and permission checked against the target ability as the user who queued it, so it is exactly as safe, or as destructive, as calling that ability by hand.', 'super-abilities' );
	}

	/**
	 * Whether the module is enabled on a fresh install.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * Risk level.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function risk() {
		return 'low';
	}

	/**
	 * The abilities this module provides.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function abilities() {
		return array(
			Job_Start::class,
			Job_Status::class,
			Job_List::class,
			Job_Cancel::class,
		);
	}

	/**
	 * Wires the runner, the daily prune and the admin side rescue.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot() {
		$cron = new Cron();
		$cron->init();
	}
}
