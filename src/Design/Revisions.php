<?php
/**
 * Post revision lookups.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the revision a write created, so a caller can roll it back.
 *
 * Every write in this module goes through `wp_update_post()`, which creates a
 * revision for post types that support them. Global styles, templates and
 * patterns all do on WordPress 6.9.
 *
 * @since 0.2.0
 */
class Revisions {

	/**
	 * Id of the newest revision of a post.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post id.
	 * @return int|null Null when the post type keeps no revisions.
	 */
	public static function latest_id( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return null;
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		if ( empty( $revisions ) ) {
			return null;
		}

		$ids = array_values( array_map( 'intval', (array) $revisions ) );

		return $ids[0] > 0 ? $ids[0] : null;
	}
}
