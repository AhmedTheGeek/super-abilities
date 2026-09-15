<?php
/**
 * Block pattern access.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Design;

use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;
use WP_Block_Patterns_Registry;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Describes registered block patterns and the user patterns stored as posts.
 *
 * There are two kinds of pattern on a WordPress site. Registered patterns come
 * from core, the theme or a plugin and are read only: they live in PHP or in a
 * theme file. User patterns are `wp_block` posts, they are editable, and they are
 * either synced (one shared instance) or unsynced (inserted as a copy).
 *
 * @since 0.2.0
 */
class Patterns {

	/**
	 * Post type holding user patterns.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const POST_TYPE = 'wp_block';

	/**
	 * Meta key core uses to mark a pattern as unsynced.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const SYNC_META = 'wp_pattern_sync_status';

	/**
	 * Taxonomy holding user pattern categories.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const CATEGORY_TAXONOMY = 'wp_pattern_category';

	/**
	 * Normalized source values.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const SOURCES = array( 'core', 'theme', 'plugin', 'directory', 'user', 'unknown' );

	/**
	 * Normalizes the `source` a pattern was registered with.
	 *
	 * Core registers patterns with sources such as `core`, `theme`, `plugin` and
	 * `pattern-directory/featured`; everything from the directory collapses into
	 * `directory` here.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $source Raw source.
	 * @return string One of {@see Patterns::SOURCES}.
	 */
	public static function source( $source ) {
		$source = is_string( $source ) ? strtolower( trim( $source ) ) : '';

		if ( '' === $source ) {
			return 'unknown';
		}

		if ( 0 === strpos( $source, 'pattern-directory' ) ) {
			return 'directory';
		}

		return in_array( $source, self::SOURCES, true ) ? $source : 'unknown';
	}

	/**
	 * Every registered pattern, flattened.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $with_content Optional. Whether to include the markup. Default false.
	 * @return array<int, array<string, mixed>>
	 */
	public static function registered( $with_content = false ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array();
		}

		$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		$items    = array();

		foreach ( (array) $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$items[] = self::from_registered( $pattern, $with_content );
		}

		usort(
			$items,
			static function ( array $a, array $b ) {
				return strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $items;
	}

	/**
	 * One registered pattern by name.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name         Pattern name including its namespace.
	 * @param bool   $with_content Optional. Whether to include the markup. Default true.
	 * @return array<string, mixed>|null
	 */
	public static function registered_one( $name, $with_content = true ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return null;
		}

		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $name );

		if ( ! is_array( $pattern ) ) {
			return null;
		}

		return self::from_registered( $pattern, $with_content );
	}

	/**
	 * Flattens a registry entry.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $pattern      Registry entry.
	 * @param bool                 $with_content Whether to include the markup.
	 * @return array<string, mixed>
	 */
	public static function from_registered( array $pattern, $with_content ) {
		$content    = isset( $pattern['content'] ) ? (string) $pattern['content'] : '';
		$categories = array();

		if ( isset( $pattern['categories'] ) && is_array( $pattern['categories'] ) ) {
			foreach ( $pattern['categories'] as $category ) {
				if ( is_scalar( $category ) ) {
					$categories[] = (string) $category;
				}
			}
		}

		$item = array(
			'id'          => null,
			'name'        => isset( $pattern['name'] ) ? (string) $pattern['name'] : '',
			'title'       => isset( $pattern['title'] ) ? (string) $pattern['title'] : '',
			'description' => isset( $pattern['description'] ) ? (string) $pattern['description'] : '',
			'categories'  => $categories,
			'source'      => self::source( isset( $pattern['source'] ) ? $pattern['source'] : '' ),
			'inserter'    => ! isset( $pattern['inserter'] ) || (bool) $pattern['inserter'],
			'status'      => null,
			'sync'        => null,
			'modified'    => null,
			'fingerprint' => Block_Markup::fingerprint( $content ),
		);

		if ( $with_content ) {
			$summary             = Block_Markup::summary( $content );
			$item['content']     = $content;
			$item['block_count'] = (int) $summary['block_count'];
			$item['blocks']      = $summary['blocks'];
		}

		return $item;
	}

	/**
	 * User patterns stored as `wp_block` posts.
	 *
	 * @since 0.2.0
	 *
	 * @param string $search Optional. Search term matched against the title. Default empty.
	 * @param int    $limit  Optional. Maximum number of posts to read. Default 200.
	 * @return array<int, array<string, mixed>>
	 */
	public static function user( $search = '', $limit = 200 ) {
		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => max( 1, (int) $limit ),
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);

		$search = (string) $search;

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = self::from_post( $post, false );
			}
		}

		return $items;
	}

	/**
	 * Flattens a `wp_block` post.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post $post         Pattern post.
	 * @param bool    $with_content Whether to include the markup.
	 * @return array<string, mixed>
	 */
	public static function from_post( WP_Post $post, $with_content ) {
		$content    = (string) $post->post_content;
		$categories = array();
		$terms      = get_the_terms( $post, self::CATEGORY_TAXONOMY );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = (string) $term->slug;
			}
		}

		$item = array(
			'id'          => (int) $post->ID,
			'name'        => null,
			'title'       => (string) $post->post_title,
			'description' => (string) $post->post_excerpt,
			'categories'  => $categories,
			'source'      => 'user',
			'inserter'    => true,
			'status'      => (string) $post->post_status,
			'sync'        => self::sync_status( (int) $post->ID ),
			'modified'    => Time::iso_from_mysql( (string) $post->post_modified_gmt ),
			'fingerprint' => Block_Markup::fingerprint( $content ),
		);

		if ( $with_content ) {
			$summary             = Block_Markup::summary( $content );
			$item['content']     = $content;
			$item['block_count'] = (int) $summary['block_count'];
			$item['blocks']      = $summary['blocks'];
		}

		return $item;
	}

	/**
	 * Schema for one pattern as this class returns it.
	 *
	 * Registered patterns and user patterns share one shape; the fields that only
	 * apply to one of them are null for the other.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $with_content Optional. Whether the markup and block summary are
	 *                           part of the item. Default false.
	 * @return array<string, mixed>
	 */
	public static function item_schema( $with_content = false ) {
		$props = array(
			'id'          => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'Post id of a user pattern. Null for a registered pattern.', 'super-abilities' ),
			),
			'name'        => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Registered pattern name including its namespace. Null for a user pattern.', 'super-abilities' ),
			),
			'title'       => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'categories'  => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'source'      => array(
				'type'        => 'string',
				'enum'        => self::SOURCES,
				'description' => __( 'Where the pattern comes from. user means a wp_block post.', 'super-abilities' ),
			),
			'inserter'    => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the pattern is offered in the block inserter.', 'super-abilities' ),
			),
			'status'      => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Post status of a user pattern.', 'super-abilities' ),
			),
			'sync'        => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'synced or unsynced for a user pattern, null for a registered one.', 'super-abilities' ),
			),
			'modified'    => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'When a user pattern was last saved.', 'super-abilities' ),
			),
			'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the pattern content.', 'super-abilities' ) ),
		);

		if ( $with_content ) {
			$props['content']     = array(
				'type'        => 'string',
				'description' => __( 'The block markup of the pattern.', 'super-abilities' ),
			);
			$props['block_count'] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
			$props['blocks']      = Templates::blocks_schema();
		}

		return Schema::object( $props, array_keys( $props ) );
	}

	/**
	 * Sync status of a user pattern.
	 *
	 * Core stores `unsynced` for copy-on-insert patterns and stores nothing at all
	 * for synced ones, so a missing meta value means synced.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Pattern post id.
	 * @return string Either `synced` or `unsynced`.
	 */
	public static function sync_status( $post_id ) {
		$stored = get_post_meta( (int) $post_id, self::SYNC_META, true );

		return 'unsynced' === $stored ? 'unsynced' : 'synced';
	}

	/**
	 * Stores the sync status of a user pattern the way core does.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $post_id Pattern post id.
	 * @param string $sync    Either `synced` or `unsynced`.
	 * @return void
	 */
	public static function set_sync_status( $post_id, $sync ) {
		$post_id = (int) $post_id;

		if ( 'unsynced' === $sync ) {
			update_post_meta( $post_id, self::SYNC_META, 'unsynced' );

			return;
		}

		delete_post_meta( $post_id, self::SYNC_META );
	}

	/**
	 * Loads a user pattern post.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|null Null when the post is missing or is not a pattern.
	 */
	public static function post( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $post;
	}
}
