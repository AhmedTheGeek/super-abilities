<?php
/**
 * Super Abilities.
 *
 * @package SuperAbilities
 * @author  Ahmed Hussein
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Super Abilities
 * Description:       Advanced, capability-gated abilities that let AI agents manage a WordPress site through the core Abilities API.
 * Version:           0.1.1
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Ahmed Hussein
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       super-abilities
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 *
 * @since 0.1.0
 */
define( 'SUPER_ABILITIES_VERSION', '0.1.1' );

/**
 * Database schema version. Bump to trigger table upgrades.
 *
 * @since 0.1.0
 */
define( 'SUPER_ABILITIES_DB_VERSION', 1 );

/**
 * Absolute path to the main plugin file.
 *
 * @since 0.1.0
 */
define( 'SUPER_ABILITIES_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 *
 * @since 0.1.0
 */
define( 'SUPER_ABILITIES_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with a trailing slash.
 *
 * @since 0.1.0
 */
define( 'SUPER_ABILITIES_URL', plugin_dir_url( __FILE__ ) );

require_once SUPER_ABILITIES_DIR . 'src/Autoloader.php';

SuperAbilities\Autoloader::register();

register_activation_hook( __FILE__, array( 'SuperAbilities\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SuperAbilities\Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SuperAbilities\Plugin', 'boot' ) );

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'super-abilities', false, dirname( plugin_basename( SUPER_ABILITIES_FILE ) ) . '/languages' );
	}
);
