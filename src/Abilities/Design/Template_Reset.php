<?php
/**
 * Removes a customized block template.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Templates;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the database override of a template so that the theme file wins again.
 *
 * @since 0.2.0
 */
class Template_Reset extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'template-reset';
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
		return __( 'Reset a block template', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reverts a customized block template to the theme, the way the site editor\'s "Clear customizations" does: the database override is deleted and the theme file takes over again. A template that has no theme file behind it has nothing to fall back to, so it is moved to the trash instead of being deleted, and can be restored. Calling it on a template that is already the theme\'s own is a no-op. Requires a block theme.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::destructive();
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
				'id'                   => array(
					'type'        => 'string',
					'minLength'   => 3,
					'description' => __( 'Template id, for example twentytwentyfive//home.', 'super-abilities' ),
				),
				'type'                 => array(
					'type'        => 'string',
					'enum'        => Templates::TYPES,
					'default'     => 'wp_template',
					'description' => __( 'Which kind of template the id refers to. Default wp_template.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from template-read. The reset is refused with 409 when the content changed since.', 'super-abilities' ) ),
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
		return Schema::object(
			array(
				'id'                => array( 'type' => 'string' ),
				'type'              => array( 'type' => 'string' ),
				'post_id'           => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Id of the override post this call acted on, when there was one.', 'super-abilities' ),
				),
				'deleted'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the override post was permanently deleted.', 'super-abilities' ),
				),
				'trashed'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the override post was moved to the trash instead.', 'super-abilities' ),
				),
				'reverted_to_theme' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a theme file now provides this template again.', 'super-abilities' ),
				),
				'source'            => array(
					'type'        => 'string',
					'description' => __( 'Source of the template after the reset.', 'super-abilities' ),
				),
				'fingerprint'       => Schema::fingerprint( __( 'Fingerprint of the content that is now in effect.', 'super-abilities' ) ),
			),
			array( 'id', 'type', 'post_id', 'deleted', 'trashed', 'reverted_to_theme', 'source', 'fingerprint' )
		);
	}

	/**
	 * Resets the template.
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

		$type = Templates::type( isset( $input['type'] ) ? $input['type'] : '' );
		$id   = isset( $input['id'] ) ? trim( (string) $input['id'] ) : '';

		if ( null === Templates::parse_id( $id ) ) {
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

		$stale = $this->guard_fingerprint( $input, Templates::fingerprint( $template ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$post_id = null === $template->wp_id ? 0 : (int) $template->wp_id;

		if ( 0 === $post_id ) {
			// Nothing to clear: this template already comes straight from the theme.
			return array(
				'id'                => $id,
				'type'              => $type,
				'post_id'           => null,
				'deleted'           => false,
				'trashed'           => false,
				'reverted_to_theme' => (bool) $template->has_theme_file,
				'source'            => (string) $template->source,
				'fingerprint'       => Templates::fingerprint( $template ),
			);
		}

		$has_theme_file = (bool) $template->has_theme_file;

		$this->note_object( 'post', $post_id );

		if ( $has_theme_file ) {
			$removed = wp_delete_post( $post_id, true );
		} else {
			// `wp_trash_post()` deletes outright when the trash is switched off, so the
			// outcome is read back from the database rather than assumed.
			$removed = wp_trash_post( $post_id );
		}

		if ( empty( $removed ) ) {
			return $this->error(
				'exception',
				__( 'WordPress refused to remove the template override.', 'super-abilities' ),
				500,
				array( 'post_id' => $post_id )
			);
		}

		clean_post_cache( $post_id );

		$override = get_post( $post_id );
		$after    = Templates::get( $id, $type );

		return array(
			'id'                => $id,
			'type'              => $type,
			'post_id'           => $post_id,
			'deleted'           => null === $override,
			'trashed'           => null !== $override && 'trash' === $override->post_status,
			'reverted_to_theme' => $has_theme_file && null !== $after,
			'source'            => null === $after ? 'none' : (string) $after->source,
			'fingerprint'       => Templates::fingerprint( $after ),
		);
	}
}
