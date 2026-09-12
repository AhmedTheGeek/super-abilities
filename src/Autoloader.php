<?php
/**
 * PSR-4 style autoloader.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the `SuperAbilities\` namespace onto the plugin `src/` directory.
 *
 * File names match class names exactly, e.g. `SuperAbilities\Abilities\Abstract_Ability`
 * lives in `src/Abilities/Abstract_Ability.php`.
 *
 * @since 0.1.0
 */
class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PREFIX = 'SuperAbilities\\';

	/**
	 * Registers the autoloader with SPL.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Loads the file holding the given class, if it belongs to this plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		$class_name = (string) $class_name;

		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );

		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return;
		}

		$path = SUPER_ABILITIES_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
