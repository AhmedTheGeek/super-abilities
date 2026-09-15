<?php
/**
 * Access posture audit ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Access;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Access\Guard;
use SuperAbilities\Support\Schema;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Summarises who can do what on this site, and what looks wrong about it.
 *
 * No email address, password hash or application password identifier appears in the
 * output: only logins, role slugs, capability names and counts.
 *
 * @since 0.2.0
 */
class Access_Audit extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'access-audit';
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
		return __( 'Audit roles and capabilities', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Summarises the access posture of the site in one call: who the administrators are, which roles grant high impact or never-grantable capabilities, which users hold such a capability through a custom role, which roles nobody uses, which users have more than one role, how many administrators have application passwords, and a list of recommendations. Email addresses and application password identifiers are never included.', 'super-abilities' );
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
		return Schema::object( array() );
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		$user_row = Schema::object(
			array(
				'id'             => Schema::id(),
				'login'          => array( 'type' => 'string' ),
				'display_name'   => array( 'type' => 'string' ),
				'roles'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'is_super_admin' => array( 'type' => 'boolean' ),
			),
			array( 'id', 'login', 'display_name', 'roles', 'is_super_admin' )
		);

		$role_row = Schema::object(
			array(
				'role'         => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'users'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'capabilities' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'role', 'name', 'users', 'capabilities' )
		);

		return Schema::object(
			array(
				'administrators'            => Schema::object(
					array(
						'count' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'users' => array(
							'type'  => 'array',
							'items' => $user_row,
						),
					),
					array( 'count', 'users' )
				),
				'roles_total'               => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'default_role'              => array( 'type' => 'string' ),
				'multisite'                 => array( 'type' => 'boolean' ),
				'roles_with_dangerous_caps' => array(
					'type'  => 'array',
					'items' => $role_row,
				),
				'roles_with_forbidden_caps' => array(
					'type'  => 'array',
					'items' => $role_row,
				),
				'empty_roles'               => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Roles nobody on this site has.', 'super-abilities' ),
				),
				'users_with_forbidden_caps' => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'id'           => Schema::id(),
							'login'        => array( 'type' => 'string' ),
							'roles'        => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'capabilities' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
						array( 'id', 'login', 'roles', 'capabilities' )
					),
				),
				'users_with_multiple_roles' => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'id'    => Schema::id(),
							'login' => array( 'type' => 'string' ),
							'roles' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
						array( 'id', 'login', 'roles' )
					),
				),
				'application_passwords'     => Schema::object(
					array(
						'checked'               => array(
							'type'        => 'boolean',
							'description' => __( 'False when the caller cannot edit users, in which case the counts are null.', 'super-abilities' ),
						),
						'admins_with_passwords' => array( 'type' => array( 'integer', 'null' ) ),
						'total'                 => array( 'type' => array( 'integer', 'null' ) ),
					),
					array( 'checked', 'admins_with_passwords', 'total' )
				),
				'users_scanned'             => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'truncated'                 => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the user scan hit its limit and the lists are incomplete.', 'super-abilities' ),
				),
				'recommendations'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'administrators', 'roles_total', 'default_role', 'multisite', 'roles_with_dangerous_caps', 'roles_with_forbidden_caps', 'empty_roles', 'users_with_forbidden_caps', 'users_with_multiple_roles', 'application_passwords', 'users_scanned', 'truncated', 'recommendations' )
		);
	}

	/**
	 * Runs the audit.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$counts      = Guard::role_user_counts();
		$definitions = Guard::role_definitions();
		$forbidden   = Guard::forbidden_capabilities();

		$dangerous_roles = array();
		$forbidden_roles = array();
		$empty_roles     = array();

		foreach ( array_keys( $definitions ) as $slug ) {
			$slug    = (string) $slug;
			$granted = Guard::granted_capabilities( $slug );
			$users   = isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;
			$name    = isset( $definitions[ $slug ]['name'] ) ? (string) $definitions[ $slug ]['name'] : $slug;

			if ( 0 === $users ) {
				$empty_roles[] = $slug;
			}

			$dangerous = array_values( array_filter( $granted, array( Guard::class, 'is_dangerous_capability' ) ) );

			if ( ! empty( $dangerous ) ) {
				$dangerous_roles[] = array(
					'role'         => $slug,
					'name'         => translate_user_role( $name ),
					'users'        => $users,
					'capabilities' => $dangerous,
				);
			}

			$never = array_values( array_intersect( $granted, $forbidden ) );

			if ( ! empty( $never ) ) {
				$forbidden_roles[] = array(
					'role'         => $slug,
					'name'         => translate_user_role( $name ),
					'users'        => $users,
					'capabilities' => $never,
				);
			}
		}

		$users     = $this->users();
		$truncated = count( $users ) >= Guard::MAX_USERS;

		$administrators        = array();
		$with_forbidden        = array();
		$with_many_roles       = array();
		$admins_with_passwords = 0;
		$password_total        = 0;
		$can_check_passwords   = current_user_can( 'edit_users' ) && class_exists( 'WP_Application_Passwords' );

		foreach ( $users as $user ) {
			$roles = Guard::normalize_roles( (array) $user->roles );

			if ( in_array( 'administrator', $roles, true ) || Guard::is_super_admin_user( $user->ID ) ) {
				$administrators[] = array(
					'id'             => (int) $user->ID,
					'login'          => (string) $user->user_login,
					'display_name'   => (string) $user->display_name,
					'roles'          => $roles,
					'is_super_admin' => Guard::is_super_admin_user( $user->ID ),
				);

				if ( $can_check_passwords ) {
					$passwords = \WP_Application_Passwords::get_user_application_passwords( (int) $user->ID );
					$found     = is_array( $passwords ) ? count( $passwords ) : 0;

					if ( $found > 0 ) {
						++$admins_with_passwords;
						$password_total += $found;
					}
				}
			}

			if ( count( $roles ) > 1 ) {
				$with_many_roles[] = array(
					'id'    => (int) $user->ID,
					'login' => (string) $user->user_login,
					'roles' => $roles,
				);
			}

			// The administrator role legitimately holds these; only report them where a
			// custom role or a per-user grant handed them out.
			if ( in_array( 'administrator', $roles, true ) ) {
				continue;
			}

			$held = array();

			foreach ( $forbidden as $cap ) {
				if ( ! empty( $user->allcaps[ $cap ] ) ) {
					$held[] = $cap;
				}
			}

			if ( ! empty( $held ) ) {
				$with_forbidden[] = array(
					'id'           => (int) $user->ID,
					'login'        => (string) $user->user_login,
					'roles'        => $roles,
					'capabilities' => $held,
				);
			}
		}

		return array(
			'administrators'            => array(
				'count' => count( $administrators ),
				'users' => $administrators,
			),
			'roles_total'               => count( $definitions ),
			'default_role'              => (string) get_option( 'default_role' ),
			'multisite'                 => is_multisite(),
			'roles_with_dangerous_caps' => $dangerous_roles,
			'roles_with_forbidden_caps' => $forbidden_roles,
			'empty_roles'               => $empty_roles,
			'users_with_forbidden_caps' => $with_forbidden,
			'users_with_multiple_roles' => $with_many_roles,
			'application_passwords'     => array(
				'checked'               => $can_check_passwords,
				'admins_with_passwords' => $can_check_passwords ? $admins_with_passwords : null,
				'total'                 => $can_check_passwords ? $password_total : null,
			),
			'users_scanned'             => count( $users ),
			'truncated'                 => $truncated,
			'recommendations'           => $this->recommendations(
				$administrators,
				$forbidden_roles,
				$with_forbidden,
				$empty_roles,
				$truncated
			),
		);
	}

	/**
	 * The users this audit walks, oldest first.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, WP_User>
	 */
	protected function users() {
		$found = get_users(
			array(
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

	/**
	 * Turns the findings into advice.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $administrators  Administrator rows.
	 * @param array<int, array<string, mixed>> $forbidden_roles Roles granting never-grantable caps.
	 * @param array<int, array<string, mixed>> $with_forbidden  Users holding never-grantable caps.
	 * @param array<int, string>               $empty_roles     Roles nobody has.
	 * @param bool                             $truncated       Whether the user scan was cut short.
	 * @return array<int, string>
	 */
	protected function recommendations( array $administrators, array $forbidden_roles, array $with_forbidden, array $empty_roles, $truncated ) {
		$notes = array();

		if ( empty( $administrators ) ) {
			$notes[] = __( 'No administrator was found on this site. Nobody can manage it.', 'super-abilities' );
		} elseif ( 1 === count( $administrators ) ) {
			$notes[] = __( 'There is only one administrator. A second one avoids losing access to the site if that account is lost.', 'super-abilities' );
		} elseif ( count( $administrators ) > 3 ) {
			$notes[] = sprintf(
				/* translators: %d: Number of administrators. */
				__( 'There are %d administrators. Demote the accounts that do not need to install plugins or edit users.', 'super-abilities' ),
				count( $administrators )
			);
		}

		foreach ( $forbidden_roles as $role ) {
			if ( 'administrator' === $role['role'] ) {
				continue;
			}

			$notes[] = sprintf(
				/* translators: 1: Role slug. 2: Comma separated capability names. */
				__( 'The role "%1$s" grants capabilities that amount to running code on the server: %2$s. Revoke them unless that is deliberate.', 'super-abilities' ),
				$role['role'],
				implode( ', ', (array) $role['capabilities'] )
			);
		}

		if ( ! empty( $with_forbidden ) ) {
			$notes[] = sprintf(
				/* translators: %d: Number of users. */
				__( '%d non-administrator user(s) can run code on the server through their roles or their own capabilities.', 'super-abilities' ),
				count( $with_forbidden )
			);
		}

		if ( ! empty( $empty_roles ) ) {
			$notes[] = sprintf(
				/* translators: %s: Comma separated role slugs. */
				__( 'No user has these roles: %s. Deleting the ones you did not plan for keeps the capability map readable.', 'super-abilities' ),
				implode( ', ', $empty_roles )
			);
		}

		if ( $truncated ) {
			$notes[] = sprintf(
				/* translators: %d: Number of users scanned. */
				__( 'Only the first %d users were examined, so these lists are incomplete.', 'super-abilities' ),
				Guard::MAX_USERS
			);
		}

		return $notes;
	}
}
