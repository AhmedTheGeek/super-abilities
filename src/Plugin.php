<?php
/**
 * Composition root.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

use SuperAbilities\Admin\Notices;
use SuperAbilities\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and owns the singleton instance.
 *
 * @since 0.1.0
 */
class Plugin {

	/**
	 * Option holding the timestamp of the last cron heartbeat.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const HEARTBEAT_OPTION = 'super_abilities_last_cron_tick';

	/**
	 * Singleton instance.
	 *
	 * @since 0.1.0
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 * @var Options
	 */
	protected $options;

	/**
	 * Module registry.
	 *
	 * @since 0.1.0
	 * @var Modules
	 */
	protected $modules;

	/**
	 * Ability registrar.
	 *
	 * @since 0.1.0
	 * @var Registrar
	 */
	protected $registrar;

	/**
	 * Whether `run()` already executed.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	protected $ran = false;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	protected function __construct() {
		$this->options   = new Options( $this );
		$this->modules   = new Modules( $this );
		$this->registrar = new Registrar( $this );
	}

	/**
	 * Boots the plugin on `plugins_loaded`.
	 *
	 * Bails with an admin notice when the Abilities API is unavailable, which happens
	 * on WordPress older than 6.9.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( Notices::class, 'missing_abilities_api' ) );
			return;
		}

		self::instance()->run();
	}

	/**
	 * Returns the singleton instance, creating it when needed.
	 *
	 * @since 0.1.0
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Whether the singleton has been created.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function has_instance() {
		return null !== self::$instance;
	}

	/**
	 * Wires hooks, modules and abilities.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->ran ) {
			return;
		}

		$this->ran = true;

		Install::maybe_upgrade();

		$this->modules->init();
		$this->registrar->init();

		add_action( 'add_option_' . Options::OPTION, array( $this->options, 'flush' ) );
		add_action( 'update_option_' . Options::OPTION, array( $this->options, 'flush' ) );
		add_action( 'wp_initialize_site', array( Install::class, 'on_new_site' ), 20 );
		add_action( 'super_abilities_heartbeat', array( $this, 'heartbeat' ) );

		if ( is_admin() ) {
			$settings_page = new Settings_Page( $this );
			$settings_page->init();
		}

		/**
		 * Fires once the plugin has wired its modules and abilities.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin Composition root.
		 */
		do_action( 'super_abilities_loaded', $this );
	}

	/**
	 * Records that WP-Cron is running.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function heartbeat() {
		update_option( self::HEARTBEAT_OPTION, time(), false );
	}

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 *
	 * @return Options
	 */
	public function options() {
		return $this->options;
	}

	/**
	 * Module registry.
	 *
	 * @since 0.1.0
	 *
	 * @return Modules
	 */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Ability registrar.
	 *
	 * @since 0.1.0
	 *
	 * @return Registrar
	 */
	public function registrar() {
		return $this->registrar;
	}
}
