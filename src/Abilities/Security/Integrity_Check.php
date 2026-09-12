<?php
/**
 * Core and plugin file integrity ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Security;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Security\Checksums;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the files on disk against the checksums published by WordPress.org.
 *
 * @since 0.1.0
 */
class Integrity_Check extends Abstract_Ability {

	/**
	 * Directories scanned for files core does not know about.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const CORE_SCAN_DIRS = array( 'wp-admin', 'wp-includes' );

	/**
	 * Wall clock budget for one run, in seconds.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TIME_BUDGET = 20;

	/**
	 * Maximum number of plugins that may be checked in one call.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_PLUGINS = 10;

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'integrity-check';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'security';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'File integrity check', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Compares the files of WordPress core, or of named plugins, against the official checksums published by WordPress.org and reports modified, missing and unexpected files. Plugins that are not hosted on WordPress.org, and core installs whose checksums are unavailable, are reported as "unverifiable" rather than as clean. Large sites may hit the twenty second budget, in which case "truncated" is true and you should narrow the scope or run the check through a background job.', 'super-abilities' );
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
				'scope'   => array(
					'type'        => 'string',
					'enum'        => array( 'core', 'plugins' ),
					'default'     => 'core',
					'description' => __( 'What to verify: "core" for the WordPress files, "plugins" for the plugins named in "plugins".', 'super-abilities' ),
				),
				'plugins' => Schema::csv_or_array_of_strings(
					sprintf(
						/* translators: %d: Maximum number of plugins per call. */
						__( 'Plugin directory slugs to verify, for example "akismet". Up to %d per call; use several calls or a background job for more.', 'super-abilities' ),
						self::MAX_PLUGINS
					)
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
		$paths = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		$plugin = Schema::object(
			array(
				'slug'     => array( 'type' => 'string' ),
				'version'  => array( 'type' => 'string' ),
				'status'   => array(
					'type' => 'string',
					'enum' => array( 'ok', 'modified', 'unverifiable', 'not_installed' ),
				),
				'reason'   => array( 'type' => 'string' ),
				'modified' => $paths,
				'missing'  => $paths,
				'unknown'  => $paths,
			),
			array( 'slug', 'version', 'status', 'reason', 'modified', 'missing', 'unknown' )
		);

		return Schema::object(
			array(
				'scope'      => array( 'type' => 'string' ),
				'wp_version' => array( 'type' => 'string' ),
				'locale'     => array( 'type' => 'string' ),
				'status'     => array(
					'type' => 'string',
					'enum' => array( 'ok', 'modified', 'unverifiable' ),
				),
				'modified'   => $paths,
				'missing'    => $paths,
				'unknown'    => $paths,
				'truncated'  => array( 'type' => 'boolean' ),
				'plugins'    => array(
					'type'  => 'array',
					'items' => $plugin,
				),
			),
			array( 'scope', 'wp_version', 'locale', 'status', 'modified', 'missing', 'unknown', 'truncated', 'plugins' )
		);
	}

	/**
	 * Runs the integrity check.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$scope    = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : 'core';
		$scope    = 'plugins' === $scope ? 'plugins' : 'core';
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$locale   = get_locale();
		$locale   = is_string( $locale ) && '' !== $locale ? $locale : 'en_US';

		$result = array(
			'scope'      => $scope,
			'wp_version' => (string) get_bloginfo( 'version' ),
			'locale'     => $locale,
			'status'     => 'ok',
			'modified'   => array(),
			'missing'    => array(),
			'unknown'    => array(),
			'truncated'  => false,
			'plugins'    => array(),
		);

		if ( 'plugins' === $scope ) {
			return $this->check_plugins( $input, $result, $deadline );
		}

		return $this->check_core( $result, $deadline );
	}

	/**
	 * Verifies the WordPress core files.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $result   Result skeleton.
	 * @param float                $deadline `microtime( true )` value after which work stops.
	 * @return array<string, mixed>
	 */
	protected function check_core( array $result, $deadline ) {
		$checksums = Checksums::core_checksums( $result['wp_version'], $result['locale'] );

		if ( null === $checksums || array() === $checksums ) {
			$result['status'] = 'unverifiable';

			return $result;
		}

		$comparison = Checksums::compare(
			$checksums,
			array( $this, 'hash_core_file' ),
			array(
				'skip_prefixes' => array( 'wp-content/' ),
				'max_entries'   => Checksums::MAX_ENTRIES,
				'deadline'      => $deadline,
			)
		);

		$result['modified']  = $this->redact_paths( $comparison['modified'] );
		$result['missing']   = $this->redact_paths( $comparison['missing'] );
		$result['truncated'] = (bool) $comparison['truncated'];

		$found     = array();
		$truncated = $comparison['truncated'];

		foreach ( self::CORE_SCAN_DIRS as $directory ) {
			if ( $truncated ) {
				break;
			}

			$scan  = Checksums::scan(
				ABSPATH . $directory,
				$directory . '/',
				array(
					'max_entries' => Checksums::MAX_ENTRIES * 20,
					'deadline'    => $deadline,
				)
			);
			$found = array_merge( $found, $scan['files'] );

			if ( $scan['truncated'] ) {
				$truncated = true;
			}
		}

		$unknown = Checksums::unknown(
			$found,
			$checksums,
			array( 'max_entries' => Checksums::MAX_ENTRIES )
		);

		$result['unknown']   = $this->redact_paths( $unknown['unknown'] );
		$result['truncated'] = (bool) ( $truncated || $unknown['truncated'] );

		if ( ! empty( $result['modified'] ) || ! empty( $result['missing'] ) ) {
			$result['status'] = 'modified';
		}

		return $result;
	}

	/**
	 * Verifies the named plugins.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input    Validated input.
	 * @param array<string, mixed> $result   Result skeleton.
	 * @param float                $deadline `microtime( true )` value after which work stops.
	 * @return array<string, mixed>
	 */
	protected function check_plugins( array $input, array $result, $deadline ) {
		$slugs = Schema::to_string_list( isset( $input['plugins'] ) ? $input['plugins'] : array() );
		$slugs = array_slice( array_filter( array_map( 'sanitize_key', $slugs ) ), 0, self::MAX_PLUGINS );

		if ( empty( $slugs ) ) {
			$result['status'] = 'unverifiable';

			return $result;
		}

		$installed  = $this->installed_plugin_versions();
		$modified   = false;
		$unverified = false;
		$truncated  = false;

		foreach ( $slugs as $slug ) {
			if ( ! isset( $installed[ $slug ] ) ) {
				$result['plugins'][] = array(
					'slug'     => $slug,
					'version'  => '',
					'status'   => 'not_installed',
					'reason'   => __( 'No plugin with this directory slug is installed.', 'super-abilities' ),
					'modified' => array(),
					'missing'  => array(),
					'unknown'  => array(),
				);

				$unverified = true;
				continue;
			}

			$report              = $this->check_plugin( $slug, (string) $installed[ $slug ], $deadline );
			$result['plugins'][] = $report;

			if ( 'modified' === $report['status'] ) {
				$modified = true;
			} elseif ( 'ok' !== $report['status'] ) {
				$unverified = true;
			}

			if ( microtime( true ) >= $deadline ) {
				$truncated = true;
				break;
			}
		}

		$result['truncated'] = $truncated;

		if ( $modified ) {
			$result['status'] = 'modified';
		} elseif ( $unverified ) {
			$result['status'] = 'unverifiable';
		}

		return $result;
	}

	/**
	 * Verifies one installed plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug     Plugin directory slug.
	 * @param string $version  Installed version.
	 * @param float  $deadline `microtime( true )` value after which work stops.
	 * @return array<string, mixed>
	 */
	protected function check_plugin( $slug, $version, $deadline ) {
		$report = array(
			'slug'     => $slug,
			'version'  => $version,
			'status'   => 'ok',
			'reason'   => '',
			'modified' => array(),
			'missing'  => array(),
			'unknown'  => array(),
		);

		$manifest = Checksums::plugin_checksums( $slug, $version );

		if ( 'ok' !== $manifest['status'] ) {
			$report['status'] = 'unverifiable';
			$report['reason'] = Redactor::text( $manifest['reason'] );

			return $report;
		}

		$directory = trailingslashit( WP_PLUGIN_DIR ) . $slug;

		$comparison = Checksums::compare(
			$manifest['files'],
			function ( $file ) use ( $directory ) {
				return $this->hash_file( $directory . '/' . $file );
			},
			array(
				'max_entries' => Checksums::MAX_ENTRIES,
				'deadline'    => $deadline,
			)
		);

		$scan    = Checksums::scan(
			$directory,
			'',
			array(
				'max_entries' => Checksums::MAX_ENTRIES * 4,
				'deadline'    => $deadline,
			)
		);
		$unknown = Checksums::unknown(
			$scan['files'],
			$manifest['files'],
			array( 'max_entries' => Checksums::MAX_ENTRIES )
		);

		$report['modified'] = $this->redact_paths( $comparison['modified'] );
		$report['missing']  = $this->redact_paths( $comparison['missing'] );
		$report['unknown']  = $this->redact_paths( $unknown['unknown'] );

		if ( ! empty( $report['modified'] ) || ! empty( $report['missing'] ) ) {
			$report['status'] = 'modified';
		}

		return $report;
	}

	/**
	 * Hashes a core file, given its path relative to `ABSPATH`.
	 *
	 * Public because it is used as a callable by {@see Checksums::compare()}.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Path relative to `ABSPATH`.
	 * @return string|null Lowercase md5, or null when the file is missing.
	 */
	public function hash_core_file( $file ) {
		return $this->hash_file( ABSPATH . ltrim( (string) $file, '/' ) );
	}

	/**
	 * Hashes one absolute path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path.
	 * @return string|null Lowercase md5, or null when the file is missing or unreadable.
	 */
	protected function hash_file( $path ) {
		$path = (string) $path;

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// Hashing is a read-only integrity check, so the native hasher is the right tool.
		$hash = md5_file( $path ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5_file -- md5 is the algorithm the WordPress.org checksum manifests publish.

		return is_string( $hash ) ? strtolower( $hash ) : null;
	}

	/**
	 * Installed plugin versions keyed by directory slug.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function installed_plugin_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$versions = array();

		foreach ( get_plugins() as $file => $data ) {
			$file = (string) $file;
			$slug = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );

			if ( '' === $slug || '.' === $slug ) {
				continue;
			}

			$versions[ $slug ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}

		return $versions;
	}

	/**
	 * Redacts a list of paths.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $paths Paths to redact.
	 * @return array<int, string>
	 */
	protected function redact_paths( array $paths ) {
		$clean = array();

		foreach ( $paths as $path ) {
			$clean[] = Redactor::text( (string) $path );
		}

		return $clean;
	}
}
