<?php
/**
 * Lists the stored redirects.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Rule_Schema;
use SuperAbilities\Redirects\Rules;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Paginated, searchable listing of every redirect rule on the site.
 *
 * @since 0.2.0
 */
class Redirects_List extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirects-list';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'redirects';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'List redirects', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the redirect rules stored on this site, newest first, with their hit counts and fingerprints. Filter by a substring of the source, target or note, by whether the rule is enabled, or by status, and sort by source, hits, last hit or last change. The fingerprint on every row is what redirect-update and redirect-delete accept as expected_fingerprint.', 'super-abilities' );
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
		return array( 'manage_options' );
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
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Substring matched against the source, the target and the note.', 'super-abilities' ),
				),
				'enabled'  => array(
					'type'        => 'boolean',
					'description' => __( 'Only enabled rules when true, only disabled rules when false. Omit for both.', 'super-abilities' ),
				),
				'status'   => array(
					'type'        => 'integer',
					'enum'        => Rules::STATUSES,
					'description' => __( 'Only rules sending this HTTP status.', 'super-abilities' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page number, starting at 1.', 'super-abilities' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
					'description' => __( 'Rows per page, up to 200.', 'super-abilities' ),
				),
				'orderby'  => array(
					'type'        => 'string',
					'enum'        => array( 'id', 'source', 'hits', 'last_hit', 'updated' ),
					'default'     => 'id',
					'description' => __( 'Column to sort by.', 'super-abilities' ),
				),
				'order'    => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
					'description' => __( 'Sort direction.', 'super-abilities' ),
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
		$props = Schema::pagination();

		$props['redirects'] = array(
			'type'        => 'array',
			'items'       => Rule_Schema::rule(),
			'description' => __( 'The rules on this page.', 'super-abilities' ),
		);

		return Schema::object(
			$props,
			array( 'page', 'per_page', 'total', 'total_pages', 'redirects' )
		);
	}

	/**
	 * Returns the page of rules.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$filters = array(
			'page'     => isset( $input['page'] ) ? (int) $input['page'] : 1,
			'per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 50,
			'orderby'  => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'id',
			'order'    => isset( $input['order'] ) ? sanitize_key( (string) $input['order'] ) : 'desc',
		);

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$filters['search'] = sanitize_text_field( (string) $input['search'] );
		}

		if ( isset( $input['enabled'] ) ) {
			$filters['enabled'] = ! empty( $input['enabled'] );
		}

		if ( isset( $input['status'] ) ) {
			$filters['status'] = (int) $input['status'];
		}

		$result = Store::query( $filters );

		return array(
			'page'        => (int) $result['page'],
			'per_page'    => (int) $result['per_page'],
			'total'       => (int) $result['total'],
			'total_pages' => (int) $result['total_pages'],
			'redirects'   => $result['redirects'],
		);
	}
}
