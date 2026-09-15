<?php
/**
 * Deletes a user block pattern.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Patterns;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Trashes, or with force permanently deletes, a `wp_block` post.
 *
 * @since 0.2.0
 */
class Pattern_Delete extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'pattern-delete';
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
		return __( 'Delete a block pattern', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Removes a user block pattern. By default the wp_block post is moved to the trash, so it can be restored and any synced pattern block still referencing it can be recovered; pass force to delete it permanently, which cannot be undone and leaves every synced instance of it broken. Patterns registered by core, a theme or a plugin live in code and cannot be deleted here. Calling it on a pattern that is already in the trash is a no-op unless force is set.', 'super-abilities' );
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
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'edit_posts' );
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
				'id'    => Schema::id( __( 'Post id of the user pattern to remove.', 'super-abilities' ) ),
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Delete permanently instead of moving to the trash. Default false.', 'super-abilities' ),
				),
			),
			array( 'id' )
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
				'id'      => Schema::id(),
				'title'   => array( 'type' => 'string' ),
				'trashed' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the pattern is now in the trash.', 'super-abilities' ),
				),
				'deleted' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the pattern was permanently deleted.', 'super-abilities' ),
				),
			),
			array( 'id', 'title', 'trashed', 'deleted' )
		);
	}

	/**
	 * Per-object permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool|\WP_Error
	 */
	public function permission( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( $id > 0 && ! current_user_can( 'delete_post', $id ) ) {
			return $this->error(
				'forbidden',
				__( 'You are not allowed to delete that pattern.', 'super-abilities' ),
				403
			);
		}

		return true;
	}

	/**
	 * Removes the pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$force = ! empty( $input['force'] );
		$post  = Patterns::post( $id );

		if ( null === $post ) {
			return $this->error(
				'not_found',
				__( 'No user pattern with that id exists. Patterns registered in code cannot be deleted.', 'super-abilities' ),
				404,
				array( 'id' => $id )
			);
		}

		$title = (string) $post->post_title;

		$this->note_object( 'post', $id );

		if ( ! $force ) {
			if ( 'trash' === $post->post_status ) {
				return array(
					'id'      => $id,
					'title'   => $title,
					'trashed' => true,
					'deleted' => false,
				);
			}

			if ( ! EMPTY_TRASH_DAYS ) {
				return $this->error(
					'unsupported',
					__( 'The trash is disabled on this site, so a pattern can only be deleted permanently. Pass force to do that.', 'super-abilities' ),
					501,
					array( 'id' => $id )
				);
			}

			$trashed = wp_trash_post( $id );

			if ( empty( $trashed ) ) {
				return $this->error(
					'exception',
					__( 'WordPress refused to trash the pattern.', 'super-abilities' ),
					500,
					array( 'id' => $id )
				);
			}

			return array(
				'id'      => $id,
				'title'   => $title,
				'trashed' => true,
				'deleted' => false,
			);
		}

		$deleted = wp_delete_post( $id, true );

		if ( empty( $deleted ) ) {
			return $this->error(
				'exception',
				__( 'WordPress refused to delete the pattern.', 'super-abilities' ),
				500,
				array( 'id' => $id )
			);
		}

		return array(
			'id'      => $id,
			'title'   => $title,
			'trashed' => false,
			'deleted' => true,
		);
	}
}
