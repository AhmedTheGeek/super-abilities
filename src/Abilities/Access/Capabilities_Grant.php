<?php
/**
 * Capability grant ability.
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
 * Adds capabilities to a role.
 *
 * Idempotent: capabilities the role already grants come back in `already_present`
 * and nothing is written for them.
 *
 * @since 0.2.0
 */
class Capabilities_Grant extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'capabilities-grant';
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
		return __( 'Grant capabilities to a role', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Adds capabilities to a role and reports which ones were added and which the role already had. Capabilities that allow arbitrary code execution or network takeover are always refused, and so is any capability the caller does not hold themselves, so nobody can grant more power than they have. Adding a capability to a protected role such as administrator requires force. Pass the fingerprint from roles-list as expected_fingerprint to be rejected instead of overwriting a concurrent change, and dry_run to preview.', 'super-abilities' );
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
				'role'                 => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Slug of the role to change.', 'super-abilities' ),
				),
				'capabilities'         => Schema::csv_or_array_of_strings( __( 'Capabilities to add.', 'super-abilities' ) ),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint of the role capability map as you last read it.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would change and change nothing. Default false.', 'super-abilities' ),
				),
				'force'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow the change on a protected role such as administrator. Default false.', 'super-abilities' ),
				),
			),
			array( 'role', 'capabilities' )
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
				'role'            => array( 'type' => 'string' ),
				'dry_run'         => array( 'type' => 'boolean' ),
				'added'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'already_present' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'dangerous'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Added capabilities that are high impact.', 'super-abilities' ),
				),
				'fingerprint'     => Schema::fingerprint( __( 'Fingerprint of the role capability map after the change.', 'super-abilities' ) ),
				'warnings'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'role', 'dry_run', 'added', 'already_present', 'dangerous', 'fingerprint', 'warnings' )
		);
	}

	/**
	 * Grants the capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$slug = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';

		if ( '' === $slug ) {
			return $this->error( 'invalid_input', __( 'A role slug is required.', 'super-abilities' ), 400 );
		}

		if ( ! Guard::role_exists( $slug ) ) {
			return $this->error(
				'not_found',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'There is no role named "%s" on this site.', 'super-abilities' ),
					$slug
				),
				404,
				array( 'role' => $slug )
			);
		}

		$stale = $this->guard_fingerprint( $input, Guard::role_fingerprint( $slug ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$caps = Guard::sanitize_capabilities( isset( $input['capabilities'] ) ? $input['capabilities'] : array() );

		if ( empty( $caps ) ) {
			return $this->error( 'invalid_input', __( 'At least one capability is required.', 'super-abilities' ), 400 );
		}

		if ( Guard::is_protected_role( $slug ) && empty( $input['force'] ) ) {
			return $this->error(
				'protected_role',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'The role "%s" is protected. Pass force to add a capability to it.', 'super-abilities' ),
					$slug
				),
				403,
				array(
					'role'      => $slug,
					'protected' => true,
				)
			);
		}

		$refused = Guard::check_grantable( $caps );

		if ( null !== $refused ) {
			return $refused;
		}

		$current         = Guard::role_capabilities( $slug );
		$added           = array();
		$already_present = array();
		$warnings        = array();

		foreach ( $caps as $cap ) {
			if ( isset( $current[ $cap ] ) && $current[ $cap ] ) {
				$already_present[] = $cap;

				continue;
			}

			if ( isset( $current[ $cap ] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: Capability name. */
					__( 'The role denied "%s" explicitly; granting it overrides that denial.', 'super-abilities' ),
					$cap
				);
			}

			$added[] = $cap;
		}

		$this->note_object( 'role', $slug );

		$dangerous = array_values( array_filter( $added, array( Guard::class, 'is_dangerous_capability' ) ) );

		if ( ! empty( $dangerous ) ) {
			$warnings[] = sprintf(
				/* translators: %s: Comma separated capability names. */
				__( 'These capabilities are high impact: %s.', 'super-abilities' ),
				implode( ', ', $dangerous )
			);
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'role'            => $slug,
				'dry_run'         => true,
				'added'           => $added,
				'already_present' => $already_present,
				'dangerous'       => $dangerous,
				'fingerprint'     => Guard::role_fingerprint( $slug ),
				'warnings'        => $warnings,
			);
		}

		$role = Guard::role( $slug );

		if ( null === $role ) {
			return $this->error( 'not_found', __( 'The role disappeared while it was being changed.', 'super-abilities' ), 404, array( 'role' => $slug ) );
		}

		foreach ( $added as $cap ) {
			$role->add_cap( $cap );
		}

		return array(
			'role'            => $slug,
			'dry_run'         => false,
			'added'           => $added,
			'already_present' => $already_present,
			'dangerous'       => $dangerous,
			'fingerprint'     => Guard::role_fingerprint( $slug ),
			'warnings'        => $warnings,
		);
	}
}
