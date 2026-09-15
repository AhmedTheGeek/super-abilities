<?php
/**
 * Reads the block tree of a post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Post_Blocks;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the parsed block tree of a post, addressed by path.
 *
 * @since 0.2.0
 */
class Blocks_Read extends Abstract_Blocks_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-read';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Read a post block tree', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Parses the content of a post into its block tree and gives every block an address: the zero based index of each list it is reached through, joined with dots, so 0 is the first top level block and 2.1.0 the first child of the second child of the third one. Those paths are what every other block ability takes, and they are stable for as long as the tree is, which the returned fingerprint lets you check. The nested tree is trimmed to depth, three levels by default, and a flat listing of every block comes with it whatever the depth. A post written in the classic editor has no blocks, so it is reported as a single core/freeform fragment at path 0, which the write abilities can edit like any other block. Pass include_raw to get the actual markup of each block, which requires permission to edit the post.', 'super-abilities' );
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
		if ( ! empty( $input['include_raw'] ) ) {
			return $this->can_edit_post( $input );
		}

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
				'post_id'       => Schema::id( __( 'Id of the post to read.', 'super-abilities' ) ),
				'path'          => self::path_schema( __( 'Return only this block and its subtree. Omit for the whole post.', 'super-abilities' ) ),
				'depth'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 3,
					'description' => __( 'How many levels of the nested tree to include. 0 for every level. Default 3.', 'super-abilities' ),
				),
				'include_raw'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include the markup of every block as inner_html and inner_content. Requires permission to edit the post. Default false.', 'super-abilities' ),
				),
				'include_attrs' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Include the attributes of every block. Default true.', 'super-abilities' ),
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
				'post_id'     => Schema::id( __( 'Id of the post that was read.', 'super-abilities' ) ),
				'post_type'   => array( 'type' => 'string' ),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Post status, for example publish or draft.', 'super-abilities' ),
				),
				'path'        => array(
					'type'        => 'string',
					'description' => __( 'The subtree that was read, or an empty string for the whole post.', 'super-abilities' ),
				),
				'depth'       => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the whole tree. Pass it back as expected_fingerprint when you write.', 'super-abilities' ) ),
				'count'       => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many blocks the returned subtree holds, at every level.', 'super-abilities' ),
				),
				'blocks'      => array(
					'type'        => 'array',
					'description' => __( 'The nested tree, trimmed to depth. Every node has path, name, inner_blocks_count and inner_blocks, plus attrs and the raw markup when those were asked for.', 'super-abilities' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'path'               => self::path_schema( __( 'Dotted, zero based path of the block.', 'super-abilities' ) ),
							'name'               => array( 'type' => 'string' ),
							'attrs'              => array( 'type' => 'object' ),
							'inner_html'         => array( 'type' => 'string' ),
							'inner_content'      => array(
								'type'  => 'array',
								'items' => array( 'type' => array( 'string', 'null' ) ),
							),
							'inner_blocks_count' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'inner_blocks'       => array( 'type' => 'array' ),
						),
					),
				),
				'flat'        => array(
					'type'        => 'array',
					'description' => __( 'Every block of the subtree in document order, whatever the depth.', 'super-abilities' ),
					'items'       => self::flat_schema(),
				),
			),
			array( 'post_id', 'post_type', 'status', 'path', 'depth', 'fingerprint', 'count', 'blocks', 'flat' )
		);
	}

	/**
	 * Reads the tree.
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

		$tree  = Post_Blocks::tree( $post );
		$depth = isset( $input['depth'] ) ? max( 0, (int) $input['depth'] ) : 3;
		$raw   = ! empty( $input['include_raw'] );
		$attrs = ! isset( $input['include_attrs'] ) || ! empty( $input['include_attrs'] );

		$nodes = $tree->nodes( '' === $path ? null : $path, $depth, $raw, $attrs );

		if ( null === $nodes ) {
			return $this->error(
				'not_found',
				__( 'No block lives at that path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $path )
			);
		}

		$flat = $tree->summary( '' === $path ? null : $path );

		return array(
			'post_id'     => (int) $post->ID,
			'post_type'   => (string) $post->post_type,
			'status'      => (string) $post->post_status,
			'path'        => $path,
			'depth'       => $depth,
			'fingerprint' => $tree->fingerprint(),
			'count'       => count( $flat ),
			'blocks'      => $nodes,
			'flat'        => $flat,
		);
	}
}
