<?php
/**
 * Writes theme mods for the active theme.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Sets and removes theme mods, the settings the Customizer owns.
 *
 * @since 0.2.0
 */
class Theme_Mods_Write extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'theme-mods-write';
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
		return __( 'Write theme mods', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Sets theme mods for the active theme, the way the Customizer does. Send an object of mod name to value; a null value removes the mod and lets the theme default win again. Values must be strings, numbers, booleans or arrays of those. Nothing is protected unless the site filters super_abilities_protected_theme_mods, so setting custom_logo or nav_menu_locations to a wrong id will visibly break the site: read theme-mods-read first and pass its fingerprint as expected_fingerprint. Calling it twice with the same input is a no-op the second time.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write_idempotent();
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
				'mods'                 => array(
					'type'        => 'object',
					'description' => __( 'Theme mods to write, keyed by name. A null value removes the mod.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from theme-mods-read. The write is refused with 409 when any mod changed since.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report which mods would change without writing anything.', 'super-abilities' ),
				),
			),
			array( 'mods' )
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
				'theme'          => array( 'type' => 'string' ),
				'option'         => array( 'type' => 'string' ),
				'dry_run'        => array( 'type' => 'boolean' ),
				'changed_keys'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Mods whose value this call changed.', 'super-abilities' ),
				),
				'removed_keys'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Mods this call removed.', 'super-abilities' ),
				),
				'unchanged_keys' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Mods that already had the value you sent.', 'super-abilities' ),
				),
				'fingerprint'    => Schema::fingerprint( __( 'Fingerprint of every theme mod after the write.', 'super-abilities' ) ),
			),
			array( 'theme', 'option', 'dry_run', 'changed_keys', 'removed_keys', 'unchanged_keys', 'fingerprint' )
		);
	}

	/**
	 * Writes the theme mods.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$mods = isset( $input['mods'] ) && is_array( $input['mods'] ) ? $input['mods'] : null;

		if ( null === $mods || array() === $mods ) {
			return $this->error(
				'invalid_input',
				__( 'Send at least one theme mod.', 'super-abilities' ),
				400
			);
		}

		foreach ( $mods as $key => $value ) {
			$key = (string) $key;

			if ( '' === trim( $key ) ) {
				return $this->error(
					'invalid_input',
					__( 'Theme mod names may not be empty.', 'super-abilities' ),
					400
				);
			}

			if ( self::is_protected( $key ) ) {
				return $this->error(
					'protected_theme_mod',
					sprintf(
						/* translators: %s: Theme mod name. */
						__( 'The theme mod "%s" is protected on this site and was not changed.', 'super-abilities' ),
						$key
					),
					403,
					array(
						'mod'       => $key,
						'protected' => true,
					)
				);
			}

			if ( ! Theme_Mods_Read::is_json_safe( $value ) ) {
				return $this->error(
					'invalid_input',
					sprintf(
						/* translators: %s: Theme mod name. */
						__( 'The value of "%s" must be a string, a number, a boolean, null or an array of those.', 'super-abilities' ),
						$key
					),
					400
				);
			}
		}

		$before = Theme_Mods_Read::current();
		$stale  = $this->guard_fingerprint( $input, Theme_Mods_Read::fingerprint( $before['mods'] ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$theme   = (string) get_stylesheet();
		$dry_run = ! empty( $input['dry_run'] );

		$changed   = array();
		$removed   = array();
		$unchanged = array();

		foreach ( $mods as $key => $value ) {
			$key    = (string) $key;
			$exists = array_key_exists( $key, $before['mods'] );

			if ( null === $value ) {
				if ( ! $exists ) {
					$unchanged[] = $key;

					continue;
				}

				$removed[] = $key;

				if ( ! $dry_run ) {
					remove_theme_mod( $key );
				}

				continue;
			}

			if ( $exists && $before['mods'][ $key ] === $value ) {
				$unchanged[] = $key;

				continue;
			}

			$changed[] = $key;

			if ( ! $dry_run ) {
				set_theme_mod( $key, $value );
			}
		}

		if ( ! $dry_run ) {
			$this->note_object( 'option', 'theme_mods_' . $theme );
		}

		$after = $dry_run ? $before : Theme_Mods_Read::current();

		return array(
			'theme'          => $theme,
			'option'         => 'theme_mods_' . $theme,
			'dry_run'        => $dry_run,
			'changed_keys'   => $changed,
			'removed_keys'   => $removed,
			'unchanged_keys' => $unchanged,
			'fingerprint'    => Theme_Mods_Read::fingerprint( $after['mods'] ),
		);
	}

	/**
	 * Whether a theme mod may not be written.
	 *
	 * Nothing is protected out of the box, exactly like the Customizer, but a site
	 * can refuse individual mods.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key Theme mod name.
	 * @return bool
	 */
	public static function is_protected( $key ) {
		/**
		 * Filters the theme mods `theme-mods-write` refuses to change.
		 *
		 * Empty by default. Add mod names such as `custom_logo` or
		 * `nav_menu_locations` to keep an agent out of them; there is no force flag,
		 * so the refusal is absolute.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, string> $mods Protected theme mod names.
		 */
		$protected = (array) apply_filters( 'super_abilities_protected_theme_mods', array() );

		return in_array( (string) $key, array_map( 'strval', $protected ), true );
	}
}
