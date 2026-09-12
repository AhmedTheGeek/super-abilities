<?php
/**
 * File permission and exposure audit ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Security;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Security\Probes;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the permissions of the sensitive paths and whether private files are public.
 *
 * @since 0.1.0
 */
class File_Permissions_Audit extends Abstract_Ability {

	/**
	 * Suffixes that turn `wp-config.php` into a readable backup copy.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const STRAY_SUFFIXES = array( '.bak', '.orig', '.save', '~', '.old', '.txt' );

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'file-permissions-audit';
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
		return __( 'File permissions audit', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reports the permissions of the sensitive files and directories of this install, flags anything world writable or a world readable wp-config.php, finds leftover configuration backups such as wp-config.php.bak, and probes over HTTP whether files that should be private, including debug.log, readme.html and the uploads directory listing, are reachable by anyone. Read only: it reports and recommends, it never changes a permission.', 'super-abilities' );
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
		return Schema::object( array() );
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
				'path'           => array( 'type' => 'string' ),
				'exists'         => array( 'type' => 'boolean' ),
				'perms'          => array( 'type' => 'string' ),
				'writable'       => array( 'type' => 'boolean' ),
				'world_writable' => array( 'type' => 'boolean' ),
				'issue'          => array( 'type' => array( 'string', 'null' ) ),
				'recommendation' => array( 'type' => array( 'string', 'null' ) ),
			),
			array( 'path', 'exists', 'perms', 'writable', 'world_writable', 'issue', 'recommendation' )
		);

		$exposure = Schema::object(
			array(
				'url'       => array( 'type' => 'string' ),
				'http_code' => array( 'type' => 'integer' ),
				'exposed'   => array( 'type' => 'boolean' ),
				'note'      => array( 'type' => 'string' ),
			),
			array( 'url', 'http_code', 'exposed', 'note' )
		);

		return Schema::object(
			array(
				'items'     => array(
					'type'  => 'array',
					'items' => $item,
				),
				'exposures' => array(
					'type'  => 'array',
					'items' => $exposure,
				),
				'summary'   => Schema::object(
					array( 'issues' => array( 'type' => 'integer' ) ),
					array( 'issues' )
				),
			),
			array( 'items', 'exposures', 'summary' )
		);
	}

	/**
	 * Runs the audit.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$items     = array_merge( $this->inspect_targets(), $this->find_stray_files() );
		$exposures = $this->probe_exposures();
		$issues    = 0;

		foreach ( $items as $item ) {
			if ( null !== $item['issue'] ) {
				++$issues;
			}
		}

		foreach ( $exposures as $exposure ) {
			if ( $exposure['exposed'] ) {
				++$issues;
			}
		}

		return array(
			'items'     => $items,
			'exposures' => $exposures,
			'summary'   => array( 'issues' => $issues ),
		);
	}

	/**
	 * Inspects the sensitive paths of this install.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function inspect_targets() {
		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$above   = dirname( untrailingslashit( ABSPATH ) ) . '/wp-config.php';
		$items   = array();
		$targets = array(
			array( ABSPATH . 'wp-config.php', 'config' ),
			array( $above, 'config' ),
			array( untrailingslashit( ABSPATH ), 'dir' ),
			array( untrailingslashit( WP_CONTENT_DIR ), 'dir' ),
			array( untrailingslashit( $basedir ), 'dir' ),
			array( ABSPATH . '.htaccess', 'file' ),
			array( untrailingslashit( WP_PLUGIN_DIR ), 'dir' ),
			array( untrailingslashit( (string) get_theme_root() ), 'dir' ),
		);
		$seen    = array();

		foreach ( $targets as $target ) {
			$path = (string) $target[0];

			if ( '' === $path || isset( $seen[ $path ] ) ) {
				continue;
			}

			$seen[ $path ] = true;
			$items[]       = $this->inspect( $path, (string) $target[1] );
		}

		return $items;
	}

	/**
	 * Inspects one path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path.
	 * @param string $type One of `config`, `dir`, `file` or `stray`.
	 * @return array<string, mixed>
	 */
	protected function inspect( $path, $type ) {
		$item = array(
			'path'           => Redactor::text( $path ),
			'exists'         => file_exists( $path ),
			'perms'          => '',
			'writable'       => false,
			'world_writable' => false,
			'issue'          => null,
			'recommendation' => null,
		);

		if ( ! $item['exists'] ) {
			return $item;
		}

		$perms = fileperms( $path );

		if ( false === $perms ) {
			return $item;
		}

		$mode                   = $perms & 0777;
		$item['perms']          = substr( sprintf( '%o', $perms ), -4 );
		$item['writable']       = is_writable( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem abstracts permissions away; this audit has to report the real mode of the real path.
		$item['world_writable'] = 0 !== ( $mode & 0002 );

		if ( 'stray' === $type ) {
			$item['issue']          = __( 'A copy of wp-config.php is lying around. Copies are often served as plain text, which leaks the database credentials and the security salts.', 'super-abilities' );
			$item['recommendation'] = __( 'Delete this file, or move it outside the web root.', 'super-abilities' );

			return $item;
		}

		if ( $item['world_writable'] ) {
			$item['issue']          = __( 'This path is world writable, so any user on the server can change it.', 'super-abilities' );
			$item['recommendation'] = 'dir' === $type
				? __( 'Set the directory to 755, or 750 when the web server runs as the owner.', 'super-abilities' )
				: __( 'Set the file to 644, or 640 when the web server runs as the owner.', 'super-abilities' );

			return $item;
		}

		if ( 'config' === $type && 0 !== ( $mode & 0044 ) ) {
			$item['issue']          = __( 'wp-config.php is readable by the group or by everyone, which exposes the database credentials and the security salts to other accounts on the server.', 'super-abilities' );
			$item['recommendation'] = __( 'Set wp-config.php to 640, or to 600 when the web server runs as the file owner.', 'super-abilities' );
		}

		return $item;
	}

	/**
	 * Finds leftover copies of the configuration file.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function find_stray_files() {
		$directories = array( untrailingslashit( ABSPATH ), dirname( untrailingslashit( ABSPATH ) ) );
		$names       = array( 'wp-config-sample.php' );

		foreach ( self::STRAY_SUFFIXES as $suffix ) {
			$names[] = 'wp-config.php' . $suffix;
		}

		$items = array();
		$seen  = array();

		foreach ( array_unique( $directories ) as $directory ) {
			foreach ( $names as $name ) {
				$path = $directory . '/' . $name;

				if ( isset( $seen[ $path ] ) || ! file_exists( $path ) ) {
					continue;
				}

				$seen[ $path ] = true;
				$items[]       = $this->inspect( $path, 'stray' );
			}
		}

		return $items;
	}

	/**
	 * Probes the files that should never be public.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function probe_exposures() {
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$probes  = array(
			array( content_url( 'debug.log' ), array() ),
			array( home_url( 'readme.html' ), array() ),
			array( home_url( 'wp-config-sample.php' ), array() ),
			array( home_url( '.git/HEAD' ), array() ),
			array( home_url( 'wp-config.php.bak' ), array() ),
		);

		if ( '' !== $baseurl ) {
			$probes[] = array(
				trailingslashit( $baseurl ),
				array(
					'method' => 'GET',
					'needle' => 'Index of',
				),
			);
		}

		$exposures = array();

		foreach ( $probes as $probe ) {
			$exposures[] = Probes::exposure( (string) $probe[0], (array) $probe[1] );
		}

		return $exposures;
	}
}
