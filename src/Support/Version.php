<?php
/**
 * WordPress version feature detection.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Answers which Abilities API features the running WordPress version offers.
 *
 * @since 0.1.0
 */
class Version {

	/**
	 * The running WordPress version.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function wp() {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * Whether WordPress is at least the given version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version Version to compare against, e.g. `7.1`.
	 * @return bool
	 */
	public static function wp_at_least( $version ) {
		return version_compare( self::wp(), (string) $version, '>=' );
	}

	/**
	 * Whether `wp_ability_invoked` and `wp_pre_execute_ability` are available.
	 *
	 * Those hooks landed in WordPress 7.1 and are the only way to observe denied
	 * or invalid ability attempts without a REST fallback.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function has_invoked_hook() {
		return self::wp_at_least( '7.1' );
	}

	/**
	 * Whether the `public` ability meta argument is understood by core.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function has_public_meta() {
		return self::wp_at_least( '7.1' );
	}

	/**
	 * The Abilities API level this site exposes, as a version string.
	 *
	 * @since 0.1.0
	 *
	 * @return string Either `7.1` or `6.9`.
	 */
	public static function abilities_api_level() {
		return self::has_invoked_hook() ? '7.1' : '6.9';
	}
}
