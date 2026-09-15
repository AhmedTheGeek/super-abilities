<?php
/**
 * Finds blocks inside a post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Blocks\Post_Blocks;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Locates the blocks of a post by name, attribute or text, and reports their paths.
 *
 * @since 0.2.0
 */
class Blocks_Find extends Abstract_Blocks_Ability {

	/**
	 * Longest excerpt returned per match.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const EXCERPT_LENGTH = 160;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-find';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Find blocks in a post', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Searches the block tree of a post and returns the path of every block that matches, so that an edit can be aimed without reading the whole post first. Match by block name, either exactly or with a trailing star as in core/*, by an attribute having a given value, and by a substring of the visible text of the block, which is its markup with the tags stripped. All the conditions given must hold. The paths come back in document order and stay valid until the post content changes, which the returned fingerprint tells you.', 'super-abilities' );
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
	 * Per-object permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	public function permission( array $input ) {
		return $this->can_read_post( $input );
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
				'post_id' => Schema::id( __( 'Id of the post to search.', 'super-abilities' ) ),
				'name'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Block name to match. A trailing star matches a prefix, so core/* matches every core block and * matches every block.', 'super-abilities' ),
				),
				'attr'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Name of a block attribute that must be present. Combine with value to require a specific value.', 'super-abilities' ),
				),
				'value'   => array(
					'type'        => array( 'string', 'number', 'boolean' ),
					'description' => __( 'Value the attribute must equal, compared as text. Only used together with attr.', 'super-abilities' ),
				),
				'text'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Substring the visible text of the block must contain, matched without regard to case.', 'super-abilities' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
					'description' => __( 'Largest number of matches to return. Default 100.', 'super-abilities' ),
				),
			),
			array( 'post_id', 'name' )
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
				'post_id'     => Schema::id( __( 'Id of the post that was searched.', 'super-abilities' ) ),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the tree the paths belong to.', 'super-abilities' ) ),
				'count'       => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many matches are returned.', 'super-abilities' ),
				),
				'truncated'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether matches were left out because the limit was reached.', 'super-abilities' ),
				),
				'matches'     => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'path'    => self::path_schema( __( 'Dotted, zero based path of the matching block.', 'super-abilities' ) ),
							'name'    => array( 'type' => 'string' ),
							'attrs'   => array(
								'type'        => 'object',
								'description' => __( 'The full attributes of the matching block.', 'super-abilities' ),
							),
							'excerpt' => array(
								'type'        => 'string',
								'description' => __( 'The start of the visible text of the block, with the tags stripped.', 'super-abilities' ),
							),
						),
						array( 'path', 'name', 'attrs', 'excerpt' )
					),
				),
			),
			array( 'post_id', 'fingerprint', 'count', 'truncated', 'matches' )
		);
	}

	/**
	 * Runs the search.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$post = $this->open( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';

		if ( '' === $name ) {
			return $this->error( 'invalid_input', __( 'A block name to match is required. Pass * to match every block.', 'super-abilities' ), 400 );
		}

		$attr  = isset( $input['attr'] ) ? trim( (string) $input['attr'] ) : '';
		$text  = isset( $input['text'] ) ? (string) $input['text'] : '';
		$limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 100;
		$value = array_key_exists( 'value', $input ) ? $input['value'] : null;

		$tree      = Post_Blocks::tree( $post );
		$matches   = array();
		$truncated = false;

		foreach ( $tree->summary() as $entry ) {
			$path  = (string) $entry['path'];
			$block = $tree->get( $path );

			if ( null === $block ) {
				continue;
			}

			if ( ! self::name_matches( (string) $entry['name'], $name ) ) {
				continue;
			}

			if ( '' !== $attr && ! self::attr_matches( $block['attrs'], $attr, $value ) ) {
				continue;
			}

			$plain = trim( wp_strip_all_tags( (string) $block['innerHTML'] ) );

			if ( '' !== $text && false === stripos( $plain, $text ) ) {
				continue;
			}

			if ( count( $matches ) >= $limit ) {
				$truncated = true;

				break;
			}

			$matches[] = array(
				'path'    => $path,
				'name'    => (string) $entry['name'],
				'attrs'   => $block['attrs'],
				'excerpt' => self::excerpt( $plain ),
			);
		}

		return array(
			'post_id'     => (int) $post->ID,
			'fingerprint' => $tree->fingerprint(),
			'count'       => count( $matches ),
			'truncated'   => $truncated,
			'matches'     => $matches,
		);
	}

	/**
	 * Whether a block name matches a pattern with an optional trailing star.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name    Block name.
	 * @param string $pattern Pattern to match.
	 * @return bool
	 */
	public static function name_matches( $name, $pattern ) {
		$name    = strtolower( (string) $name );
		$pattern = strtolower( trim( (string) $pattern ) );

		if ( '*' === $pattern || '' === $pattern ) {
			return true;
		}

		if ( '*' === substr( $pattern, -1 ) ) {
			$prefix = substr( $pattern, 0, -1 );

			return '' === $prefix || 0 === strpos( $name, $prefix );
		}

		// A bare namespace such as `core` matches every block in it.
		if ( false === strpos( $pattern, '/' ) && Block_Tree::FREEFORM !== $pattern ) {
			return $name === $pattern || 0 === strpos( $name, $pattern . '/' );
		}

		return $name === $pattern;
	}

	/**
	 * Whether a block has an attribute, optionally with a given value.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @param string               $attr  Attribute name.
	 * @param mixed                $value Value to compare, or null to only require presence.
	 * @return bool
	 */
	public static function attr_matches( array $attrs, $attr, $value ) {
		if ( ! array_key_exists( $attr, $attrs ) ) {
			return false;
		}

		if ( null === $value ) {
			return true;
		}

		$actual = $attrs[ $attr ];

		if ( is_array( $actual ) || is_object( $actual ) ) {
			return false;
		}

		return self::as_text( $actual ) === self::as_text( $value );
	}

	/**
	 * Renders a scalar as the text the comparison uses.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Scalar value.
	 * @return string
	 */
	protected static function as_text( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '';
		}

		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Cuts the plain text of a block down to an excerpt.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	protected static function excerpt( $plain ) {
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', (string) $plain ) );

		return (string) wp_html_excerpt( $plain, self::EXCERPT_LENGTH, '&hellip;' );
	}
}
