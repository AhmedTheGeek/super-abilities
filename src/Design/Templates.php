<?php
/**
 * Block template and template part access.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Design;

use SuperAbilities\Support\Error;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;
use WP_Block_Template;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Finds, describes and overrides block templates without going through the REST API.
 *
 * Template ids look like `twentytwentyfive//home`: the theme the template belongs
 * to, two slashes, then the slug. A template can live in the theme only, in the
 * database only, or in both, in which case the database copy wins and core reports
 * its source as `custom`.
 *
 * @since 0.2.0
 */
class Templates {

	/**
	 * Post types that hold templates.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const TYPES = array( 'wp_template', 'wp_template_part' );

	/**
	 * Taxonomy linking a template post to its theme.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const THEME_TAXONOMY = 'wp_theme';

	/**
	 * Taxonomy holding the area of a template part.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const AREA_TAXONOMY = 'wp_template_part_area';

	/**
	 * Whether this site can have block templates at all.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function supported() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Normalizes a template type.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $type Raw type.
	 * @return string One of the values in {@see Templates::TYPES}.
	 */
	public static function type( $type ) {
		$type = is_string( $type ) ? trim( $type ) : '';

		return in_array( $type, self::TYPES, true ) ? $type : 'wp_template';
	}

	/**
	 * Splits a template id into its theme and slug.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $id Raw id, for example `twentytwentyfive//home`.
	 * @return array{theme: string, slug: string}|null Null when the id is malformed.
	 */
	public static function parse_id( $id ) {
		$id = is_string( $id ) ? trim( $id ) : '';

		if ( '' === $id || false === strpos( $id, '//' ) ) {
			return null;
		}

		$parts = explode( '//', $id, 2 );
		$theme = trim( $parts[0] );
		$slug  = trim( $parts[1] );

		if ( '' === $theme || '' === $slug ) {
			return null;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_.\-]+$/', $theme ) || ! preg_match( '#^[A-Za-z0-9_/\-]+$#', $slug ) ) {
			return null;
		}

		return array(
			'theme' => $theme,
			'slug'  => $slug,
		);
	}

	/**
	 * Loads one template.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id   Template id.
	 * @param string $type Template type.
	 * @return WP_Block_Template|null
	 */
	public static function get( $id, $type ) {
		$template = get_block_template( (string) $id, self::type( $type ) );

		return $template instanceof WP_Block_Template ? $template : null;
	}

	/**
	 * Every template of one type, theme files and database copies merged.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Template type.
	 * @return array<int, WP_Block_Template>
	 */
	public static function all( $type ) {
		$templates = get_block_templates( array(), self::type( $type ) );
		$clean     = array();

		foreach ( (array) $templates as $template ) {
			if ( $template instanceof WP_Block_Template ) {
				$clean[] = $template;
			}
		}

		usort(
			$clean,
			static function ( WP_Block_Template $a, WP_Block_Template $b ) {
				return strcmp( (string) $a->slug, (string) $b->slug );
			}
		);

		return $clean;
	}

	/**
	 * Fingerprints a template's stored content.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Block_Template|null $template Template, or null for "does not exist".
	 * @return string
	 */
	public static function fingerprint( $template ) {
		$content = $template instanceof WP_Block_Template ? (string) $template->content : '';

		return Block_Markup::fingerprint( $content );
	}

	/**
	 * Flattens a template into a schema safe array.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Block_Template $template     Template to describe.
	 * @param bool              $with_content Optional. Whether to include the markup and
	 *                                        its block summary. Default false.
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Block_Template $template, $with_content = false ) {
		/*
		 * `WP_Block_Template::$modified` carries `post_modified`, which is site local
		 * time, so the GMT column is read from the post instead. Templates that come
		 * straight from a theme file have no post and no modification date.
		 */
		$modified = '';

		if ( null !== $template->wp_id ) {
			$post = get_post( (int) $template->wp_id );

			if ( null !== $post ) {
				$modified = Time::iso_from_mysql( (string) $post->post_modified_gmt );
			}
		}

		$item = array(
			'id'             => (string) $template->id,
			'type'           => (string) $template->type,
			'theme'          => (string) $template->theme,
			'slug'           => (string) $template->slug,
			'title'          => (string) $template->title,
			'description'    => (string) $template->description,
			'source'         => (string) $template->source,
			'origin'         => null === $template->origin ? null : (string) $template->origin,
			'plugin'         => null === $template->plugin ? null : (string) $template->plugin,
			'area'           => null === $template->area ? null : (string) $template->area,
			'status'         => (string) $template->status,
			'has_theme_file' => (bool) $template->has_theme_file,
			'is_custom'      => (bool) $template->is_custom,
			'post_id'        => null === $template->wp_id ? null : (int) $template->wp_id,
			'modified'       => '' === $modified ? null : $modified,
			'fingerprint'    => self::fingerprint( $template ),
		);

		if ( $with_content ) {
			$summary             = Block_Markup::summary( (string) $template->content );
			$item['content']     = (string) $template->content;
			$item['block_count'] = (int) $summary['block_count'];
			$item['blocks']      = $summary['blocks'];
		}

		return $item;
	}

	/**
	 * Schema for one template as {@see Templates::to_array()} returns it.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $with_content Optional. Whether the markup and block summary are
	 *                           part of the item. Default false.
	 * @return array<string, mixed>
	 */
	public static function item_schema( $with_content = false ) {
		$props = array(
			'id'             => array(
				'type'        => 'string',
				'description' => __( 'Template id, theme slug and template slug joined by two slashes.', 'super-abilities' ),
			),
			'type'           => array(
				'type'        => 'string',
				'description' => __( 'Either wp_template or wp_template_part.', 'super-abilities' ),
			),
			'theme'          => array(
				'type'        => 'string',
				'description' => __( 'Stylesheet slug the template belongs to.', 'super-abilities' ),
			),
			'slug'           => array(
				'type'        => 'string',
				'description' => __( 'Template slug, for example home or single-post.', 'super-abilities' ),
			),
			'title'          => array( 'type' => 'string' ),
			'description'    => array( 'type' => 'string' ),
			'source'         => array(
				'type'        => 'string',
				'description' => __( 'Where the content comes from: theme, custom for a database override, or plugin.', 'super-abilities' ),
			),
			'origin'         => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Where a customized template originally came from.', 'super-abilities' ),
			),
			'plugin'         => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Plugin that registered the template, when one did.', 'super-abilities' ),
			),
			'area'           => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Template part area, for example header or footer. Null for templates.', 'super-abilities' ),
			),
			'status'         => array( 'type' => 'string' ),
			'has_theme_file' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether a file in the theme backs this template, so it can be reset.', 'super-abilities' ),
			),
			'is_custom'      => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this is a user created template rather than one of the theme\'s own.', 'super-abilities' ),
			),
			'post_id'        => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'Id of the database post holding the override, when there is one.', 'super-abilities' ),
			),
			'modified'       => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'When the override was last saved.', 'super-abilities' ),
			),
			'fingerprint'    => Schema::fingerprint( __( 'Fingerprint of the template content.', 'super-abilities' ) ),
		);

		if ( $with_content ) {
			$props['content']     = array(
				'type'        => 'string',
				'description' => __( 'The block markup of the template.', 'super-abilities' ),
			);
			$props['block_count'] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
			$props['blocks']      = self::blocks_schema();
		}

		return Schema::object( $props, array_keys( $props ) );
	}

	/**
	 * Schema for a block name tally.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function blocks_schema() {
		return array(
			'type'        => 'array',
			'description' => __( 'How many times each block type appears, including nested blocks.', 'super-abilities' ),
			'items'       => Schema::object(
				array(
					'name'  => array( 'type' => 'string' ),
					'count' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				array( 'name', 'count' )
			),
		);
	}

	/**
	 * Creates the database override for a template that only exists in the theme.
	 *
	 * Mirrors what `WP_REST_Templates_Controller::prepare_item_for_database()` does
	 * for a create request: a published post of the template type, named after the
	 * slug, attached to the theme through the `wp_theme` taxonomy, and for template
	 * parts also to an area term.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $args {
	 *     Template to create.
	 *
	 *     @type string $type        Template post type.
	 *     @type string $theme       Theme stylesheet the template belongs to.
	 *     @type string $slug        Template slug.
	 *     @type string $content     Block markup.
	 *     @type string $title       Optional. Template title.
	 *     @type string $description Optional. Template description.
	 *     @type string $area        Optional. Template part area.
	 * }
	 * @return int|WP_Error New post id.
	 */
	public static function create_override( array $args ) {
		$type  = self::type( isset( $args['type'] ) ? $args['type'] : '' );
		$theme = isset( $args['theme'] ) ? (string) $args['theme'] : get_stylesheet();
		$slug  = isset( $args['slug'] ) ? (string) $args['slug'] : '';

		if ( '' === $slug ) {
			return Error::make( 'invalid_input', __( 'A template slug is required.', 'super-abilities' ) );
		}

		$post = array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => isset( $args['title'] ) ? (string) $args['title'] : $slug,
			'post_excerpt' => isset( $args['description'] ) ? (string) $args['description'] : '',
			'post_content' => isset( $args['content'] ) ? (string) $args['content'] : '',
			'tax_input'    => array( self::THEME_TAXONOMY => $theme ),
		);

		if ( 'wp_template_part' === $type ) {
			$post['tax_input'][ self::AREA_TAXONOMY ] = self::area( isset( $args['area'] ) ? $args['area'] : '' );
		}

		$post_id = wp_insert_post( $post, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		/*
		 * `tax_input` is skipped when the user cannot assign terms in the taxonomy, and
		 * a template post without its `wp_theme` term is invisible to core. Setting the
		 * terms again is cheap and makes the outcome independent of that check.
		 */
		self::ensure_term( $post_id, self::THEME_TAXONOMY, $theme );

		if ( 'wp_template_part' === $type ) {
			self::ensure_term( $post_id, self::AREA_TAXONOMY, self::area( isset( $args['area'] ) ? $args['area'] : '' ) );
		}

		return $post_id;
	}

	/**
	 * Normalizes a template part area.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $area Raw area.
	 * @return string
	 */
	public static function area( $area ) {
		$fallback = defined( 'WP_TEMPLATE_PART_AREA_UNCATEGORIZED' ) ? (string) constant( 'WP_TEMPLATE_PART_AREA_UNCATEGORIZED' ) : 'uncategorized';
		$area     = is_string( $area ) ? trim( $area ) : '';

		if ( '' === $area ) {
			return $fallback;
		}

		if ( function_exists( '_filter_block_template_part_area' ) ) {
			return (string) _filter_block_template_part_area( $area );
		}

		return $area;
	}

	/**
	 * Attaches a term to a post when it is not attached already.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $term     Term slug or name.
	 * @return void
	 */
	protected static function ensure_term( $post_id, $taxonomy, $term ) {
		if ( ! taxonomy_exists( $taxonomy ) || '' === $term ) {
			return;
		}

		$current = get_the_terms( $post_id, $taxonomy );

		if ( is_array( $current ) && ! empty( $current ) ) {
			return;
		}

		wp_set_post_terms( (int) $post_id, array( $term ), $taxonomy );
	}
}
