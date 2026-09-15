<?php
/**
 * User role reading ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Access;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Access\Guard;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the roles and effective capabilities of one user.
 *
 * @since 0.2.0
 */
class User_Roles_Read extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'user-roles-read';
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
		return __( 'Read a user\'s roles', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reports the roles of one user, looked up by id, login or email address, together with every capability those roles add up to, the capabilities set on the account itself rather than on a role, whether the account is a network super admin, and a fingerprint of the role list to pass back as expected_fingerprint when assigning roles. The email address is only returned to callers who can edit users.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'list_users' );
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
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'user_id' => Schema::id( __( 'Id of the user to read.', 'super-abilities' ) ),
				'login'   => array(
					'type'        => 'string',
					'description' => __( 'Login of the user to read, when you have no id.', 'super-abilities' ),
				),
				'email'   => array(
					'type'        => 'string',
					'description' => __( 'Email address of the user to read, when you have no id.', 'super-abilities' ),
				),
			)
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
				'id'                  => Schema::id(),
				'login'               => array( 'type' => 'string' ),
				'display_name'        => array( 'type' => 'string' ),
				'email'               => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Null unless the caller can edit users.', 'super-abilities' ),
				),
				'roles'               => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'role_names'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'capabilities'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Every capability the account effectively has.', 'super-abilities' ),
				),
				'capability_count'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'direct_capabilities' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Capabilities set on the account itself rather than through a role.', 'super-abilities' ),
				),
				'dangerous'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'is_super_admin'      => array( 'type' => 'boolean' ),
				'fingerprint'         => Schema::fingerprint( __( 'Fingerprint of the role list, for expected_fingerprint on user-roles-assign.', 'super-abilities' ) ),
			),
			array( 'id', 'login', 'display_name', 'email', 'roles', 'role_names', 'capabilities', 'capability_count', 'direct_capabilities', 'dangerous', 'is_super_admin', 'fingerprint' )
		);
	}

	/**
	 * Reads the user.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		if ( empty( $input['user_id'] ) && empty( $input['login'] ) && empty( $input['email'] ) ) {
			return $this->error( 'invalid_input', __( 'Pass a user_id, a login or an email address.', 'super-abilities' ), 400 );
		}

		$user = Guard::resolve_user( $input );

		if ( null === $user ) {
			return $this->error( 'not_found', __( 'No user matches that id, login or email address.', 'super-abilities' ), 404 );
		}

		$roles       = Guard::normalize_roles( (array) $user->roles );
		$definitions = Guard::role_definitions();
		$names       = array();

		foreach ( $roles as $role ) {
			$name    = isset( $definitions[ $role ]['name'] ) ? (string) $definitions[ $role ]['name'] : $role;
			$names[] = translate_user_role( $name );
		}

		$caps = array();

		foreach ( (array) $user->allcaps as $cap => $granted ) {
			$cap = (string) $cap;

			if ( ! $granted || Guard::role_exists( $cap ) ) {
				continue;
			}

			$caps[] = $cap;
		}

		sort( $caps );

		$direct = array();

		foreach ( (array) $user->caps as $cap => $granted ) {
			$cap = (string) $cap;

			if ( ! $granted || Guard::role_exists( $cap ) ) {
				continue;
			}

			$direct[] = $cap;
		}

		sort( $direct );

		return array(
			'id'                  => (int) $user->ID,
			'login'               => (string) $user->user_login,
			'display_name'        => (string) $user->display_name,
			'email'               => current_user_can( 'edit_users' ) ? (string) $user->user_email : null,
			'roles'               => $roles,
			'role_names'          => $names,
			'capabilities'        => $caps,
			'capability_count'    => count( $caps ),
			'direct_capabilities' => $direct,
			'dangerous'           => array_values( array_filter( $caps, array( Guard::class, 'is_dangerous_capability' ) ) ),
			'is_super_admin'      => Guard::is_super_admin_user( $user->ID ),
			'fingerprint'         => Guard::roles_fingerprint( $roles ),
		);
	}
}
