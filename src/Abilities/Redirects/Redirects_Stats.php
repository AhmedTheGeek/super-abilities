<?php
/**
 * Aggregate view of the redirect table.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Rule_Schema;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the rules, ranks them by traffic and shows the ones nobody uses.
 *
 * @since 0.2.0
 */
class Redirects_Stats extends Abstract_Ability {

	/**
	 * How many rules the lists are capped at.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const LIST_LIMIT = 50;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirects-stats';
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
		return __( 'Redirect statistics', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Summarises the redirect table: how many rules exist, how many are enabled, how they split by status, the twenty rules with the most hits, the rules that have never been hit at all, and the rules added in the last thirty days. Useful for pruning a redirect list that has grown by accident and for confirming that a migration is actually being used.', 'super-abilities' );
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
		return Schema::object( array() );
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		$count = array(
			'type'    => 'integer',
			'minimum' => 0,
		);

		$rule_list = array(
			'type'  => 'array',
			'items' => Rule_Schema::rule(),
		);

		return Schema::object(
			array(
				'totals'    => Schema::object(
					array(
						'rules'                => $count,
						'enabled'              => $count,
						'disabled'             => $count,
						'never_hit'            => $count,
						'total_hits'           => $count,
						'created_last_30_days' => $count,
					),
					array( 'rules', 'enabled', 'disabled', 'never_hit', 'total_hits', 'created_last_30_days' )
				),
				'by_status' => array(
					'type'        => 'array',
					'description' => __( 'How many rules send each status.', 'super-abilities' ),
					'items'       => Schema::object(
						array(
							'status' => array( 'type' => 'integer' ),
							'count'  => $count,
						),
						array( 'status', 'count' )
					),
				),
				'top_hits'  => array_merge(
					$rule_list,
					array( 'description' => __( 'The twenty most used rules, busiest first. Rules with no hits are left out.', 'super-abilities' ) )
				),
				'never_hit' => array_merge(
					$rule_list,
					array( 'description' => __( 'Rules that have never answered a request, up to fifty of them.', 'super-abilities' ) )
				),
				'recent'    => array_merge(
					$rule_list,
					array( 'description' => __( 'Rules created in the last thirty days, up to fifty of them.', 'super-abilities' ) )
				),
			),
			array( 'totals', 'by_status', 'top_hits', 'never_hit', 'recent' )
		);
	}

	/**
	 * Builds the summary.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- This ability takes no input.
		$stats     = Store::stats();
		$by_status = array();

		foreach ( $stats['by_status'] as $status => $count ) {
			$by_status[] = array(
				'status' => (int) $status,
				'count'  => (int) $count,
			);
		}

		return array(
			'totals'    => array(
				'rules'                => (int) $stats['totals']['rules'],
				'enabled'              => (int) $stats['totals']['enabled'],
				'disabled'             => (int) $stats['totals']['disabled'],
				'never_hit'            => (int) $stats['totals']['never_hit'],
				'total_hits'           => (int) $stats['totals']['hits'],
				'created_last_30_days' => count( $stats['recent'] ),
			),
			'by_status' => $by_status,
			'top_hits'  => $stats['top_hits'],
			'never_hit' => array_slice( $stats['never_hit'], 0, self::LIST_LIMIT ),
			'recent'    => array_slice( $stats['recent'], 0, self::LIST_LIMIT ),
		);
	}
}
