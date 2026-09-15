<?php
/**
 * Role deletion ability.
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
 * Removes a role and moves the users who had it to another role.
 *
 * @since 0.2.0
 */
class Role_Delete extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'role-delete';
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
		return __( 'Delete a role', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes a role. When users still have it, reassign_to is required and every one of them is moved to that role first, so nobody is left without one. Protected roles such as administrator are never deleted, not even with force, and the role new users get is only deleted with force. Pass the fingerprint from roles-list as expected_fingerprint to be told, rather than surprised, when the role changed since you read it, and dry_run to see how many users would move.', 'super-abilities' );
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
					'description' => __( 'Slug of the role to delete.', 'super-abilities' ),
				),
				'reassign_to'          => array(
					'type'        => 'string',
					'description' => __( 'Role the current holders are moved to. Required when the role has users.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint of the role capability map as you last read it.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would happen and change nothing. Default false.', 'super-abilities' ),
				),
				'force'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Delete the role even when it is the role new users get. Protected roles are refused regardless. Default false.', 'super-abilities' ),
				),
			),
			array( 'role' )
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
				'role'             => array( 'type' => 'string' ),
				'deleted'          => array( 'type' => 'boolean' ),
				'dry_run'          => array( 'type' => 'boolean' ),
				'reassign_to'      => array( 'type' => array( 'string', 'null' ) ),
				'users_reassigned' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'warnings'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'role', 'deleted', 'dry_run', 'reassign_to', 'users_reassigned', 'warnings' )
		);
	}

	/**
	 * Deletes the role.
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

		if ( Guard::is_protected_role( $slug ) ) {
			return $this->error(
				'protected_role',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'The role "%s" is protected and is never deleted, with or without force.', 'super-abilities' ),
					$slug
				),
				403,
				array(
					'role'      => $slug,
					'protected' => true,
				)
			);
		}

		$force    = ! empty( $input['force'] );
		$warnings = array();

		if ( sanitize_key( (string) get_option( 'default_role' ) ) === $slug && ! $force ) {
			return $this->error(
				'default_role',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'The role "%s" is the role new users receive. Point the default_role setting somewhere else, or pass force.', 'super-abilities' ),
					$slug
				),
				403,
				array( 'role' => $slug )
			);
		}

		$holders     = $this->holders( $slug );
		$reassign_to = isset( $input['reassign_to'] ) ? sanitize_key( (string) $input['reassign_to'] ) : '';

		if ( '' !== $reassign_to ) {
			if ( ! Guard::role_exists( $reassign_to ) ) {
				return $this->error(
					'not_found',
					sprintf(
						/* translators: %s: Role slug. */
						__( 'There is no role named "%s" to reassign the users to.', 'super-abilities' ),
						$reassign_to
					),
					404,
					array( 'role' => $reassign_to )
				);
			}

			if ( $reassign_to === $slug ) {
				return $this->error(
					'invalid_input',
					__( 'The users cannot be reassigned to the role that is being deleted.', 'super-abilities' ),
					400,
					array( 'role' => $slug )
				);
			}

			$refused = Guard::check_assignable( array( $reassign_to ) );

			if ( null !== $refused ) {
				return $refused;
			}
		} elseif ( ! empty( $holders ) ) {
			return $this->error(
				'invalid_input',
				sprintf(
					/* translators: 1: Role slug. 2: Number of users. */
					__( 'The role "%1$s" still has %2$d user(s). Pass reassign_to with the role they should get instead.', 'super-abilities' ),
					$slug,
					count( $holders )
				),
				400,
				array(
					'role'  => $slug,
					'users' => count( $holders ),
				)
			);
		}

		$this->note_object( 'role', $slug );

		if ( in_array( get_current_user_id(), wp_list_pluck( $holders, 'ID' ), true ) ) {
			$warnings[] = __( 'You have this role yourself, so your own roles changed too.', 'super-abilities' );
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'role'             => $slug,
				'deleted'          => false,
				'dry_run'          => true,
				'reassign_to'      => '' === $reassign_to ? null : $reassign_to,
				'users_reassigned' => count( $holders ),
				'warnings'         => $warnings,
			);
		}

		$reassigned = 0;

		foreach ( $holders as $user ) {
			$this->note_object( 'user', $user->ID );

			$other = array_diff( Guard::normalize_roles( (array) $user->roles ), array( $slug ) );

			if ( empty( $other ) ) {
				// The role was their only one, so replace it outright.
				$user->set_role( $reassign_to );
			} else {
				$user->remove_role( $slug );
				$user->add_role( $reassign_to );
			}

			++$reassigned;
		}

		remove_role( $slug );

		if ( null !== Guard::role( $slug ) ) {
			return $this->error(
				'exception',
				__( 'WordPress refused to remove the role.', 'super-abilities' ),
				500,
				array( 'role' => $slug )
			);
		}

		return array(
			'role'             => $slug,
			'deleted'          => true,
			'dry_run'          => false,
			'reassign_to'      => '' === $reassign_to ? null : $reassign_to,
			'users_reassigned' => $reassigned,
			'warnings'         => $warnings,
		);
	}

	/**
	 * The users who currently have a role.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return array<int, WP_User>
	 */
	protected function holders( $role ) {
		$found = get_users(
			array(
				'role'    => $role,
				'number'  => Guard::MAX_USERS,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$users = array();

		foreach ( $found as $user ) {
			if ( $user instanceof WP_User ) {
				$users[] = $user;
			}
		}

		return $users;
	}
}
