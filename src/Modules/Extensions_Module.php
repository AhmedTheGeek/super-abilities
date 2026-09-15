<?php
/**
 * Plugin and theme management module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Extensions\Extension_Activate;
use SuperAbilities\Abilities\Extensions\Extension_Deactivate;
use SuperAbilities\Abilities\Extensions\Extension_Delete;
use SuperAbilities\Abilities\Extensions\Extension_Install;
use SuperAbilities\Abilities\Extensions\Extension_Preflight;
use SuperAbilities\Abilities\Extensions\Extension_Rollback;
use SuperAbilities\Abilities\Extensions\Extension_Update;
use SuperAbilities\Abilities\Extensions\Extensions_List;
use SuperAbilities\Abilities\Extensions\Restore_Point_Delete;
use SuperAbilities\Abilities\Extensions\Restore_Points_List;
use SuperAbilities\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Installs, updates, activates, rolls back and deletes plugins and themes.
 *
 * This is the only module that writes to the filesystem, which is why it is off until
 * an administrator switches it on. Every write is preceded by a pre-flight check and,
 * where files are replaced or removed, by a restore point, and followed by a loopback
 * smoke test that undoes the change when the site stops answering.
 *
 * @since 0.2.0
 */
class Extensions_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id() {
		return 'extensions';
	}

	/**
	 * Module label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Extensions', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Plugin and theme install, update and rollback with a pre-flight check, a restore point and a smoke test. Packages come from WordPress.org by slug, or from a ZIP URL when you allow that below and list the hosts. Before anything is written the pre-flight check confirms that file modifications are allowed, that WordPress can write files directly, that there is disk space and that the package still supports this WordPress and this PHP. Updates and deletes copy the current files into a private restore point first, and an update that leaves the site returning HTTP 500 is rolled back automatically. This module is the only one that touches the filesystem, so it is off until you switch it on.', 'super-abilities' );
	}

	/**
	 * Whether the module is enabled on a fresh install.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return false;
	}

	/**
	 * Risk level.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function risk() {
		return 'high';
	}

	/**
	 * The abilities this module provides.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function abilities() {
		return array(
			Extensions_List::class,
			Extension_Preflight::class,
			Extension_Install::class,
			Extension_Update::class,
			Extension_Rollback::class,
			Extension_Activate::class,
			Extension_Deactivate::class,
			Extension_Delete::class,
			Restore_Points_List::class,
			Restore_Point_Delete::class,
		);
	}
}
