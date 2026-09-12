<?php
/**
 * Reads and groups the tail of the PHP debug log.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Health\Debug_Log_Parser;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Structured access to `debug.log`, read backwards from the end.
 *
 * A missing or disabled log is not an error: the ability says so in its output so
 * that an agent can tell the difference between "no errors" and "no log".
 *
 * @since 0.1.0
 */
class Debug_Log_Read extends Abstract_Ability {

	/**
	 * Largest slice of the log we are willing to read, in bytes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_BYTES = 2097152;

	/**
	 * Size of one reverse read, in bytes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK_BYTES = 65536;

	/**
	 * Default number of entries returned.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const DEFAULT_LINES = 100;

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'debug-log-read';
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
		return __( 'Read the debug log', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reads the end of the WordPress debug log and returns parsed entries plus grouped occurrences, with the file, line and owning plugin or theme for each one. Stack traces are attached to the entry they belong to. Paths and secrets are redacted. When logging is off or the file does not exist the ability returns enabled or exists as false with empty lists instead of failing, so it is always safe to call.', 'super-abilities' );
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
				'lines' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => self::DEFAULT_LINES,
					'description' => __( 'How many of the most recent entries to return. Default 100, maximum 500.', 'super-abilities' ),
				),
				'since' => array(
					'type'        => 'string',
					'description' => __( 'Only return entries at or after this point in time. Accepts an ISO 8601 timestamp, a MySQL datetime in UTC or a relative expression such as "-2 hours". Entries whose timestamp cannot be parsed are left out when this is set.', 'super-abilities' ),
				),
				'level' => array(
					'type'        => 'string',
					'enum'        => Debug_Log_Parser::LEVELS,
					'description' => __( 'Only return entries of this level.', 'super-abilities' ),
				),
				'group' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether to also return occurrences grouped by level, message, file and line. Default true.', 'super-abilities' ),
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
		$entry = Schema::object(
			array(
				'time'        => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'ISO 8601 UTC timestamp, or null when the line carries none.', 'super-abilities' ),
				),
				'level'       => array(
					'type' => 'string',
					'enum' => Debug_Log_Parser::LEVELS,
				),
				'message'     => array( 'type' => 'string' ),
				'file'        => array( 'type' => 'string' ),
				'line'        => array( 'type' => 'integer' ),
				'plugin'      => array( 'type' => 'string' ),
				'theme'       => array( 'type' => 'string' ),
				'raw_excerpt' => array( 'type' => 'string' ),
			),
			array( 'time', 'level', 'message', 'file', 'line', 'raw_excerpt' )
		);

		return Schema::object(
			array(
				'enabled'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether WP_DEBUG_LOG is switched on.', 'super-abilities' ),
				),
				'path'          => array(
					'type'        => 'string',
					'description' => __( 'Redacted path of the log file.', 'super-abilities' ),
				),
				'exists'        => array( 'type' => 'boolean' ),
				'size_bytes'    => array( 'type' => 'integer' ),
				'scanned_bytes' => array( 'type' => 'integer' ),
				'truncated'     => array(
					'type'        => 'boolean',
					'description' => __( 'True when older entries exist beyond the part of the file that was read.', 'super-abilities' ),
				),
				'entries'       => array(
					'type'  => 'array',
					'items' => $entry,
				),
				'groups'        => array(
					'type'  => 'array',
					'items' => self::group_schema(),
				),
			),
			array( 'enabled', 'path', 'exists', 'size_bytes', 'scanned_bytes', 'truncated', 'entries', 'groups' )
		);
	}

	/**
	 * Schema of one grouped occurrence, shared with `error-triage`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function group_schema() {
		return Schema::object(
			array(
				'level'      => array(
					'type' => 'string',
					'enum' => Debug_Log_Parser::LEVELS,
				),
				'message'    => array( 'type' => 'string' ),
				'file'       => array( 'type' => 'string' ),
				'line'       => array( 'type' => 'integer' ),
				'plugin'     => array( 'type' => 'string' ),
				'theme'      => array( 'type' => 'string' ),
				'count'      => array( 'type' => 'integer' ),
				'first_seen' => array( 'type' => 'string' ),
				'last_seen'  => array( 'type' => 'string' ),
				'sample'     => array( 'type' => 'string' ),
			),
			array( 'level', 'message', 'file', 'line', 'count', 'first_seen', 'last_seen', 'sample' )
		);
	}

	/**
	 * Reads the log.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$lines = isset( $input['lines'] ) ? (int) $input['lines'] : self::DEFAULT_LINES;
		$lines = max( 1, min( 500, $lines ) );
		$level = isset( $input['level'] ) && in_array( (string) $input['level'], Debug_Log_Parser::LEVELS, true ) ? (string) $input['level'] : '';
		$group = ! isset( $input['group'] ) || (bool) $input['group'];
		$since = isset( $input['since'] ) && '' !== $input['since'] ? Time::parse( $input['since'] ) : null;

		$path    = self::log_path();
		$enabled = '' !== $path;

		$response = array(
			'enabled'       => $enabled,
			'path'          => Redactor::text( $path ),
			'exists'        => false,
			'size_bytes'    => 0,
			'scanned_bytes' => 0,
			'truncated'     => false,
			'entries'       => array(),
			'groups'        => array(),
		);

		if ( ! $enabled || ! is_readable( $path ) ) {
			return $response;
		}

		$tail = self::read_tail( $path, $lines );

		$response['exists']        = true;
		$response['size_bytes']    = $tail['size'];
		$response['scanned_bytes'] = $tail['scanned'];
		$response['truncated']     = $tail['truncated'];

		$entries = Debug_Log_Parser::parse( $tail['text'] );
		$entries = self::filter( $entries, $level, $since );
		$entries = array_slice( $entries, -$lines );

		$response['entries'] = self::redact_entries( $entries );

		if ( $group ) {
			$response['groups'] = self::redact_groups( Debug_Log_Parser::group( $entries ) );
		}

		return $response;
	}

	/**
	 * The debug log path, or an empty string when logging to a file is off.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function log_path() {
		$path = '';

		if ( defined( 'WP_DEBUG_LOG' ) ) {
			// `constant()` keeps static analysis from assuming the documented bool type.
			$setting = constant( 'WP_DEBUG_LOG' );

			if ( is_string( $setting ) && '' !== $setting ) {
				$path = $setting;
			} elseif ( $setting ) {
				$path = rtrim( WP_CONTENT_DIR, '/\\' ) . '/debug.log';
			}
		}

		/**
		 * Filters the path of the log `debug-log-read` and `error-triage` read.
		 *
		 * Useful when PHP logs somewhere else entirely, for example through an
		 * `error_log` directive in php.ini. Return an empty string to report logging as
		 * switched off.
		 *
		 * @since 0.1.0
		 *
		 * @param string $path Absolute path derived from `WP_DEBUG_LOG`, or an empty
		 *                     string when logging to a file is off.
		 */
		return (string) apply_filters( 'super_abilities_debug_log_path', $path );
	}

	/**
	 * Reads the end of a file until it holds enough entries.
	 *
	 * Seeks backwards in 64 KB chunks and never reads more than 2 MB. Uses `fopen()`
	 * rather than `WP_Filesystem` because the log can be large, the read must start at
	 * the end of the file, and no credentials prompt may ever happen inside an ability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  Absolute path to the file.
	 * @param int    $lines Number of entries wanted.
	 * @return array{text: string, size: int, scanned: int, truncated: bool}
	 */
	public static function read_tail( $path, $lines ) {
		$empty = array(
			'text'      => '',
			'size'      => 0,
			'scanned'   => 0,
			'truncated' => false,
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem cannot seek, and this reads only the tail of a possibly huge log.
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A log that disappears mid request must not warn.

		if ( $size < 1 ) {
			$empty['size'] = max( 0, $size );

			return $empty;
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable log is reported through the output, not a warning.

		if ( ! $handle ) {
			$empty['size'] = $size;

			return $empty;
		}

		$buffer   = '';
		$scanned  = 0;
		$position = $size;
		$wanted   = max( 1, (int) $lines );

		while ( $position > 0 && $scanned < self::MAX_BYTES ) {
			$read = (int) min( self::CHUNK_BYTES, $position, self::MAX_BYTES - $scanned );

			if ( $read < 1 ) {
				break;
			}

			$position -= $read;

			if ( -1 === fseek( $handle, $position ) ) {
				break;
			}

			$chunk = fread( $handle, $read );

			if ( false === $chunk ) {
				break;
			}

			$buffer   = $chunk . $buffer;
			$scanned += strlen( $chunk );

			if ( Debug_Log_Parser::count_entries_hint( $buffer ) > $wanted ) {
				break;
			}
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		if ( $position > 0 ) {
			// The first line of the buffer may be half an entry, so drop it.
			$newline = strpos( $buffer, "\n" );
			$buffer  = false === $newline ? '' : substr( $buffer, $newline + 1 );
		}

		return array(
			'text'      => $buffer,
			'size'      => $size,
			'scanned'   => $scanned,
			'truncated' => $position > 0,
		);
	}

	/**
	 * Applies the level and time filters.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $entries Parsed entries.
	 * @param string                           $level   Level to keep, or an empty string for all.
	 * @param int|null                         $since   Unix timestamp to start at, or null.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function filter( array $entries, $level, $since ) {
		if ( '' === $level && null === $since ) {
			return $entries;
		}

		$kept = array();

		foreach ( $entries as $entry ) {
			if ( '' !== $level && $level !== $entry['level'] ) {
				continue;
			}

			if ( null !== $since ) {
				if ( ! is_string( $entry['time'] ) || '' === $entry['time'] ) {
					continue;
				}

				$timestamp = Time::parse( $entry['time'] );

				if ( null === $timestamp || $timestamp < $since ) {
					continue;
				}
			}

			$kept[] = $entry;
		}

		return $kept;
	}

	/**
	 * Redacts every string in a list of entries.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $entries Parsed entries.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function redact_entries( array $entries ) {
		$clean = array();

		foreach ( $entries as $entry ) {
			foreach ( array( 'message', 'file', 'raw_excerpt' ) as $key ) {
				if ( isset( $entry[ $key ] ) ) {
					$entry[ $key ] = Redactor::text( (string) $entry[ $key ], true );
				}
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Redacts every string in a list of groups.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $groups Grouped occurrences.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function redact_groups( array $groups ) {
		$clean = array();

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
}
