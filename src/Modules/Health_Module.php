<?php
/**
 * Diagnostics module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Health\Cron_Health;
use SuperAbilities\Abilities\Health\Cron_List;
use SuperAbilities\Abilities\Health\Cron_Run;
use SuperAbilities\Abilities\Health\Cron_Unschedule;
use SuperAbilities\Abilities\Health\Debug_Log_Read;
use SuperAbilities\Abilities\Health\Error_Triage;
use SuperAbilities\Abilities\Health\Health_Run;
use SuperAbilities\Abilities\Health\Health_Summary;
use SuperAbilities\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health, the debug log and WP-Cron, exposed as abilities.
 *
 * Everything here is read-only except `cron-run` and `cron-unschedule`, which act
 * on the cron schedule only.
 *
 * @since 0.1.0
 */
class Health_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id() {
		return 'health';
	}

	/**
	 * Module label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Diagnostics', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs the Site Health tests, reads and groups the PHP debug log, correlates fatal errors with recent changes, and inspects, runs or removes WP-Cron events.', 'super-abilities' );
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
	 * Abilities provided by this module.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function abilities() {
		return array(
			Health_Run::class,
			Health_Summary::class,
			Debug_Log_Read::class,
			Error_Triage::class,
			Cron_List::class,
			Cron_Health::class,
			Cron_Run::class,
			Cron_Unschedule::class,
		);
	}
}
