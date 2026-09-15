<?php
/**
 * Role creation ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Access;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Access\Guard;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a role, optionally cloned from an existing one.
 *
 * Every capability the new role would grant, including the ones inherited from
 * `clone_from`, goes through the forbidden list and the caller-holds-it check, so
 * cloning the administrator role is only possible for someone who already has
 * everything the administrator role grants.
 *
 * @since 0.2.0
 */
class Role_Create extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'role-create';
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
		return __( 'Create a role', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Creates a role with the capabilities you list, optionally starting from the capabilities of an existing role. The slug is sanitized and must not already exist. Capabilities that allow arbitrary code execution, such as edit_plugins or unfiltered_html, are refused when you ask for them and quietly left out, and reported, when they come from clone_from, because most core roles carry one. Any capability you do not hold yourself is refused either way, which is what stops an editor from building an administrator-equivalent role. Pass dry_run to see exactly what would be created.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return Guard::definition_capabilities();
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
				'role'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Slug for the new role. Sanitized with sanitize_key.', 'super-abilities' ),
				),
				'display_name' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Human readable name of the role.', 'super-abilities' ),
				),
				'capabilities' => Schema::csv_or_array_of_strings( __( 'Capabilities the new role grants.', 'super-abilities' ) ),
				'clone_from'   => array(
					'type'        => 'string',
					'description' => __( 'Start from the capabilities this role grants, then add the ones listed above.', 'super-abilities' ),
				),
				'dry_run'      => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would be created and change nothing. Default false.', 'super-abilities' ),
				),
			),
			array( 'role', 'display_name' )
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
				'created'               => array( 'type' => 'boolean' ),
				'dry_run'               => array( 'type' => 'boolean' ),
				'role'                  => Guard::role_schema(),
				'excluded_capabilities' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Capabilities the cloned role grants that this plugin never grants, so the new role does not have them.', 'super-abilities' ),
				),
			),
			array( 'created', 'dry_run', 'role', 'excluded_capabilities' )
		);
	}

	/**
	 * Creates the role.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$slug = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';

		if ( '' === $slug ) {
			return $this->error( 'invalid_input', __( 'A role slug is required and must contain at least one letter or digit.', 'super-abilities' ), 400 );
		}

		$display_name = isset( $input['display_name'] ) ? sanitize_text_field( (string) $input['display_name'] ) : '';

		if ( '' === $display_name ) {
			return $this->error( 'invalid_input', __( 'A display name is required.', 'super-abilities' ), 400 );
		}

		if ( Guard::role_exists( $slug ) ) {
			return $this->error(
				'role_exists',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'The role "%s" already exists. Use capabilities-grant to change it.', 'super-abilities' ),
					$slug
				),
				409,
				array( 'role' => $slug )
			);
		}

		$caps = Guard::sanitize_capabilities( isset( $input['capabilities'] ) ? $input['capabilities'] : array() );

		// Capabilities the caller named are refused outright when they are forbidden.
		$refused = Guard::check_grantable( $caps );

		if ( null !== $refused ) {
			return $refused;
		}

		$excluded = array();

		if ( ! empty( $input['clone_from'] ) ) {
			$source = sanitize_key( (string) $input['clone_from'] );

			if ( ! Guard::role_exists( $source ) ) {
				return $this->error(
					'not_found',
					sprintf(
						/* translators: %s: Role slug. */
						__( 'There is no role named "%s" to clone.', 'super-abilities' ),
						$source
					),
					404,
					array( 'role' => $source )
				);
			}

			$inherited = array();

			foreach ( Guard::granted_capabilities( $source ) as $cap ) {
				if ( in_array( $cap, $caps, true ) ) {
					continue;
				}

				/*
				 * Every core role from editor up grants `unfiltered_html`, so refusing the
				 * whole call would make clone_from useless. A capability nobody can ever be
				 * granted through this module is left out and reported instead; one the
				 * caller simply does not hold is still an error, because that is the
				 * escalation the caller needs to hear about.
				 */
				if ( Guard::is_forbidden_capability( $cap ) ) {
					$excluded[] = $cap;

					continue;
				}

				$inherited[] = $cap;
			}

			$refused = Guard::check_grantable( $inherited );

			if ( null !== $refused ) {
				return $refused;
			}

			$caps     = Guard::sanitize_capabilities( array_merge( $caps, $inherited ) );
			$excluded = Guard::sanitize_capabilities( $excluded );
		}

		$this->note_object( 'role', $slug );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'created'               => false,
				'dry_run'               => true,
				'role'                  => $this->preview( $slug, $display_name, $caps ),
				'excluded_capabilities' => $excluded,
			);
		}

		add_role( $slug, $display_name, array_fill_keys( $caps, true ) );

		if ( null === Guard::role( $slug ) ) {
			return $this->error(
				'exception',
				__( 'WordPress refused to add the role.', 'super-abilities' ),
				500,
				array( 'role' => $slug )
			);
		}

		return array(
			'created'               => true,
			'dry_run'               => false,
			'role'                  => Guard::describe_role( $slug ),
			'excluded_capabilities' => $excluded,
		);
	}

	/**
	 * Describes the role a dry run would have created.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $slug         Role slug.
	 * @param string             $display_name Display name.
	 * @param array<int, string> $caps         Capabilities the role would grant.
	 * @return array<string, mixed>
	 */
	protected function preview( $slug, $display_name, array $caps ) {
		return array(
			'slug'                => $slug,
			'name'                => translate_user_role( $display_name ),
			'capability_count'    => count( $caps ),
			'capabilities'        => $caps,
			'denied_capabilities' => array(),
			'dangerous'           => array_values( array_filter( $caps, array( Guard::class, 'is_dangerous_capability' ) ) ),
			'users'               => 0,
			'protected'           => Guard::is_protected_role( $slug ),
			'is_default'          => false,
			'fingerprint'         => Fingerprint::of_array( array_fill_keys( $caps, true ) ),
		);
	}
}
