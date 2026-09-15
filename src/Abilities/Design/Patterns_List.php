<?php
/**
 * Lists block patterns.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Patterns;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Lists registered patterns and the user patterns stored as `wp_block` posts.
 *
 * @since 0.2.0
 */
class Patterns_List extends Abstract_Ability {

	/**
	 * Default page size.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'patterns-list';
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
		return __( 'List block patterns', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists every block pattern this site knows about in one list: the patterns core, the theme and plugins register, the ones pulled from the pattern directory, and the user patterns an administrator saved, which are wp_block posts and are the only editable ones. Each item says where it came from, whether the inserter offers it, and for user patterns whether it is synced or unsynced. Filter with source and search, then call pattern-read for the markup. Works on classic themes too.', 'super-abilities' );
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
				'source'   => array(
					'type'        => 'string',
					'enum'        => Patterns::SOURCES,
					'description' => __( 'Only list patterns from this source. Use user for the editable ones.', 'super-abilities' ),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Only list patterns whose name or title contains this text.', 'super-abilities' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page of results to return.', 'super-abilities' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => self::PER_PAGE,
					'description' => __( 'How many patterns to return per page.', 'super-abilities' ),
				),
			)
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
		$props = array(
			'patterns'         => array(
				'type'  => 'array',
				'items' => Patterns::item_schema( false ),
			),
			'total_registered' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'How many matching patterns come from core, the theme, a plugin or the directory.', 'super-abilities' ),
			),
			'total_user'       => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'How many matching patterns are editable wp_block posts.', 'super-abilities' ),
			),
		);

		return Schema::object(
			array_merge( $props, Schema::pagination() ),
			array( 'patterns', 'total_registered', 'total_user', 'page', 'per_page', 'total', 'total_pages' )
		);
	}

	/**
	 * Lists the patterns.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$source   = isset( $input['source'] ) ? (string) $input['source'] : '';
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : self::PER_PAGE;

		$items = array();

		if ( 'user' !== $source ) {
			foreach ( Patterns::registered( false ) as $item ) {
				if ( '' !== $source && $source !== $item['source'] ) {
					continue;
				}

				$items[] = $item;
			}
		}

		if ( '' === $source || 'user' === $source ) {
			foreach ( Patterns::user( '' ) as $item ) {
				$items[] = $item;
			}
		}

		$items      = self::filter_by_search( $items, $search );
		$registered = 0;
		$user       = 0;

		foreach ( $items as $item ) {
			if ( 'user' === $item['source'] ) {
				++$user;

				continue;
			}

			++$registered;
		}

		$total = count( $items );

		return array(
			'patterns'         => array_values( array_slice( $items, ( $page - 1 ) * $per_page, $per_page ) ),
			'total_registered' => $registered,
			'total_user'       => $user,
			'page'             => $page,
			'per_page'         => $per_page,
			'total'            => $total,
			'total_pages'      => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Keeps the items whose name or title contains the search term.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $items  Pattern items.
	 * @param string                           $search Search term.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function filter_by_search( array $items, $search ) {
		if ( '' === $search ) {
			return $items;
		}

		$needle = strtolower( $search );
		$kept   = array();

		foreach ( $items as $item ) {
			$haystack = strtolower( (string) $item['name'] . ' ' . (string) $item['title'] );

			if ( false !== strpos( $haystack, $needle ) ) {
				$kept[] = $item;
			}
		}

		return $kept;
	}
}
