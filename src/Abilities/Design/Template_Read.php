<?php
/**
 * Reads one block template.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Templates;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Returns one template with its raw block markup and a summary of the blocks in it.
 *
 * @since 0.2.0
 */
class Template_Read extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'template-read';
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
		return __( 'Read a block template', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Returns one block template or template part by id, for example twentytwentyfive//home, with its raw block markup, a tally of the block types it uses, where the content comes from and a fingerprint you pass back to template-write. When an administrator has customized a theme template you get the customized markup, not the file. Requires a block theme.', 'super-abilities' );
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
				'id'   => array(
					'type'        => 'string',
					'minLength'   => 3,
					'description' => __( 'Template id as templates-list reports it, for example twentytwentyfive//home.', 'super-abilities' ),
				),
				'type' => array(
					'type'        => 'string',
					'enum'        => Templates::TYPES,
					'default'     => 'wp_template',
					'description' => __( 'Which kind of template the id refers to. Default wp_template.', 'super-abilities' ),
				),
			),
			array( 'id' )
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
		return Templates::item_schema( true );
	}

	/**
	 * Reads the template.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! Templates::supported() ) {
			return $this->error(
				'unsupported',
				__( 'Block templates need a block theme. The active theme is a classic theme.', 'super-abilities' ),
				501
			);
		}

		$type  = Templates::type( isset( $input['type'] ) ? $input['type'] : '' );
		$id    = isset( $input['id'] ) ? trim( (string) $input['id'] ) : '';
		$parts = Templates::parse_id( $id );

		if ( null === $parts ) {
			return $this->error(
				'invalid_input',
				__( 'A template id looks like theme-slug//template-slug.', 'super-abilities' ),
				400
			);
		}

		$template = Templates::get( $id, $type );

		if ( null === $template ) {
			return $this->error(
				'not_found',
				__( 'No template with that id exists for this theme.', 'super-abilities' ),
				404,
				array(
					'id'   => $id,
					'type' => $type,
				)
			);
		}

		return Templates::to_array( $template, true );
	}
}
