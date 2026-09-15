<?php
/**
 * Roles and capabilities module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Access\Access_Audit;
use SuperAbilities\Abilities\Access\Capabilities_Grant;
use SuperAbilities\Abilities\Access\Capabilities_Revoke;
use SuperAbilities\Abilities\Access\Capability_Explain;
use SuperAbilities\Abilities\Access\Role_Create;
use SuperAbilities\Abilities\Access\Role_Delete;
use SuperAbilities\Abilities\Access\Roles_List;
use SuperAbilities\Abilities\Access\User_Roles_Assign;
use SuperAbilities\Abilities\Access\User_Roles_Read;
use SuperAbilities\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Explains, defines and assigns roles and capabilities.
 *
 * This is the most dangerous module in the plugin, which is why it ships switched
 * off: a single capability handed to the wrong role is a site takeover. Everything
 * here is therefore bounded by {@see \SuperAbilities\Access\Guard}: the administrator
 * role cannot be weakened, a short list of capabilities is never granted at all, and
 * no caller can hand out a capability they do not hold themselves.
 *
 * @since 0.2.0
 */
class Access_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id() {
		return 'access';
	}

	/**
	 * Module label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Roles and capabilities', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reads, explains and edits the role and capability map of the site: what every role grants, which roles give a capability to whom, and why one user can do something another cannot. It also creates and deletes roles, grants and revokes capabilities and changes which roles a user has. The administrator role is never weakened or deleted, capabilities that amount to arbitrary code execution such as edit_plugins or unfiltered_html are never granted, a caller can only hand out capabilities they hold themselves, and the site can never be left without an administrator.', 'super-abilities' );
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
			Roles_List::class,
			Capability_Explain::class,
			Role_Create::class,
			Role_Delete::class,
			Capabilities_Grant::class,
			Capabilities_Revoke::class,
			User_Roles_Read::class,
			User_Roles_Assign::class,
			Access_Audit::class,
		);
	}
}
