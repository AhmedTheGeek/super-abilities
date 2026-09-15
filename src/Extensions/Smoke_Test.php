<?php
/**
 * Post change smoke test.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the site whether it still answers after a plugin or theme changed.
 *
 * Two loopback GET requests are made, one to the home page and one to the admin AJAX
 * heartbeat, with the current request's cookies deliberately left out so the probes see
 * the site the way an anonymous visitor does. A 5xx answer is a failure; a transport
 * error is only `unverified`, because plenty of hosts block loopback requests and that
 * must not roll back a perfectly good update.
 *
 * `Health\Loopback` is not reused here: it POSTs to `wp-cron.php` to answer a different
 * question, and it reports a blocked loopback as a hard failure.
 *
 * @since 0.2.0
 */
class Smoke_Test {

	/**
	 * Request timeout in seconds.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const TIMEOUT = 10;

	/**
	 * Runs the probes.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Options.
	 *
	 *     @type int $timeout Timeout per probe in seconds. Default {@see Smoke_Test::TIMEOUT}.
	 * }
	 * @return array{passed: bool, ran: bool, plugin_file_present: bool, probes: array<int, array<string, mixed>>}
	 */
	public static function run( array $args = array() ) {
		$timeout = isset( $args['timeout'] ) && (int) $args['timeout'] > 0 ? (int) $args['timeout'] : self::TIMEOUT;

		$probes = array(
			self::probe( 'home', home_url( '/' ), $timeout ),
			self::probe( 'heartbeat', admin_url( 'admin-ajax.php?action=heartbeat' ), $timeout ),
		);

		$present = self::plugin_file_present();
		$passed  = $present;

		foreach ( $probes as $probe ) {
			if ( 'failed' === $probe['status'] ) {
				$passed = false;
			}
		}

		return array(
			'passed'              => $passed,
			'ran'                 => true,
			'plugin_file_present' => $present,
			'probes'              => $probes,
		);
	}

	/**
	 * The result shape used when the caller switched the smoke test off.
	 *
	 * @since 0.2.0
	 *
	 * @return array{passed: bool, ran: bool, plugin_file_present: bool, probes: array<int, array<string, mixed>>}
	 */
	public static function skipped() {
		return array(
			'passed'              => true,
			'ran'                 => false,
			'plugin_file_present' => self::plugin_file_present(),
			'probes'              => array(),
		);
	}

	/**
	 * Whether this plugin's own main file is still on disk.
	 *
	 * A change that removes it would take the abilities down with it, so it is worth
	 * reporting even though nothing here can repair it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function plugin_file_present() {
		if ( ! defined( 'SUPER_ABILITIES_FILE' ) ) {
			return true;
		}

		return file_exists( (string) SUPER_ABILITIES_FILE );
	}

	/**
	 * Performs one loopback GET.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name    Probe name.
	 * @param string $url     URL to request.
	 * @param int    $timeout Timeout in seconds.
	 * @return array<string, mixed>
	 */
	protected static function probe( $name, $url, $timeout ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => (int) $timeout,
				'redirection' => 2,
				'blocking'    => true,
				'cookies'     => array(),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied the way `spawn_cron()` does for loopbacks.
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'name'      => (string) $name,
				'url'       => (string) $url,
				'status'    => 'unverified',
				'http_code' => 0,
				'message'   => sprintf(
					/* translators: %s: Error message from the HTTP API. */
					__( 'The loopback request could not be made: %s', 'super-abilities' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 1 ) {
			return array(
				'name'      => (string) $name,
				'url'       => (string) $url,
				'status'    => 'unverified',
				'http_code' => 0,
				'message'   => __( 'The loopback request returned no status code.', 'super-abilities' ),
			);
		}

		if ( $code >= 500 ) {
			return array(
				'name'      => (string) $name,
				'url'       => (string) $url,
				'status'    => 'failed',
				'http_code' => $code,
				'message'   => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The site answered with HTTP %d, which means PHP is failing on that request.', 'super-abilities' ),
					$code
				),
			);
		}

		return array(
			'name'      => (string) $name,
			'url'       => (string) $url,
			'status'    => 'ok',
			'http_code' => $code,
			'message'   => sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The site answered with HTTP %d.', 'super-abilities' ),
				$code
			),
		);
	}
}
