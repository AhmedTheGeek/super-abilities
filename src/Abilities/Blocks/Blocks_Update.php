<?php
/**
 * Updates one block of a post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Blocks\Block_Guard;
use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Blocks\Post_Blocks;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces a block, merges its attributes or rewrites its markup, in place.
 *
 * @since 0.2.0
 */
class Blocks_Update extends Abstract_Blocks_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'blocks-update';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Update a block', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Rewrites the block at one path of a post, in one of three ways: block replaces it outright, children and all; attrs merges into its attributes, where a null value removes an attribute and everything else is left alone; and inner_html swaps its markup while its children stay where they are. Exactly one of the three is accepted per call. Markup holding a script tag or a PHP open tag, and attributes holding a javascript URL, are refused, and with strict a block name no block type has registered is refused too. The write goes through wp_update_post with nothing but the new content, so the post keeps everything else and WordPress stores a revision; the id of that revision comes back. Pass the fingerprint you read to be told with a 409 if somebody edited the post since, and dry_run to see the effect without writing.', 'super-abilities' );
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
					'path'       => self::path_schema( __( 'Path of the block to update.', 'super-abilities' ) ),
					'block'      => self::block_schema( __( 'Replace the whole block with this one.', 'super-abilities' ) ),
					'attrs'      => array(
						'type'        => 'object',
						'description' => __( 'Attributes merged into the existing ones. A null value removes that attribute.', 'super-abilities' ),
					),
					'inner_html' => array(
						'type'        => 'string',
						'description' => __( 'New markup for the block. Its child blocks are kept and re-anchored inside it.', 'super-abilities' ),
					),
					'strict'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Refuse block names that no block type has registered on this site. Default false.', 'super-abilities' ),
					),
				)
			),
			array( 'post_id', 'path' )
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
					'path'  => self::path_schema( __( 'Path of the block that was updated.', 'super-abilities' ) ),
					'block' => self::flat_schema(),
				)
			),
			array_merge( self::write_output_required(), array( 'path', 'block' ) )
		);
	}

	/**
	 * Applies the update.
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

		if ( '' === $path ) {
			return $this->error( 'invalid_input', __( 'A block path is required.', 'super-abilities' ), 400 );
		}

		$modes = array();

		foreach ( array( 'block', 'attrs', 'inner_html' ) as $mode ) {
			if ( isset( $input[ $mode ] ) ) {
				$modes[] = $mode;
			}
		}

		if ( 1 !== count( $modes ) ) {
			return $this->error(
				'invalid_input',
				__( 'Pass exactly one of block, attrs or inner_html.', 'super-abilities' ),
				400,
				array( 'given' => $modes )
			);
		}

		$content_before = (string) $post->post_content;
		$tree           = Block_Tree::parse( $content_before );

		$stale = $this->tree_guard( $input, $tree );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$current = $tree->get( $path );

		if ( null === $current ) {
			return $this->error(
				'not_found',
				__( 'No block lives at that path in this post.', 'super-abilities' ),
				404,
				array( 'path' => $path )
			);
		}

		$strict      = ! empty( $input['strict'] );
		$replacement = $this->build( $modes[0], $input, $current );

		if ( is_wp_error( $replacement ) ) {
			return $replacement;
		}

		$unsafe = Block_Guard::check_block( $replacement, $strict, $path );

		if ( $unsafe instanceof WP_Error ) {
			return $unsafe;
		}

		if ( ! $tree->set( $path, $replacement ) ) {
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

		$summary = $tree->summary( $path );

		$result['path']  = $path;
		$result['block'] = isset( $summary[0] ) ? $summary[0] : array(
			'path'              => $path,
			'name'              => Block_Tree::public_name( $replacement['blockName'] ),
			'attrs'             => array(),
			'inner_html_length' => 0,
			'inner_blocks'      => 0,
			'fingerprint'       => $tree->fingerprint_of( $path ),
		);

		return $result;
	}

	/**
	 * Builds the block that replaces the current one.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $mode    One of `block`, `attrs` or `inner_html`.
	 * @param array<string, mixed> $input   Validated input.
	 * @param array<string, mixed> $current The block as it is now.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function build( $mode, array $input, array $current ) {
		if ( 'block' === $mode ) {
			if ( ! is_array( $input['block'] ) ) {
				return $this->error( 'invalid_input', __( 'The block value must be an object.', 'super-abilities' ), 400 );
			}

			return Block_Tree::from_input( $input['block'] );
		}

		if ( 'inner_html' === $mode ) {
			$current['innerHTML']    = (string) $input['inner_html'];
			$current['innerContent'] = '' === $current['innerHTML'] ? array() : array( $current['innerHTML'] );

			return Block_Tree::rebuild( $current );
		}

		if ( ! is_array( $input['attrs'] ) ) {
			return $this->error( 'invalid_input', __( 'The attrs value must be an object.', 'super-abilities' ), 400 );
		}

		$attrs = $current['attrs'];

		foreach ( $input['attrs'] as $key => $value ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			if ( null === $value ) {
				unset( $attrs[ $key ] );

				continue;
			}

			$attrs[ $key ] = $value;
		}

		$current['attrs'] = $attrs;

		return Block_Tree::normalize( $current );
	}
}
