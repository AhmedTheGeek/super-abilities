<?php
/**
 * Reads the theme mods of the active theme.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Returns everything the Customizer saved for the active theme.
 *
 * @since 0.2.0
 */
class Theme_Mods_Read extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'theme-mods-read';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'design';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Read theme mods', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Returns the theme mods of the active theme, the per-theme settings the Customizer writes: custom logo, header and background images, colours, nav menu locations and whatever the theme itself stores there. Works on classic and block themes alike. Values that cannot be represented as JSON, such as stored objects, are dropped, and secrets and absolute paths are redacted. The fingerprint covers every mod, not just the ones you filtered for, and is what theme-mods-write compares against.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'edit_theme_options' );
	}

	/**
	 * Plugin version this ability shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'keys' => Schema::csv_or_array_of_strings( __( 'Only return these theme mods. Omit for all of them.', 'super-abilities' ) ),
			)
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'theme'        => array(
					'type'        => 'string',
					'description' => __( 'Stylesheet slug of the active theme.', 'super-abilities' ),
				),
				'option'       => array(
					'type'        => 'string',
					'description' => __( 'Name of the option the mods are stored in.', 'super-abilities' ),
				),
				'mods'         => array(
					'type'        => 'object',
					'description' => __( 'The theme mods, keyed by name.', 'super-abilities' ),
				),
				'total'        => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many mods the theme has in total, before any filtering.', 'super-abilities' ),
				),
				'dropped_keys' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Mods left out because their value cannot be represented as JSON.', 'super-abilities' ),
				),
				'fingerprint'  => Schema::fingerprint( __( 'Fingerprint of every theme mod. Pass it to theme-mods-write.', 'super-abilities' ) ),
			),
			array( 'theme', 'option', 'mods', 'total', 'dropped_keys', 'fingerprint' )
		);
	}

	/**
	 * Reads the theme mods.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$scrubbed = self::current();
		$keys     = isset( $input['keys'] ) ? Schema::to_string_list( $input['keys'] ) : array();
		$mods     = $scrubbed['mods'];

		if ( ! empty( $keys ) ) {
			$mods = array_intersect_key( $mods, array_fill_keys( $keys, true ) );
		}

		return array(
			'theme'        => (string) get_stylesheet(),
			'option'       => 'theme_mods_' . get_stylesheet(),
			'mods'         => Redactor::redact( $mods ),
			'total'        => count( $scrubbed['mods'] ),
			'dropped_keys' => $scrubbed['dropped'],
			'fingerprint'  => self::fingerprint( $scrubbed['mods'] ),
		);
	}

	/**
	 * The theme mods of the active theme, with unrepresentable values removed.
	 *
	 * @since 0.2.0
	 *
	 * @return array{mods: array<string, mixed>, dropped: array<int, string>}
	 */
	public static function current() {
		$stored  = get_theme_mods();
		$stored  = is_array( $stored ) ? $stored : array();
		$mods    = array();
		$dropped = array();

		foreach ( $stored as $key => $value ) {
			$key = (string) $key;

			if ( self::is_json_safe( $value ) ) {
				$mods[ $key ] = $value;

				continue;
			}

			$dropped[] = $key;
		}

		ksort( $mods );
		sort( $dropped );

		return array(
			'mods'    => $mods,
			'dropped' => $dropped,
		);
	}

	/**
	 * Fingerprints a set of theme mods.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $mods Scrubbed theme mods.
	 * @return string
	 */
	public static function fingerprint( array $mods ) {
		ksort( $mods );

		return Fingerprint::of_array( $mods );
	}

	/**
	 * Whether a value survives a JSON round trip.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	public static function is_json_safe( $value ) {
		if ( null === $value || is_scalar( $value ) ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! self::is_json_safe( $item ) ) {
				return false;
			}
		}

		return true;
	}
}
