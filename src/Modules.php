<?php
/**
 * Module registry.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the ordered list of module instances.
 *
 * Module classes that are not present on disk are skipped, so packages that ship
 * later never break the plugin.
 *
 * @since 0.1.0
 */
class Modules {

	/**
	 * Module classes in display order.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const MODULE_CLASSES = array(
		'SuperAbilities\\Modules\\Audit_Module',
		'SuperAbilities\\Modules\\Jobs_Module',
		'SuperAbilities\\Modules\\Health_Module',
		'SuperAbilities\\Modules\\Security_Module',
		'SuperAbilities\\Modules\\Design_Module',
		'SuperAbilities\\Modules\\Blocks_Module',
		'SuperAbilities\\Modules\\Extensions_Module',
		'SuperAbilities\\Modules\\Media_Module',
		'SuperAbilities\\Modules\\Access_Module',
		'SuperAbilities\\Modules\\Redirects_Module',
	);

	/**
	 * Composition root.
	 *
	 * @since 0.1.0
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Module instances keyed by id.
	 *
	 * @since 0.1.0
	 * @var array<string, Module_Interface>
	 */
	protected $modules = array();

	/**
	 * Whether `init()` already ran.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Builds the module list.
	 *
	 * Nothing is installed or booted here, so this constructor never touches
	 * settings and can run before the registry is exposed on the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param Plugin $plugin Composition root.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		$instances = array();

		foreach ( self::module_classes() as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name( $plugin );

			if ( ! $module instanceof Module_Interface ) {
				continue;
			}

			$instances[ $module->id() ] = $module;
		}

		/**
		 * Filters the registered modules.
		 *
		 * Values must implement {@see Module_Interface}. The array is keyed by module id
		 * and its order drives the settings screen and the catalog ability.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, Module_Interface> $instances Module instances keyed by id.
		 * @param Plugin                          $plugin    Composition root.
		 */
		$instances = (array) apply_filters( 'super_abilities_modules', $instances, $plugin );

		foreach ( $instances as $id => $module ) {
			if ( $module instanceof Module_Interface ) {
				$this->modules[ is_string( $id ) && '' !== $id ? $id : $module->id() ] = $module;
			}
		}
	}

	/**
	 * The module class names to try, in display order.
	 *
	 * Classes from packages that are not installed yet are simply skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public static function module_classes() {
		return self::MODULE_CLASSES;
	}

	/**
	 * Installs every module and boots the enabled ones.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->modules as $module ) {
			$module->install();
		}

		foreach ( $this->enabled() as $module ) {
			$module->boot();
		}
	}

	/**
	 * Every registered module, in display order.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Module_Interface>
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * Modules that are currently enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Module_Interface>
	 */
	public function enabled() {
		$options = $this->plugin->options();
		$enabled = array();

		foreach ( $this->modules as $id => $module ) {
			if ( $options->is_module_enabled( $id ) ) {
				$enabled[ $id ] = $module;
			}
		}

		return $enabled;
	}

	/**
	 * Returns one module by id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Module id.
	 * @return Module_Interface|null
	 */
	public function get( $id ) {
		$id = (string) $id;

		return isset( $this->modules[ $id ] ) ? $this->modules[ $id ] : null;
	}
}
