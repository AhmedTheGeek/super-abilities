<?php
/**
 * Post side plumbing for the block abilities.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Blocks;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Loads, locks and saves the post a block tree belongs to.
 *
 * Every write goes through `wp_update_post()` with nothing but the id and the new
 * `post_content`, so the post keeps its title, status, dates and meta, the usual
 * save hooks run and WordPress stores a revision the edit can be rolled back to.
 *
 * @since 0.2.0
 */
class Post_Blocks {

	/**
	 * Returns a post by id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|null
	 */
	public static function find( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id < 1 ) {
			return null;
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * The block tree of a post.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post $post Post object.
	 * @return Block_Tree
	 */
	public static function tree( WP_Post $post ) {
		return Block_Tree::parse( $post->post_content );
	}

	/**
	 * The id of the user currently holding the editor lock on a post.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post id.
	 * @return int User id, or 0 when nobody else is editing the post.
	 */
	public static function locked_by( $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$user_id = wp_check_post_lock( (int) $post_id );

		return is_numeric( $user_id ) ? (int) $user_id : 0;
	}

	/**
	 * Writes new content to a post.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post $post    Post object.
	 * @param string  $content New post content.
	 * @return int|WP_Error The post id, or the error `wp_update_post()` returned.
	 */
	public static function save( WP_Post $post, $content ) {
		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => (string) $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result;
	}

	/**
	 * The id of the most recent revision of a post.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post id.
	 * @return int Revision id, or 0 when the post has no revisions.
	 */
	public static function latest_revision( $post_id ) {
		$revisions = wp_get_post_revisions(
			(int) $post_id,
			array(
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		if ( ! is_array( $revisions ) || array() === $revisions ) {
			return 0;
		}

		$ids = array_values( $revisions );

		return (int) $ids[0];
	}
}
