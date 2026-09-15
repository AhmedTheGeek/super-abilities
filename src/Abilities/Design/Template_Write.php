<?php
/**
 * Writes one block template.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Block_Markup;
use SuperAbilities\Design\Revisions;
use SuperAbilities\Design\Templates;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Saves block markup into a template, creating the database override when the
 * template so far only exists as a theme file.
 *
 * @since 0.2.0
 */
class Template_Write extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'template-write';
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
		return __( 'Write a block template', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Saves block markup into a block template or template part. When the template so far only exists as a file in the theme, this creates the database override the site editor would create, attached to the theme through the wp_theme taxonomy, and the theme file stays untouched so template-reset can bring it back. When an override already exists it is updated through wp_update_post, so a revision is created. The markup must parse as blocks and survive a re-serialize without losing any, and markup containing a script tag or a PHP opening tag is refused. Requires a block theme. Read template-read first and pass its fingerprint as expected_fingerprint.', 'super-abilities' );
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
				'content'              => array(
					'type'        => 'string',
					'description' => __( 'The complete block markup to store. This replaces the template, it is not merged.', 'super-abilities' ),
				),
				'title'                => array(
					'type'        => 'string',
					'description' => __( 'New title. Omit to keep the current one.', 'super-abilities' ),
				),
				'description'          => array(
					'type'        => 'string',
					'description' => __( 'New description. Omit to keep the current one.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from template-read. The write is refused with 409 when the content changed since.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Validate the markup and report what would happen without writing anything.', 'super-abilities' ),
				),
			),
			array( 'id', 'content' )
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
				'id'          => array( 'type' => 'string' ),
				'type'        => array( 'type' => 'string' ),
				'dry_run'     => array( 'type' => 'boolean' ),
				'created'     => array(
					'type'        => 'boolean',
					'description' => __( 'True when this call created the database override for a theme template.', 'super-abilities' ),
				),
				'changed'     => array(
					'type'        => 'boolean',
					'description' => __( 'False when the stored markup, title and description already matched.', 'super-abilities' ),
				),
				'post_id'     => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Id of the post holding the override.', 'super-abilities' ),
				),
				'source'      => array(
					'type'        => 'string',
					'description' => __( 'Source of the template after the write, custom once an override exists.', 'super-abilities' ),
				),
				'block_count' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'blocks'      => Templates::blocks_schema(),
				'revision_id' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Newest revision of the template post, when one was created.', 'super-abilities' ),
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the stored content after the write.', 'super-abilities' ) ),
			),
			array( 'id', 'type', 'dry_run', 'created', 'changed', 'post_id', 'source', 'block_count', 'blocks', 'revision_id', 'fingerprint' )
		);
	}

	/**
	 * Writes the template.
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
				__( 'No template with that id exists for this theme. Templates cannot be created from nothing here; copy an existing one instead.', 'super-abilities' ),
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

		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$summary = Block_Markup::check( $content );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$title       = array_key_exists( 'title', $input ) ? (string) $input['title'] : (string) $template->title;
		$description = array_key_exists( 'description', $input ) ? (string) $input['description'] : (string) $template->description;
		$post_id     = null === $template->wp_id ? 0 : (int) $template->wp_id;
		$dry_run     = ! empty( $input['dry_run'] );

		$changed = $content !== (string) $template->content
			|| $title !== (string) $template->title
			|| $description !== (string) $template->description
			|| 0 === $post_id;

		$result = array(
			'id'          => $id,
			'type'        => $type,
			'dry_run'     => $dry_run,
			'created'     => 0 === $post_id,
			'changed'     => $changed,
			'post_id'     => $post_id > 0 ? $post_id : null,
			'source'      => 0 === $post_id ? 'custom' : (string) $template->source,
			'block_count' => (int) $summary['block_count'],
			'blocks'      => $summary['blocks'],
			'revision_id' => null,
			'fingerprint' => Block_Markup::fingerprint( $content ),
		);

		if ( $dry_run ) {
			return $result;
		}

		if ( 0 === $post_id ) {
			$created = Templates::create_override(
				array(
					'type'        => $type,
					'theme'       => $parts['theme'],
					'slug'        => $parts['slug'],
					'title'       => $title,
					'description' => $description,
					'content'     => $content,
					'area'        => (string) $template->area,
				)
			);

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$post_id = (int) $created;
		} elseif ( $changed ) {
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $title,
					'post_excerpt' => $description,
					'post_content' => $content,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$this->note_object( 'post', $post_id );

		clean_post_cache( $post_id );

		$after = Templates::get( $id, $type );

		$result['post_id']     = $post_id;
		$result['source']      = null === $after ? 'custom' : (string) $after->source;
		$result['revision_id'] = Revisions::latest_id( $post_id );
		$result['fingerprint'] = null === $after ? $result['fingerprint'] : Templates::fingerprint( $after );

		return $result;
	}
}
