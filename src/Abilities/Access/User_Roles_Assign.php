<?php
/**
 * User role assignment ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Access;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Access\Guard;
use SuperAbilities\Support\Schema;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Changes which roles a user has.
 *
 * Three guards apply on top of the capability gate: a caller can only hand out a
 * role whose every capability they hold themselves, nobody can remove their own
 * administrator role without `force`, and the last administrator on the site can
 * never be demoted at all.
 *
 * @since 0.2.0
 */
class User_Roles_Assign extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'user-roles-assign';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'access';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Assign roles to a user', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Sets, adds or removes the roles of one user and reports the roles before and after. A caller can only hand out roles whose capabilities they hold themselves, which is what stops an editor from creating an administrator. Removing your own administrator role requires force, demoting the only administrator on the site is always refused, and only a network super admin can change another super admin. Pass the fingerprint from user-roles-read as expected_fingerprint, and dry_run to preview.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write_idempotent();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'promote_users' );
	}

	/**
	 * Plugin version this ability first shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Object level permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool|WP_Error
	 */
	public function permission( array $input ) {
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

		if ( $user_id <= 0 ) {
			return true;
		}

		if ( ! get_user_by( 'id', $user_id ) instanceof WP_User ) {
			return $this->error( 'not_found', __( 'That user does not exist.', 'super-abilities' ), 404, array( 'user_id' => $user_id ) );
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return $this->error(
				'forbidden',
				__( 'You are not allowed to edit that user.', 'super-abilities' ),
				403,
				array(
					'user_id' => $user_id,
					'reason'  => 'cannot_edit_user',
				)
			);
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_users' ) ) {
			return $this->error(
				'forbidden',
				__( 'Changing roles on a multisite installation requires the manage_network_users capability.', 'super-abilities' ),
				403,
				array(
					'user_id' => $user_id,
					'reason'  => 'requires_manage_network_users',
				)
			);
		}

		if ( Guard::is_super_admin_user( $user_id ) && ! is_super_admin( get_current_user_id() ) ) {
			return $this->error(
				'forbidden',
				__( 'Only a network super admin can change the roles of another super admin.', 'super-abilities' ),
				403,
				array(
					'user_id' => $user_id,
					'reason'  => 'super_admin_target',
				)
			);
		}

		return true;
	}

	/**
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'user_id'              => Schema::id( __( 'Id of the user whose roles change.', 'super-abilities' ) ),
				'roles'                => Schema::csv_or_array_of_strings( __( 'Role slugs. In replace mode this is the final set; in add and remove mode it is the delta.', 'super-abilities' ) ),
				'mode'                 => array(
					'type'        => 'string',
					'enum'        => array( 'replace', 'add', 'remove' ),
					'default'     => 'replace',
					'description' => __( 'replace makes the list the final set of roles, add appends, remove takes away. Default replace.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint of the role list as you last read it.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would change and change nothing. Default false.', 'super-abilities' ),
				),
				'force'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow removing your own administrator role. Default false.', 'super-abilities' ),
				),
			),
			array( 'user_id', 'roles' )
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'user_id'     => Schema::id(),
				'login'       => array( 'type' => 'string' ),
				'mode'        => array( 'type' => 'string' ),
				'dry_run'     => array( 'type' => 'boolean' ),
				'changed'     => array( 'type' => 'boolean' ),
				'before'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'after'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'added'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'removed'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the role list after the change.', 'super-abilities' ) ),
				'warnings'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'user_id', 'login', 'mode', 'dry_run', 'changed', 'before', 'after', 'added', 'removed', 'fingerprint', 'warnings' )
		);
	}

	/**
	 * Applies the change.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

		if ( ! $user instanceof WP_User ) {
			return $this->error( 'not_found', __( 'That user does not exist.', 'super-abilities' ), 404, array( 'user_id' => $user_id ) );
		}

		$before = Guard::normalize_roles( (array) $user->roles );

		$stale = $this->guard_fingerprint( $input, Guard::roles_fingerprint( $before ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$requested = Guard::normalize_roles( Schema::to_string_list( isset( $input['roles'] ) ? $input['roles'] : array() ) );

		if ( empty( $requested ) ) {
			return $this->error( 'invalid_input', __( 'At least one role is required.', 'super-abilities' ), 400 );
		}

		$unknown = array();

		foreach ( $requested as $role ) {
			if ( ! Guard::role_exists( $role ) ) {
				$unknown[] = $role;
			}
		}

		if ( ! empty( $unknown ) ) {
			return $this->error(
				'invalid_input',
				sprintf(
					/* translators: %s: Comma separated role slugs. */
					__( 'These roles do not exist on this site: %s. Call roles-list to see what does.', 'super-abilities' ),
					implode( ', ', $unknown )
				),
				400,
				array( 'roles' => $unknown )
			);
		}

		$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
		$after = $this->apply_mode( $mode, $before, $requested );

		if ( null === $after ) {
			return $this->error( 'invalid_input', __( 'Mode must be replace, add or remove.', 'super-abilities' ), 400 );
		}

		$added   = array_values( array_diff( $after, $before ) );
		$removed = array_values( array_diff( $before, $after ) );

		$refused = Guard::check_assignable( $added );

		if ( null !== $refused ) {
			return $refused;
		}

		$refused = Guard::check_administrator_loss( $user_id, $before, $after, ! empty( $input['force'] ) );

		if ( null !== $refused ) {
			return $refused;
		}

		$warnings = array();

		if ( empty( $after ) ) {
			$warnings[] = __( 'This user now has no role at all and can do nothing on the site.', 'super-abilities' );
		}

		if ( in_array( 'administrator', $added, true ) ) {
			$warnings[] = __( 'This user is now an administrator and can do anything on the site.', 'super-abilities' );
		}

		$this->note_object( 'user', $user_id );

		$changed = ! empty( $added ) || ! empty( $removed );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'user_id'     => $user_id,
				'login'       => (string) $user->user_login,
				'mode'        => $mode,
				'dry_run'     => true,
				'changed'     => false,
				'before'      => $before,
				'after'       => $after,
				'added'       => $added,
				'removed'     => $removed,
				'fingerprint' => Guard::roles_fingerprint( $before ),
				'warnings'    => $warnings,
			);
		}

		if ( $changed ) {
			$this->apply_roles( $user, $after, $added, $removed );
		}

		$fresh = get_user_by( 'id', $user_id );
		$final = $fresh instanceof WP_User ? Guard::normalize_roles( (array) $fresh->roles ) : $after;

		return array(
			'user_id'     => $user_id,
			'login'       => (string) $user->user_login,
			'mode'        => $mode,
			'dry_run'     => false,
			'changed'     => $changed,
			'before'      => $before,
			'after'       => $final,
			'added'       => $added,
			'removed'     => $removed,
			'fingerprint' => Guard::roles_fingerprint( $final ),
			'warnings'    => $warnings,
		);
	}

	/**
	 * Works out the role list the change ends with.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $mode      One of `replace`, `add` or `remove`.
	 * @param array<int, string> $before    Current roles.
	 * @param array<int, string> $requested Roles from the input.
	 * @return array<int, string>|null Null when the mode is unknown.
	 */
	protected function apply_mode( $mode, array $before, array $requested ) {
		if ( 'replace' === $mode ) {
			return $requested;
		}

		if ( 'add' === $mode ) {
			return Guard::normalize_roles( array_merge( $before, $requested ) );
		}

		if ( 'remove' === $mode ) {
			return Guard::normalize_roles( array_diff( $before, $requested ) );
		}

		return null;
	}

	/**
	 * Writes the new role list onto the user.
	 *
	 * `set_role()` is only used when every role is being replaced at once, because it
	 * drops the rest; otherwise the delta is applied role by role so that nothing the
	 * caller did not ask about is lost.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_User            $user    User to change.
	 * @param array<int, string> $after   Roles the user should end with.
	 * @param array<int, string> $added   Roles to add.
	 * @param array<int, string> $removed Roles to remove.
	 * @return void
	 */
	protected function apply_roles( WP_User $user, array $after, array $added, array $removed ) {
		if ( empty( $after ) ) {
			// The only way to end with no role at all.
			$user->set_role( '' );

			return;
		}

		foreach ( $added as $role ) {
			$user->add_role( $role );
		}

		foreach ( $removed as $role ) {
			$user->remove_role( $role );
		}
	}
}
