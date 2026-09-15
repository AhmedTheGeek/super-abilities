<?php
/**
 * Reads the user global styles of the active theme.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Global_Styles;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the user half of theme.json, and optionally the fully merged result.
 *
 * @since 0.2.0
 */
class Global_Styles_Read extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'global-styles-read';
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
		return __( 'Read global styles', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Returns the user global styles of the active theme: the settings and styles an administrator has saved in the site editor, which WordPress keeps in a single wp_global_styles post rather than in theme.json. A theme without a theme.json file has no such post, and then post_id is null and the document is empty. Pass include_merged to also get the resolved tree WordPress actually renders, with core, block, theme and user data layered in that order, which is what you want before writing a value that the theme may already define. The fingerprint identifies the user styles you read and is what global-styles-write compares against.', 'super-abilities' );
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
				'include_merged' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also return the merged settings and styles WordPress resolves from core, blocks, the theme and the user. Larger and slower.', 'super-abilities' ),
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
				'theme'          => array(
					'type'        => 'string',
					'description' => __( 'Stylesheet slug of the active theme.', 'super-abilities' ),
				),
				'is_block_theme' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the active theme is a block theme. Global styles have no effect on a classic theme.', 'super-abilities' ),
				),
				'has_theme_json' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the theme ships a theme.json. Without one WordPress stores no user global styles for it.', 'super-abilities' ),
				),
				'post_id'        => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Id of the wp_global_styles post holding the user styles, created on demand by WordPress.', 'super-abilities' ),
				),
				'version'        => array(
					'type'        => 'integer',
					'description' => __( 'theme.json schema version of the stored document.', 'super-abilities' ),
				),
				'settings'       => array(
					'type'        => 'object',
					'description' => __( 'User settings, the settings half of theme.json.', 'super-abilities' ),
				),
				'styles'         => array(
					'type'        => 'object',
					'description' => __( 'User styles, the styles half of theme.json.', 'super-abilities' ),
				),
				'merged'         => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'The merged settings and styles, when include_merged was true.', 'super-abilities' ),
				),
				'modified'       => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'When the user styles were last saved.', 'super-abilities' ),
				),
				'fingerprint'    => Schema::fingerprint( __( 'Fingerprint of the user settings and styles. Pass it to global-styles-write.', 'super-abilities' ) ),
			),
			array( 'theme', 'is_block_theme', 'has_theme_json', 'post_id', 'version', 'settings', 'styles', 'merged', 'modified', 'fingerprint' )
		);
	}

	/**
	 * Reads the global styles.
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

		$post_id  = Global_Styles::post_id();
		$data     = Global_Styles::read( $post_id );
		$post     = $post_id > 0 ? get_post( $post_id ) : null;
		$modified = null === $post ? '' : Time::iso_from_mysql( (string) $post->post_modified_gmt );

		return array(
			'theme'          => Global_Styles::theme(),
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'has_theme_json' => Global_Styles::theme_has_theme_json(),
			'post_id'        => $post_id > 0 ? $post_id : null,
			'version'        => Global_Styles::version(),
			'settings'       => $data['settings'],
			'styles'         => $data['styles'],
			'merged'         => empty( $input['include_merged'] ) ? null : Global_Styles::merged(),
			'modified'       => '' === $modified ? null : $modified,
			'fingerprint'    => Global_Styles::fingerprint( $data ),
		);
	}
}
