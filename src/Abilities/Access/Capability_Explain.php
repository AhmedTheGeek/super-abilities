<?php
/**
 * Capability explanation ability.
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
 * Answers "who can do this, and why" for a single capability.
 *
 * @since 0.2.0
 */
class Capability_Explain extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'capability-explain';
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
		return __( 'Explain a capability', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Explains one capability: which roles grant it, which roles deny it explicitly, whether any role knows it at all, whether it is high impact or one this plugin never grants, and, for a meta capability such as edit_post, that it cannot be answered without an object. Pass a user id to also get whether that user has it, through which of their roles, and which primitive capabilities WordPress maps it to for them.', 'super-abilities' );
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
				'capability' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Capability name to explain, for example edit_posts.', 'super-abilities' ),
				),
				'user_id'    => Schema::id( __( 'Also answer whether this user has the capability.', 'super-abilities' ) ),
			),
			array( 'capability' )
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
				'capability'      => array( 'type' => 'string' ),
				'known'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether any role on this site mentions the capability.', 'super-abilities' ),
				),
				'forbidden'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this plugin refuses to grant the capability.', 'super-abilities' ),
				),
				'dangerous'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the capability is high impact.', 'super-abilities' ),
				),
				'requires_object' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a meta capability that needs an object id to be answered.', 'super-abilities' ),
				),
				'granted_by'      => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'role'  => array( 'type' => 'string' ),
							'name'  => array( 'type' => 'string' ),
							'users' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						array( 'role', 'name', 'users' )
					),
				),
				'denied_by'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'maps_to'         => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Primitive capabilities WordPress maps this one to for the given user. Empty when no user was given.', 'super-abilities' ),
				),
				'user'            => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'id'               => Schema::id(),
						'login'            => array( 'type' => 'string' ),
						'can'              => array(
							'type'        => array( 'boolean', 'null' ),
							'description' => __( 'Whether the user has the capability, or null when it needs an object id.', 'super-abilities' ),
						),
						'via_roles'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'roles'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'granted_directly' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the capability is set on the user rather than on one of their roles.', 'super-abilities' ),
						),
						'is_super_admin'   => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
			),
			array( 'capability', 'known', 'forbidden', 'dangerous', 'requires_object', 'granted_by', 'denied_by', 'maps_to', 'user' )
		);
	}

	/**
	 * Builds the explanation.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$cap = isset( $input['capability'] ) ? sanitize_key( (string) $input['capability'] ) : '';

		if ( '' === $cap ) {
			return $this->error( 'invalid_input', __( 'A capability name is required.', 'super-abilities' ), 400 );
		}

		$counts     = Guard::role_user_counts();
		$granted_by = array();
		$denied_by  = array();

		foreach ( array_keys( Guard::role_definitions() ) as $slug ) {
			$slug = (string) $slug;
			$caps = Guard::role_capabilities( $slug );

			if ( ! array_key_exists( $cap, $caps ) ) {
				continue;
			}

			if ( $caps[ $cap ] ) {
				$described    = Guard::describe_role( $slug, $counts );
				$granted_by[] = array(
					'role'  => $described['slug'],
					'name'  => $described['name'],
					'users' => $described['users'],
				);

				continue;
			}

			$denied_by[] = $slug;
		}

		$requires_object = Guard::requires_object( $cap );

		$result = array(
			'capability'      => $cap,
			'known'           => ! empty( $granted_by ) || ! empty( $denied_by ),
			'forbidden'       => Guard::is_forbidden_capability( $cap ),
			'dangerous'       => Guard::is_dangerous_capability( $cap ),
			'requires_object' => $requires_object,
			'granted_by'      => $granted_by,
			'denied_by'       => $denied_by,
			'maps_to'         => array(),
			'user'            => null,
		);

		if ( empty( $input['user_id'] ) ) {
			return $result;
		}

		$user = get_user_by( 'id', (int) $input['user_id'] );

		if ( ! $user instanceof WP_User ) {
			return $this->error(
				'not_found',
				__( 'That user does not exist.', 'super-abilities' ),
				404,
				array( 'user_id' => (int) $input['user_id'] )
			);
		}

		if ( ! $requires_object ) {
			$result['maps_to'] = array_values( array_map( 'strval', (array) map_meta_cap( $cap, $user->ID ) ) );
		}

		$roles     = Guard::normalize_roles( (array) $user->roles );
		$via_roles = array();

		foreach ( $roles as $slug ) {
			$caps = Guard::role_capabilities( $slug );

			if ( ! empty( $caps[ $cap ] ) ) {
				$via_roles[] = $slug;
			}
		}

		$result['user'] = array(
			'id'               => (int) $user->ID,
			'login'            => (string) $user->user_login,
			'can'              => $requires_object ? null : user_can( $user, $cap ),
			'via_roles'        => $via_roles,
			'roles'            => $roles,
			'granted_directly' => ! empty( $user->caps[ $cap ] ) && ! Guard::role_exists( $cap ),
			'is_super_admin'   => Guard::is_super_admin_user( $user->ID ),
		);

		return $result;
	}
}
