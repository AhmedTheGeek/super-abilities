<?php
/**
 * Module contract.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * A module groups related abilities behind a single settings toggle.
 *
 * @since 0.1.0
 */
interface Module_Interface {

	/**
	 * Machine id, e.g. `audit`. Used for the settings key and the category slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human readable, translated module name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Translated one paragraph description of what the module does.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description();

	/**
	 * Whether the module is enabled on a fresh install.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function default_enabled();

	/**
	 * Risk level: `low`, `medium` or `high`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function risk();

	/**
	 * Fully qualified class names of the abilities this module provides.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function abilities();

	/**
	 * Registers runtime hooks. Only called when the module is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot();

	/**
	 * Registers install time hooks such as table schema filters. Always called.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function install();

	/**
	 * Arguments passed to `wp_register_ability_category()` for this module.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function category_args();
}
