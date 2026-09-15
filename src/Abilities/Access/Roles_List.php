<?php
/**
 * Role listing ability.
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
 * Lists every role with the capabilities it grants, denies and the users in it.
 *
 * @since 0.2.0
 */
class Roles_List extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'roles-list';
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
		return __( 'List roles', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists every role on the site with its translated name, the capabilities it grants, the capabilities it denies explicitly, how many users are in it, whether this plugin protects it from being weakened, and a fingerprint of its capability map to pass back as expected_fingerprint on a write. Pass a role slug to describe just that one role.', 'super-abilities' );
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
				'role' => array(
					'type'        => 'string',
					'description' => __( 'Describe only this role slug. Omit to list every role.', 'super-abilities' ),
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
				'roles'                  => array(
					'type'  => 'array',
					'items' => Guard::role_schema(),
				),
				'total'                  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'default_role'           => array(
					'type'        => 'string',
					'description' => __( 'Role new users receive.', 'super-abilities' ),
				),
				'protected_roles'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'forbidden_capabilities' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Capabilities this module never grants.', 'super-abilities' ),
				),
				'multisite'              => array( 'type' => 'boolean' ),
			),
			array( 'roles', 'total', 'default_role', 'protected_roles', 'forbidden_capabilities', 'multisite' )
		);
	}

	/**
	 * Builds the listing.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$wanted = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';
		$counts = Guard::role_user_counts();
		$slugs  = array_keys( Guard::role_definitions() );

		if ( '' !== $wanted ) {
			if ( ! Guard::role_exists( $wanted ) ) {
				return $this->error(
					'not_found',
					sprintf(
						/* translators: %s: Role slug. */
						__( 'There is no role named "%s" on this site.', 'super-abilities' ),
						$wanted
					),
					404,
					array( 'role' => $wanted )
				);
			}

			$slugs = array( $wanted );
		}

		$roles = array();

		foreach ( $slugs as $slug ) {
			$roles[] = Guard::describe_role( (string) $slug, $counts );
		}

		return array(
			'roles'                  => $roles,
			'total'                  => count( $roles ),
			'default_role'           => (string) get_option( 'default_role' ),
			'protected_roles'        => Guard::protected_roles(),
			'forbidden_capabilities' => Guard::forbidden_capabilities(),
			'multisite'              => is_multisite(),
		);
	}
}
