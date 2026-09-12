<?php
/**
 * Privileged user audit ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Security;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the users who can take over this site, and what is weak about their accounts.
 *
 * The output deliberately contains no email addresses, password hashes, application
 * password hashes or application password identifiers. Only the email domain, the
 * number of application passwords and the most recent use of one are reported.
 *
 * @since 0.1.0
 */
class Admin_Users_Audit extends Abstract_Ability {

	/**
	 * Capabilities that make a role privileged.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const PRIVILEGED_CAPS = array(
		'manage_options',
		'edit_users',
		'install_plugins',
		'edit_plugins',
		'edit_themes',
		'promote_users',
	);

	/**
	 * Logins an attacker tries first.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const WEAK_LOGINS = array( 'admin', 'administrator', 'root', 'test', 'user', 'demo', 'wordpress' );

	/**
	 * User meta keys other plugins use to record the last login.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const LAST_LOGIN_META = array(
		'last_login',
		'wp_last_login',
		'_last_login',
		'wfls-last-login',
		'simple_history_last_login',
	);

	/**
	 * Maximum number of users examined in one call.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_USERS = 500;

	/**
	 * Days after which a last login counts as stale.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const STALE_LOGIN_DAYS = 90;

	/**
	 * Number of application passwords above which an account is flagged.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MANY_APP_PASSWORDS = 3;

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'admin-users-audit';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'security';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Privileged users audit', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists every user whose role can take over this site, together with what is weak about the account: a guessable login such as "admin", a display name that gives the login away, no login for more than ninety days, an unusual number of application passwords, or no two factor authentication. Email addresses, password hashes and application password identifiers are never included, only the email domain and counts.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'list_users', 'manage_options' );
	}

	/**
	 * Input schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object( array() );
	}

	/**
	 * Output schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		$user = Schema::object(
			array(
				'id'             => Schema::id(),
				'login'          => array( 'type' => 'string' ),
				'display_name'   => array( 'type' => 'string' ),
				'roles'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'registered'     => array( 'type' => array( 'string', 'null' ) ),
				'last_login'     => array( 'type' => array( 'string', 'null' ) ),
				'email_domain'   => array( 'type' => 'string' ),
				'app_passwords'  => Schema::object(
					array(
						'count'     => array( 'type' => 'integer' ),
						'last_used' => array( 'type' => array( 'string', 'null' ) ),
					),
					array( 'count', 'last_used' )
				),
				'is_super_admin' => array( 'type' => 'boolean' ),
				'flags'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'id', 'login', 'display_name', 'roles', 'registered', 'last_login', 'email_domain', 'app_passwords', 'is_super_admin', 'flags' )
		);

		return Schema::object(
			array(
				'total_privileged' => array( 'type' => 'integer' ),
				'roles_checked'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'users'            => array(
					'type'  => 'array',
					'items' => $user,
				),
				'summary'          => Schema::object(
					array(
						'admins'      => array( 'type' => 'integer' ),
						'weak_logins' => array( 'type' => 'integer' ),
						'without_2fa' => array( 'type' => array( 'integer', 'null' ) ),
					),
					array( 'admins', 'weak_logins', 'without_2fa' )
				),
			),
			array( 'total_privileged', 'roles_checked', 'users', 'summary' )
		);
	}

	/**
	 * Runs the audit.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$roles      = $this->privileged_roles();
		$two_factor = $this->has_two_factor_plugin();

		// On a single site `get_super_admins()` falls back to a literal `admin` login,
		// which is not a real privilege, so it is only consulted on multisite.
		$super_admins = is_multisite() ? array_map( 'strval', (array) get_super_admins() ) : array();

		$users       = array();
		$admins      = 0;
		$weak        = 0;
		$without_2fa = 0;

		foreach ( $this->collect_users( $roles, $super_admins ) as $user ) {
			$report  = $this->describe_user( $user, $super_admins, $two_factor );
			$users[] = $report;

			if ( in_array( 'administrator', $report['roles'], true ) || $report['is_super_admin'] ) {
				++$admins;
			}

			if ( in_array( 'weak_login', $report['flags'], true ) ) {
				++$weak;
			}

			if ( in_array( 'no_2fa', $report['flags'], true ) ) {
				++$without_2fa;
			}
		}

		return array(
			'total_privileged' => count( $users ),
			'roles_checked'    => $roles,
			'users'            => $users,
			'summary'          => array(
				'admins'      => $admins,
				'weak_logins' => $weak,
				'without_2fa' => $two_factor ? $without_2fa : null,
			),
		);
	}

	/**
	 * Role slugs that grant at least one privileged capability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	protected function privileged_roles() {
		$all    = (array) wp_roles()->roles;
		$wanted = array();

		foreach ( $all as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();

			foreach ( self::PRIVILEGED_CAPS as $cap ) {
				if ( ! empty( $caps[ $cap ] ) ) {
					$wanted[] = (string) $slug;
					break;
				}
			}
		}

		sort( $wanted );

		return array_values( array_unique( $wanted ) );
	}

	/**
	 * The privileged users, plus any network super admin that no privileged role covers.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $roles        Role slugs to query.
	 * @param array<int, string> $super_admins Super admin logins.
	 * @return array<int, WP_User>
	 */
	protected function collect_users( array $roles, array $super_admins ) {
		$users = array();

		if ( ! empty( $roles ) ) {
			$found = get_users(
				array(
					'role__in' => $roles,
					'number'   => self::MAX_USERS,
					'orderby'  => 'ID',
					'order'    => 'ASC',
				)
			);

			foreach ( $found as $user ) {
				if ( $user instanceof WP_User ) {
					$users[ $user->ID ] = $user;
				}
			}
		}

		foreach ( $super_admins as $login ) {
			if ( count( $users ) >= self::MAX_USERS ) {
				break;
			}

			$user = get_user_by( 'login', $login );

			if ( $user instanceof WP_User && ! isset( $users[ $user->ID ] ) ) {
				$users[ $user->ID ] = $user;
			}
		}

		ksort( $users );

		return array_values( $users );
	}

	/**
	 * Describes one user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User            $user         User to describe.
	 * @param array<int, string> $super_admins Super admin logins.
	 * @param bool               $two_factor   Whether a supported two factor plugin is active.
	 * @return array<string, mixed>
	 */
	protected function describe_user( WP_User $user, array $super_admins, $two_factor ) {
		$login        = (string) $user->user_login;
		$display_name = (string) $user->display_name;
		$last_login   = $this->last_login( $user->ID );
		$passwords    = $this->application_passwords( $user->ID );
		$flags        = array();

		if ( $this->is_weak_login( $login ) ) {
			$flags[] = 'weak_login';
		}

		if ( '' !== $display_name && strtolower( $display_name ) === strtolower( $login ) ) {
			$flags[] = 'display_name_equals_login';
		}

		if ( null !== $last_login && $last_login < time() - ( self::STALE_LOGIN_DAYS * DAY_IN_SECONDS ) ) {
			$flags[] = 'no_recent_login';
		}

		if ( $passwords['count'] > self::MANY_APP_PASSWORDS ) {
			$flags[] = 'many_app_passwords';
		}

		if ( $two_factor && ! $this->uses_two_factor( $user->ID ) ) {
			$flags[] = 'no_2fa';
		}

		return array(
			'id'             => (int) $user->ID,
			'login'          => $login,
			'display_name'   => $display_name,
			'roles'          => array_values( array_map( 'strval', (array) $user->roles ) ),
			'registered'     => $this->registered( $user ),
			'last_login'     => null === $last_login ? null : Time::iso( $last_login ),
			'email_domain'   => $this->email_domain( (string) $user->user_email ),
			'app_passwords'  => $passwords,
			'is_super_admin' => in_array( $login, $super_admins, true ),
			'flags'          => $flags,
		);
	}

	/**
	 * Whether a login is one an attacker guesses first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $login User login.
	 * @return bool
	 */
	protected function is_weak_login( $login ) {
		$login = strtolower( trim( (string) $login ) );

		if ( '' === $login ) {
			return false;
		}

		if ( in_array( $login, self::WEAK_LOGINS, true ) ) {
			return true;
		}

		$site = sanitize_title( (string) get_bloginfo( 'name' ) );

		return '' !== $site && strtolower( $site ) === $login;
	}

	/**
	 * Registration date as an ISO 8601 UTC string.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User.
	 * @return string|null
	 */
	protected function registered( WP_User $user ) {
		$registered = isset( $user->user_registered ) ? (string) $user->user_registered : '';

		if ( '' === $registered || '0000-00-00 00:00:00' === $registered ) {
			return null;
		}

		$iso = Time::iso_from_mysql( $registered );

		return '' === $iso ? null : $iso;
	}

	/**
	 * Last login timestamp, from whichever plugin recorded one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User id.
	 * @return int|null Unix timestamp, or null when no plugin tracks logins.
	 */
	protected function last_login( $user_id ) {
		foreach ( self::LAST_LOGIN_META as $key ) {
			$value = get_user_meta( (int) $user_id, $key, true );

			if ( is_array( $value ) || null === $value || '' === $value ) {
				continue;
			}

			$timestamp = Time::parse( is_numeric( $value ) ? (int) $value : (string) $value );

			if ( null !== $timestamp && $timestamp > 0 ) {
				return $timestamp;
			}
		}

		return null;
	}

	/**
	 * Application password count and most recent use.
	 *
	 * Neither the hashes nor the UUIDs of the passwords ever leave this method.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User id.
	 * @return array{count: int, last_used: string|null}
	 */
	protected function application_passwords( $user_id ) {
		$summary = array(
			'count'     => 0,
			'last_used' => null,
		);

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return $summary;
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( (int) $user_id );

		if ( ! is_array( $passwords ) ) {
			return $summary;
		}

		$summary['count'] = count( $passwords );
		$last_used        = 0;

		foreach ( $passwords as $password ) {
			if ( ! is_array( $password ) || empty( $password['last_used'] ) ) {
				continue;
			}

			$last_used = max( $last_used, (int) $password['last_used'] );
		}

		if ( $last_used > 0 ) {
			$summary['last_used'] = Time::iso( $last_used );
		}

		return $summary;
	}

	/**
	 * The domain part of an email address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return string Domain, or an empty string when there is none.
	 */
	protected function email_domain( $email ) {
		$at = strrpos( $email, '@' );

		if ( false === $at ) {
			return '';
		}

		return strtolower( substr( $email, $at + 1 ) );
	}

	/**
	 * Whether the Two Factor plugin is active.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	protected function has_two_factor_plugin() {
		return class_exists( 'Two_Factor_Core' ) && is_callable( array( 'Two_Factor_Core', 'is_user_using_two_factor' ) );
	}

	/**
	 * Whether a user has two factor authentication enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	protected function uses_two_factor( $user_id ) {
		if ( ! $this->has_two_factor_plugin() ) {
			return false;
		}

		// Called dynamically because the Two Factor plugin may not be installed.
		return (bool) call_user_func( array( 'Two_Factor_Core', 'is_user_using_two_factor' ), (int) $user_id );
	}
}
