<?php
/**
 * Capability helpers.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Capability checks shared by abilities and the settings screen.
 *
 * @since 0.1.0
 */
class Capabilities {

	/**
	 * Whether a user has every one of the given capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $caps    Capabilities to test.
	 * @param int|null           $user_id Optional. User to test. Default the current user.
	 * @return bool
	 */
	public static function user_can_all( array $caps, $user_id = null ) {
		return array() === self::lacking( $caps, $user_id );
	}

	/**
	 * The capabilities from the list that the user does not have.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $caps    Capabilities to test.
	 * @param int|null           $user_id Optional. User to test. Default the current user.
	 * @return array<int, string>
	 */
	public static function lacking( array $caps, $user_id = null ) {
		$missing = array();

		foreach ( $caps as $cap ) {
			$cap = (string) $cap;

			if ( '' === $cap ) {
				continue;
			}

			$allowed = null === $user_id ? current_user_can( $cap ) : user_can( (int) $user_id, $cap );

			if ( ! $allowed ) {
				$missing[] = $cap;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Number of users in the administrator role on this site.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function count_admins() {
		$counts = count_users();

		if ( isset( $counts['avail_roles']['administrator'] ) ) {
			return (int) $counts['avail_roles']['administrator'];
		}

		return 0;
	}

	/**
	 * Whether file modifications of the given kind are allowed on this site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $context Context passed to `wp_is_file_mod_allowed()`, for example
	 *                        `install_plugin` or `delete_plugin`.
	 * @return bool
	 */
	public static function file_mods_allowed( $context ) {
		return (bool) wp_is_file_mod_allowed( (string) $context );
	}
}
