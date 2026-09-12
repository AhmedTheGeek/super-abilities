<?php
/**
 * Content fingerprints.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Short, stable fingerprints used to reject writes against a stale base.
 *
 * Format: `fp1:` followed by the first 20 hex characters of the SHA-256 digest.
 *
 * @since 0.1.0
 */
class Fingerprint {

	/**
	 * Fingerprint format prefix.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PREFIX = 'fp1:';

	/**
	 * Number of hex characters kept from the digest.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const LENGTH = 20;

	/**
	 * Fingerprints a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Value to fingerprint.
	 * @return string
	 */
	public static function of_string( $value ) {
		return self::PREFIX . substr( hash( 'sha256', (string) $value ), 0, self::LENGTH );
	}

	/**
	 * Fingerprints an array, independently of key order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, mixed> $value Value to fingerprint.
	 * @return string
	 */
	public static function of_array( array $value ) {
		$encoded = wp_json_encode( self::normalize( $value ) );

		return self::of_string( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Whether two fingerprints match.
	 *
	 * The comparison tolerates a missing `fp1:` prefix on either side.
	 *
	 * @since 0.1.0
	 *
	 * @param string $expected Fingerprint supplied by the caller.
	 * @param string $actual   Fingerprint computed from the current state.
	 * @return bool
	 */
	public static function matches( $expected, $actual ) {
		return hash_equals( self::canonical( $actual ), self::canonical( $expected ) );
	}

	/**
	 * Normalizes a fingerprint string for comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Fingerprint.
	 * @return string
	 */
	protected static function canonical( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( 0 === strpos( $value, self::PREFIX ) ) {
			$value = substr( $value, strlen( self::PREFIX ) );
		}

		return self::PREFIX . $value;
	}

	/**
	 * Recursively sorts array keys so equal payloads hash equally.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, mixed> $value Value to normalize.
	 * @return array<int|string, mixed>
	 */
	protected static function normalize( array $value ) {
		ksort( $value );

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::normalize( $item );
			}
		}

		return $value;
	}
}
