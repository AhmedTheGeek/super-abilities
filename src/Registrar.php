<?php
/**
 * Ability and category registration.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Catalog_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Registers our ability categories and abilities with core.
 *
 * @since 0.1.0
 */
class Registrar {

	/**
	 * Slug of the always-on core category.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CORE_CATEGORY = 'super-abilities-core';

	/**
	 * Composition root.
	 *
	 * @since 0.1.0
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Ability instances that were registered, keyed by ability name.
	 *
	 * @since 0.1.0
	 * @var array<string, Abstract_Ability>
	 */
	protected $registered = array();

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
	 * Hooks into the Abilities API initialization actions.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the core category and one category per enabled module.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			self::CORE_CATEGORY,
			array(
				'label'       => __( 'Super Abilities', 'super-abilities' ),
				'description' => __( 'Discovery abilities provided by the Super Abilities plugin.', 'super-abilities' ),
			)
		);

		foreach ( $this->plugin->modules()->enabled() as $id => $module ) {
			$args = $module->category_args();

			if ( empty( $args['label'] ) || empty( $args['description'] ) ) {
				continue;
			}

			wp_register_ability_category( Abstract_Ability::CATEGORY_PREFIX . $id, $args );
		}
	}

	/**
	 * Registers the catalog ability and every ability of every enabled module.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_abilities() {
		$this->register( new Catalog_Ability() );

		foreach ( $this->plugin->modules()->enabled() as $module ) {
			foreach ( $module->abilities() as $class_name ) {
				$class_name = (string) $class_name;

				if ( ! class_exists( $class_name ) ) {
					continue;
				}

				$ability = new $class_name();

				if ( $ability instanceof Abstract_Ability ) {
					$this->register( $ability );
				}
			}
		}
	}

	/**
	 * Registers a single ability, unless a filter vetoes it.
	 *
	 * @since 0.1.0
	 *
	 * @param Abstract_Ability $ability Ability to register.
	 * @return bool Whether the ability was registered.
	 */
	public function register( Abstract_Ability $ability ) {
		$name = $ability->name();

		/**
		 * Filters whether one of our abilities is registered.
		 *
		 * Return false to keep an ability out of the registry, for example once core
		 * ships an equivalent ability of its own.
		 *
		 * @since 0.1.0
		 *
		 * @param bool             $enabled Whether to register the ability.
		 * @param string           $name    Fully namespaced ability name.
		 * @param Abstract_Ability $ability Ability instance.
		 */
		if ( ! apply_filters( 'super_abilities_ability_enabled', true, $name, $ability ) ) {
			return false;
		}

		if ( null === wp_register_ability( $name, $ability->to_args() ) ) {
			return false;
		}

		$this->registered[ $name ] = $ability;

		return true;
	}

	/**
	 * Every ability instance this plugin registered.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Abstract_Ability>
	 */
	public function registered() {
		return $this->registered;
	}

	/**
	 * Returns one registered ability instance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Fully namespaced ability name.
	 * @return Abstract_Ability|null
	 */
	public function get( $name ) {
		$name = (string) $name;

		return isset( $this->registered[ $name ] ) ? $this->registered[ $name ] : null;
	}
}
