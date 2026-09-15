<?php
/**
 * Moves a block within a post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Relocates one block, and its whole subtree, somewhere else in the same post.
 *
 * @since 0.2.0
 */
class Blocks_Move extends Abstract_Blocks_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-move';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Move a block', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Takes the block at one path, with everything nested inside it, and puts it somewhere else in the same post: before or after the block at the target path, or append or prepend to put it inside that block as its last or first child. Leave the target out to move it to the top level of the post. Moving a block into its own subtree is refused, because that would be asking the tree to contain itself, and so is moving a block onto itself. The block comes back with its new path, which is not simply the target path: removing the block first shifts the indexes of everything that followed it. The write leaves a revision behind, whose id comes back.', 'super-abilities' );
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
					'from'     => self::path_schema( __( 'Path of the block to move.', 'super-abilities' ) ),
					'to'       => array(
						'type'        => 'string',
						'pattern'     => Block_Tree::PATH_OR_ROOT_PATTERN,
						'description' => __( 'Path of the target block. Omit, or pass an empty string, to target the post itself.', 'super-abilities' ),
					),
					'position' => array(
						'type'        => 'string',
						'enum'        => Block_Tree::POSITIONS,
						'default'     => 'after',
						'description' => __( 'Where to put the block relative to the target: before, after, append or prepend. Default after.', 'super-abilities' ),
					),
				)
			),
			array( 'post_id', 'from' )
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
					'from'     => self::path_schema( __( 'The path the block was at.', 'super-abilities' ) ),
					'to'       => array(
						'type'        => 'string',
						'description' => __( 'The target path that was given, or an empty string for the post itself.', 'super-abilities' ),
					),
					'position' => array( 'type' => 'string' ),
					'path'     => self::path_schema( __( 'The path the block is at now.', 'super-abilities' ) ),
					'block'    => self::flat_schema(),
				)
			),
			array_merge( self::write_output_required(), array( 'from', 'to', 'position', 'path', 'block' ) )
		);
	}

	/**
	 * Moves the block.
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

		$from = $this->read_path( $input, 'from' );

		if ( is_wp_error( $from ) ) {
			return $from;
		}

		if ( '' === $from ) {
			return $this->error( 'invalid_input', __( 'A from path is required.', 'super-abilities' ), 400 );
		}

		$to = $this->read_path( $input, 'to' );

		if ( is_wp_error( $to ) ) {
			return $to;
		}

		$position = Block_Tree::normalize_position( isset( $input['position'] ) ? $input['position'] : 'after' );

		if ( '' !== $to && Block_Tree::is_within( $to, $from ) ) {
			return $this->error(
				'invalid_move',
				__( 'A block cannot be moved into its own subtree, or onto itself.', 'super-abilities' ),
				400,
				array(
					'from' => $from,
					'to'   => $to,
				)
			);
		}

		$content_before = (string) $post->post_content;
		$tree           = Block_Tree::parse( $content_before );

		$stale = $this->tree_guard( $input, $tree );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		if ( null === $tree->get( $from ) ) {
			return $this->error(
				'not_found',
				__( 'No block lives at the from path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $from )
			);
		}

		if ( '' !== $to && null === $tree->get( $to ) ) {
			return $this->error(
				'not_found',
				__( 'No block lives at the to path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $to )
			);
		}

		$path = $tree->move( $from, '' === $to ? null : $to, $position );

		if ( null === $path ) {
			return $this->error(
				'not_found',
				__( 'The block could not be moved to that path.', 'super-abilities' ),
				404,
				array(
					'from' => $from,
					'to'   => $to,
				)
			);
		}

		$result = $this->commit( $post, $tree, $content_before, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$summary = $tree->summary( $path );

		$result['from']     = $from;
		$result['to']       = $to;
		$result['position'] = $position;
		$result['path']     = $path;
		$result['block']    = isset( $summary[0] ) ? $summary[0] : array(
			'path'              => $path,
			'name'              => Block_Tree::FREEFORM,
			'attrs'             => array(),
			'inner_html_length' => 0,
			'inner_blocks'      => 0,
			'fingerprint'       => $tree->fingerprint_of( $path ),
		);

		return $result;
	}
}
