<?php
/**
 * Guarded outbound HTTP.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every outbound request this plugin makes goes through here.
 *
 * HTTPS only by default, `wp_http_validate_url()` to keep SSRF targets out,
 * `reject_unsafe_urls` on, a hard response size limit and a short timeout.
 *
 * @since 0.1.0
 */
class Http {

	/**
	 * Default request timeout in seconds.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Default response size limit in bytes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_BYTES = 1048576;

	/**
	 * The user agent this plugin identifies itself with.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function user_agent() {
		return sprintf( 'SuperAbilities/%s (+%s)', SUPER_ABILITIES_VERSION, home_url( '/' ) );
	}

	/**
	 * Validates an outbound URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url  URL to validate.
	 * @param array<string, mixed> $args {
	 *     Optional. Validation options.
	 *
	 *     @type bool               $allow_http Whether to allow plain HTTP. Default false.
	 *     @type array<int, string> $hosts      Host allowlist. Empty means any host that
	 *                                          passes `wp_http_validate_url()`.
	 * }
	 * @return string|WP_Error The URL on success.
	 */
	public static function validate_url( $url, array $args = array() ) {
		$url        = trim( (string) $url );
		$allow_http = ! empty( $args['allow_http'] );
		$hosts      = isset( $args['hosts'] ) && is_array( $args['hosts'] ) ? $args['hosts'] : array();

		if ( '' === $url ) {
			return Error::make( 'invalid_input', __( 'A URL is required.', 'super-abilities' ) );
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( 'https' !== $scheme && ! ( $allow_http && 'http' === $scheme ) ) {
			return Error::make(
				'invalid_input',
				__( 'Only HTTPS URLs are allowed.', 'super-abilities' ),
				array( 'url' => $url )
			);
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return Error::make(
				'invalid_input',
				__( 'The URL is not a valid or safe external URL.', 'super-abilities' ),
				array( 'url' => $url )
			);
		}

		if ( ! empty( $hosts ) ) {
			$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$allowed = false;

			foreach ( $hosts as $candidate ) {
				$candidate = strtolower( ltrim( trim( (string) $candidate ), '.' ) );

				if ( '' === $candidate ) {
					continue;
				}

				if ( $host === $candidate || substr( $host, -strlen( '.' . $candidate ) ) === '.' . $candidate ) {
					$allowed = true;
					break;
				}
			}

			if ( ! $allowed ) {
				return Error::make(
					'invalid_input',
					__( 'The host of this URL is not on the allowlist.', 'super-abilities' ),
					array( 'host' => $host )
				);
			}
		}

		return $url;
	}

	/**
	 * Performs a guarded GET request.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url  URL to fetch.
	 * @param array<string, mixed> $args Optional. See {@see Http::request()}.
	 * @return array<string, mixed>|WP_Error Response array with `code`, `headers` and `body`.
	 */
	public static function get( $url, array $args = array() ) {
		return self::request( 'GET', $url, $args );
	}

	/**
	 * Performs a guarded HEAD request.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url  URL to probe.
	 * @param array<string, mixed> $args Optional. See {@see Http::request()}.
	 * @return array<string, mixed>|WP_Error Response array with `code`, `headers` and `body`.
	 */
	public static function head( $url, array $args = array() ) {
		return self::request( 'HEAD', $url, $args );
	}

	/**
	 * Downloads a URL to a temporary file and enforces a size cap.
	 *
	 * The caller owns the returned file and must `wp_delete_file()` it.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url       URL to download.
	 * @param int                  $max_bytes Optional. Maximum allowed size in bytes. 0 means
	 *                                        {@see Http::MAX_BYTES}. Default 0.
	 * @param array<string, mixed> $args      Optional. See {@see Http::validate_url()}, plus `timeout`.
	 * @return string|WP_Error Absolute path to the downloaded temporary file.
	 */
	public static function download( $url, $max_bytes = 0, array $args = array() ) {
		$validated = self::validate_url( $url, $args );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$max_bytes = (int) $max_bytes > 0 ? (int) $max_bytes : self::MAX_BYTES;
		$timeout   = isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT;

		$head = self::head( $validated, $args );

		if ( ! is_wp_error( $head ) && isset( $head['headers']['content-length'] ) ) {
			$length = (int) $head['headers']['content-length'];

			if ( $length > $max_bytes ) {
				return Error::make(
					'invalid_input',
					sprintf(
						/* translators: %s: Maximum allowed size in bytes. */
						__( 'The remote file is larger than the allowed %s bytes.', 'super-abilities' ),
						number_format_i18n( $max_bytes )
					),
					array(
						'max_bytes'      => $max_bytes,
						'content_length' => $length,
					)
				);
			}
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $validated, $timeout );

		if ( is_wp_error( $file ) ) {
			return Error::make(
				'upstream_http',
				$file->get_error_message(),
				array( 'url' => $validated )
			);
		}

		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesize() warns on unreadable temp files.

		if ( $size > $max_bytes ) {
			wp_delete_file( $file );

			return Error::make(
				'invalid_input',
				sprintf(
					/* translators: %s: Maximum allowed size in bytes. */
					__( 'The downloaded file is larger than the allowed %s bytes.', 'super-abilities' ),
					number_format_i18n( $max_bytes )
				),
				array(
					'max_bytes' => $max_bytes,
					'size'      => $size,
				)
			);
		}

		return (string) $file;
	}

	/**
	 * Performs a guarded request.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method HTTP method, `GET` or `HEAD`.
	 * @param string               $url    URL to request.
	 * @param array<string, mixed> $args   {
	 *     Optional. Request options.
	 *
	 *     @type bool                $allow_http  Whether to allow plain HTTP. Default false.
	 *     @type array<int, string>  $hosts       Host allowlist. Default empty.
	 *     @type int                 $timeout     Timeout in seconds. Default 15.
	 *     @type int                 $max_bytes   Response size limit. Default 1 MB.
	 *     @type array<string,string> $headers    Extra request headers. Default empty.
	 *     @type int                 $redirection Maximum redirects to follow. Default 3.
	 * }
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function request( $method, $url, array $args = array() ) {
		$validated = self::validate_url( $url, $args );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$request_args = array(
			'method'              => 'HEAD' === $method ? 'HEAD' : 'GET',
			'timeout'             => isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT,
			'redirection'         => isset( $args['redirection'] ) ? (int) $args['redirection'] : 3,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => isset( $args['max_bytes'] ) ? (int) $args['max_bytes'] : self::MAX_BYTES,
			'user-agent'          => self::user_agent(),
			'headers'             => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(),
		);

		$response = wp_safe_remote_request( $validated, $request_args );

		if ( is_wp_error( $response ) ) {
			return Error::make(
				'upstream_http',
				$response->get_error_message(),
				array( 'url' => $validated )
			);
		}

		$headers = wp_remote_retrieve_headers( $response );

		return array(
			'url'     => $validated,
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? (array) $headers->getAll() : (array) $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
