<?php
/**
 * Pre-flight checks for an install or update.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

use SuperAbilities\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, without changing anything, whether an install or update can succeed.
 *
 * Findings are split into `blockers`, which always abort the operation, and
 * `warnings`, which are reported and ignored. Each finding carries a stable code so an
 * agent can react to it without parsing the message.
 *
 * @since 0.2.0
 */
class Preflight {

	/**
	 * How much free disk space a package needs, as a multiple of its own size.
	 *
	 * The package is downloaded, unpacked into a working directory and copied into
	 * place, so three times its size is the realistic floor.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const SPACE_FACTOR = 3;

	/**
	 * Smallest amount of free disk space an install is allowed to start with.
	 *
	 * Used when the package size is unknown.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MIN_FREE_BYTES = 20971520;

	/**
	 * Runs every check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $args {
	 *     What is about to happen.
	 *
	 *     @type string               $type    Extension type, `plugin` or `theme`.
	 *     @type string               $slug    Plugin file, plugin slug or theme stylesheet.
	 *     @type string               $action  Either `install` or `update`.
	 *     @type array<string, mixed> $package Resolved package from {@see Packages::resolve()}.
	 * }
	 * @return array<string, mixed>
	 */
	public static function check( array $args ) {
		$type    = Target::normalize_type( isset( $args['type'] ) ? $args['type'] : '' );
		$slug    = isset( $args['slug'] ) ? trim( (string) $args['slug'] ) : '';
		$action  = 'update' === ( isset( $args['action'] ) ? (string) $args['action'] : 'install' ) ? 'update' : 'install';
		$package = isset( $args['package'] ) && is_array( $args['package'] ) ? $args['package'] : array();

		$blockers = array();
		$warnings = array();

		$described      = '' === $slug ? null : Target::describe( $type, $slug );
		$installed      = null !== $described;
		$is_mu          = Target::TYPE_PLUGIN === $type && Target::is_mu_or_dropin( $slug );
		$method         = Upgrades::filesystem_method();
		$package_size   = isset( $package['size_bytes'] ) ? (int) $package['size_bytes'] : 0;
		$required_bytes = $package_size > 0 ? $package_size * self::SPACE_FACTOR : self::MIN_FREE_BYTES;
		$free_bytes     = self::free_bytes();

		// 1. Does this site allow file modifications of this kind at all?
		$context = self::file_mod_context( $type, $action );

		if ( ! Capabilities::file_mods_allowed( $context ) ) {
			$blockers[] = self::finding(
				'file_mods_disallowed',
				sprintf(
					/* translators: %s: wp_is_file_mod_allowed() context, for example install_plugin. */
					__( 'File modifications are disabled on this site (%s is not allowed), usually by the DISALLOW_FILE_MODS constant.', 'super-abilities' ),
					$context
				)
			);
		}

		// 2. Only the direct filesystem transport works without asking for credentials.
		if ( 'direct' !== $method ) {
			$blockers[] = self::finding(
				'filesystem_not_direct',
				sprintf(
					/* translators: %s: Filesystem method name, for example ftpext. */
					__( 'WordPress would use the "%s" filesystem method, which needs credentials that cannot be supplied from an ability call.', 'super-abilities' ),
					'' === $method ? 'unknown' : $method
				)
			);
		}

		// 3. Is there room for the download, the working copy and the final copy?
		if ( null === $free_bytes ) {
			$warnings[] = self::finding(
				'disk_space_unknown',
				__( 'The free disk space of wp-content could not be measured, so the space check was skipped.', 'super-abilities' )
			);
		} elseif ( $free_bytes < $required_bytes ) {
			$blockers[] = self::finding(
				'insufficient_disk_space',
				sprintf(
					/* translators: 1: Free bytes. 2: Required bytes. */
					__( 'Only %1$s bytes are free in wp-content, and this operation needs about %2$s bytes.', 'super-abilities' ),
					number_format_i18n( $free_bytes ),
					number_format_i18n( $required_bytes )
				)
			);
		}

		// 4. Is WordPress in the middle of another update?
		if ( self::maintenance_mode() ) {
			$blockers[] = self::finding(
				'maintenance_mode',
				__( 'The site is in maintenance mode, which means another update is running or a previous one was interrupted.', 'super-abilities' )
			);
		}

		// 5. Must-use plugins and drop-ins are not managed by the upgrader.
		if ( $is_mu ) {
			$blockers[] = self::finding(
				'unsupported',
				__( 'Must-use plugins and drop-ins are installed by hand and cannot be managed through the upgrader.', 'super-abilities' )
			);
		}

		// 6. Does the target exist, or already exist?
		if ( 'update' === $action && ! $installed ) {
			$blockers[] = self::finding(
				'not_installed',
				__( 'That plugin or theme is not installed, so there is nothing to update.', 'super-abilities' )
			);
		}

		if ( 'install' === $action && $installed ) {
			$warnings[] = self::finding(
				'already_installed',
				__( 'Something is already installed under that folder name. The install will refuse to overwrite it; update it instead.', 'super-abilities' )
			);
		}

		// 7. Does the package still run on this WordPress and this PHP?
		$requires_wp  = isset( $package['requires_wp'] ) ? Target::requirement( $package['requires_wp'] ) : null;
		$requires_php = isset( $package['requires_php'] ) ? Target::requirement( $package['requires_php'] ) : null;
		$tested       = isset( $package['tested'] ) ? Target::requirement( $package['tested'] ) : null;

		if ( null !== $requires_wp && ! is_wp_version_compatible( $requires_wp ) ) {
			$blockers[] = self::finding(
				'requires_wp',
				sprintf(
					/* translators: 1: Required WordPress version. 2: Current WordPress version. */
					__( 'It needs WordPress %1$s or newer and this site runs %2$s.', 'super-abilities' ),
					$requires_wp,
					get_bloginfo( 'version' )
				)
			);
		}

		if ( null !== $requires_php && ! is_php_version_compatible( $requires_php ) ) {
			$blockers[] = self::finding(
				'requires_php',
				sprintf(
					/* translators: 1: Required PHP version. 2: Current PHP version. */
					__( 'It needs PHP %1$s or newer and this site runs %2$s.', 'super-abilities' ),
					$requires_php,
					PHP_VERSION
				)
			);
		}

		if ( null !== $tested && version_compare( $tested, (string) get_bloginfo( 'version' ), '<' ) ) {
			$warnings[] = self::finding(
				'untested_wp',
				sprintf(
					/* translators: 1: Version the package was tested up to. 2: Current WordPress version. */
					__( 'The author only tested it up to WordPress %1$s and this site runs %2$s.', 'super-abilities' ),
					$tested,
					get_bloginfo( 'version' )
				)
			);
		}

		// 8. Warn about what the operation will interrupt.
		if ( 'update' === $action && $installed && ! empty( $described['network_active'] ) ) {
			$warnings[] = self::finding(
				'network_active',
				__( 'It is active across the whole network, so every site is affected by this change.', 'super-abilities' )
			);
		}

		if ( 'update' === $action && $installed && ! empty( $described['active'] ) ) {
			$warnings[] = self::finding(
				'active',
				__( 'It is active, so the site runs the new version the moment the files are replaced.', 'super-abilities' )
			);
		}

		if ( '' !== $slug && ! $installed && 'install' === $action && empty( $package ) ) {
			$warnings[] = self::finding(
				'package_unresolved',
				__( 'No package was resolved, so version and compatibility could not be checked.', 'super-abilities' )
			);
		}

		return array(
			'ok'                => empty( $blockers ),
			'type'              => $type,
			'action'            => $action,
			'slug'              => '' === $slug ? '' : ( $installed ? (string) $described['slug'] : $slug ),
			'installed'         => $installed,
			'active'            => $installed && ! empty( $described['active'] ),
			'network_active'    => $installed && ! empty( $described['network_active'] ),
			'is_mu'             => $is_mu,
			'filesystem_method' => $method,
			'maintenance_mode'  => self::maintenance_mode(),
			'disk_free_bytes'   => $free_bytes,
			'required_bytes'    => $required_bytes,
			'installed_version' => $installed ? (string) $described['version'] : '',
			'blockers'          => $blockers,
			'warnings'          => $warnings,
		);
	}

	/**
	 * The `wp_is_file_mod_allowed()` context for an operation.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type   Extension type.
	 * @param string $action One of `install`, `update` or `delete`.
	 * @return string
	 */
	public static function file_mod_context( $type, $action ) {
		$type   = Target::normalize_type( $type );
		$action = (string) $action;

		if ( Target::TYPE_THEME === $type ) {
			$contexts = array(
				'install' => 'install_theme',
				'update'  => 'update_themes',
				'delete'  => 'delete_theme',
			);
		} else {
			$contexts = array(
				'install' => 'install_plugin',
				'update'  => 'update_plugin',
				'delete'  => 'delete_plugin',
			);
		}

		return isset( $contexts[ $action ] ) ? $contexts[ $action ] : $contexts['install'];
	}

	/**
	 * Whether WordPress is currently showing the maintenance page.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function maintenance_mode() {
		$file = ABSPATH . '.maintenance';

		if ( ! file_exists( $file ) ) {
			return false;
		}

		// Core writes the marker with `$upgrading = time()`, so its modification time is
		// the moment maintenance mode started. A marker older than ten minutes is stale,
		// exactly as `wp-settings.php` treats it.
		$modified = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filemtime() warns when the file disappears between the two calls.

		return $modified > 0 && ( time() - $modified ) < 600;
	}

	/**
	 * Free disk space on the volume holding `wp-content`.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null Null when the platform refuses to answer.
	 */
	public static function free_bytes() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space() warns when the function is disabled by the host.

		if ( false === $free ) {
			return null;
		}

		return (int) $free;
	}

	/**
	 * Builds one finding.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code    Stable machine code.
	 * @param string $message Human readable explanation.
	 * @return array<string, string>
	 */
	protected static function finding( $code, $message ) {
		return array(
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}
}
