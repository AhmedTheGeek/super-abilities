<?php
/**
 * Correlates fatal errors with recent changes.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Health\Debug_Log_Parser;
use SuperAbilities\Install;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "what broke, and what changed just before it broke?".
 *
 * The link between the two lists is a correlation, never a proven cause.
 *
 * @since 0.1.0
 */
class Error_Triage extends Abstract_Ability {

	/**
	 * Default window.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const DEFAULT_SINCE = '-24 hours';

	/**
	 * Largest number of entries read out of the debug log.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Largest number of audit rows considered.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_ROWS = 200;

	/**
	 * Levels treated as breakage.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const FATAL_LEVELS = array( 'fatal', 'error' );

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'error-triage';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'health';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Triage recent errors', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the fatal and error level entries from the debug log in a time window, the writes recorded in the Super Abilities audit log during the same window, and the plugin or theme slugs that appear in both. Suspects are a correlation and not proof: confirm before acting. Needs the audit module switched on and WP_DEBUG_LOG enabled to produce both halves; whatever is missing comes back as a warning.', 'super-abilities' );
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
				'since' => array(
					'type'        => 'string',
					'default'     => self::DEFAULT_SINCE,
					'description' => __( 'Start of the window. Accepts an ISO 8601 timestamp, a MySQL datetime in UTC or a relative expression such as "-24 hours". Default "-24 hours".', 'super-abilities' ),
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
		$change = Schema::object(
			array(
				'created_at'  => array( 'type' => 'string' ),
				'ability'     => array( 'type' => 'string' ),
				'outcome'     => array( 'type' => 'string' ),
				'user_id'     => array( 'type' => 'integer' ),
				'object_type' => array( 'type' => 'string' ),
				'object_id'   => array( 'type' => 'string' ),
				'transport'   => array( 'type' => 'string' ),
				'request_id'  => array( 'type' => 'string' ),
			),
			array( 'created_at', 'ability', 'outcome', 'user_id', 'object_type', 'object_id', 'transport', 'request_id' )
		);

		$suspect = Schema::object(
			array(
				'slug'    => array( 'type' => 'string' ),
				'type'    => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'reasons' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'slug', 'type', 'reasons' )
		);

		return Schema::object(
			array(
				'since'          => array( 'type' => 'string' ),
				'fatals'         => array(
					'type'  => 'array',
					'items' => Debug_Log_Read::group_schema(),
				),
				'recent_changes' => array(
					'type'  => 'array',
					'items' => $change,
				),
				'suspects'       => array(
					'type'  => 'array',
					'items' => $suspect,
				),
				'warnings'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'since', 'fatals', 'recent_changes', 'suspects', 'warnings' )
		);
	}

	/**
	 * Builds the triage report.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$raw_since = isset( $input['since'] ) && '' !== $input['since'] ? $input['since'] : self::DEFAULT_SINCE;
		$since     = Time::parse( $raw_since );

		if ( null === $since ) {
			return $this->error(
				'invalid_input',
				__( 'The since value could not be understood as a point in time.', 'super-abilities' ),
				400,
				array( 'since' => (string) $raw_since )
			);
		}

		$warnings = array();
		$fatals   = $this->fatals( $since, $warnings );
		$changes  = $this->recent_changes( $since, $warnings );

		return array(
			'since'          => Time::iso( $since ),
			'fatals'         => $fatals,
			'recent_changes' => $changes,
			'suspects'       => $this->suspects( $fatals, $changes ),
			'warnings'       => $warnings,
		);
	}

	/**
	 * Grouped fatal and error entries inside the window.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $since    Window start as a Unix timestamp.
	 * @param array<int, string> $warnings Warnings, appended to by reference.
	 * @return array<int, array<string, mixed>>
	 */
	protected function fatals( $since, array &$warnings ) {
		$path = Debug_Log_Read::log_path();

		if ( '' === $path ) {
			$warnings[] = __( 'WP_DEBUG_LOG is off, so no PHP errors could be read. Enable it to collect fatal errors.', 'super-abilities' );

			return array();
		}

		if ( ! is_readable( $path ) ) {
			$warnings[] = sprintf(
				/* translators: %s: Redacted file path. */
				__( 'The debug log at %s does not exist or cannot be read, so no PHP errors could be collected.', 'super-abilities' ),
				Redactor::text( $path )
			);

			return array();
		}

		$tail    = Debug_Log_Read::read_tail( $path, self::MAX_ENTRIES );
		$entries = Debug_Log_Parser::parse( $tail['text'] );
		$kept    = array();

		foreach ( $entries as $entry ) {
			if ( ! in_array( (string) $entry['level'], self::FATAL_LEVELS, true ) ) {
				continue;
			}

			if ( ! is_string( $entry['time'] ) || '' === $entry['time'] ) {
				continue;
			}

			$timestamp = Time::parse( $entry['time'] );

			if ( null === $timestamp || $timestamp < $since ) {
				continue;
			}

			$kept[] = $entry;
		}

		if ( $tail['truncated'] ) {
			$warnings[] = __( 'Only the most recent part of the debug log was read, so older errors inside the window may be missing.', 'super-abilities' );
		}

		$groups = Debug_Log_Parser::group( $kept );
		$clean  = array();

		foreach ( $groups as $group ) {
			foreach ( array( 'message', 'file', 'sample' ) as $key ) {
				if ( isset( $group[ $key ] ) ) {
					$group[ $key ] = Redactor::text( (string) $group[ $key ], true );
				}
			}

			$clean[] = $group;
		}

		return $clean;
	}

	/**
	 * Audit rows in the window that changed something.
	 *
	 * Rows are kept when the ability is one of our `event/*` markers or when its
	 * annotations say it is not read-only. Abilities that are no longer registered are
	 * kept, because we cannot prove they were harmless.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $since    Window start as a Unix timestamp.
	 * @param array<int, string> $warnings Warnings, appended to by reference.
	 * @return array<int, array<string, mixed>>
	 */
	protected function recent_changes( $since, array &$warnings ) {
		global $wpdb;

		if ( ! Plugin::instance()->options()->is_module_enabled( 'audit' ) ) {
			$warnings[] = __( 'The audit module is switched off, so recent changes are unknown. Turn it on under Settings to enable this correlation.', 'super-abilities' );

			return array();
		}

		$table = Install::table( 'audit_log' );

		if ( ! self::table_exists( $table ) ) {
			$warnings[] = __( 'The audit log table is missing, so recent changes are unknown. Deactivate and reactivate the plugin to create it.', 'super-abilities' );

			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own audit table: no core API exists for it and triage must see the newest rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, ability, outcome, user_id, object_type, object_id, transport, request_id
				FROM {$table}
				WHERE created_at >= %s AND outcome = 'ok'
				ORDER BY created_at DESC
				LIMIT %d",
				Time::mysql( $since ),
				self::MAX_ROWS
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$changes = array();

		foreach ( $rows as $row ) {
			$ability = isset( $row['ability'] ) ? (string) $row['ability'] : '';

			if ( ! $this->is_change( $ability ) ) {
				continue;
			}

			$changes[] = array(
				'created_at'  => Time::iso_from_mysql( isset( $row['created_at'] ) ? $row['created_at'] : '' ),
				'ability'     => $ability,
				'outcome'     => isset( $row['outcome'] ) ? (string) $row['outcome'] : '',
				'user_id'     => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
				'object_type' => isset( $row['object_type'] ) ? (string) $row['object_type'] : '',
				'object_id'   => isset( $row['object_id'] ) ? (string) $row['object_id'] : '',
				'transport'   => isset( $row['transport'] ) ? (string) $row['transport'] : '',
				'request_id'  => isset( $row['request_id'] ) ? (string) $row['request_id'] : '',
			);
		}

		return $changes;
	}

	/**
	 * Whether one of our tables exists.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table Fully prefixed table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence of our own table cannot be answered by a core API.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (string) $table === (string) $found;
	}

	/**
	 * Whether an audited ability name represents a change.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Ability name as recorded in the audit log.
	 * @return bool
	 */
	protected function is_change( $ability ) {
		if ( '' === $ability ) {
			return false;
		}

		if ( 0 === strpos( $ability, 'event/' ) ) {
			return true;
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return true;
		}

		$registered = wp_get_ability( $ability );

		if ( null === $registered ) {
			return true;
		}

		$meta = (array) $registered->get_meta();

		if ( ! isset( $meta['annotations']['readonly'] ) ) {
			return true;
		}

		return ! $meta['annotations']['readonly'];
	}

	/**
	 * Slugs that both crashed and changed.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $fatals  Grouped fatal entries.
	 * @param array<int, array<string, mixed>> $changes Recent change rows.
	 * @return array<int, array<string, mixed>>
	 */
	protected function suspects( array $fatals, array $changes ) {
		$crashed = array();

		foreach ( $fatals as $group ) {
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				if ( empty( $group[ $type ] ) ) {
					continue;
				}

				$slug = (string) $group[ $type ];
				$key  = $type . ':' . $slug;

				if ( ! isset( $crashed[ $key ] ) ) {
					$crashed[ $key ] = array(
						'slug'  => $slug,
						'type'  => $type,
						'count' => 0,
					);
				}

				$crashed[ $key ]['count'] += (int) $group['count'];
			}
		}

		if ( array() === $crashed ) {
			return array();
		}

		$suspects = array();

		foreach ( $changes as $change ) {
			$touched = self::touched_slug( $change );

			if ( array() === $touched ) {
				continue;
			}

			$key = $touched['type'] . ':' . $touched['slug'];

			if ( ! isset( $crashed[ $key ] ) ) {
				continue;
			}

			if ( ! isset( $suspects[ $key ] ) ) {
				$suspects[ $key ] = array(
					'slug'    => $touched['slug'],
					'type'    => $touched['type'],
					'reasons' => array(
						sprintf(
							/* translators: %d: Number of logged errors. */
							_n(
								'%d logged error points at files in this extension inside the window.',
								'%d logged errors point at files in this extension inside the window.',
								$crashed[ $key ]['count'],
								'super-abilities'
							),
							$crashed[ $key ]['count']
						),
					),
				);
			}

			$suspects[ $key ]['reasons'][] = sprintf(
				/* translators: 1: Ability name, 2: ISO 8601 timestamp. */
				__( 'Changed by %1$s at %2$s.', 'super-abilities' ),
				$change['ability'],
				$change['created_at']
			);
		}

		return array_values( $suspects );
	}

	/**
	 * The plugin or theme an audit row acted on.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return array<string, string> Either `slug` and `type`, or an empty array.
	 */
	protected static function touched_slug( array $change ) {
		$type = isset( $change['object_type'] ) ? (string) $change['object_type'] : '';
		$id   = isset( $change['object_id'] ) ? (string) $change['object_id'] : '';

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || '' === $id ) {
			return array();
		}

		$id = str_replace( '\\', '/', $id );

		if ( false !== strpos( $id, '/' ) ) {
			$parts = explode( '/', $id );
			$id    = (string) $parts[0];
		}

		$id = preg_replace( '/\.php$/', '', $id );

		if ( ! is_string( $id ) || '' === $id ) {
			return array();
		}

		return array(
			'slug' => $id,
			'type' => $type,
		);
	}
}
