<?php
/**
 * Secret redaction.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Strips credentials, salts and absolute paths out of anything we hand to an agent.
 *
 * @since 0.1.0
 */
class Redactor {

	/**
	 * Replacement token.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const MASK = '[redacted]';

	/**
	 * Constants whose values must never appear in output.
	 *
	 * `DB_CHARSET` and `DB_COLLATE` are deliberately absent: they hold no secret and
	 * their values (`utf8`) would over-redact unrelated text.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const SECRET_CONSTANTS = array(
		'DB_NAME',
		'DB_USER',
		'DB_PASSWORD',
		'DB_HOST',
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	/**
	 * Constants whose values identify rather than authenticate, redacted only when long.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, string>
	 */
	const IDENTIFIER_CONSTANTS = array( 'DB_NAME', 'DB_USER', 'DB_HOST' );

	/**
	 * The literal secret values defined on this site.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string> Secret values, longest first.
	 */
	public static function secrets() {
		$secrets = array();

		foreach ( self::SECRET_CONSTANTS as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );

			if ( ! is_string( $value ) || strlen( $value ) < 4 ) {
				continue;
			}

			// Connection identifiers are often ordinary words (`wordpress`, `localhost`); only
			// treat them as literal secrets when they are long enough not to over-redact.
			if ( in_array( $constant, self::IDENTIFIER_CONSTANTS, true ) && strlen( $value ) < 12 ) {
				continue;
			}

			$secrets[] = $value;
		}

		$secrets = array_values( array_unique( $secrets ) );

		usort(
			$secrets,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $secrets;
	}

	/**
	 * Redacts a single string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text           Text to redact.
	 * @param bool   $redact_emails  Optional. Whether to mask email addresses. Default false.
	 * @return string
	 */
	public static function text( $text, $redact_emails = false ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return $text;
		}

		$secrets = self::secrets();

		if ( ! empty( $secrets ) ) {
			$text = str_replace( $secrets, self::MASK, $text );
		}

		$text = (string) preg_replace_callback(
			'/([a-z0-9_.\-]*(?:key|token|secret|password|authorization))(\s*[:=]\s*)([^\s,;)\]}"\']+)/i',
			static function ( $matches ) {
				return $matches[1] . $matches[2] . self::MASK;
			},
			$text
		);

		if ( defined( 'ABSPATH' ) ) {
			$root = untrailingslashit( ABSPATH );

			if ( '' !== $root ) {
				$text = str_replace( $root, '{ABSPATH}', $text );
			}
		}

		if ( $redact_emails ) {
			$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[redacted-email]', $text );
		}

		return $text;
	}

	/**
	 * Redacts strings anywhere inside a scalar or array structure.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value         Value to redact.
	 * @param bool  $redact_emails Optional. Whether to mask email addresses. Default false.
	 * @return mixed Redacted value, with the original types preserved.
	 */
	public static function redact( $value, $redact_emails = false ) {
		if ( is_string( $value ) ) {
			return self::text( $value, $redact_emails );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::redact( $item, $redact_emails );
			}

			return $value;
		}

		return $value;
	}

	/**
	 * Redacts a `WP_Debug_Data` style structure and drops private fields.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $sections      Debug data sections.
	 * @param bool                 $redact_emails Optional. Whether to mask email addresses. Default false.
	 * @return array<string, mixed>
	 */
	public static function redact_debug_data( array $sections, $redact_emails = false ) {
		$clean = array();

		foreach ( $sections as $section_id => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$fields = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : array();
			unset( $section['fields'] );

			$clean_fields = array();

			foreach ( $fields as $field_id => $field ) {
				if ( is_array( $field ) && ! empty( $field['private'] ) ) {
					continue;
				}

				if ( is_array( $field ) ) {
					unset( $field['private'] );
				}

				$clean_fields[ $field_id ] = self::redact( $field, $redact_emails );
			}

			$section           = self::redact( $section, $redact_emails );
			$section['fields'] = $clean_fields;

			$clean[ $section_id ] = $section;
		}

		return $clean;
	}
}
