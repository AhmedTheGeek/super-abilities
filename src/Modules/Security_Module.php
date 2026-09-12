<?php
/**
 * Security audit module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Security\Admin_Users_Audit;
use SuperAbilities\Abilities\Security\Config_Hardening_Check;
use SuperAbilities\Abilities\Security\File_Permissions_Audit;
use SuperAbilities\Abilities\Security\Integrity_Check;
use SuperAbilities\Abilities\Security\Updates_Overview;
use SuperAbilities\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only security posture abilities: integrity, permissions, users, hardening, updates.
 *
 * Nothing in this module writes, installs or repairs anything. Every ability answers
 * a question about the site and returns findings with a recommendation, which keeps
 * the module safe to leave enabled by default.
 *
 * @since 0.1.0
 */
class Security_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id() {
		return 'security';
	}

	/**
	 * Module label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Security audit', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Read-only security auditing: compares core and plugin files against the official WordPress.org checksums, inspects file permissions and publicly reachable files, audits privileged users and their application passwords, reviews hardening constants such as DISALLOW_FILE_EDIT and the security salts, and reports pending updates with their PHP and WordPress compatibility.', 'super-abilities' );
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
			Integrity_Check::class,
			File_Permissions_Audit::class,
			Admin_Users_Audit::class,
			Config_Hardening_Check::class,
			Updates_Overview::class,
		);
	}
}
