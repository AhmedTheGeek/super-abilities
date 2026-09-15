<?php
/**
 * Shared permission and protection checks.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

use SuperAbilities\Support\Capabilities;
use SuperAbilities\Support\Error;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The checks every extensions ability shares: the capability that matches the type, the
 * network rules multisite imposes, `DISALLOW_FILE_MODS`, and the protected list that
 * keeps an agent from deactivating or deleting this plugin out from under itself.
 *
 * @since 0.2.0
 */
class Guard {

	/**
	 * Capability matrix: action to plugin capability and theme capability.
	 *
	 * @since 0.2.0
	 * @var array<string, array<string, string>>
	 */
	const CAPS = array(
		'list'     => array(
			'plugin' => 'activate_plugins',
			'theme'  => 'switch_themes',
		),
		'install'  => array(
			'plugin' => 'install_plugins',
			'theme'  => 'install_themes',
		),
		'update'   => array(
			'plugin' => 'update_plugins',
			'theme'  => 'update_themes',
		),
		'delete'   => array(
			'plugin' => 'delete_plugins',
			'theme'  => 'delete_themes',
		),
		'activate' => array(
			'plugin' => 'activate_plugins',
			'theme'  => 'switch_themes',
		),
	);

	/**
	 * The capability an action needs for one extension type.
	 *
	 * @since 0.2.0
	 *
	 * @param string $action One of `list`, `install`, `update`, `delete` or `activate`.
	 * @param string $type   Extension type.
	 * @return string
	 */
	public static function cap_for( $action, $type ) {
		$action = (string) $action;
		$type   = Target::normalize_type( $type );

		if ( ! isset( self::CAPS[ $action ][ $type ] ) ) {
			return 'manage_options';
		}

		return self::CAPS[ $action ][ $type ];
	}

	/**
	 * Requires the capability that matches the type the caller asked for.
	 *
	 * Abilities declare the plugin side capability in `capability()`, because that is
	 * the one nearly every caller needs; this adds the theme side when the input names
	 * a theme, which is what keeps the gate honest for both types.
	 *
	 * @since 0.2.0
	 *
	 * @param string $action One of `list`, `install`, `update`, `delete` or `activate`.
	 * @param string $type   Extension type, or `all` to require both.
	 * @return WP_Error|null Null when the caller may proceed.
	 */
	public static function require_cap( $action, $type ) {
		$type  = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		$types = 'all' === $type ? array( Target::TYPE_PLUGIN, Target::TYPE_THEME ) : array( Target::normalize_type( $type ) );

		foreach ( $types as $one ) {
			$cap = self::cap_for( $action, $one );

			if ( ! current_user_can( $cap ) ) {
				return Error::make(
					'forbidden',
					sprintf(
						/* translators: %s: Capability name. */
						__( 'You are not allowed to do this. It requires the "%s" capability.', 'super-abilities' ),
						$cap
					),
					array(
						'status'              => 403,
						'required_capability' => $cap,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Applies the multisite rules core applies to plugin and theme files.
	 *
	 * Installing, updating and deleting plugins and themes is a network operation: the
	 * files are shared by every site, so only a network administrator on the main site
	 * may touch them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $action One of `install`, `update` or `delete`.
	 * @param string $type   Extension type, or `all`.
	 * @return WP_Error|null Null when the caller may proceed.
	 */
	public static function require_network( $action, $type ) {
		if ( ! is_multisite() ) {
			return null;
		}

		if ( ! in_array( (string) $action, array( 'install', 'update', 'delete' ), true ) ) {
			return null;
		}

		if ( ! is_main_site() ) {
			return Error::make(
				'unsupported',
				__( 'Plugin and theme files are shared by every site in the network and can only be changed from the main site.', 'super-abilities' ),
				array( 'status' => 501 )
			);
		}

		$type  = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		$types = 'all' === $type ? array( Target::TYPE_PLUGIN, Target::TYPE_THEME ) : array( Target::normalize_type( $type ) );

		foreach ( $types as $one ) {
			$cap = Target::TYPE_THEME === $one ? 'manage_network_themes' : 'manage_network_plugins';

			if ( ! current_user_can( $cap ) ) {
				return Error::make(
					'unsupported',
					sprintf(
						/* translators: %s: Capability name. */
						__( 'On a multisite network this is a network administrator operation and requires the "%s" capability.', 'super-abilities' ),
						$cap
					),
					array(
						'status'              => 501,
						'required_capability' => $cap,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Refuses the operation when this site forbids file modifications.
	 *
	 * @since 0.2.0
	 *
	 * @param string $action One of `install`, `update` or `delete`.
	 * @param string $type   Extension type.
	 * @return WP_Error|null Null when the caller may proceed.
	 */
	public static function require_file_mods( $action, $type ) {
		$context = Preflight::file_mod_context( $type, $action );

		if ( Capabilities::file_mods_allowed( $context ) ) {
			return null;
		}

		return Error::make(
			'forbidden',
			sprintf(
				/* translators: %s: wp_is_file_mod_allowed() context, for example install_plugin. */
				__( 'This site does not allow file modifications (%s), usually because DISALLOW_FILE_MODS is defined.', 'super-abilities' ),
				$context
			),
			array(
				'status'  => 403,
				'context' => $context,
			)
		);
	}

	/**
	 * This plugin's own basename.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function self_basename() {
		if ( defined( 'SUPER_ABILITIES_FILE' ) ) {
			return plugin_basename( (string) SUPER_ABILITIES_FILE );
		}

		return 'super-abilities/super-abilities.php';
	}

	/**
	 * The plugins and themes that may not be deactivated or deleted without `force`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function protected_extensions() {
		$protected = array( self::self_basename() );

		/**
		 * Filters the plugins and themes the extensions module refuses to deactivate or delete.
		 *
		 * Entries are plugin files (`akismet/akismet.php`), plugin folder names (`akismet`)
		 * or theme stylesheet directories. This plugin's own basename is always on the list,
		 * because an agent that deactivates it loses the ability to put it back. Callers can
		 * still pass `force` to override the list.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, string> $protected Protected plugin files, plugin slugs and stylesheets.
		 */
		$protected = (array) apply_filters( 'super_abilities_protected_extensions', $protected );

		$clean = array();

		foreach ( $protected as $entry ) {
			if ( is_array( $entry ) || is_object( $entry ) ) {
				continue;
			}

			$entry = trim( (string) $entry );

			if ( '' !== $entry ) {
				$clean[ $entry ] = $entry;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Whether one extension is on the protected list.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return bool
	 */
	public static function is_protected( $type, $slug ) {
		$slug = trim( (string) $slug );

		if ( '' === $slug ) {
			return false;
		}

		$candidates = array( $slug );

		if ( Target::TYPE_PLUGIN === Target::normalize_type( $type ) ) {
			$candidates[] = Target::plugin_slug( $slug );
		}

		foreach ( self::protected_extensions() as $entry ) {
			if ( in_array( $entry, $candidates, true ) || in_array( Target::plugin_slug( $entry ), $candidates, true ) ) {
				return true;
			}
		}

		return false;
	}
}
