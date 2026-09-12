<?php
/**
 * Base module implementation.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Provides sane defaults for every module.
 *
 * Subclasses must implement `id()`, `label()` and `description()`.
 *
 * @since 0.1.0
 */
abstract class Abstract_Module implements Module_Interface {

	/**
	 * Composition root.
	 *
	 * @since 0.1.0
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Plugin $plugin Composition root.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether the module is enabled on a fresh install.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * Risk level: `low`, `medium` or `high`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function risk() {
		return 'low';
	}

	/**
	 * Fully qualified class names of the abilities this module provides.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function abilities() {
		return array();
	}

	/**
	 * Registers runtime hooks. Only called when the module is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot() {}

	/**
	 * Registers install time hooks such as table schema filters. Always called.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function install() {}

	/**
	 * Arguments passed to `wp_register_ability_category()` for this module.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function category_args() {
		return array(
			'label'       => $this->label(),
			'description' => $this->description(),
			'meta'        => array(
				'super_abilities' => array(
					'module' => $this->id(),
					'risk'   => $this->risk(),
				),
			),
		);
	}

	/**
	 * Whether this module is currently enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->plugin->options()->is_module_enabled( $this->id() );
	}

	/**
	 * Shortcut to the settings object.
	 *
	 * @since 0.1.0
	 *
	 * @return Options
	 */
	protected function options() {
		return $this->plugin->options();
	}
}
