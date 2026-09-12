<?php
/**
 * Public exposure probes.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Security;

use SuperAbilities\Support\Http;
use SuperAbilities\Support\Redactor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the site, over HTTP, whether a file an attacker would look for is reachable.
 *
 * Probes are short (five seconds), never follow redirects, and read at most 20 KB of
 * the body. Certificate verification is off by default because a staging site with a
 * self-signed certificate would otherwise report every probe as an error; the answer
 * to "is this file public" does not depend on the certificate. Filter
 * `super_abilities_probe_sslverify` to turn verification back on.
 *
 * @since 0.1.0
 */
class Probes {

	/**
	 * Probe timeout in seconds.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TIMEOUT = 5;

	/**
	 * Maximum number of body bytes read by a GET probe.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_BODY_BYTES = 20480;

	/**
	 * Probes one URL and decides whether it is publicly exposed.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url  Absolute URL to probe.
	 * @param array<string, mixed> $args {
	 *     Optional. Probe options.
	 *
	 *     @type string             $method       `HEAD` or `GET`. Default `HEAD`.
	 *     @type string             $needle       Case insensitive string the body must contain for
	 *                                            the URL to count as exposed. Implies a GET probe.
	 *     @type array<int, int>    $expect_codes Status codes that count as exposed. Default `array( 200 )`.
	 *     @type int                $max_bytes    Body byte cap. Default 20480.
	 *     @type int                $timeout      Timeout in seconds. Default 5.
	 * }
	 * @return array{url: string, http_code: int, exposed: bool, note: string}
	 */
	public static function exposure( $url, array $args = array() ) {
		$args = array_merge(
			array(
				'method'       => 'HEAD',
				'needle'       => '',
				'expect_codes' => array( 200 ),
				'max_bytes'    => self::MAX_BODY_BYTES,
				'timeout'      => self::TIMEOUT,
			),
			$args
		);

		$url    = (string) $url;
		$needle = (string) $args['needle'];
		$codes  = array_map( 'intval', (array) $args['expect_codes'] );

		$result = array(
			'url'       => Redactor::text( $url ),
			'http_code' => 0,
			'exposed'   => false,
			'note'      => '',
		);

		$method   = '' === $needle ? (string) $args['method'] : 'GET';
		$response = self::request( $method, $url, $args );

		if ( is_wp_error( $response ) ) {
			$result['note'] = Redactor::text(
				sprintf(
					/* translators: %s: Error message. */
					__( 'The probe could not be completed: %s', 'super-abilities' ),
					$response->get_error_message()
				)
			);

			return $result;
		}

		$result['http_code'] = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$exposed             = in_array( $result['http_code'], $codes, true );

		if ( $exposed && '' !== $needle ) {
			$body    = isset( $response['body'] ) ? (string) $response['body'] : '';
			$exposed = false !== stripos( substr( $body, 0, self::MAX_BODY_BYTES ), $needle );
		}

		$result['exposed'] = $exposed;

		if ( $exposed ) {
			$result['note'] = __( 'Reachable by anyone on the internet.', 'super-abilities' );
		} else {
			$result['note'] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Not publicly reachable (HTTP %d).', 'super-abilities' ),
				$result['http_code']
			);
		}

		return $result;
	}

	/**
	 * Follows a single redirect hop without fetching the target.
	 *
	 * Used by the author enumeration check, which only needs the `Location` header.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url  Absolute URL to request.
	 * @param array<string, mixed> $args Optional. See {@see Probes::exposure()}.
	 * @return array{code: int, location: string, error: string}
	 */
	public static function redirect_target( $url, array $args = array() ) {
		$response = self::request( 'GET', $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code'     => 0,
				'location' => '',
				'error'    => Redactor::text( $response->get_error_message() ),
			);
		}

		$headers  = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
		$location = '';

		foreach ( $headers as $name => $value ) {
			if ( 'location' !== strtolower( (string) $name ) ) {
				continue;
			}

			$location = is_array( $value ) ? (string) reset( $value ) : (string) $value;
			break;
		}

		return array(
			'code'     => isset( $response['code'] ) ? (int) $response['code'] : 0,
			'location' => $location,
			'error'    => '',
		);
	}

	/**
	 * Performs the guarded request behind a probe.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method HTTP method, `GET` or `HEAD`.
	 * @param string               $url    Absolute URL.
	 * @param array<string, mixed> $args   Optional. See {@see Probes::exposure()}.
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function request( $method, $url, array $args = array() ) {
		$url = (string) $url;

		$request_args = array(
			'timeout'     => isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT,
			'redirection' => isset( $args['redirection'] ) ? (int) $args['redirection'] : 0,
			'max_bytes'   => isset( $args['max_bytes'] ) ? (int) $args['max_bytes'] : self::MAX_BODY_BYTES,
			'allow_http'  => self::allows_http( $url ),
		);

		$filter = static function ( $parsed_args, $request_url ) use ( $url ) {
			if ( (string) $request_url === $url ) {
				/**
				 * Filters whether security probes verify TLS certificates.
				 *
				 * Off by default so that staging sites with a self-signed certificate still
				 * get meaningful answers. Return true to verify.
				 *
				 * @since 0.1.0
				 *
				 * @param bool   $sslverify Whether to verify the certificate.
				 * @param string $url       URL being probed.
				 */
				$parsed_args['sslverify'] = (bool) apply_filters( 'super_abilities_probe_sslverify', false, $url );
			}

			return $parsed_args;
		};

		add_filter( 'http_request_args', $filter, 10, 2 );

		try {
			if ( 'GET' === strtoupper( (string) $method ) ) {
				$response = Http::get( $url, $request_args );
			} else {
				$response = Http::head( $url, $request_args );
			}
		} finally {
			remove_filter( 'http_request_args', $filter, 10 );
		}

		return $response;
	}

	/**
	 * Whether a plain HTTP probe is acceptable for this URL.
	 *
	 * Only the site's own host is probed over HTTP, and only when the site itself is
	 * configured without HTTPS.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL to probe.
	 * @return bool
	 */
	protected static function allows_http( $url ) {
		if ( 'http' !== strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}

		$host      = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		return '' !== $host && $host === $home_host;
	}
}
