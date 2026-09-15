<?php
/**
 * Writes the user global styles of the active theme.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Global_Styles;
use SuperAbilities\Design\Revisions;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Merges or replaces the user half of theme.json, through the post that stores it.
 *
 * @since 0.2.0
 */
class Global_Styles_Write extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'global-styles-write';
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
		return __( 'Write global styles', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Saves user global styles for the active theme, exactly as the site editor does: into the wp_global_styles post, never into a theme.json file on disk. In the default merge mode the settings and styles you send are merged into the stored document key by key, and a null value removes a key; in replace mode the stored document becomes what you sent. The result is run through WordPress\' own theme.json sanitizer before it is stored, so anything WordPress would not apply is dropped and reported back to you, and the write goes through wp_update_post so a revision exists to roll back to. Read global-styles-read first and pass its fingerprint as expected_fingerprint so you cannot overwrite someone else\'s change.', 'super-abilities' );
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
				'styles'               => array(
					'type'        => 'object',
					'description' => __( 'The styles half of theme.json, or the part of it you want to change.', 'super-abilities' ),
				),
				'settings'             => array(
					'type'        => 'object',
					'description' => __( 'The settings half of theme.json, or the part of it you want to change.', 'super-abilities' ),
				),
				'mode'                 => array(
					'type'        => 'string',
					'enum'        => array( 'merge', 'replace' ),
					'default'     => 'merge',
					'description' => __( 'merge folds your input into the stored document recursively and treats null as "remove this key"; replace stores only what you sent. Default merge.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from global-styles-read. The write is refused with 409 when the stored styles changed since.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Validate and report the resulting document without saving anything.', 'super-abilities' ),
				),
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
				'theme'         => array( 'type' => 'string' ),
				'post_id'       => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Id of the wp_global_styles post that was written.', 'super-abilities' ),
				),
				'mode'          => array( 'type' => 'string' ),
				'dry_run'       => array( 'type' => 'boolean' ),
				'changed'       => array(
					'type'        => 'boolean',
					'description' => __( 'False when the resulting document is identical to the stored one.', 'super-abilities' ),
				),
				'settings'      => array(
					'type'        => 'object',
					'description' => __( 'The user settings as stored after the write.', 'super-abilities' ),
				),
				'styles'        => array(
					'type'        => 'object',
					'description' => __( 'The user styles as stored after the write.', 'super-abilities' ),
				),
				'dropped_paths' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Paths WordPress\' theme.json sanitizer does not recognise. They are stored but never applied, so they are usually a typo.', 'super-abilities' ),
				),
				'revision_id'   => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Newest revision of the global styles post, when the post type keeps revisions.', 'super-abilities' ),
				),
				'fingerprint'   => Schema::fingerprint( __( 'Fingerprint of the resulting user styles.', 'super-abilities' ) ),
			),
			array( 'theme', 'post_id', 'mode', 'dry_run', 'changed', 'settings', 'styles', 'dropped_paths', 'revision_id', 'fingerprint' )
		);
	}

	/**
	 * Writes the global styles.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return $this->error(
				'unsupported',
				__( 'This WordPress install has no global styles support.', 'super-abilities' ),
				501
			);
		}

		$has_styles   = array_key_exists( 'styles', $input );
		$has_settings = array_key_exists( 'settings', $input );

		if ( ! $has_styles && ! $has_settings ) {
			return $this->error(
				'invalid_input',
				__( 'Send styles, settings or both.', 'super-abilities' ),
				400
			);
		}

		foreach ( array( 'styles', 'settings' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ! is_array( $input[ $key ] ) ) {
				return $this->error(
					'invalid_input',
					sprintf(
						/* translators: %s: Property name, `styles` or `settings`. */
						__( 'The "%s" property must be an object.', 'super-abilities' ),
						$key
					),
					400
				);
			}
		}

		$post_id = Global_Styles::post_id();

		if ( $post_id <= 0 ) {
			if ( ! Global_Styles::theme_has_theme_json() ) {
				return $this->error(
					'unsupported',
					__( 'The active theme ships no theme.json, so WordPress stores no user global styles for it. Switch to a block theme, or use theme-mods-write instead.', 'super-abilities' ),
					501
				);
			}

			return $this->error(
				'not_found',
				__( 'WordPress could not create the global styles post for the active theme.', 'super-abilities' ),
				404
			);
		}

		$current = Global_Styles::read( $post_id );
		$stale   = $this->guard_fingerprint( $input, Global_Styles::fingerprint( $current ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$mode  = isset( $input['mode'] ) && 'replace' === $input['mode'] ? 'replace' : 'merge';
		$patch = array(
			'settings' => $has_settings ? (array) $input['settings'] : array(),
			'styles'   => $has_styles ? (array) $input['styles'] : array(),
		);

		if ( 'replace' === $mode ) {
			$next = array(
				'settings' => $has_settings ? $patch['settings'] : $current['settings'],
				'styles'   => $has_styles ? $patch['styles'] : $current['styles'],
			);
		} else {
			$next = $current;

			if ( $has_settings ) {
				$next['settings'] = Global_Styles::merge( $current['settings'], $patch['settings'] );
			}

			if ( $has_styles ) {
				$next['styles'] = Global_Styles::merge( $current['styles'], $patch['styles'] );
			}
		}

		$validated = Global_Styles::validate( $next );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$document    = $validated['document'];
		$fingerprint = Global_Styles::fingerprint( $document );
		$changed     = Global_Styles::fingerprint( $current ) !== $fingerprint;

		$result = array(
			'theme'         => Global_Styles::theme(),
			'post_id'       => $post_id,
			'mode'          => $mode,
			'dry_run'       => ! empty( $input['dry_run'] ),
			'changed'       => $changed,
			'settings'      => $document['settings'],
			'styles'        => $document['styles'],
			'dropped_paths' => $validated['dropped'],
			'revision_id'   => null,
			'fingerprint'   => $fingerprint,
		);

		if ( $result['dry_run'] ) {
			return $result;
		}

		$this->note_object( 'global_styles', $post_id );

		if ( ! $changed ) {
			$result['revision_id'] = Revisions::latest_id( $post_id );

			return $result;
		}

		$written = Global_Styles::write( $post_id, $document );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$result['revision_id'] = Revisions::latest_id( $post_id );

		return $result;
	}
}
