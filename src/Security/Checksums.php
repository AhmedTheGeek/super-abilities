<?php
/**
 * Checksum comparison for core and plugin files.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Security;

use SuperAbilities\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Compares files on disk against the checksums published by WordPress.org.
 *
 * The comparison itself is pure: {@see Checksums::compare()} takes a map of
 * `relative path => expected hash` plus a callable that hashes one path, so it can be
 * unit tested without touching the filesystem or the network.
 *
 * Remote checksum manifests are cached in transients for twelve hours because the
 * WordPress.org endpoints have undocumented rate limits.
 *
 * @since 0.1.0
 */
class Checksums {

	/**
	 * Transient key prefix for cached plugin manifests.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'super_abilities_cs_';

	/**
	 * How long a fetched manifest stays cached, in seconds.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * Host serving the plugin checksum manifests.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CHECKSUM_HOST = 'downloads.wordpress.org';

	/**
	 * URL template for a plugin checksum manifest.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PLUGIN_CHECKSUM_URL = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';

	/**
	 * Maximum number of findings or scanned files reported per run.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Maximum size of a manifest we are willing to download, in bytes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_MANIFEST_BYTES = 4194304;

	/**
	 * File names that are never reported as unknown.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const IGNORED_NAMES = array( '.htaccess', '.DS_Store', 'Thumbs.db', '.gitkeep' );

	/**
	 * Fetches the core checksums for a version, falling back to the `en_US` manifest.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version WordPress version, e.g. `6.9`.
	 * @param string $locale  Locale, e.g. `de_DE`.
	 * @return array<string, string>|null Map of relative path to md5, or null when the
	 *                                    manifest is unavailable.
	 */
	public static function core_checksums( $version, $locale ) {
		$version = (string) $version;
		$locale  = (string) $locale;

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$checksums = get_core_checksums( $version, '' === $locale ? 'en_US' : $locale );

		if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
			$checksums = get_core_checksums( $version, 'en_US' );
		}

		if ( ! is_array( $checksums ) ) {
			return null;
		}

		$clean = array();

		foreach ( $checksums as $file => $hash ) {
			if ( is_string( $hash ) ) {
				$clean[ (string) $file ] = $hash;
			}
		}

		return $clean;
	}

	/**
	 * Fetches, and caches, the checksum manifest of a WordPress.org plugin version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug    Plugin directory slug.
	 * @param string $version Installed plugin version.
	 * @return array{status: string, files: array<string, mixed>, reason: string} The status is
	 *                        `ok` when a manifest was returned and `unverifiable` otherwise,
	 *                        which is what happens for plugins that are not hosted on
	 *                        WordPress.org.
	 */
	public static function plugin_checksums( $slug, $version ) {
		$slug    = sanitize_key( (string) $slug );
		$version = trim( (string) $version );

		if ( '' === $slug || '' === $version ) {
			return self::unverifiable( __( 'The plugin slug or version is unknown.', 'super-abilities' ) );
		}

		$key    = self::TRANSIENT_PREFIX . md5( $slug . '|' . $version );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return array(
				'status' => (string) $cached['status'],
				'files'  => isset( $cached['files'] ) && is_array( $cached['files'] ) ? $cached['files'] : array(),
				'reason' => isset( $cached['reason'] ) ? (string) $cached['reason'] : '',
			);
		}

		$response = Http::get(
			sprintf( self::PLUGIN_CHECKSUM_URL, $slug, rawurlencode( $version ) ),
			array(
				'hosts'     => array( self::CHECKSUM_HOST ),
				'timeout'   => 15,
				'max_bytes' => self::MAX_MANIFEST_BYTES,
			)
		);

		// A transport failure may be temporary, so it is reported but never cached.
		if ( is_wp_error( $response ) ) {
			return self::unverifiable( $response->get_error_message() );
		}

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;

		if ( 200 !== $code ) {
			$result = self::unverifiable(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'WordPress.org does not publish checksums for this plugin version (HTTP %d).', 'super-abilities' ),
					$code
				)
			);

			set_transient( $key, $result, self::CACHE_TTL );

			return $result;
		}

		$data = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );

		if ( ! is_array( $data ) || empty( $data['files'] ) || ! is_array( $data['files'] ) ) {
			$result = self::unverifiable( __( 'The checksum manifest could not be read.', 'super-abilities' ) );

			set_transient( $key, $result, self::CACHE_TTL );

			return $result;
		}

		$result = array(
			'status' => 'ok',
			'files'  => $data['files'],
			'reason' => '',
		);

		set_transient( $key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Compares expected hashes against the hashes of the files on disk.
	 *
	 * This function performs no I/O of its own: `$hasher` receives one relative path and
	 * returns the hash of that file, or null when the file does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $expected Map of relative path to expected hash. The hash
	 *                                       may be a string, a list of acceptable strings, or
	 *                                       a `{md5: [...]}` structure as published for plugins.
	 * @param callable             $hasher   Callable receiving a relative path and returning a
	 *                                       hash string, or null/false when the file is missing.
	 * @param array<string, mixed> $args     {
	 *     Optional. Comparison options.
	 *
	 *     @type array<int, string> $skip_prefixes Relative path prefixes to leave out entirely.
	 *     @type int                $max_entries   Maximum number of findings. Default 500.
	 *     @type float              $deadline      `microtime( true )` value after which the
	 *                                             comparison stops. 0 disables the budget.
	 * }
	 * @return array{modified: array<int, string>, missing: array<int, string>, checked: int, skipped: int, truncated: bool}
	 */
	public static function compare( array $expected, callable $hasher, array $args = array() ) {
		$args = array_merge(
			array(
				'skip_prefixes' => array(),
				'max_entries'   => self::MAX_ENTRIES,
				'deadline'      => 0.0,
			),
			$args
		);

		$max_entries = max( 1, (int) $args['max_entries'] );
		$prefixes    = is_array( $args['skip_prefixes'] ) ? $args['skip_prefixes'] : array();

		$modified  = array();
		$missing   = array();
		$checked   = 0;
		$skipped   = 0;
		$truncated = false;

		foreach ( $expected as $file => $hash ) {
			$file = (string) $file;

			if ( '' === $file || self::is_skipped( $file, $prefixes ) ) {
				++$skipped;
				continue;
			}

			if ( self::out_of_budget( $args['deadline'] ) ) {
				$truncated = true;
				break;
			}

			++$checked;

			$actual = call_user_func( $hasher, $file );

			if ( null === $actual || false === $actual || '' === $actual ) {
				$missing[] = $file;
			} elseif ( ! self::hash_matches( $hash, (string) $actual ) ) {
				$modified[] = $file;
			}

			if ( count( $modified ) + count( $missing ) >= $max_entries ) {
				$truncated = true;
				break;
			}
		}

		sort( $modified );
		sort( $missing );

		return array(
			'modified'  => $modified,
			'missing'   => $missing,
			'checked'   => $checked,
			'skipped'   => $skipped,
			'truncated' => $truncated,
		);
	}

	/**
	 * Files present on disk that the manifest does not know about.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>   $found    Relative paths found on disk.
	 * @param array<string, mixed> $expected Map of expected relative path to hash.
	 * @param array<string, mixed> $args     {
	 *     Optional. Options.
	 *
	 *     @type array<int, string> $skip_prefixes Relative path prefixes to leave out.
	 *     @type int                $max_entries   Maximum number of findings. Default 500.
	 * }
	 * @return array{unknown: array<int, string>, truncated: bool}
	 */
	public static function unknown( array $found, array $expected, array $args = array() ) {
		$args = array_merge(
			array(
				'skip_prefixes' => array(),
				'max_entries'   => self::MAX_ENTRIES,
			),
			$args
		);

		$max_entries = max( 1, (int) $args['max_entries'] );
		$prefixes    = is_array( $args['skip_prefixes'] ) ? $args['skip_prefixes'] : array();

		$unknown   = array();
		$truncated = false;

		foreach ( $found as $file ) {
			$file = (string) $file;

			if ( '' === $file || isset( $expected[ $file ] ) || self::is_skipped( $file, $prefixes ) ) {
				continue;
			}

			$unknown[] = $file;

			if ( count( $unknown ) >= $max_entries ) {
				$truncated = true;
				break;
			}
		}

		sort( $unknown );

		return array(
			'unknown'   => $unknown,
			'truncated' => $truncated,
		);
	}

	/**
	 * Lists every file below a directory, as paths relative to a prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $root   Absolute directory to walk.
	 * @param string               $prefix Prefix prepended to every returned path, e.g. `wp-admin/`.
	 * @param array<string, mixed> $args   {
	 *     Optional. Options.
	 *
	 *     @type int                $max_entries  Maximum number of files. Default 500.
	 *     @type float              $deadline     `microtime( true )` value after which the walk stops.
	 *     @type array<int, string> $ignore_names File names to skip. Default {@see Checksums::IGNORED_NAMES}.
	 * }
	 * @return array{files: array<int, string>, truncated: bool}
	 */
	public static function scan( $root, $prefix, array $args = array() ) {
		$args = array_merge(
			array(
				'max_entries'  => self::MAX_ENTRIES,
				'deadline'     => 0.0,
				'ignore_names' => self::IGNORED_NAMES,
			),
			$args
		);

		$max_entries = max( 1, (int) $args['max_entries'] );
		$ignored     = is_array( $args['ignore_names'] ) ? $args['ignore_names'] : array();

		$files     = array();
		$truncated = false;
		$root      = untrailingslashit( str_replace( '\\', '/', (string) $root ) );

		if ( '' === $root || ! is_dir( $root ) ) {
			return array(
				'files'     => array(),
				'truncated' => false,
			);
		}

		$stack = array( array( $root, (string) $prefix ) );

		while ( ! empty( $stack ) ) {
			$frame       = array_pop( $stack );
			$directory   = $frame[0];
			$path_prefix = $frame[1];

			// Unreadable directories are common on shared hosting and must not warn.
			$entries = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- scandir() warns on directories the web user cannot read; an unreadable directory is simply skipped.

			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = $directory . '/' . $entry;

				if ( is_dir( $path ) ) {
					$stack[] = array( $path, $path_prefix . $entry . '/' );
					continue;
				}

				if ( in_array( $entry, $ignored, true ) ) {
					continue;
				}

				$files[] = $path_prefix . $entry;

				if ( count( $files ) >= $max_entries || self::out_of_budget( $args['deadline'] ) ) {
					$truncated = true;

					return array(
						'files'     => $files,
						'truncated' => $truncated,
					);
				}
			}
		}

		return array(
			'files'     => $files,
			'truncated' => $truncated,
		);
	}

	/**
	 * Whether a hash on disk matches any of the acceptable expected hashes.
	 *
	 * When the manifest publishes no md5 for a file, the file is treated as matching:
	 * we would rather stay silent than report a finding we cannot prove.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $expected Expected hash, list of hashes, or `{md5: [...]}` structure.
	 * @param string $actual   Hash of the file on disk.
	 * @return bool
	 */
	public static function hash_matches( $expected, $actual ) {
		$actual = strtolower( trim( (string) $actual ) );

		if ( '' === $actual ) {
			return false;
		}

		$candidates = self::hash_candidates( $expected );

		if ( empty( $candidates ) ) {
			return true;
		}

		foreach ( $candidates as $candidate ) {
			if ( hash_equals( $candidate, $actual ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes an expected hash into a list of lowercase md5 strings.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $expected Expected hash in any of the published shapes.
	 * @return array<int, string>
	 */
	protected static function hash_candidates( $expected ) {
		if ( is_string( $expected ) ) {
			$expected = trim( $expected );

			return '' === $expected ? array() : array( strtolower( $expected ) );
		}

		if ( ! is_array( $expected ) ) {
			return array();
		}

		if ( isset( $expected['md5'] ) ) {
			$expected = $expected['md5'];
		} elseif ( isset( $expected['sha256'] ) ) {
			// Only md5 is comparable with what we compute, so this file stays unverified.
			return array();
		}

		$candidates = array();

		foreach ( (array) $expected as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}

			$candidate = trim( $candidate );

			if ( '' !== $candidate ) {
				$candidates[] = strtolower( $candidate );
			}
		}

		return $candidates;
	}

	/**
	 * Whether a relative path starts with any of the skipped prefixes.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $file     Relative path.
	 * @param array<int, string> $prefixes Prefixes to skip.
	 * @return bool
	 */
	protected static function is_skipped( $file, array $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			$prefix = (string) $prefix;

			if ( '' !== $prefix && 0 === strpos( $file, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the time budget for this run is used up.
	 *
	 * @since 0.1.0
	 *
	 * @param float $deadline `microtime( true )` value after which work must stop. 0 disables it.
	 * @return bool
	 */
	protected static function out_of_budget( $deadline ) {
		$deadline = (float) $deadline;

		return $deadline > 0.0 && microtime( true ) >= $deadline;
	}

	/**
	 * Builds an `unverifiable` manifest result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Human readable reason.
	 * @return array{status: string, files: array<string, mixed>, reason: string}
	 */
	protected static function unverifiable( $reason ) {
		return array(
			'status' => 'unverifiable',
			'files'  => array(),
			'reason' => (string) $reason,
		);
	}
}
