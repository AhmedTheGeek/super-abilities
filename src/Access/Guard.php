<?php
/**
 * Safety rules shared by every role and capability ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Access;

use SuperAbilities\Support\Error;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Schema;
use WP_Error;
use WP_Role;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The guard rails of the access module.
 *
 * Every rule that keeps a role edit from locking an administrator out of the site,
 * or from handing an agent more power than the caller has, lives here so that the
 * nine abilities cannot disagree with each other.
 *
 * Three rules matter most:
 *
 * - Protected roles (`administrator` by default) are never deleted and never lose a
 *   capability. They only gain one when the caller passes `force`.
 * - A short list of capabilities is never granted through this module at all, because
 *   holding one of them is equivalent to arbitrary code execution on the server.
 * - A caller may only hand out capabilities they hold themselves, so an editor with
 *   `manage_options` cannot promote themselves to an administrator.
 *
 * @since 0.2.0
 */
class Guard {

	/**
	 * Roles that may not be deleted or stripped of capabilities.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const DEFAULT_PROTECTED_ROLES = array( 'administrator' );

	/**
	 * Capabilities this module never grants, with or without `force`.
	 *
	 * Each one of these is, directly or indirectly, the ability to run arbitrary code
	 * on the server or to take over the whole network.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const FORBIDDEN_CAPABILITIES = array(
		'unfiltered_html',
		'unfiltered_upload',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'setup_network',
		'upgrade_network',
		'delete_site',
	);

	/**
	 * Capabilities worth warning an agent about before it grants them.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const DANGEROUS_CAPABILITIES = array(
		'install_plugins',
		'edit_plugins',
		'edit_themes',
		'edit_users',
		'delete_users',
		'promote_users',
		'manage_options',
		'unfiltered_html',
		'edit_files',
		'update_core',
		'export',
		'import',
	);

	/**
	 * Core meta capabilities that are meaningless without an object id.
	 *
	 * `map_meta_cap()` and `user_can()` read `$args[0]` for these, so asking about them
	 * without an object emits a PHP warning and answers `do_not_allow`. The abilities
	 * report them as requiring an object instead of guessing.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const META_CAPS_REQUIRING_OBJECT = array(
		'edit_post',
		'read_post',
		'delete_post',
		'publish_post',
		'edit_page',
		'read_page',
		'delete_page',
		'add_post_meta',
		'edit_post_meta',
		'delete_post_meta',
		'edit_comment',
		'read_comment',
		'moderate_comment',
		'edit_user',
		'delete_user',
		'promote_user',
		'remove_user',
		'add_user_to_blog',
		'edit_user_meta',
		'add_user_meta',
		'delete_user_meta',
		'edit_term',
		'delete_term',
		'assign_term',
		'edit_term_meta',
		'add_term_meta',
		'delete_term_meta',
		'edit_comment_meta',
		'add_comment_meta',
		'delete_comment_meta',
		'customize',
	);

	/**
	 * Maximum number of users any one audit walks.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_USERS = 500;

	/**
	 * Role slugs that may not be deleted or stripped of capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function protected_roles() {
		/**
		 * Filters the roles the access module refuses to weaken or delete.
		 *
		 * A protected role is never deleted and never loses a capability, not even with
		 * `force`. It only gains a capability when the caller passes `force`.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, string> $roles Protected role slugs.
		 */
		$roles = (array) apply_filters( 'super_abilities_protected_roles', self::DEFAULT_PROTECTED_ROLES );

		$clean = array();

		foreach ( $roles as $role ) {
			if ( is_array( $role ) || is_object( $role ) ) {
				continue;
			}

			$role = sanitize_key( (string) $role );

			if ( '' !== $role ) {
				$clean[ $role ] = $role;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Whether a role is protected.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_protected_role( $role ) {
		return in_array( sanitize_key( (string) $role ), self::protected_roles(), true );
	}

	/**
	 * Capabilities this module never grants.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function forbidden_capabilities() {
		/**
		 * Filters the capabilities the access module never grants.
		 *
		 * Removing one from this list lets an agent hand out the ability to run arbitrary
		 * PHP on the server. Add to it freely; remove from it only deliberately.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, string> $capabilities Forbidden capability names.
		 */
		$caps = (array) apply_filters( 'super_abilities_forbidden_capabilities', self::FORBIDDEN_CAPABILITIES );

		$clean = array();

		foreach ( $caps as $cap ) {
			if ( is_array( $cap ) || is_object( $cap ) ) {
				continue;
			}

			$cap = sanitize_key( (string) $cap );

			if ( '' !== $cap ) {
				$clean[ $cap ] = $cap;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Whether a capability may never be granted through this module.
	 *
	 * @since 0.2.0
	 *
	 * @param string $cap Capability name.
	 * @return bool
	 */
	public static function is_forbidden_capability( $cap ) {
		return in_array( sanitize_key( (string) $cap ), self::forbidden_capabilities(), true );
	}

	/**
	 * Whether a capability deserves a warning in the output.
	 *
	 * @since 0.2.0
	 *
	 * @param string $cap Capability name.
	 * @return bool
	 */
	public static function is_dangerous_capability( $cap ) {
		$cap = sanitize_key( (string) $cap );

		return in_array( $cap, self::DANGEROUS_CAPABILITIES, true ) || self::is_forbidden_capability( $cap );
	}

	/**
	 * Whether a capability needs an object id to be answered at all.
	 *
	 * @since 0.2.0
	 *
	 * @param string $cap Capability name.
	 * @return bool
	 */
	public static function requires_object( $cap ) {
		return in_array( (string) $cap, self::META_CAPS_REQUIRING_OBJECT, true );
	}

	/**
	 * Capabilities required to define or delete a role.
	 *
	 * Core has no capability for defining roles, so this module uses the one plugins
	 * have always used, `manage_options`, and adds the network capability core requires
	 * for user administration on multisite.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function definition_capabilities() {
		$caps = array( 'manage_options' );

		if ( is_multisite() ) {
			$caps[] = 'manage_network_users';
		}

		return $caps;
	}

	/**
	 * Every role definition on this site, keyed by slug.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function role_definitions() {
		$roles = wp_roles()->roles;

		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * Whether a role exists.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function role_exists( $role ) {
		$role = sanitize_key( (string) $role );

		return '' !== $role && wp_roles()->is_role( $role );
	}

	/**
	 * The raw capability map of one role.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return array<string, bool> Capability name to granted flag, including denials.
	 */
	public static function role_capabilities( $role ) {
		$definitions = self::role_definitions();
		$role        = sanitize_key( (string) $role );

		if ( ! isset( $definitions[ $role ]['capabilities'] ) || ! is_array( $definitions[ $role ]['capabilities'] ) ) {
			return array();
		}

		$caps = array();

		foreach ( $definitions[ $role ]['capabilities'] as $cap => $granted ) {
			$caps[ (string) $cap ] = (bool) $granted;
		}

		ksort( $caps );

		return $caps;
	}

	/**
	 * Capabilities a role grants.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return array<int, string> Sorted capability names.
	 */
	public static function granted_capabilities( $role ) {
		return array_map( 'strval', array_keys( array_filter( self::role_capabilities( $role ) ) ) );
	}

	/**
	 * Capabilities a role denies explicitly.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return array<int, string> Sorted capability names.
	 */
	public static function denied_capabilities( $role ) {
		$denied = array();

		foreach ( self::role_capabilities( $role ) as $cap => $granted ) {
			if ( ! $granted ) {
				$denied[] = $cap;
			}
		}

		return $denied;
	}

	/**
	 * Fingerprint of a role's capability map.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function role_fingerprint( $role ) {
		return Fingerprint::of_array( self::role_capabilities( $role ) );
	}

	/**
	 * Fingerprint of a list of role slugs, independently of order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $roles Role slugs.
	 * @return string
	 */
	public static function roles_fingerprint( array $roles ) {
		return Fingerprint::of_array( self::normalize_roles( $roles ) );
	}

	/**
	 * Sorts and deduplicates a list of role slugs.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, mixed> $roles Role slugs.
	 * @return array<int, string>
	 */
	public static function normalize_roles( array $roles ) {
		$clean = array();

		foreach ( $roles as $role ) {
			if ( is_array( $role ) || is_object( $role ) ) {
				continue;
			}

			$role = sanitize_key( (string) $role );

			if ( '' !== $role ) {
				$clean[ $role ] = $role;
			}
		}

		$clean = array_values( $clean );
		sort( $clean );

		return $clean;
	}

	/**
	 * Splits a capability input value into a clean, sorted list.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Raw value, a comma separated string or an array.
	 * @return array<int, string>
	 */
	public static function sanitize_capabilities( $value ) {
		$clean = array();

		foreach ( Schema::to_string_list( $value ) as $cap ) {
			$cap = sanitize_key( $cap );

			if ( '' !== $cap ) {
				$clean[ $cap ] = $cap;
			}
		}

		$clean = array_values( $clean );
		sort( $clean );

		return $clean;
	}

	/**
	 * Number of users in a role, per `count_users()`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int> Role slug to user count.
	 */
	public static function role_user_counts() {
		$counts = count_users();
		$roles  = isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
		$clean  = array();

		foreach ( $roles as $role => $count ) {
			$clean[ (string) $role ] = (int) $count;
		}

		return $clean;
	}

	/**
	 * Number of users in the administrator role.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public static function administrator_count() {
		$counts = self::role_user_counts();

		return isset( $counts['administrator'] ) ? (int) $counts['administrator'] : 0;
	}

	/**
	 * Whether the current user's own account carries a capability.
	 *
	 * The user's capability map is consulted first, and `current_user_can()` only as a
	 * fallback, for two reasons. Meta capabilities that need an object id would make
	 * `current_user_can()` emit a warning and answer no. More importantly, core maps
	 * several capabilities that roles really do grant to `do_not_allow` for everybody
	 * depending on site configuration: `manage_links` without the link manager,
	 * `unfiltered_upload` without `ALLOW_UNFILTERED_UPLOADS`. Asking
	 * `current_user_can()` alone would therefore make even an administrator unable to
	 * assign the editor role, because nobody on the site "has" `manage_links`.
	 *
	 * What matters for escalation is whether the capability is part of the caller's own
	 * account, which is exactly what the capability map says.
	 *
	 * @since 0.2.0
	 *
	 * @param string $cap Capability name.
	 * @return bool
	 */
	public static function caller_has( $cap ) {
		$cap = (string) $cap;

		if ( '' === $cap ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( $user instanceof WP_User && ! empty( $user->allcaps[ $cap ] ) ) {
			return true;
		}

		if ( self::requires_object( $cap ) ) {
			return false;
		}

		return current_user_can( $cap );
	}

	/**
	 * The capabilities from a list that the current user does not hold.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $caps Capability names.
	 * @return array<int, string>
	 */
	public static function not_held_by_caller( array $caps ) {
		$missing = array();

		foreach ( $caps as $cap ) {
			if ( ! self::caller_has( (string) $cap ) ) {
				$missing[] = (string) $cap;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * The forbidden capabilities inside a list.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $caps Capability names.
	 * @return array<int, string>
	 */
	public static function forbidden_in( array $caps ) {
		$found = array();

		foreach ( $caps as $cap ) {
			if ( self::is_forbidden_capability( (string) $cap ) ) {
				$found[] = (string) $cap;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Rejects a list of capabilities the caller may not hand out.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $caps Capability names.
	 * @return WP_Error|null Null when every capability may be granted.
	 */
	public static function check_grantable( array $caps ) {
		$forbidden = self::forbidden_in( $caps );

		if ( ! empty( $forbidden ) ) {
			return Error::make(
				'forbidden_capability',
				sprintf(
					/* translators: %s: Comma separated capability names. */
					__( 'These capabilities are never granted through this plugin because they allow arbitrary code execution or network takeover: %s.', 'super-abilities' ),
					implode( ', ', $forbidden )
				),
				array(
					'status'       => 403,
					'capabilities' => $forbidden,
				)
			);
		}

		$missing = self::not_held_by_caller( $caps );

		if ( ! empty( $missing ) ) {
			return Error::make(
				'capability_escalation',
				sprintf(
					/* translators: %s: Comma separated capability names. */
					__( 'You cannot grant capabilities you do not have yourself: %s.', 'super-abilities' ),
					implode( ', ', $missing )
				),
				array(
					'status'       => 403,
					'capabilities' => $missing,
				)
			);
		}

		return null;
	}

	/**
	 * Rejects assigning a role whose capabilities the caller does not hold.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $roles Role slugs being handed to a user.
	 * @return WP_Error|null Null when every role may be assigned.
	 */
	public static function check_assignable( array $roles ) {
		foreach ( self::normalize_roles( $roles ) as $role ) {
			$missing = self::not_held_by_caller( self::granted_capabilities( $role ) );

			if ( empty( $missing ) ) {
				continue;
			}

			return Error::make(
				'capability_escalation',
				sprintf(
					/* translators: 1: Role slug. 2: Comma separated capability names. */
					__( 'You cannot assign the role "%1$s" because it grants capabilities you do not have yourself: %2$s.', 'super-abilities' ),
					$role,
					implode( ', ', $missing )
				),
				array(
					'status'       => 403,
					'role'         => $role,
					'capabilities' => $missing,
				)
			);
		}

		return null;
	}

	/**
	 * Whether a user is a network super admin.
	 *
	 * On a single site `is_super_admin()` answers yes for anyone who can delete users,
	 * which is every administrator, so it is only a meaningful distinction on multisite.
	 *
	 * @since 0.2.0
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_super_admin_user( $user_id ) {
		return (bool) is_super_admin( (int) $user_id );
	}

	/**
	 * Refuses a role change that would leave the site without an administrator, or
	 * demote the caller out of the administrator role.
	 *
	 * @since 0.2.0
	 *
	 * @param int                $user_id User whose roles are changing.
	 * @param array<int, string> $before  Role slugs before the change.
	 * @param array<int, string> $after   Role slugs after the change.
	 * @param bool               $force   Whether the caller accepted demoting themselves.
	 * @return WP_Error|null Null when the change is safe.
	 */
	public static function check_administrator_loss( $user_id, array $before, array $after, $force = false ) {
		$user_id = (int) $user_id;
		$before  = self::normalize_roles( $before );
		$after   = self::normalize_roles( $after );

		if ( ! in_array( 'administrator', $before, true ) || in_array( 'administrator', $after, true ) ) {
			return null;
		}

		if ( get_current_user_id() === $user_id && ! $force ) {
			return Error::make(
				'self_demotion',
				__( 'You cannot remove your own administrator role. Pass force to do it anyway, and expect to lose access to this site immediately.', 'super-abilities' ),
				array(
					'status'  => 403,
					'user_id' => $user_id,
				)
			);
		}

		if ( self::administrator_count() <= 1 ) {
			return Error::make(
				'last_administrator',
				__( 'This user is the only administrator on the site. Promote someone else first.', 'super-abilities' ),
				array(
					'status'  => 409,
					'user_id' => $user_id,
				)
			);
		}

		return null;
	}

	/**
	 * Describes one role the way `roles-list` reports it.
	 *
	 * @since 0.2.0
	 *
	 * @param string                  $role   Role slug.
	 * @param array<string, int>|null $counts Optional. Pre-computed user counts. Default null.
	 * @return array<string, mixed>
	 */
	public static function describe_role( $role, ?array $counts = null ) {
		$role        = sanitize_key( (string) $role );
		$definitions = self::role_definitions();
		$counts      = null === $counts ? self::role_user_counts() : $counts;
		$granted     = self::granted_capabilities( $role );
		$name        = isset( $definitions[ $role ]['name'] ) ? (string) $definitions[ $role ]['name'] : $role;

		return array(
			'slug'                => $role,
			'name'                => translate_user_role( $name ),
			'capability_count'    => count( $granted ),
			'capabilities'        => $granted,
			'denied_capabilities' => self::denied_capabilities( $role ),
			'dangerous'           => array_values( array_filter( $granted, array( __CLASS__, 'is_dangerous_capability' ) ) ),
			'users'               => isset( $counts[ $role ] ) ? (int) $counts[ $role ] : 0,
			'protected'           => self::is_protected_role( $role ),
			'is_default'          => sanitize_key( (string) get_option( 'default_role' ) ) === $role,
			'fingerprint'         => self::role_fingerprint( $role ),
		);
	}

	/**
	 * Output schema for one role, as `describe_role()` builds it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function role_schema() {
		return Schema::object(
			array(
				'slug'                => array(
					'type'        => 'string',
					'description' => __( 'Role slug.', 'super-abilities' ),
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'Translated display name of the role.', 'super-abilities' ),
				),
				'capability_count'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'capabilities'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Capabilities the role grants.', 'super-abilities' ),
				),
				'denied_capabilities' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Capabilities the role denies explicitly.', 'super-abilities' ),
				),
				'dangerous'           => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Granted capabilities that are high impact or never grantable.', 'super-abilities' ),
				),
				'users'               => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'protected'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this plugin refuses to weaken or delete the role.', 'super-abilities' ),
				),
				'is_default'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether new users get this role.', 'super-abilities' ),
				),
				'fingerprint'         => Schema::fingerprint( __( 'Fingerprint of the role capability map, for expected_fingerprint on writes.', 'super-abilities' ) ),
			),
			array( 'slug', 'name', 'capability_count', 'capabilities', 'denied_capabilities', 'dangerous', 'users', 'protected', 'is_default', 'fingerprint' )
		);
	}

	/**
	 * The role object for a slug, or null.
	 *
	 * @since 0.2.0
	 *
	 * @param string $role Role slug.
	 * @return WP_Role|null
	 */
	public static function role( $role ) {
		$found = wp_roles()->get_role( sanitize_key( (string) $role ) );

		return $found instanceof WP_Role ? $found : null;
	}

	/**
	 * Resolves a user from an id, a login or an email address.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return WP_User|null
	 */
	public static function resolve_user( array $input ) {
		if ( ! empty( $input['user_id'] ) ) {
			$user = get_user_by( 'id', (int) $input['user_id'] );

			return $user instanceof WP_User ? $user : null;
		}

		if ( ! empty( $input['login'] ) && is_string( $input['login'] ) ) {
			$user = get_user_by( 'login', sanitize_user( $input['login'] ) );

			return $user instanceof WP_User ? $user : null;
		}

		if ( ! empty( $input['email'] ) && is_string( $input['email'] ) ) {
			$user = get_user_by( 'email', sanitize_email( $input['email'] ) );

			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}
}
