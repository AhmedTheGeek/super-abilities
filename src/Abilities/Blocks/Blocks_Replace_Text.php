<?php
/**
 * Search and replace inside block text.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Guard;
use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Blocks\Text_Replacer;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces text in the visible content of a post's blocks, leaving markup alone.
 *
 * @since 0.2.0
 */
class Blocks_Replace_Text extends Abstract_Blocks_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-replace-text';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Replace text in blocks', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Replaces text throughout the blocks of a post, or of one subtree of it, without disturbing the structure. Only the text that sits outside an HTML tag is visited, so a tag name, a class, an href and the JSON attributes of a block are never rewritten, and neither are the block comment delimiters the editor needs to parse the post; searching for "p" will not turn every paragraph into something else. Matching ignores case unless case_sensitive is set. With regex the search is a PCRE body without delimiters, at most 200 characters long, and $1 style backreferences work in the replacement; a pattern that fails to compile, or that gives up against the content, fails the whole call rather than writing half of it. The paths whose text changed come back with the number of replacements, and the write leaves a revision behind.', 'super-abilities' );
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
	 * Per-object permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	public function permission( array $input ) {
		return $this->can_edit_post( $input );
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
			array_merge(
				self::write_input_props(),
				array(
					'search'         => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Text to look for, or a PCRE body without delimiters when regex is true.', 'super-abilities' ),
					),
					'replace'        => array(
						'type'        => 'string',
						'description' => __( 'Text to put in its place. May be empty, which deletes the matches.', 'super-abilities' ),
					),
					'path'           => self::path_schema( __( 'Limit the replacement to this block and its subtree. Omit for the whole post.', 'super-abilities' ) ),
					'case_sensitive' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Match case exactly. Default false.', 'super-abilities' ),
					),
					'regex'          => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Treat search as a regular expression body, without delimiters or modifiers. Default false.', 'super-abilities' ),
					),
				)
			),
			array( 'post_id', 'search', 'replace' )
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
			array_merge(
				self::write_output_props(),
				array(
					'path'          => array(
						'type'        => 'string',
						'description' => __( 'The subtree that was searched, or an empty string for the whole post.', 'super-abilities' ),
					),
					'replacements'  => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'How many matches were replaced.', 'super-abilities' ),
					),
					'changed_paths' => array(
						'type'        => 'array',
						'description' => __( 'Paths of the blocks whose own text changed.', 'super-abilities' ),
						'items'       => self::path_schema( __( 'Path of a block whose text changed.', 'super-abilities' ) ),
					),
				)
			),
			array_merge( self::write_output_required(), array( 'path', 'replacements', 'changed_paths' ) )
		);
	}

	/**
	 * Runs the replacement.
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

		$locked = $this->lock_guard( $post, $input );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$path = $this->read_path( $input );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$search  = isset( $input['search'] ) ? (string) $input['search'] : '';
		$replace = isset( $input['replace'] ) ? (string) $input['replace'] : '';

		$unsafe = Block_Guard::check_markup( $replace );

		if ( $unsafe instanceof WP_Error ) {
			return $unsafe;
		}

		$replacer = new Text_Replacer( $search, $replace, ! empty( $input['case_sensitive'] ), ! empty( $input['regex'] ) );
		$invalid  = $replacer->prepare();

		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$content_before = (string) $post->post_content;
		$tree           = Block_Tree::parse( $content_before );

		$stale = $this->tree_guard( $input, $tree );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		if ( '' !== $path && null === $tree->get( $path ) ) {
			return $this->error(
				'not_found',
				__( 'No block lives at that path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $path )
			);
		}

		$changed = $tree->map_text(
			'' === $path ? null : $path,
			array( $replacer, 'apply' )
		);

		$failed = $replacer->error();

		if ( $failed instanceof WP_Error ) {
			return $failed;
		}

		if ( null === $changed ) {
			return $this->error(
				'not_found',
				__( 'No block lives at that path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $path )
			);
		}

		$result = $this->commit( $post, $tree, $content_before, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['path']          = $path;
		$result['replacements']  = $replacer->total();
		$result['changed_paths'] = $changed;

		return $result;
	}
}
