<?php
/**
 * Audit log query ability.
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
 * Reads the audit trail, with filters and pagination.
 *
 * @since 0.1.0
 */
class Audit_Query extends Abstract_Ability {

	/**
	 * Outcomes a row can carry.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const OUTCOMES = array( 'ok', 'denied', 'invalid', 'error', 'rejected', 'cancelled' );

	/**
	 * Transports a row can carry.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const TRANSPORTS = array( 'rest', 'mcp', 'wp-cli', 'cron', 'job', 'internal' );

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'audit-query';
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
		return __( 'Query the audit trail', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Searches the audit trail of every ability call made on this site, including calls to abilities registered by other plugins and calls that were refused. Filter by ability, user, outcome, transport, time range, affected object, request id or job id. Only the top level input keys of each call are recorded, never the input values.', 'super-abilities' );
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
				'ability'     => array(
					'type'        => 'string',
					'description' => __( 'Exact ability name, for example "super-abilities/audit-query". Site events are recorded under names such as "event/plugin-activated".', 'super-abilities' ),
				),
				'user_id'     => Schema::id( __( 'Only calls made by this user.', 'super-abilities' ) ),
				'outcome'     => array(
					'type'        => 'string',
					'enum'        => self::OUTCOMES,
					'description' => __( 'Only calls with this outcome. "rejected" means the attempt was refused before execution and WordPress could not say whether that was a permission denial or invalid input.', 'super-abilities' ),
				),
				'transport'   => array(
					'type'        => 'string',
					'enum'        => self::TRANSPORTS,
					'description' => __( 'Only calls that arrived over this transport.', 'super-abilities' ),
				),
				'since'       => array(
					'type'        => array( 'string', 'integer' ),
					'description' => __( 'Start of the time range. Accepts an ISO 8601 timestamp, a Unix timestamp, or a relative expression such as "-24 hours".', 'super-abilities' ),
				),
				'until'       => array(
					'type'        => array( 'string', 'integer' ),
					'description' => __( 'End of the time range, in the same formats as "since".', 'super-abilities' ),
				),
				'object_type' => array(
					'type'        => 'string',
					'description' => __( 'Only calls that touched this kind of object, for example "post", "plugin" or "option".', 'super-abilities' ),
				),
				'object_id'   => array(
					'type'        => 'string',
					'description' => __( 'Only calls that touched this object id. Use together with "object_type".', 'super-abilities' ),
				),
				'request_id'  => array(
					'type'        => 'string',
					'description' => __( 'Only calls that were part of this request. Use it to see everything one agent did in one round trip.', 'super-abilities' ),
				),
				'job_id'      => Schema::id( __( 'Only calls made while running this background job.', 'super-abilities' ) ),
				'page'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page number, starting at 1.', 'super-abilities' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 25,
					'description' => __( 'Rows per page, up to 100.', 'super-abilities' ),
				),
				'order'       => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
					'description' => __( 'Sort direction by time. Defaults to newest first.', 'super-abilities' ),
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
		$item = Schema::object(
			array(
				'id'                => Schema::id(),
				'created_at'        => Schema::iso_datetime(),
				'request_id'        => array( 'type' => 'string' ),
				'ability'           => array( 'type' => 'string' ),
				'transport'         => array( 'type' => 'string' ),
				'client'            => array( 'type' => 'string' ),
				'user_id'           => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'user_login'        => array( 'type' => 'string' ),
				'app_password_uuid' => array( 'type' => 'string' ),
				'outcome'           => array( 'type' => 'string' ),
				'error_code'        => array( 'type' => 'string' ),
				'input_keys'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'object_type'       => array( 'type' => 'string' ),
				'object_id'         => array( 'type' => 'string' ),
				'duration_ms'       => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'ip'                => array( 'type' => 'string' ),
				'job_id'            => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array(
				'id',
				'created_at',
				'request_id',
				'ability',
				'transport',
				'client',
				'user_id',
				'user_login',
				'app_password_uuid',
				'outcome',
				'error_code',
				'input_keys',
				'object_type',
				'object_id',
				'duration_ms',
				'ip',
				'job_id',
			)
		);

		return Schema::object(
			array(
				'total'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'items'    => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			array( 'total', 'page', 'per_page', 'items' )
		);
	}

	/**
	 * Runs the query.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		global $wpdb;

		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 25;
		$order    = isset( $input['order'] ) && 'asc' === strtolower( (string) $input['order'] ) ? 'ASC' : 'DESC';

		list( $where, $params ) = $this->build_where( $input );

		$table  = Install::table( 'audit_log' );
		$clause = implode( ' AND ', $where );

		/*
		 * Direct queries against our own table: the audit log has no WordPress API and
		 * must never be served from a stale cache. The table name comes from
		 * Install::table(), the sort direction is one of two literals chosen above, the
		 * WHERE fragments are built from a fixed column allow list in build_where() and
		 * every caller supplied value is bound through a placeholder. The placeholder
		 * sniffs cannot follow a placeholder list that is assembled at runtime.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$clause}", $params )
		);

		$items = array();

		if ( $total > 0 ) {
			$paged   = $params;
			$paged[] = $per_page;
			$paged[] = ( $page - 1 ) * $per_page;

			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$clause} ORDER BY created_at {$order}, id {$order} LIMIT %d OFFSET %d", $paged ),
				ARRAY_A
			);

			$items = $this->format_rows( is_array( $rows ) ? $rows : array() );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		);
	}

	/**
	 * Builds the `WHERE` clause fragments and their bound values.
	 *
	 * The first condition is always present, which guarantees that `prepare()` gets
	 * at least one placeholder however the caller filtered.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array{0: array<int, string>, 1: array<int, mixed>}
	 */
	protected function build_where( array $input ) {
		$where  = array( 'id > %d' );
		$params = array( 0 );

		$strings = array(
			'ability'     => 'ability',
			'transport'   => 'transport',
			'outcome'     => 'outcome',
			'object_type' => 'object_type',
			'object_id'   => 'object_id',
			'request_id'  => 'request_id',
		);

		foreach ( $strings as $key => $column ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $input[ $key ] );

			if ( '' === $value ) {
				continue;
			}

			$where[]  = "{$column} = %s";
			$params[] = $value;
		}

		foreach ( array( 'user_id', 'job_id' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$where[]  = "{$key} = %d";
			$params[] = (int) $input[ $key ];
		}

		if ( isset( $input['since'] ) ) {
			$since = Time::parse( $input['since'] );

			if ( null !== $since ) {
				$where[]  = 'created_at >= %s';
				$params[] = Time::mysql( $since );
			}
		}

		if ( isset( $input['until'] ) ) {
			$until = Time::parse( $input['until'] );

			if ( null !== $until ) {
				$where[]  = 'created_at <= %s';
				$params[] = Time::mysql( $until );
			}
		}

		return array( $where, $params );
	}

	/**
	 * Turns raw rows into output items, resolving user logins in one query.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	protected function format_rows( array $rows ) {
		$logins = $this->resolve_logins( $rows );
		$items  = array();

		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;

			$items[] = array(
				'id'                => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'created_at'        => Time::iso_from_mysql( isset( $row['created_at'] ) ? (string) $row['created_at'] : '' ),
				'request_id'        => isset( $row['request_id'] ) ? (string) $row['request_id'] : '',
				'ability'           => isset( $row['ability'] ) ? (string) $row['ability'] : '',
				'transport'         => isset( $row['transport'] ) ? (string) $row['transport'] : '',
				'client'            => isset( $row['client'] ) ? (string) $row['client'] : '',
				'user_id'           => $user_id,
				'user_login'        => isset( $logins[ $user_id ] ) ? $logins[ $user_id ] : '',
				'app_password_uuid' => isset( $row['app_password_uuid'] ) ? (string) $row['app_password_uuid'] : '',
				'outcome'           => isset( $row['outcome'] ) ? (string) $row['outcome'] : '',
				'error_code'        => isset( $row['error_code'] ) ? (string) $row['error_code'] : '',
				'input_keys'        => self::decode_input_keys( isset( $row['input_keys'] ) ? $row['input_keys'] : '' ),
				'object_type'       => isset( $row['object_type'] ) ? (string) $row['object_type'] : '',
				'object_id'         => isset( $row['object_id'] ) ? (string) $row['object_id'] : '',
				'duration_ms'       => isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0,
				'ip'                => isset( $row['ip'] ) ? (string) $row['ip'] : '',
				'job_id'            => isset( $row['job_id'] ) ? (int) $row['job_id'] : 0,
			);
		}

		return $items;
	}

	/**
	 * Maps the user ids in a result set to their logins.
	 *
	 * Users that have been deleted since simply have no entry, and the item reports an
	 * empty login rather than failing.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $rows Raw rows.
	 * @return array<int, string>
	 */
	protected function resolve_logins( array $rows ) {
		$ids = array();

		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;

			if ( $user_id > 0 ) {
				$ids[ $user_id ] = $user_id;
			}
		}

		if ( empty( $ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => array_values( $ids ),
				'fields'  => array( 'ID', 'user_login' ),
				'number'  => count( $ids ),
			)
		);

		$logins = array();

		foreach ( $users as $user ) {
			$logins[ (int) $user->ID ] = (string) $user->user_login;
		}

		return $logins;
	}

	/**
	 * Decodes the stored `input_keys` column.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $stored Raw column value.
	 * @return array<int, string>
	 */
	public static function decode_input_keys( $stored ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$decoded = json_decode( $stored, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$keys = array();

		foreach ( $decoded as $key ) {
			if ( is_scalar( $key ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}
}
