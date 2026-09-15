<?php
/**
 * Removes blocks from a post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes blocks by path, deepest first so the remaining paths stay valid.
 *
 * @since 0.2.0
 */
class Blocks_Remove extends Abstract_Blocks_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-remove';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Remove blocks', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the block at a path, or every block in a list of paths, together with everything nested inside them. Several paths are removed deepest and last first, so that the indexes of the paths still to go are not invalidated half way through; a path listed together with one of its own ancestors therefore costs nothing. Nothing is trashed, because a block is not a post: the recovery path is the revision the write leaves behind, whose id comes back. Pass the fingerprint you read to be told with a 409 if somebody edited the post since, and dry_run to see what would go without writing.', 'super-abilities' );
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
					'path'  => self::path_schema( __( 'Path of the block to remove.', 'super-abilities' ) ),
					'paths' => array(
						'type'        => 'array',
						'maxItems'    => 200,
						'items'       => self::path_schema( __( 'Path of a block to remove.', 'super-abilities' ) ),
						'description' => __( 'Paths of several blocks to remove. Use instead of path.', 'super-abilities' ),
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
					'removed'       => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'How many of the requested blocks were removed.', 'super-abilities' ),
					),
					'removed_paths' => array(
						'type'        => 'array',
						'description' => __( 'The paths that were removed, in removal order.', 'super-abilities' ),
						'items'       => self::path_schema( __( 'Path of a removed block.', 'super-abilities' ) ),
					),
					'missing'       => array(
						'type'        => 'array',
						'description' => __( 'Requested paths that addressed nothing, which is not an error.', 'super-abilities' ),
						'items'       => self::path_schema( __( 'Path that addressed nothing.', 'super-abilities' ) ),
					),
					'count'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'How many blocks the post holds now, at every level.', 'super-abilities' ),
					),
				)
			),
			array_merge( self::write_output_required(), array( 'removed', 'removed_paths', 'missing', 'count' ) )
		);
	}

	/**
	 * Removes the blocks.
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

		$requested = array();

		if ( isset( $input['path'] ) ) {
			$requested[] = trim( (string) $input['path'] );
		}

		if ( isset( $input['paths'] ) && is_array( $input['paths'] ) ) {
			foreach ( $input['paths'] as $path ) {
				if ( is_string( $path ) ) {
					$requested[] = trim( $path );
				}
			}
		}

		$requested = array_values(
			array_unique(
				array_filter(
					$requested,
					static function ( $path ) {
						return '' !== $path;
					}
				)
			)
		);

		if ( array() === $requested ) {
			return $this->error( 'invalid_input', __( 'Pass path or paths.', 'super-abilities' ), 400 );
		}

		foreach ( $requested as $path ) {
			if ( ! Block_Tree::is_path( $path ) ) {
				return $this->error(
					'invalid_input',
					__( 'One of the paths is not a block path. A path is zero based indexes joined with dots, for example 2.1.', 'super-abilities' ),
					400,
					array( 'path' => $path )
				);
			}
		}

		$content_before = (string) $post->post_content;
		$tree           = Block_Tree::parse( $content_before );

		$stale = $this->tree_guard( $input, $tree );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$missing = array();

		foreach ( $requested as $path ) {
			if ( null === $tree->get( $path ) ) {
				$missing[] = $path;
			}
		}

		$removed = $tree->remove_many( $requested );
		$result  = $this->commit( $post, $tree, $content_before, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['removed']       = count( $removed );
		$result['removed_paths'] = $removed;
		$result['missing']       = $missing;
		$result['count']         = $tree->size();

		return $result;
	}
}
