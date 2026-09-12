<?php
/**
 * Audit log summary ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Audit;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Install;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates the audit trail over a time window.
 *
 * @since 0.1.0
 */
class Audit_Summary extends Abstract_Ability {

	/**
	 * Default start of the window.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const DEFAULT_SINCE = '-24 hours';

	/**
	 * Grouping key to table column.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const GROUPS = array(
		'ability'   => 'ability',
		'user'      => 'user_id',
		'outcome'   => 'outcome',
		'transport' => 'transport',
	);

	/**
	 * How many `ok` durations are pulled into PHP to compute the percentile.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const DURATION_SAMPLE = 5000;

	/**
	 * How many distinct error codes are reported.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TOP_ERROR_CODES = 10;

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'audit-summary';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'audit';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Summarize the audit trail', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Aggregates the audit trail over a time window into counts per ability, user, outcome or transport, plus the most common error codes and the 95th percentile duration of successful calls. Use it to see what agents have been doing on this site before drilling into individual calls with the audit query ability.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'manage_options' );
	}

	/**
	 * Input schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'since'    => array(
					'type'        => array( 'string', 'integer' ),
					'default'     => self::DEFAULT_SINCE,
					'description' => __( 'Start of the window. Accepts an ISO 8601 timestamp, a Unix timestamp, or a relative expression such as "-7 days". Defaults to the last 24 hours.', 'super-abilities' ),
				),
				'until'    => array(
					'type'        => array( 'string', 'integer' ),
					'description' => __( 'End of the window, in the same formats as "since". Defaults to now.', 'super-abilities' ),
				),
				'group_by' => array(
					'type'        => 'string',
					'enum'        => array( 'ability', 'user', 'outcome', 'transport' ),
					'default'     => 'ability',
					'description' => __( 'What the "groups" list is grouped by.', 'super-abilities' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => __( 'How many groups to return, largest first.', 'super-abilities' ),
				),
			)
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		$group = Schema::object(
			array(
				'key'          => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
				'count'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'error_count'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'denied_count' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'key', 'label', 'count', 'error_count', 'denied_count' )
		);

		$error_code = Schema::object(
			array(
				'code'  => array( 'type' => 'string' ),
				'count' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'code', 'count' )
		);

		return Schema::object(
			array(
				'since'           => Schema::iso_datetime(),
				'until'           => Schema::iso_datetime(),
				'total'           => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'by_outcome'      => array(
					'type'                 => 'object',
					'description'          => __( 'Row count per outcome, keyed by outcome.', 'super-abilities' ),
					'additionalProperties' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'groups'          => array(
					'type'  => 'array',
					'items' => $group,
				),
				'top_error_codes' => array(
					'type'  => 'array',
					'items' => $error_code,
				),
				'distinct_users'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'p95_duration_ms' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'since', 'until', 'total', 'by_outcome', 'groups', 'top_error_codes', 'distinct_users', 'p95_duration_ms' )
		);
	}

	/**
	 * Builds the summary.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$since = isset( $input['since'] ) ? Time::parse( $input['since'] ) : null;

		if ( null === $since ) {
			$since = Time::parse( self::DEFAULT_SINCE );
		}

		$until = isset( $input['until'] ) ? Time::parse( $input['until'] ) : null;

		if ( null === $until ) {
			$until = Time::now();
		}

		if ( $since > $until ) {
			$swap  = $since;
			$since = $until;
			$until = $swap;
		}

		$group_by = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'ability';
		$column   = isset( self::GROUPS[ $group_by ] ) ? self::GROUPS[ $group_by ] : 'ability';
		$limit    = isset( $input['limit'] ) ? min( 100, max( 1, (int) $input['limit'] ) ) : 20;

		$table  = Install::table( 'audit_log' );
		$window = array( Time::mysql( (int) $since ), Time::mysql( (int) $until ) );

		$by_outcome = $this->outcome_counts( $table, $window );
		$durations  = $this->durations( $table, $window );

		return array(
			'since'           => Time::iso( (int) $since ),
			'until'           => Time::iso( (int) $until ),
			'total'           => (int) array_sum( $by_outcome ),
			'by_outcome'      => $by_outcome,
			'groups'          => $this->groups( $table, $window, $column, $limit ),
			'top_error_codes' => $this->error_codes( $table, $window ),
			'distinct_users'  => $this->distinct_users( $table, $window ),
			'p95_duration_ms' => self::percentile( $durations, 95 ),
		);
	}

	/**
	 * Row count per outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $table  Audit table name.
	 * @param array<int, string> $window Start and end MySQL datetimes.
	 * @return array<string, int>
	 */
	protected function outcome_counts( $table, array $window ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Reading our own table: the audit log has no WordPress API, must not be served from a stale cache, and its name comes from Install::table(). Both window values are bound placeholders passed as a list, which the placeholder sniff cannot count.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT outcome, COUNT(*) AS total FROM `{$table}` WHERE created_at >= %s AND created_at <= %s GROUP BY outcome", $window ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$outcome = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';

			if ( '' === $outcome ) {
				continue;
			}

			$counts[ $outcome ] = isset( $row['total'] ) ? (int) $row['total'] : 0;
		}

		return $counts;
	}

	/**
	 * The largest groups in the window.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $table  Audit table name.
	 * @param array<int, string> $window Start and end MySQL datetimes.
	 * @param string             $column Column to group by, already validated.
	 * @param int                $limit  How many groups to return.
	 * @return array<int, array<string, mixed>>
	 */
	protected function groups( $table, array $window, $column, $limit ) {
		global $wpdb;

		$params = array( $window[0], $window[1], 'error', 'denied', (int) $limit );

		/*
		 * Direct query against our own table: no WordPress API exists for it and an audit
		 * read must not be served from a stale cache. The table name comes from
		 * Install::table(), the grouping column is one of the four literals in the GROUPS
		 * allow list, and every caller supplied value is a bound placeholder.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS group_key,
					COUNT(*) AS total,
					SUM( CASE WHEN outcome = %s THEN 1 ELSE 0 END ) AS errors,
					SUM( CASE WHEN outcome = %s THEN 1 ELSE 0 END ) AS denials
				FROM `{$table}`
				WHERE created_at >= %s AND created_at <= %s
				GROUP BY {$column}
				ORDER BY total DESC
				LIMIT %d",
				$params[0],
				$params[1],
				$params[2],
				$params[3],
				$params[4]
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows   = is_array( $rows ) ? $rows : array();
		$groups = array();
		$labels = 'user_id' === $column ? $this->user_labels( $rows ) : array();

		foreach ( $rows as $row ) {
			$key = isset( $row['group_key'] ) ? (string) $row['group_key'] : '';

			$groups[] = array(
				'key'          => $key,
				'label'        => 'user_id' === $column && isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
				'count'        => isset( $row['total'] ) ? (int) $row['total'] : 0,
				'error_count'  => isset( $row['errors'] ) ? (int) $row['errors'] : 0,
				'denied_count' => isset( $row['denials'] ) ? (int) $row['denials'] : 0,
			);
		}

		return $groups;
	}

	/**
	 * The most common error codes in the window.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $table  Audit table name.
	 * @param array<int, string> $window Start and end MySQL datetimes.
	 * @return array<int, array<string, mixed>>
	 */
	protected function error_codes( $table, array $window ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading our own table: no WordPress API, no caching for an audit read, and the table name comes from Install::table(). Every value is a bound placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT error_code, COUNT(*) AS total
				FROM `{$table}`
				WHERE created_at >= %s AND created_at <= %s AND error_code <> ''
				GROUP BY error_code
				ORDER BY total DESC
				LIMIT %d",
				$window[0],
				$window[1],
				self::TOP_ERROR_CODES
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$codes = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$codes[] = array(
				'code'  => isset( $row['error_code'] ) ? (string) $row['error_code'] : '',
				'count' => isset( $row['total'] ) ? (int) $row['total'] : 0,
			);
		}

		return $codes;
	}

	/**
	 * How many distinct signed in users appear in the window.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $table  Audit table name.
	 * @param array<int, string> $window Start and end MySQL datetimes.
	 * @return int
	 */
	protected function distinct_users( $table, array $window ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Reading our own table: no WordPress API, no caching for an audit read, and the table name comes from Install::table(). Both window values are bound placeholders passed as a list, which the placeholder sniff cannot count.
		$distinct = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT( DISTINCT user_id ) FROM `{$table}` WHERE created_at >= %s AND created_at <= %s AND user_id > 0", $window )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $distinct;
	}

	/**
	 * Durations of the most recent successful calls in the window.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $table  Audit table name.
	 * @param array<int, string> $window Start and end MySQL datetimes.
	 * @return array<int, int>
	 */
	protected function durations( $table, array $window ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading our own table: no WordPress API, no caching for an audit read, and the table name comes from Install::table(). Every value is a bound placeholder.
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT duration_ms FROM `{$table}` WHERE created_at >= %s AND created_at <= %s AND outcome = %s ORDER BY id DESC LIMIT %d",
				$window[0],
				$window[1],
				'ok',
				self::DURATION_SAMPLE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$durations = array();

		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$durations[] = (int) $value;
		}

		return $durations;
	}

	/**
	 * Maps the user ids of a grouped result set to their logins.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $rows Grouped rows.
	 * @return array<int|string, string> Keyed by user id, which PHP stores as an integer.
	 */
	protected function user_labels( array $rows ) {
		$ids = array();

		foreach ( $rows as $row ) {
			$user_id = isset( $row['group_key'] ) ? (int) $row['group_key'] : 0;

			if ( $user_id > 0 ) {
				$ids[ $user_id ] = $user_id;
			}
		}

		$labels = array( 0 => __( 'Anonymous', 'super-abilities' ) );

		if ( empty( $ids ) ) {
			return $labels;
		}

		$users = get_users(
			array(
				'include' => array_values( $ids ),
				'fields'  => array( 'ID', 'user_login' ),
				'number'  => count( $ids ),
			)
		);

		foreach ( $users as $user ) {
			$labels[ (int) $user->ID ] = (string) $user->user_login;
		}

		return $labels;
	}

	/**
	 * The nth percentile of a list of integers, using nearest rank.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $values     Sample values.
	 * @param int             $percentile Percentile between 1 and 100.
	 * @return int Zero when the sample is empty.
	 */
	public static function percentile( array $values, $percentile ) {
		if ( empty( $values ) ) {
			return 0;
		}

		sort( $values, SORT_NUMERIC );

		$count = count( $values );
		$rank  = (int) ceil( ( (int) $percentile / 100 ) * $count );
		$index = max( 0, min( $count - 1, $rank - 1 ) );

		return (int) $values[ $index ];
	}
}
