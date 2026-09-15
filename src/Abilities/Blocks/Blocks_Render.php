<?php
/**
 * Renders a block preview.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Support\Schema;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the blocks of a post, or an arbitrary markup fragment, for preview.
 *
 * @since 0.2.0
 */
class Blocks_Render extends Abstract_Blocks_Ability {

	/**
	 * Largest amount of rendered output returned, in bytes.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_BYTES = 204800;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-render';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Render a block preview', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Renders the blocks of a post, one subtree of them, or a markup fragment you pass instead, and returns the resulting HTML or its plain text. Rendering runs do_blocks inside the post loop, so dynamic blocks such as a query loop or a post title resolve against that post the way they would on the front end, and the result is then run through wp_kses_post. Shortcodes are short circuited rather than executed, because a preview should not have side effects. This is a preview and not the front end: the theme, its templates and the the_content filters are not involved, so what comes back is the block output alone. Output is capped at 200 KB and truncated is reported when the cap was hit.', 'super-abilities' );
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
				'post_id' => Schema::id( __( 'Id of the post that provides the rendering context.', 'super-abilities' ) ),
				'path'    => self::path_schema( __( 'Render only this block and its subtree. Omit for the whole post.', 'super-abilities' ) ),
				'markup'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Render this block markup instead of anything from the post. The post is still used as the rendering context.', 'super-abilities' ),
				),
				'format'  => array(
					'type'        => 'string',
					'enum'        => array( 'html', 'text' ),
					'default'     => 'html',
					'description' => __( 'Whether to return the rendered HTML or its plain text, line breaks kept. Default html.', 'super-abilities' ),
				),
			),
			array( 'post_id' )
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
				'post_id'   => Schema::id( __( 'Id of the post used as the rendering context.', 'super-abilities' ) ),
				'path'      => array(
					'type'        => 'string',
					'description' => __( 'The subtree that was rendered, or an empty string for the whole post or for passed markup.', 'super-abilities' ),
				),
				'format'    => array( 'type' => 'string' ),
				'source'    => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'markup' ),
					'description' => __( 'Whether the blocks came from the post or from the markup that was passed.', 'super-abilities' ),
				),
				'html'      => array(
					'type'        => 'string',
					'description' => __( 'The rendered HTML, sanitized with wp_kses_post. Empty when text was asked for.', 'super-abilities' ),
				),
				'text'      => array(
					'type'        => 'string',
					'description' => __( 'The rendered output with the tags stripped. Empty when html was asked for.', 'super-abilities' ),
				),
				'length'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Number of bytes returned.', 'super-abilities' ),
				),
				'truncated' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the output was cut short at 200 KB.', 'super-abilities' ),
				),
			),
			array( 'post_id', 'path', 'format', 'source', 'html', 'text', 'length', 'truncated' )
		);
	}

	/**
	 * Renders the preview.
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

		$path = $this->read_path( $input );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$format = ( isset( $input['format'] ) && 'text' === $input['format'] ) ? 'text' : 'html';
		$markup = isset( $input['markup'] ) ? (string) $input['markup'] : '';
		$source = 'post';

		if ( '' !== trim( $markup ) ) {
			$source = 'markup';
			$path   = '';
		} else {
			$tree    = Block_Tree::parse( $post->post_content );
			$subtree = $tree->serialize_path( '' === $path ? null : $path );

			if ( null === $subtree ) {
				return $this->error(
					'not_found',
					__( 'No block lives at that path in this post.', 'super-abilities' ),
					404,
					array( 'path' => $path )
				);
			}

			$markup = $subtree;
		}

		$rendered = self::render( $post, $markup );

		if ( 'text' === $format ) {
			$output = trim( wp_strip_all_tags( $rendered ) );
			$html   = '';
			$text   = $output;
		} else {
			$output = wp_kses_post( $rendered );
			$html   = $output;
			$text   = '';
		}

		$truncated = strlen( $output ) > self::MAX_BYTES;

		if ( $truncated ) {
			$output = substr( $output, 0, self::MAX_BYTES );

			if ( 'text' === $format ) {
				$text = $output;
			} else {
				$html = $output;
			}
		}

		return array(
			'post_id'   => (int) $post->ID,
			'path'      => $path,
			'format'    => $format,
			'source'    => $source,
			'html'      => $html,
			'text'      => $text,
			'length'    => strlen( $output ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Runs `do_blocks()` against a post, with shortcodes short circuited.
	 *
	 * The global post is swapped for the target post and put back afterwards, so a
	 * dynamic block resolves against it exactly as it would inside the loop, and no
	 * other code in the request sees a different post than it did before.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post $post   Post providing the rendering context.
	 * @param string  $markup Block markup to render.
	 * @return string
	 */
	protected static function render( WP_Post $post, $markup ) {
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The previous value is restored below; dynamic blocks need the post in the global.

		setup_postdata( $post );
		add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'skip_shortcode' ), 99 );

		try {
			$rendered = do_blocks( (string) $markup );
		} finally {
			remove_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'skip_shortcode' ), 99 );
			wp_reset_postdata();

			if ( null === $previous ) {
				unset( $GLOBALS['post'] );
			} else {
				$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the value this method replaced.
			}
		}

		return (string) $rendered;
	}

	/**
	 * Short circuits every shortcode so that a preview has no side effects.
	 *
	 * @since 0.2.0
	 *
	 * @return string Always an empty string, which stops `do_shortcode_tag()`.
	 */
	public static function skip_shortcode() {
		return '';
	}
}
