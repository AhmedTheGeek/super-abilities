<?php
/**
 * Capability revocation ability.
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
 * Removes capabilities from a role.
 *
 * Protected roles are refused outright. Unlike granting, there is no `force`: a
 * WordPress site with a weakened administrator role is unrecoverable through the
 * admin screens, so this ability never offers that.
 *
 * @since 0.2.0
 */
class Capabilities_Revoke extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'capabilities-revoke';
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
		return __( 'Revoke capabilities from a role', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Removes capabilities from a role and reports which ones were removed and which the role did not have. Protected roles such as administrator are always refused, with no force option, because a site whose administrator role has lost manage_options cannot be repaired from the admin screens. Removing read is allowed but warned about, since a role without it cannot reach the dashboard. Pass the fingerprint from roles-list as expected_fingerprint, and dry_run to preview.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::destructive();
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
				'capabilities'         => Schema::csv_or_array_of_strings( __( 'Capabilities to remove.', 'super-abilities' ) ),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint of the role capability map as you last read it.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would change and change nothing. Default false.', 'super-abilities' ),
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
				'role'        => array( 'type' => 'string' ),
				'dry_run'     => array( 'type' => 'boolean' ),
				'removed'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'not_present' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the role capability map after the change.', 'super-abilities' ) ),
				'warnings'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'role', 'dry_run', 'removed', 'not_present', 'fingerprint', 'warnings' )
		);
	}

	/**
	 * Revokes the capabilities.
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

		if ( Guard::is_protected_role( $slug ) ) {
			return $this->error(
				'protected_role',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'The role "%s" is protected and never loses a capability.', 'super-abilities' ),
					$slug
				),
				403,
				array(
					'role'      => $slug,
					'protected' => true,
				)
			);
		}

		$current     = Guard::role_capabilities( $slug );
		$removed     = array();
		$not_present = array();
		$warnings    = array();

		foreach ( $caps as $cap ) {
			if ( ! empty( $current[ $cap ] ) ) {
				$removed[] = $cap;

				continue;
			}

			$not_present[] = $cap;
		}

		if ( in_array( 'read', $removed, true ) ) {
			$warnings[] = __( 'Without read, users in this role cannot reach the dashboard at all.', 'super-abilities' );
		}

		if ( in_array( $slug, Guard::normalize_roles( (array) wp_get_current_user()->roles ), true ) ) {
			$warnings[] = __( 'You have this role yourself, so you just removed capabilities from your own account.', 'super-abilities' );
		}

		$this->note_object( 'role', $slug );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'role'        => $slug,
				'dry_run'     => true,
				'removed'     => $removed,
				'not_present' => $not_present,
				'fingerprint' => Guard::role_fingerprint( $slug ),
				'warnings'    => $warnings,
			);
		}

		$role = Guard::role( $slug );

		if ( null === $role ) {
			return $this->error( 'not_found', __( 'The role disappeared while it was being changed.', 'super-abilities' ), 404, array( 'role' => $slug ) );
		}

		foreach ( $removed as $cap ) {
			$role->remove_cap( $cap );
		}

		return array(
			'role'        => $slug,
			'dry_run'     => false,
			'removed'     => $removed,
			'not_present' => $not_present,
			'fingerprint' => Guard::role_fingerprint( $slug ),
			'warnings'    => $warnings,
		);
	}
}
