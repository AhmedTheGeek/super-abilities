<?php
/**
 * Error code to HTTP status mapping.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `WP_Error` objects abilities return.
 *
 * @since 0.1.0
 */
class Error {

	/**
	 * Error code prefix used by every error this plugin returns.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PREFIX = 'super_abilities_';

	/**
	 * Unprefixed error code to HTTP status.
	 *
	 * @since 0.1.0
	 * @var array<string, int>
	 */
	const STATUS_MAP = array(
		'invalid_input'     => 400,
		'forbidden'         => 403,
		'not_found'         => 404,
		'stale_fingerprint' => 409,
		'locked'            => 423,
		'exception'         => 500,
		'unsupported'       => 501,
		'upstream_http'     => 502,
		'budget_exceeded'   => 504,
	);

	/**
	 * Prefixes an error code when it is not prefixed already.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function code( $code ) {
		$code = (string) $code;

		return 0 === strpos( $code, self::PREFIX ) ? $code : self::PREFIX . $code;
	}

	/**
	 * HTTP status for an error code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Error code, with or without the plugin prefix.
	 * @return int Mapped status, or 400 when the code is unknown.
	 */
	public static function status_for( $code ) {
		$code = (string) $code;

		if ( 0 === strpos( $code, self::PREFIX ) ) {
			$code = substr( $code, strlen( self::PREFIX ) );
		}

		return isset( self::STATUS_MAP[ $code ] ) ? self::STATUS_MAP[ $code ] : 400;
	}

	/**
	 * Builds a `WP_Error` with a mapped status.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $code    Error code, with or without the plugin prefix.
	 * @param string               $message Optional. Human readable message. Default empty.
	 * @param array<string, mixed> $data    Optional. Extra error data. Default empty array.
	 * @return WP_Error
	 */
	public static function make( $code, $message = '', array $data = array() ) {
		if ( '' === $message ) {
			$message = __( 'The request could not be completed.', 'super-abilities' );
		}

		$data['status'] = isset( $data['status'] ) ? (int) $data['status'] : self::status_for( $code );

		return new WP_Error( self::code( $code ), $message, $data );
	}
}
