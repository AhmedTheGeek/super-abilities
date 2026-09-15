<?php
/**
 * Upgrader and filesystem bootstrap.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the wp-admin includes the upgrader needs and connects `WP_Filesystem`.
 *
 * Abilities run from the REST API, WP-CLI or cron, where none of wp-admin is loaded.
 * Everything in this module therefore calls {@see Upgrades::bootstrap()} before it
 * touches `WP_Upgrader`, `WP_Filesystem` or the install APIs. It must also run before
 * {@see Silent_Skin} is autoloaded, because that class extends `WP_Upgrader_Skin`.
 *
 * @since 0.2.0
 */
class Upgrades {

	/**
	 * Whether `bootstrap()` already ran in this request.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	protected static $booted = false;

	/**
	 * Loads every wp-admin include the upgrader path depends on.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * The filesystem transport WordPress would use for a file modification.
	 *
	 * @since 0.2.0
	 *
	 * @return string One of `direct`, `ssh2`, `ftpext` or `ftpsockets`, or an empty
	 *                string when WordPress cannot decide.
	 */
	public static function filesystem_method() {
		self::bootstrap();

		return (string) get_filesystem_method();
	}

	/**
	 * Connects `WP_Filesystem` and returns the global instance.
	 *
	 * Only the `direct` transport can work without credentials, which is why every
	 * ability refuses to continue when the method is something else.
	 *
	 * @since 0.2.0
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function filesystem() {
		self::bootstrap();

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			WP_Filesystem();
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * The file permission mask `WP_Filesystem` writes new files with.
	 *
	 * `FS_CHMOD_FILE` is only defined once `WP_Filesystem()` has run, so callers ask
	 * for it here rather than referencing the constant directly.
	 *
	 * @since 0.2.0
	 *
	 * @return int|false
	 */
	public static function chmod_file() {
		self::filesystem();

		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false;
	}

	/**
	 * Recursively measures a directory or file.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Absolute path.
	 * @return int Size in bytes. Zero when the path does not exist.
	 */
	public static function size_of( $path ) {
		$path = (string) $path;

		if ( '' === $path || ! file_exists( $path ) ) {
			return 0;
		}

		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}

		$bytes = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $item ) {
				if ( $item instanceof \SplFileInfo && $item->isFile() ) {
					$bytes += (int) $item->getSize();
				}
			}
		} catch ( \Throwable $throwable ) {
			return $bytes;
		}

		return $bytes;
	}
}
