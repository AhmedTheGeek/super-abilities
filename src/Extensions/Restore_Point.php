<?php
/**
 * File level restore points for plugins and themes.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

use SuperAbilities\Support\Error;
use SuperAbilities\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Copies a plugin or theme directory aside before anything changes it.
 *
 * Restore points live in `wp-content/uploads/super-abilities/restore/`, which is
 * closed to the web with an `index.php` and a `.htaccess`, and are described in the
 * `super_abilities_restore_points` option. The newest twenty are kept per site; older
 * ones are removed from disk when a new one is created.
 *
 * @since 0.2.0
 */
class Restore_Point {

	/**
	 * Option holding the restore point index.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const OPTION = 'super_abilities_restore_points';

	/**
	 * How many restore points are kept per site.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX = 20;

	/**
	 * Absolute path of the plugin's private uploads directory.
	 *
	 * @since 0.2.0
	 *
	 * @return string Path without a trailing slash, or an empty string when uploads are broken.
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return untrailingslashit( (string) $uploads['basedir'] ) . '/super-abilities';
	}

	/**
	 * Absolute path of the directory holding the restore points.
	 *
	 * @since 0.2.0
	 *
	 * @return string Path without a trailing slash, or an empty string.
	 */
	public static function restore_dir() {
		$base = self::base_dir();

		return '' === $base ? '' : $base . '/restore';
	}

	/**
	 * Creates the directories and closes them to the web.
	 *
	 * @since 0.2.0
	 *
	 * @return string|WP_Error Absolute path of the restore directory.
	 */
	public static function prepare_dir() {
		$base    = self::base_dir();
		$restore = self::restore_dir();

		if ( '' === $base || '' === $restore ) {
			return Error::make( 'exception', __( 'The uploads directory is not available, so no restore point can be written.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		$filesystem = Upgrades::filesystem();

		if ( null === $filesystem ) {
			return Error::make( 'exception', __( 'WordPress could not connect to the filesystem.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		foreach ( array( $base, $restore ) as $dir ) {
			if ( ! $filesystem->is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return Error::make(
					'exception',
					__( 'The restore point directory could not be created inside the uploads folder.', 'super-abilities' ),
					array( 'status' => 500 )
				);
			}
		}

		if ( ! $filesystem->exists( $base . '/index.php' ) ) {
			$filesystem->put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n", Upgrades::chmod_file() );
		}

		if ( ! $filesystem->exists( $base . '/.htaccess' ) ) {
			$filesystem->put_contents( $base . '/.htaccess', "Order allow,deny\nDeny from all\n", Upgrades::chmod_file() );
		}

		return $restore;
	}

	/**
	 * Takes a restore point of one installed plugin or theme.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type, `plugin` or `theme`.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return array<string, mixed>|WP_Error The restore point metadata.
	 */
	public static function create( $type, $slug ) {
		$type      = Target::normalize_type( $type );
		$described = Target::describe( $type, $slug );

		if ( null === $described ) {
			return Error::make(
				'not_found',
				__( 'That plugin or theme is not installed, so there is nothing to snapshot.', 'super-abilities' )
			);
		}

		$source = Target::path( $type, $described['slug'] );

		if ( '' === $source || ! file_exists( $source ) ) {
			return Error::make( 'not_found', __( 'The files of that plugin or theme could not be found.', 'super-abilities' ) );
		}

		$restore_dir = self::prepare_dir();

		if ( is_wp_error( $restore_dir ) ) {
			return $restore_dir;
		}

		$filesystem = Upgrades::filesystem();

		if ( null === $filesystem ) {
			return Error::make( 'exception', __( 'WordPress could not connect to the filesystem.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		$single_file = is_file( $source ) ? basename( $source ) : '';
		$folder      = self::folder_name( $type, (string) $described['slug'], (string) $described['version'], $restore_dir );
		$target      = $restore_dir . '/' . $folder;

		if ( ! wp_mkdir_p( $target ) ) {
			return Error::make( 'exception', __( 'The restore point directory could not be created.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		if ( '' !== $single_file ) {
			$copied = $filesystem->copy( $source, $target . '/' . $single_file, true, Upgrades::chmod_file() );
		} else {
			$copied = ! is_wp_error( copy_dir( $source, $target ) );
		}

		if ( ! $copied ) {
			$filesystem->delete( $target, true );

			return Error::make(
				'exception',
				__( 'The files could not be copied into the restore point, so nothing was changed.', 'super-abilities' ),
				array( 'status' => 500 )
			);
		}

		$meta = array(
			'id'          => self::id_for( $folder ),
			'type'        => $type,
			'slug'        => (string) $described['slug'],
			'name'        => (string) $described['name'],
			'version'     => (string) $described['version'],
			'created'     => Time::iso( Time::now() ),
			'size_bytes'  => Upgrades::size_of( $target ),
			'was_active'  => ! empty( $described['active'] ) || ! empty( $described['network_active'] ),
			'network'     => ! empty( $described['network_active'] ),
			'folder'      => $folder,
			'single_file' => $single_file,
		);

		$index                = self::index();
		$index[ $meta['id'] ] = $meta;
		$index                = self::sort_index( $index );

		self::save_index( $index );
		self::prune();

		return $meta;
	}

	/**
	 * Every restore point known to this site, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $filters {
	 *     Optional. Filters.
	 *
	 *     @type string $type Only restore points of this extension type.
	 *     @type string $slug Only restore points of this plugin file or stylesheet.
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( array $filters = array() ) {
		$type = isset( $filters['type'] ) && '' !== (string) $filters['type'] ? Target::normalize_type( $filters['type'] ) : '';
		$slug = isset( $filters['slug'] ) ? trim( (string) $filters['slug'] ) : '';
		$list = array();

		foreach ( self::sort_index( self::index() ) as $meta ) {
			if ( '' !== $type && (string) $meta['type'] !== $type ) {
				continue;
			}

			if ( '' !== $slug && (string) $meta['slug'] !== $slug && Target::plugin_slug( (string) $meta['slug'] ) !== $slug ) {
				continue;
			}

			$list[] = $meta;
		}

		return $list;
	}

	/**
	 * One restore point by id.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id Restore point id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		$index = self::index();
		$id    = (string) $id;

		return isset( $index[ $id ] ) ? $index[ $id ] : null;
	}

	/**
	 * The newest restore point for one extension.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return array<string, mixed>|null
	 */
	public static function newest_for( $type, $slug ) {
		$list = self::all(
			array(
				'type' => $type,
				'slug' => $slug,
			)
		);

		return empty( $list ) ? null : $list[0];
	}

	/**
	 * Removes a restore point from disk and from the index.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id Restore point id.
	 * @return bool True when the restore point existed.
	 */
	public static function delete( $id ) {
		$id    = (string) $id;
		$index = self::index();

		if ( ! isset( $index[ $id ] ) ) {
			return false;
		}

		$path       = self::path_of( $index[ $id ] );
		$filesystem = Upgrades::filesystem();

		if ( '' !== $path && null !== $filesystem && $filesystem->exists( $path ) ) {
			$filesystem->delete( $path, true );
		}

		unset( $index[ $id ] );
		self::save_index( $index );

		return true;
	}

	/**
	 * Puts the files of a restore point back in place.
	 *
	 * The current directory is moved aside first and moved back when the copy fails,
	 * so a failed restore leaves the site exactly as it was.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id Restore point id.
	 * @return array<string, mixed>|WP_Error The restore point metadata on success.
	 */
	public static function restore( $id ) {
		$meta = self::get( $id );

		if ( null === $meta ) {
			return Error::make( 'not_found', __( 'That restore point does not exist.', 'super-abilities' ) );
		}

		$source = self::path_of( $meta );

		if ( '' === $source || ! file_exists( $source ) ) {
			return Error::make(
				'not_found',
				__( 'The files of that restore point are gone from disk. Delete the restore point and take a new one.', 'super-abilities' )
			);
		}

		$filesystem = Upgrades::filesystem();

		if ( null === $filesystem ) {
			return Error::make( 'exception', __( 'WordPress could not connect to the filesystem.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		$type        = Target::normalize_type( $meta['type'] );
		$single_file = isset( $meta['single_file'] ) ? (string) $meta['single_file'] : '';
		$destination = self::destination_of( $meta );

		if ( '' === $destination ) {
			return Error::make( 'exception', __( 'The restore target path could not be resolved.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		if ( '' !== $single_file ) {
			if ( ! $filesystem->copy( $source . '/' . $single_file, $destination, true, Upgrades::chmod_file() ) ) {
				return Error::make( 'exception', __( 'The restore point file could not be copied back.', 'super-abilities' ), array( 'status' => 500 ) );
			}

			self::refresh_caches( $type );

			return $meta;
		}

		$aside = '';

		if ( $filesystem->is_dir( $destination ) ) {
			$aside = self::restore_dir() . '/.aside-' . $meta['id'] . '-' . Time::now();

			if ( ! $filesystem->move( $destination, $aside, true ) ) {
				return Error::make(
					'locked',
					__( 'The current files could not be moved aside, so the restore was not attempted.', 'super-abilities' ),
					array( 'status' => 423 )
				);
			}
		}

		if ( ! wp_mkdir_p( $destination ) ) {
			self::move_back( $aside, $destination );

			return Error::make( 'exception', __( 'The destination directory could not be recreated.', 'super-abilities' ), array( 'status' => 500 ) );
		}

		$copied = copy_dir( $source, $destination );

		if ( is_wp_error( $copied ) ) {
			$filesystem->delete( $destination, true );
			self::move_back( $aside, $destination );

			return Error::make(
				'exception',
				__( 'The restore point could not be copied back; the previous files were put back in place.', 'super-abilities' ),
				array( 'status' => 500 )
			);
		}

		if ( '' !== $aside && $filesystem->exists( $aside ) ) {
			$filesystem->delete( $aside, true );
		}

		self::refresh_caches( $type );

		return $meta;
	}

	/**
	 * Deletes everything but the newest {@see Restore_Point::MAX} restore points.
	 *
	 * @since 0.2.0
	 *
	 * @return int How many restore points were removed.
	 */
	public static function prune() {
		$index = self::sort_index( self::index() );

		if ( count( $index ) <= self::MAX ) {
			return 0;
		}

		$stale   = array_slice( $index, self::MAX, null, true );
		$removed = 0;

		foreach ( array_keys( $stale ) as $id ) {
			if ( self::delete( (string) $id ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Removes every restore point, used by the test suite and by uninstall paths.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function delete_all() {
		foreach ( array_keys( self::index() ) as $id ) {
			self::delete( (string) $id );
		}

		delete_option( self::OPTION );
	}

	/**
	 * Absolute path of a restore point's files.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $meta Restore point metadata.
	 * @return string
	 */
	public static function path_of( array $meta ) {
		$folder  = isset( $meta['folder'] ) ? (string) $meta['folder'] : '';
		$restore = self::restore_dir();

		if ( '' === $folder || '' === $restore || false !== strpos( $folder, '/' ) || false !== strpos( $folder, '\\' ) ) {
			return '';
		}

		return $restore . '/' . $folder;
	}

	/**
	 * Where the files of a restore point belong.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $meta Restore point metadata.
	 * @return string Absolute path, or an empty string when it cannot be resolved.
	 */
	protected static function destination_of( array $meta ) {
		$type = Target::normalize_type( isset( $meta['type'] ) ? $meta['type'] : '' );
		$slug = isset( $meta['slug'] ) ? (string) $meta['slug'] : '';

		if ( '' === $slug || 0 !== validate_file( $slug ) ) {
			return '';
		}

		if ( Target::TYPE_THEME === $type ) {
			return untrailingslashit( get_theme_root() ) . '/' . $slug;
		}

		$single_file = isset( $meta['single_file'] ) ? (string) $meta['single_file'] : '';
		$root        = untrailingslashit( WP_PLUGIN_DIR );

		if ( '' !== $single_file ) {
			return $root . '/' . basename( $slug );
		}

		return false === strpos( $slug, '/' ) ? $root . '/' . $slug : $root . '/' . dirname( $slug );
	}

	/**
	 * Puts a directory that was moved aside back in place.
	 *
	 * @since 0.2.0
	 *
	 * @param string $aside       Path the directory was moved to, or an empty string.
	 * @param string $destination Path it came from.
	 * @return void
	 */
	protected static function move_back( $aside, $destination ) {
		if ( '' === (string) $aside ) {
			return;
		}

		$filesystem = Upgrades::filesystem();

		if ( null !== $filesystem && $filesystem->exists( $aside ) ) {
			$filesystem->move( $aside, $destination, true );
		}
	}

	/**
	 * Clears the plugin or theme caches after files changed underneath WordPress.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @return void
	 */
	protected static function refresh_caches( $type ) {
		if ( Target::TYPE_THEME === Target::normalize_type( $type ) ) {
			wp_clean_themes_cache();

			return;
		}

		wp_clean_plugins_cache();
	}

	/**
	 * Builds a unique directory name for a new restore point.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type        Extension type.
	 * @param string $slug        Plugin file or theme stylesheet.
	 * @param string $version     Installed version.
	 * @param string $restore_dir Absolute path of the restore directory.
	 * @return string
	 */
	protected static function folder_name( $type, $slug, $version, $restore_dir ) {
		$safe_slug    = self::slug_part( $slug );
		$safe_version = self::slug_part( '' === $version ? 'unknown' : $version );
		$base         = $type . '-' . $safe_slug . '-' . $safe_version . '-' . Time::now();
		$folder       = $base;
		$suffix       = 0;

		while ( file_exists( $restore_dir . '/' . $folder ) && $suffix < 50 ) {
			++$suffix;
			$folder = $base . '-' . $suffix;
		}

		return $folder;
	}

	/**
	 * Reduces a slug or version to characters that are safe in a directory name.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function slug_part( $value ) {
		$value = strtolower( str_replace( array( '/', '\\', ' ' ), '-', (string) $value ) );
		$value = (string) preg_replace( '/[^a-z0-9._-]/', '', $value );
		$value = trim( (string) preg_replace( '/-+/', '-', $value ), '-.' );

		return '' === $value ? 'unknown' : substr( $value, 0, 60 );
	}

	/**
	 * The id of a restore point directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string $folder Directory name.
	 * @return string
	 */
	protected static function id_for( $folder ) {
		return 'rp_' . substr( sha1( (string) $folder ), 0, 12 );
	}

	/**
	 * The stored index, keyed by restore point id.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function index() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$index = array();

		foreach ( $stored as $id => $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['folder'] ) ) {
				continue;
			}

			$meta['id']           = isset( $meta['id'] ) ? (string) $meta['id'] : (string) $id;
			$index[ $meta['id'] ] = $meta;
		}

		return $index;
	}

	/**
	 * Sorts an index newest first.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $index Restore point index.
	 * @return array<string, array<string, mixed>>
	 */
	protected static function sort_index( array $index ) {
		uasort(
			$index,
			static function ( $a, $b ) {
				$left  = isset( $a['created'] ) ? (string) $a['created'] : '';
				$right = isset( $b['created'] ) ? (string) $b['created'] : '';

				if ( $left === $right ) {
					return strcmp( (string) ( isset( $b['folder'] ) ? $b['folder'] : '' ), (string) ( isset( $a['folder'] ) ? $a['folder'] : '' ) );
				}

				return strcmp( $right, $left );
			}
		);

		return $index;
	}

	/**
	 * Writes the index back to the option.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $index Restore point index.
	 * @return void
	 */
	protected static function save_index( array $index ) {
		if ( empty( $index ) ) {
			delete_option( self::OPTION );

			return;
		}

		update_option( self::OPTION, $index, false );
	}
}
