<?php
/**
 * Redirect storage.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Redirects;

use SuperAbilities\Install;
use SuperAbilities\Support\Error;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `{prefix}sa_redirects` table.
 *
 * Every row leaves this class as a plain array with ISO 8601 timestamps and a
 * fingerprint, ready to be returned from an ability. The full rule set is kept in the
 * object cache because the runtime handler needs it on every front end request; every
 * write flushes it.
 *
 * @since 0.2.0
 */
class Store {

	/**
	 * Object cache group holding the rule set.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const CACHE_GROUP = 'super_abilities_redirects';

	/**
	 * Object cache key holding every rule.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const CACHE_KEY = 'all';

	/**
	 * Option holding the installed version of this module's table.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const TABLE_VERSION_OPTION = 'super_abilities_redirects_table_version';

	/**
	 * Version of the table definition below.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const TABLE_VERSION = '1';

	/**
	 * Columns a caller may sort by, mapped to the SQL column.
	 *
	 * @since 0.2.0
	 * @var array<string, string>
	 */
	const ORDERBY = array(
		'id'       => 'id',
		'source'   => 'source',
		'hits'     => 'hits',
		'last_hit' => 'last_hit',
		'updated'  => 'updated',
	);

	/**
	 * The fully prefixed table name.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function table() {
		return Install::table( 'redirects' );
	}

	/**
	 * The `CREATE TABLE` statement for the redirects table.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function schema() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE $table (
	id bigint(20) unsigned NOT NULL auto_increment,
	source varchar(191) NOT NULL default '',
	match_query tinyint(1) NOT NULL default 0,
	target text,
	status smallint(5) unsigned NOT NULL default 301,
	enabled tinyint(1) NOT NULL default 1,
	hits bigint(20) unsigned NOT NULL default 0,
	last_hit datetime default NULL,
	created datetime NOT NULL default '0000-00-00 00:00:00',
	updated datetime NOT NULL default '0000-00-00 00:00:00',
	created_by bigint(20) unsigned NOT NULL default 0,
	note varchar(255) NOT NULL default '',
	PRIMARY KEY  (id),
	UNIQUE KEY source_match (source,match_query),
	KEY enabled_status (enabled,status),
	KEY hits (hits),
	KEY created (created)
) $charset_collate;";
	}

	/**
	 * Whether the table exists right now.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking for our own table cannot go through an API and must not be cached.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $found === $table;
	}

	/**
	 * Creates or updates the table when the stored version is behind.
	 *
	 * `Install::maybe_upgrade()` only runs `dbDelta()` when the plugin schema version
	 * changes, which never happens for a module whose table ships in a later release,
	 * so the module calls this on every boot. It costs one autoloaded option read once
	 * the table is in place.
	 *
	 * @since 0.2.0
	 *
	 * @return bool Whether `dbDelta()` was run.
	 */
	public static function maybe_install() {
		if ( self::TABLE_VERSION === (string) get_option( self::TABLE_VERSION_OPTION, '' ) ) {
			return false;
		}

		self::install_table();

		return true;
	}

	/**
	 * Runs `dbDelta()` for the redirects table and records the version.
	 *
	 * Safe to call repeatedly: `dbDelta()` only issues the statements the table needs.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function install_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema() );

		update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION, true );

		self::flush_cache();
	}

	/**
	 * Drops the cached rule set.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function flush_cache() {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Every rule on the site, newest source order, straight from the cache when warm.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table and the result is cached below.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
			"SELECT * FROM {$table} ORDER BY id ASC"
		);

		$rules = self::hydrate( is_array( $rows ) ? $rows : array() );

		wp_cache_set( self::CACHE_KEY, $rules, self::CACHE_GROUP );

		return $rules;
	}

	/**
	 * Only the rules the runtime handler may act on.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function enabled() {
		$enabled = array();

		foreach ( self::all() as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				$enabled[] = $rule;
			}
		}

		return $enabled;
	}

	/**
	 * One rule by id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id Rule id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		$id = (int) $id;

		if ( $id < 1 ) {
			return null;
		}

		foreach ( self::all() as $rule ) {
			if ( (int) $rule['id'] === $id ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * One rule by source.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $source      Raw or normalized source.
	 * @param int|null $match_query Optional. Restrict to this flavour. Default null, which
	 *                              prefers the query-less rule and falls back to the other.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_source( $source, $match_query = null ) {
		$plain = Rules::normalize_source( $source, false );
		$exact = Rules::normalize_source( $source, true );

		if ( '' === $plain ) {
			return null;
		}

		$fallback = null;

		foreach ( self::all() as $rule ) {
			$rule_query = ! empty( $rule['match_query'] );

			if ( null !== $match_query && (int) $rule_query !== (int) $match_query ) {
				continue;
			}

			if ( ! $rule_query && (string) $rule['source'] === $plain ) {
				return $rule;
			}

			if ( $rule_query && (string) $rule['source'] === $exact && null === $fallback ) {
				$fallback = $rule;
			}
		}

		return $fallback;
	}

	/**
	 * Inserts a rule.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Normalized rule: `source`, `target`, `status`,
	 *                                   `match_query`, `enabled`, `note`.
	 * @return array<string, mixed>|WP_Error The stored rule.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = Time::mysql( Time::now() );

		$row = array(
			'source'      => (string) $data['source'],
			'match_query' => empty( $data['match_query'] ) ? 0 : 1,
			'target'      => isset( $data['target'] ) ? (string) $data['target'] : '',
			'status'      => (int) $data['status'],
			'enabled'     => empty( $data['enabled'] ) ? 0 : 1,
			'hits'        => 0,
			'last_hit'    => null,
			'created'     => $now,
			'updated'     => $now,
			'created_by'  => (int) get_current_user_id(),
			'note'        => isset( $data['note'] ) ? (string) $data['note'] : '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table; the cache is flushed below.
		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		self::flush_cache();

		if ( ! $inserted ) {
			return Error::make(
				'write_failed',
				__( 'The redirect could not be stored. The database rejected the row.', 'super-abilities' ),
				array( 'status' => 500 )
			);
		}

		$stored = self::get( (int) $wpdb->insert_id );

		if ( null === $stored ) {
			return Error::make(
				'write_failed',
				__( 'The redirect was written but could not be read back.', 'super-abilities' ),
				array( 'status' => 500 )
			);
		}

		return $stored;
	}

	/**
	 * Updates a rule.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $id   Rule id.
	 * @param array<string, mixed> $data Columns to write.
	 * @return array<string, mixed>|WP_Error The stored rule.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$id = (int) $id;

		$row     = array( 'updated' => Time::mysql( Time::now() ) );
		$formats = array( '%s' );

		$columns = array(
			'source'      => '%s',
			'match_query' => '%d',
			'target'      => '%s',
			'status'      => '%d',
			'enabled'     => '%d',
			'note'        => '%s',
		);

		foreach ( $columns as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			if ( '%d' === $format ) {
				$row[ $column ] = in_array( $column, array( 'match_query', 'enabled' ), true )
					? ( empty( $data[ $column ] ) ? 0 : 1 )
					: (int) $data[ $column ];
			} else {
				$row[ $column ] = (string) $data[ $column ];
			}

			$formats[] = $format;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table; the cache is flushed below.
		$updated = $wpdb->update( self::table(), $row, array( 'id' => $id ), $formats, array( '%d' ) );

		self::flush_cache();

		if ( false === $updated ) {
			return Error::make(
				'write_failed',
				__( 'The redirect could not be updated. The database rejected the change.', 'super-abilities' ),
				array( 'status' => 500 )
			);
		}

		$stored = self::get( $id );

		if ( null === $stored ) {
			return Error::make(
				'not_found',
				__( 'The redirect no longer exists.', 'super-abilities' ),
				array( 'status' => 404 )
			);
		}

		return $stored;
	}

	/**
	 * Deletes rules by id.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $ids Rule ids.
	 * @return int Number of rows removed.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$clean = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $clean ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table; the cache is flushed below.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every id is a placeholder.
				"DELETE FROM {$table} WHERE id IN ( {$placeholders} )",
				$clean
			)
		);

		self::flush_cache();

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Records that a rule answered a request.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id Rule id.
	 * @return void
	 */
	public static function record_hit( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id < 1 ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table and a counter increment has nothing to cache.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().
				"UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
				Time::mysql( Time::now() ),
				$id
			)
		);

		self::flush_cache();
	}

	/**
	 * Paginated, filtered rule list.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $filters Optional. `search`, `enabled`, `status`, `page`,
	 *                                      `per_page`, `orderby`, `order`.
	 * @return array{redirects: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public static function query( array $filters = array() ) {
		global $wpdb;

		$table    = self::table();
		$page     = isset( $filters['page'] ) ? max( 1, (int) $filters['page'] ) : 1;
		$per_page = isset( $filters['per_page'] ) ? min( 200, max( 1, (int) $filters['per_page'] ) ) : 25;
		$orderby  = isset( $filters['orderby'] ) ? (string) $filters['orderby'] : 'id';
		$orderby  = isset( self::ORDERBY[ $orderby ] ) ? self::ORDERBY[ $orderby ] : 'id';
		$order    = isset( $filters['order'] ) && 'asc' === strtolower( (string) $filters['order'] ) ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$values = array();

		if ( isset( $filters['search'] ) && '' !== (string) $filters['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '( source LIKE %s OR target LIKE %s OR note LIKE %s )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( isset( $filters['enabled'] ) ) {
			$where[]  = 'enabled = %d';
			$values[] = empty( $filters['enabled'] ) ? 0 : 1;
		}

		if ( isset( $filters['status'] ) && (int) $filters['status'] > 0 ) {
			$where[]  = 'status = %d';
			$values[] = (int) $filters['status'];
		}

		$clause = implode( ' AND ', $where );

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table and a filtered listing is not cacheable.
			$total = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table() and the clause holds no user input.
				"SELECT COUNT(*) FROM {$table} WHERE {$clause}"
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table and a filtered listing is not cacheable.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from Install::table() and every value is a placeholder.
					"SELECT COUNT(*) FROM {$table} WHERE {$clause}",
					$values
				)
			);
		}

		$page_values = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirects live in a custom table and a filtered listing is not cacheable.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The LIMIT and OFFSET placeholders are counted on top of the clause placeholders built above.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name, the sort column and the direction are all from fixed allowlists.
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				$page_values
			)
		);

		return array(
			'redirects'   => self::hydrate( is_array( $rows ) ? $rows : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Aggregate counts over the whole table.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function stats() {
		$rules = self::all();

		$totals = array(
			'rules'     => count( $rules ),
			'enabled'   => 0,
			'disabled'  => 0,
			'never_hit' => 0,
			'hits'      => 0,
		);

		$by_status = array();
		$never     = array();
		$recent    = array();
		$cutoff    = Time::now() - ( 30 * DAY_IN_SECONDS );

		foreach ( Rules::STATUSES as $status ) {
			$by_status[ (string) $status ] = 0;
		}

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				++$totals['enabled'];
			} else {
				++$totals['disabled'];
			}

			$status = (string) (int) $rule['status'];

			if ( ! isset( $by_status[ $status ] ) ) {
				$by_status[ $status ] = 0;
			}

			++$by_status[ $status ];

			$totals['hits'] += (int) $rule['hits'];

			if ( (int) $rule['hits'] < 1 ) {
				++$totals['never_hit'];
				$never[] = $rule;
			}

			$created = Time::parse( (string) $rule['created'] );

			if ( null !== $created && $created >= $cutoff ) {
				$recent[] = $rule;
			}
		}

		$top = $rules;

		usort(
			$top,
			static function ( $left, $right ) {
				return (int) $right['hits'] <=> (int) $left['hits'];
			}
		);

		$top = array_values(
			array_filter(
				array_slice( $top, 0, 20 ),
				static function ( $rule ) {
					return (int) $rule['hits'] > 0;
				}
			)
		);

		return array(
			'totals'    => $totals,
			'by_status' => $by_status,
			'top_hits'  => $top,
			'never_hit' => array_slice( $never, 0, 50 ),
			'recent'    => $recent,
		);
	}

	/**
	 * Removes every rule.
	 *
	 * Uses `DELETE` rather than `TRUNCATE` so that the statement stays inside the
	 * caller's transaction, which is what the test suite relies on.
	 *
	 * @since 0.2.0
	 *
	 * @return int Number of rows removed.
	 */
	public static function delete_all() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying our own table has no API and nothing to cache.
		$deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Install::table().

		self::flush_cache();

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * The fingerprint of a rule, used to reject stale writes.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $rule Rule array.
	 * @return string
	 */
	public static function fingerprint( array $rule ) {
		return Fingerprint::of_array(
			array(
				'id'          => isset( $rule['id'] ) ? (int) $rule['id'] : 0,
				'source'      => isset( $rule['source'] ) ? (string) $rule['source'] : '',
				'match_query' => empty( $rule['match_query'] ) ? 0 : 1,
				'target'      => isset( $rule['target'] ) ? (string) $rule['target'] : '',
				'status'      => isset( $rule['status'] ) ? (int) $rule['status'] : 0,
				'enabled'     => empty( $rule['enabled'] ) ? 0 : 1,
				'note'        => isset( $rule['note'] ) ? (string) $rule['note'] : '',
			)
		);
	}

	/**
	 * Turns database rows into the arrays abilities return.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, object> $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function hydrate( array $rows ) {
		$rules = array();

		foreach ( $rows as $row ) {
			$rules[] = self::to_array( $row );
		}

		return $rules;
	}

	/**
	 * Turns one database row into the array shape abilities return.
	 *
	 * @since 0.2.0
	 *
	 * @param object $row Raw row.
	 * @return array<string, mixed>
	 */
	public static function to_array( $row ) {
		$row  = (array) $row;
		$rule = array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'source'      => isset( $row['source'] ) ? (string) $row['source'] : '',
			'match_query' => ! empty( $row['match_query'] ),
			'target'      => isset( $row['target'] ) ? (string) $row['target'] : '',
			'status'      => isset( $row['status'] ) ? (int) $row['status'] : 301,
			'enabled'     => ! empty( $row['enabled'] ),
			'hits'        => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			'last_hit'    => empty( $row['last_hit'] ) ? null : Time::iso_from_mysql( (string) $row['last_hit'] ),
			'created'     => isset( $row['created'] ) ? Time::iso_from_mysql( (string) $row['created'] ) : '',
			'updated'     => isset( $row['updated'] ) ? Time::iso_from_mysql( (string) $row['updated'] ) : '',
			'created_by'  => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'note'        => isset( $row['note'] ) ? (string) $row['note'] : '',
		);

		if ( '' === $rule['last_hit'] ) {
			$rule['last_hit'] = null;
		}

		$rule['fingerprint'] = self::fingerprint( $rule );

		return $rule;
	}
}
