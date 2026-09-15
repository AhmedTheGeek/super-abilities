<?php
/**
 * Plugin and theme lookup.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

use SuperAbilities\Support\Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the `type` plus `slug` pair every extensions ability accepts into a concrete
 * installed plugin file or theme stylesheet, and describes it.
 *
 * Plugins are addressed by their plugin file (`akismet/akismet.php`), themes by their
 * stylesheet directory (`twentytwentyfive`). Callers may also pass just the folder name
 * of a plugin, which is what WordPress.org calls the slug.
 *
 * @since 0.2.0
 */
class Target {

	/**
	 * Plugin type identifier.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const TYPE_PLUGIN = 'plugin';

	/**
	 * Theme type identifier.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const TYPE_THEME = 'theme';

	/**
	 * Normalizes a caller supplied type.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $type Raw type.
	 * @return string Either `plugin` or `theme`. Unknown values become `plugin`.
	 */
	public static function normalize_type( $type ) {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';

		return self::TYPE_THEME === $type ? self::TYPE_THEME : self::TYPE_PLUGIN;
	}

	/**
	 * Loads the wp-admin plugin helpers.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Every installed plugin, keyed by plugin file.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function plugins() {
		self::bootstrap();

		$plugins = get_plugins();

		return is_array( $plugins ) ? $plugins : array();
	}

	/**
	 * Every must-use plugin, keyed by file name.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function mu_plugins() {
		self::bootstrap();

		$plugins = get_mu_plugins();

		return is_array( $plugins ) ? $plugins : array();
	}

	/**
	 * Every drop-in, keyed by file name.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function dropins() {
		self::bootstrap();

		$dropins = get_dropins();

		return is_array( $dropins ) ? $dropins : array();
	}

	/**
	 * Resolves a caller supplied plugin reference into an installed plugin file.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Plugin file, plugin folder name or single file name.
	 * @return string The plugin file, or an empty string when nothing matches.
	 */
	public static function plugin_file( $slug ) {
		$slug = trim( (string) $slug );

		if ( '' === $slug ) {
			return '';
		}

		$slug    = ltrim( str_replace( '\\', '/', $slug ), '/' );
		$plugins = self::plugins();

		if ( isset( $plugins[ $slug ] ) ) {
			return $slug;
		}

		if ( isset( $plugins[ $slug . '.php' ] ) ) {
			return $slug . '.php';
		}

		foreach ( array_keys( $plugins ) as $file ) {
			$file = (string) $file;

			if ( false !== strpos( $file, '/' ) && dirname( $file ) === $slug ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * The WordPress.org style slug of a plugin file.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	public static function plugin_slug( $file ) {
		$file = (string) $file;

		if ( '' === $file ) {
			return '';
		}

		return false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
	}

	/**
	 * Whether a reference names a must-use plugin or a drop-in.
	 *
	 * Those cannot be installed, updated or deleted through the upgrader, so every
	 * ability refuses them with `unsupported`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Plugin reference.
	 * @return bool
	 */
	public static function is_mu_or_dropin( $slug ) {
		$slug = ltrim( trim( (string) $slug ), '/' );

		if ( '' === $slug ) {
			return false;
		}

		$base = basename( $slug );

		foreach ( array_keys( self::mu_plugins() ) as $file ) {
			if ( (string) $file === $slug || (string) $file === $base ) {
				return true;
			}
		}

		foreach ( array_keys( self::dropins() ) as $file ) {
			if ( (string) $file === $slug || (string) $file === $base ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a theme is installed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $stylesheet Theme stylesheet directory.
	 * @return bool
	 */
	public static function theme_exists( $stylesheet ) {
		$stylesheet = trim( (string) $stylesheet );

		if ( '' === $stylesheet ) {
			return false;
		}

		$theme = wp_get_theme( $stylesheet );

		return $theme->exists();
	}

	/**
	 * Whether a plugin is active on this site or network wide.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file Plugin file.
	 * @return bool
	 */
	public static function plugin_is_active( $file ) {
		self::bootstrap();

		return is_plugin_active( (string) $file );
	}

	/**
	 * Whether a plugin is active for the whole network.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file Plugin file.
	 * @return bool
	 */
	public static function plugin_is_network_active( $file ) {
		self::bootstrap();

		return is_multisite() && is_plugin_active_for_network( (string) $file );
	}

	/**
	 * Absolute path of the directory holding an installed extension.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return string Absolute path without a trailing slash, or an empty string.
	 */
	public static function path( $type, $slug ) {
		$type = self::normalize_type( $type );

		if ( self::TYPE_THEME === $type ) {
			$stylesheet = trim( (string) $slug );

			if ( '' === $stylesheet || ! self::theme_exists( $stylesheet ) ) {
				return '';
			}

			$theme = wp_get_theme( $stylesheet );

			return untrailingslashit( (string) $theme->get_stylesheet_directory() );
		}

		$file = self::plugin_file( $slug );

		if ( '' === $file ) {
			return '';
		}

		$root = untrailingslashit( WP_PLUGIN_DIR );

		return false === strpos( $file, '/' ) ? $root . '/' . $file : $root . '/' . dirname( $file );
	}

	/**
	 * Describes one installed extension.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return array<string, mixed>|null Null when the extension is not installed.
	 */
	public static function describe( $type, $slug ) {
		$type = self::normalize_type( $type );

		if ( self::TYPE_THEME === $type ) {
			return self::describe_theme( $slug );
		}

		return self::describe_plugin( $slug );
	}

	/**
	 * Describes one installed plugin.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Plugin reference.
	 * @return array<string, mixed>|null
	 */
	public static function describe_plugin( $slug ) {
		$file = self::plugin_file( $slug );

		if ( '' === $file ) {
			return null;
		}

		$plugins = self::plugins();
		$data    = isset( $plugins[ $file ] ) ? (array) $plugins[ $file ] : array();
		$enabled = array_map( 'strval', (array) get_site_option( 'auto_update_plugins', array() ) );

		return array(
			'type'           => self::TYPE_PLUGIN,
			'slug'           => $file,
			'wporg_slug'     => self::plugin_slug( $file ),
			'name'           => isset( $data['Name'] ) ? (string) $data['Name'] : '',
			'version'        => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'author'         => isset( $data['Author'] ) ? (string) wp_strip_all_tags( (string) $data['Author'] ) : '',
			'active'         => self::plugin_is_active( $file ),
			'network_active' => self::plugin_is_network_active( $file ),
			'requires_wp'    => self::requirement( isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : null ),
			'requires_php'   => self::requirement( isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : null ),
			'is_mu'          => false,
			'auto_update'    => in_array( $file, $enabled, true ),
		);
	}

	/**
	 * Describes one installed theme.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Theme stylesheet.
	 * @return array<string, mixed>|null
	 */
	public static function describe_theme( $slug ) {
		$stylesheet = trim( (string) $slug );

		if ( '' === $stylesheet || ! self::theme_exists( $stylesheet ) ) {
			return null;
		}

		$theme   = wp_get_theme( $stylesheet );
		$enabled = array_map( 'strval', (array) get_site_option( 'auto_update_themes', array() ) );

		return array(
			'type'           => self::TYPE_THEME,
			'slug'           => $stylesheet,
			'wporg_slug'     => $stylesheet,
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'author'         => (string) wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'active'         => get_stylesheet() === $stylesheet,
			'network_active' => self::theme_is_network_enabled( $stylesheet ),
			'requires_wp'    => self::requirement( $theme->get( 'RequiresWP' ) ),
			'requires_php'   => self::requirement( $theme->get( 'RequiresPHP' ) ),
			'is_mu'          => false,
			'auto_update'    => in_array( $stylesheet, $enabled, true ),
		);
	}

	/**
	 * Whether a theme is enabled for the whole network.
	 *
	 * @since 0.2.0
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return bool
	 */
	public static function theme_is_network_enabled( $stylesheet ) {
		if ( ! is_multisite() ) {
			return false;
		}

		$allowed = (array) get_site_option( 'allowedthemes', array() );

		return ! empty( $allowed[ (string) $stylesheet ] );
	}

	/**
	 * A short fingerprint of one extension's identity, version and active state.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>|null $described Result of {@see Target::describe()}.
	 * @return string
	 */
	public static function fingerprint( $described ) {
		if ( ! is_array( $described ) ) {
			return Fingerprint::of_string( 'missing' );
		}

		return Fingerprint::of_array(
			array(
				'type'    => isset( $described['type'] ) ? (string) $described['type'] : '',
				'slug'    => isset( $described['slug'] ) ? (string) $described['slug'] : '',
				'version' => isset( $described['version'] ) ? (string) $described['version'] : '',
				'active'  => ! empty( $described['active'] ),
			)
		);
	}

	/**
	 * The pending update for one extension, read from the update transients.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return string New version, or an empty string when no update is pending.
	 */
	public static function new_version( $type, $slug ) {
		$type = self::normalize_type( $type );
		$slug = (string) $slug;

		if ( self::TYPE_THEME === $type ) {
			$transient = get_site_transient( 'update_themes' );
			$responses = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
			$update    = isset( $responses[ $slug ] ) ? (array) $responses[ $slug ] : array();

			return isset( $update['new_version'] ) ? (string) $update['new_version'] : '';
		}

		$transient = get_site_transient( 'update_plugins' );
		$responses = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();

		if ( ! isset( $responses[ $slug ] ) ) {
			return '';
		}

		$update = $responses[ $slug ];

		return is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : '';
	}

	/**
	 * Normalizes a version requirement into a non-empty string or null.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Raw requirement.
	 * @return string|null
	 */
	public static function requirement( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
