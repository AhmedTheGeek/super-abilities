<?php
/**
 * Timestamp helpers.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Parses and formats the timestamps used by ability inputs and outputs.
 *
 * Every timestamp this plugin emits is ISO 8601 in UTC.
 *
 * @since 0.1.0
 */
class Time {

	/**
	 * Current Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function now() {
		return (int) time();
	}

	/**
	 * Parses a user supplied point in time.
	 *
	 * Accepts a Unix timestamp (int or numeric string), an ISO 8601 string such as
	 * `2026-09-12T10:00:00Z`, a MySQL datetime, or a relative expression such as
	 * `-24 hours` or `-7 days`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return int|null Unix timestamp, or null when the value cannot be parsed.
	 */
	public static function parse( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			return (int) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^-?\d{9,11}$/', $value ) ) {
			return (int) $value;
		}

		// MySQL datetimes are stored in UTC, so parse them as UTC.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value ) ) {
			$parsed = strtotime( $value . ' UTC' );

			return false === $parsed ? null : (int) $parsed;
		}

		$parsed = strtotime( $value, self::now() );

		return false === $parsed ? null : (int) $parsed;
	}

	/**
	 * Formats a timestamp as ISO 8601 in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function iso( $timestamp ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
	}

	/**
	 * Formats a timestamp as a MySQL datetime in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function mysql( $timestamp ) {
		return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
	}

	/**
	 * Converts a UTC MySQL datetime into an ISO 8601 string.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $datetime MySQL datetime in UTC.
	 * @return string Empty string when the value is empty or unparseable.
	 */
	public static function iso_from_mysql( $datetime ) {
		$timestamp = self::parse( (string) $datetime );

		return null === $timestamp ? '' : self::iso( $timestamp );
	}
}
