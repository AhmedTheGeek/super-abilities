<?php
/**
 * PHP debug log parser.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw `debug.log` text into structured entries and grouped occurrences.
 *
 * This class is deliberately free of WordPress calls so that it can be unit tested
 * on its own. Redaction happens in the calling ability, never here.
 *
 * @since 0.1.0
 */
class Debug_Log_Parser {

	/**
	 * Levels an entry can be classified as.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const LEVELS = array( 'fatal', 'error', 'warning', 'notice', 'deprecated', 'other' );

	/**
	 * Maximum number of characters kept in `raw_excerpt`.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const EXCERPT_LIMIT = 1200;

	/**
	 * PHP error label to level.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const LEVEL_MAP = array(
		'fatal error'             => 'fatal',
		'parse error'             => 'fatal',
		'compile error'           => 'fatal',
		'core error'              => 'fatal',
		'recoverable fatal error' => 'error',
		'error'                   => 'error',
		'user error'              => 'error',
		'warning'                 => 'warning',
		'core warning'            => 'warning',
		'compile warning'         => 'warning',
		'user warning'            => 'warning',
		'notice'                  => 'notice',
		'user notice'             => 'notice',
		'strict standards'        => 'notice',
		'deprecated'              => 'deprecated',
		'user deprecated'         => 'deprecated',
	);

	/**
	 * Parses debug log text into entries, newest last.
	 *
	 * Multi-line messages (`Stack trace:`, `#0 ...`, `thrown in ...`) are attached to
	 * the entry that precedes them rather than reported as entries of their own.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Raw log text.
	 * @return array<int, array<string, mixed>> Entries with `time`, `level`, `message`,
	 *                                          `file`, `line`, optional `plugin` or
	 *                                          `theme`, and `raw_excerpt`.
	 */
	public static function parse( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return array();
		}

		$lines   = preg_split( "/\r\n|\n|\r/", $text );
		$lines   = is_array( $lines ) ? $lines : array();
		$entries = array();
		$last    = -1;

		foreach ( $lines as $raw_line ) {
			if ( '' === trim( (string) $raw_line ) ) {
				continue;
			}

			$time = null;
			$body = (string) $raw_line;

			if ( preg_match( '/^\[([^\]]{4,64})\]\s?(.*)$/', $body, $matches ) ) {
				$parsed = self::parse_time( $matches[1] );

				if ( null !== $parsed ) {
					$time = $parsed;
					$body = $matches[2];
				}
			}

			if ( $last >= 0 && self::is_continuation( (string) $raw_line, $body ) ) {
				$entries[ $last ] = self::append_continuation( $entries[ $last ], (string) $raw_line, $body );
				continue;
			}

			$entries[] = self::build_entry( $time, $body, (string) $raw_line );
			++$last;
		}

		return $entries;
	}

	/**
	 * Groups identical entries by level, message, file and line.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $entries Entries from {@see Debug_Log_Parser::parse()}.
	 * @return array<int, array<string, mixed>> Groups, most frequent first.
	 */
	public static function group( array $entries ) {
		$groups = array();

		foreach ( $entries as $entry ) {
			$level   = isset( $entry['level'] ) ? (string) $entry['level'] : 'other';
			$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
			$file    = isset( $entry['file'] ) ? (string) $entry['file'] : '';
			$line    = isset( $entry['line'] ) ? (int) $entry['line'] : 0;
			$time    = isset( $entry['time'] ) && is_string( $entry['time'] ) ? $entry['time'] : '';
			$key     = $level . "\0" . $message . "\0" . $file . "\0" . $line;

			if ( ! isset( $groups[ $key ] ) ) {
				$group = array(
					'level'   => $level,
					'message' => $message,
					'file'    => $file,
					'line'    => $line,
				);

				if ( isset( $entry['plugin'] ) ) {
					$group['plugin'] = (string) $entry['plugin'];
				}

				if ( isset( $entry['theme'] ) ) {
					$group['theme'] = (string) $entry['theme'];
				}

				$group['count']      = 0;
				$group['first_seen'] = $time;
				$group['last_seen']  = $time;
				$group['sample']     = isset( $entry['raw_excerpt'] ) ? (string) $entry['raw_excerpt'] : '';

				$groups[ $key ] = $group;
			}

			++$groups[ $key ]['count'];
			$groups[ $key ]['sample'] = isset( $entry['raw_excerpt'] ) ? (string) $entry['raw_excerpt'] : $groups[ $key ]['sample'];

			if ( '' !== $time ) {
				if ( '' === $groups[ $key ]['first_seen'] || $time < $groups[ $key ]['first_seen'] ) {
					$groups[ $key ]['first_seen'] = $time;
				}

				if ( '' === $groups[ $key ]['last_seen'] || $time > $groups[ $key ]['last_seen'] ) {
					$groups[ $key ]['last_seen'] = $time;
				}
			}
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			static function ( $a, $b ) {
				if ( $a['count'] === $b['count'] ) {
					return strcmp( (string) $b['last_seen'], (string) $a['last_seen'] );
				}

				return $b['count'] <=> $a['count'];
			}
		);

		return $groups;
	}

	/**
	 * Rough count of how many entries a chunk of log text holds.
	 *
	 * Used by the reverse tail reader to decide whether it has read far enough back.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Raw log text.
	 * @return int
	 */
	public static function count_entries_hint( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return 0;
		}

		$stamped = (int) preg_match_all( '/^\[[^\]]{4,64}\]/m', $text );

		if ( $stamped > 0 ) {
			return $stamped;
		}

		return (int) preg_match_all( '/^\S/m', $text );
	}

	/**
	 * Attributes a file path to a plugin or a theme.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path File path, absolute or relative.
	 * @return array<string, string> Either `array( 'plugin' => $slug )`,
	 *                               `array( 'theme' => $slug )` or an empty array.
	 */
	public static function attribute( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		if ( '' === $path ) {
			return array();
		}

		if ( preg_match( '#/wp-content/(?:plugins|mu-plugins)/([^/]+)/#', $path, $matches ) ) {
			return array( 'plugin' => $matches[1] );
		}

		if ( preg_match( '#/wp-content/themes/([^/]+)/#', $path, $matches ) ) {
			return array( 'theme' => $matches[1] );
		}

		return array();
	}

	/**
	 * Builds one entry.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $time Entry time as ISO 8601 UTC, or null when unknown.
	 * @param string      $body Line with the timestamp removed.
	 * @param string      $raw  The untouched line.
	 * @return array<string, mixed>
	 */
	protected static function build_entry( $time, $body, $raw ) {
		$message = trim( (string) $body );
		$level   = 'other';

		if ( preg_match( '/^(?:PHP\s+)?(Fatal error|Parse error|Compile error|Core error|Recoverable fatal error|Compile warning|Core warning|User warning|User notice|User error|User deprecated|Strict Standards|Warning|Notice|Deprecated|Error)\s*:\s*(.*)$/i', $message, $matches ) ) {
			$label   = strtolower( trim( $matches[1] ) );
			$level   = isset( self::LEVEL_MAP[ $label ] ) ? self::LEVEL_MAP[ $label ] : 'other';
			$message = trim( $matches[2] );
		} elseif ( 0 === stripos( $message, 'WordPress database error' ) ) {
			$level = 'error';
		}

		$location = self::locate( $message );

		$entry = array(
			'time'    => is_string( $time ) ? $time : null,
			'level'   => $level,
			'message' => $message,
			'file'    => $location['file'],
			'line'    => $location['line'],
		);

		$attribution = self::attribute( $location['file'] );

		if ( array() === $attribution ) {
			$attribution = self::attribute( $message );
		}

		$entry = array_merge( $entry, $attribution );

		$entry['raw_excerpt'] = self::cap( trim( (string) $raw ) );

		return $entry;
	}

	/**
	 * Appends a continuation line to the entry it belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $entry Entry to extend.
	 * @param string               $raw   The untouched line.
	 * @param string               $body  Line with the timestamp removed.
	 * @return array<string, mixed>
	 */
	protected static function append_continuation( array $entry, $raw, $body ) {
		$entry['raw_excerpt'] = self::cap( $entry['raw_excerpt'] . "\n" . trim( (string) $raw ) );

		if ( '' === (string) $entry['file'] ) {
			$location = self::locate( (string) $body );

			if ( '' !== $location['file'] ) {
				$entry['file'] = $location['file'];
				$entry['line'] = $location['line'];

				if ( ! isset( $entry['plugin'] ) && ! isset( $entry['theme'] ) ) {
					$entry = array_merge( $entry, self::attribute( $location['file'] ) );
				}
			}
		}

		return $entry;
	}

	/**
	 * Whether a line continues the previous entry instead of starting a new one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw  The untouched line.
	 * @param string $body Line with the timestamp removed.
	 * @return bool
	 */
	protected static function is_continuation( $raw, $body ) {
		if ( preg_match( '/^\s/', (string) $raw ) ) {
			return true;
		}

		$body = trim( (string) $body );

		if ( '' === $body ) {
			return true;
		}

		return (bool) preg_match( '/^(?:PHP\s+)?(?:Stack trace:|#\d+\s|\{main\}|thrown in |\d+\.\s)/i', $body );
	}

	/**
	 * Extracts the file and line a message points at.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message text.
	 * @return array{file: string, line: int}
	 */
	protected static function locate( $message ) {
		$message = (string) $message;

		if ( preg_match( '/\bin (\S+\.php) on line (\d+)/', $message, $matches ) ) {
			return array(
				'file' => $matches[1],
				'line' => (int) $matches[2],
			);
		}

		if ( preg_match( '/\bin (\S+\.php):(\d+)/', $message, $matches ) ) {
			return array(
				'file' => $matches[1],
				'line' => (int) $matches[2],
			);
		}

		return array(
			'file' => '',
			'line' => 0,
		);
	}

	/**
	 * Parses the bracketed timestamp PHP writes in front of each entry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Bracket contents, e.g. `12-Sep-2026 10:00:00 UTC`.
	 * @return string|null ISO 8601 UTC timestamp, or null when the value is not a date.
	 */
	protected static function parse_time( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || ! preg_match( '/\d/', $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Truncates an excerpt.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to cap.
	 * @return string
	 */
	protected static function cap( $text ) {
		$text = (string) $text;

		if ( strlen( $text ) <= self::EXCERPT_LIMIT ) {
			return $text;
		}

		return substr( $text, 0, self::EXCERPT_LIMIT ) . '...';
	}
}
