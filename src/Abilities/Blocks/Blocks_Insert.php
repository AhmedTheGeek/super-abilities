<?php
/**
 * Inserts blocks into a post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Guard;
use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds one or more blocks next to, or inside, an existing block.
 *
 * @since 0.2.0
 */
class Blocks_Insert extends Abstract_Blocks_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-insert';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Insert blocks', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Adds blocks to a post, either as one block object or as a markup string that may hold several blocks, which are then inserted in the order they appear. The anchor is a block path together with a position: before or after put them next to that block, append and prepend put them inside it, first or last among its children. Leave the path out to anchor on the post itself, where append adds to the end and prepend to the start. Markup holding a script tag or a PHP open tag, and attributes holding a javascript URL, are refused, and with strict a block name no block type has registered is refused too. The paths the blocks ended up at come back, along with the revision the write created; existing paths after the insertion point shift, so read the tree again before the next edit.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
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
					'path'     => array(
						'type'        => 'string',
						'pattern'     => Block_Tree::PATH_OR_ROOT_PATTERN,
						'description' => __( 'Path of the anchor block. Omit, or pass an empty string, to anchor on the post itself.', 'super-abilities' ),
					),
					'position' => array(
						'type'        => 'string',
						'enum'        => Block_Tree::POSITIONS,
						'default'     => 'after',
						'description' => __( 'Where to put the blocks relative to the anchor: before, after, append or prepend. Default after.', 'super-abilities' ),
					),
					'block'    => self::block_schema( __( 'A single block to insert.', 'super-abilities' ) ),
					'markup'   => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Block markup to parse and insert. It may hold several blocks; the whitespace between them is dropped.', 'super-abilities' ),
					),
					'strict'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Refuse block names that no block type has registered on this site. Default false.', 'super-abilities' ),
					),
				)
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
			array_merge(
				self::write_output_props(),
				array(
					'position' => array( 'type' => 'string' ),
					'anchor'   => array(
						'type'        => 'string',
						'description' => __( 'The anchor path that was used, or an empty string for the post itself.', 'super-abilities' ),
					),
					'paths'    => array(
						'type'        => 'array',
						'description' => __( 'Paths the inserted blocks ended up at, in order.', 'super-abilities' ),
						'items'       => self::path_schema( __( 'Path of an inserted block.', 'super-abilities' ) ),
					),
					'inserted' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'How many top level blocks were inserted.', 'super-abilities' ),
					),
					'blocks'   => array(
						'type'        => 'array',
						'description' => __( 'A flat description of each inserted block.', 'super-abilities' ),
						'items'       => self::flat_schema(),
					),
				)
			),
			array_merge( self::write_output_required(), array( 'position', 'anchor', 'paths', 'inserted', 'blocks' ) )
		);
	}

	/**
	 * Inserts the blocks.
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

		$anchor = $this->read_path( $input );

		if ( is_wp_error( $anchor ) ) {
			return $anchor;
		}

		$has_block  = isset( $input['block'] ) && is_array( $input['block'] );
		$has_markup = isset( $input['markup'] ) && '' !== trim( (string) $input['markup'] );

		if ( $has_block === $has_markup ) {
			return $this->error(
				'invalid_input',
				__( 'Pass exactly one of block or markup.', 'super-abilities' ),
				400
			);
		}

		$strict = ! empty( $input['strict'] );

		if ( $has_markup ) {
			$markup = (string) $input['markup'];
			$unsafe = Block_Guard::check_markup( $markup );

			if ( $unsafe instanceof WP_Error ) {
				return $unsafe;
			}

			$incoming = Block_Tree::parse_fragment( $markup );

			if ( array() === $incoming ) {
				return $this->error(
					'invalid_input',
					__( 'The markup parsed into no blocks at all.', 'super-abilities' ),
					400
				);
			}
		} else {
			$incoming = array( Block_Tree::from_input( $input['block'] ) );
		}

		$unsafe = Block_Guard::check_blocks( $incoming, $strict );

		if ( $unsafe instanceof WP_Error ) {
			return $unsafe;
		}

		$position       = Block_Tree::normalize_position( isset( $input['position'] ) ? $input['position'] : 'after' );
		$content_before = (string) $post->post_content;
		$tree           = Block_Tree::parse( $content_before );

		$stale = $this->tree_guard( $input, $tree );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		if ( '' !== $anchor && null === $tree->get( $anchor ) ) {
			return $this->error(
				'not_found',
				__( 'No block lives at that path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $anchor )
			);
		}

		$paths = $tree->insert_many( $anchor, $incoming, $position );

		if ( null === $paths ) {
			return $this->error(
				'not_found',
				__( 'No block lives at that path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $anchor )
			);
		}

		$result = $this->commit( $post, $tree, $content_before, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$blocks = array();

		foreach ( $paths as $path ) {
			$summary = $tree->summary( $path );

			if ( isset( $summary[0] ) ) {
				$blocks[] = $summary[0];
			}
		}

		$result['position'] = $position;
		$result['anchor']   = $anchor;
		$result['paths']    = $paths;
		$result['inserted'] = count( $paths );
		$result['blocks']   = $blocks;

		return $result;
	}
}
