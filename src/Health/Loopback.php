<?php
/**
 * WP-Cron loopback probe.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Asks this site to call its own `wp-cron.php`, the way `spawn_cron()` does.
 *
 * Mirrors what WP-CLI's `wp cron test` command checks: whether a loopback request
 * to `wp-cron.php` reaches the site at all. Many hosts block loopback requests, in
 * which case WP-Cron never runs on its own.
 *
 * @since 0.1.0
 */
class Loopback {

	/**
	 * Request timeout in seconds.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TIMEOUT = 5;

	/**
	 * Performs the loopback request.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timeout Optional. Timeout in seconds. Default {@see Loopback::TIMEOUT}.
	 * @return array{status: string, http_code: int, message: string} Status is `ok`,
	 *                                                                `failed` or `unverified`.
	 */
	public static function test( $timeout = self::TIMEOUT ) {
		$timeout = (int) $timeout > 0 ? (int) $timeout : self::TIMEOUT;
		$url     = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => $timeout,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied the same way `spawn_cron()` does.
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'    => 'failed',
				'http_code' => 0,
				'message'   => sprintf(
					/* translators: %s: Error message from the HTTP API. */
					__( 'The loopback request to wp-cron.php failed: %s', 'super-abilities' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'status'    => 'ok',
				'http_code' => $code,
				'message'   => __( 'wp-cron.php answered a loopback request, so WP-Cron can spawn itself.', 'super-abilities' ),
			);
		}

		if ( $code < 1 ) {
			return array(
				'status'    => 'unverified',
				'http_code' => 0,
				'message'   => __( 'The loopback request returned no status code, so WP-Cron spawning could not be verified.', 'super-abilities' ),
			);
		}

		return array(
			'status'    => 'failed',
			'http_code' => $code,
			'message'   => sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The loopback request to wp-cron.php returned HTTP %d.', 'super-abilities' ),
				$code
			),
		);
	}
}
