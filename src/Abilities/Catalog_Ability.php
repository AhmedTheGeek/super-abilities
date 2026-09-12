<?php
/**
 * Layered discovery ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities;

use SuperAbilities\Module_Interface;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Version;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the plugin, its modules and every ability they provide.
 *
 * Disabled modules are listed too, with `enabled: false`, so an agent can tell the
 * difference between "this site cannot do that" and "an administrator turned it off".
 *
 * @since 0.1.0
 */
class Catalog_Ability extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'catalog';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'core';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Super Abilities catalog', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the Super Abilities modules installed on this site and the abilities each one provides, including modules that are currently switched off. Call this first to orient yourself instead of loading every ability schema, then call the abilities you need.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'read' );
	}

	/**
	 * Input schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'module' => array(
					'type'        => 'string',
					'description' => __( 'Limit the response to a single module id, for example "health".', 'super-abilities' ),
				),
			)
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		$ability = Schema::object(
			array(
				'name'        => array( 'type' => 'string' ),
				'label'       => array( 'type' => 'string' ),
				'summary'     => array( 'type' => 'string' ),
				'annotations' => Schema::object(
					array(
						'readonly'    => array( 'type' => 'boolean' ),
						'destructive' => array( 'type' => 'boolean' ),
						'idempotent'  => array( 'type' => 'boolean' ),
					)
				),
				'capability'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'name', 'label', 'summary', 'annotations', 'capability' )
		);

		$module = Schema::object(
			array(
				'id'              => array( 'type' => 'string' ),
				'label'           => array( 'type' => 'string' ),
				'description'     => array( 'type' => 'string' ),
				'enabled'         => array( 'type' => 'boolean' ),
				'risk'            => array( 'type' => 'string' ),
				'default_enabled' => array( 'type' => 'boolean' ),
				'abilities'       => array(
					'type'  => 'array',
					'items' => $ability,
				),
			),
			array( 'id', 'label', 'description', 'enabled', 'risk', 'default_enabled', 'abilities' )
		);

		return Schema::object(
			array(
				'version'             => array( 'type' => 'string' ),
				'wp_version'          => array( 'type' => 'string' ),
				'abilities_api_level' => array( 'type' => 'string' ),
				'modules'             => array(
					'type'  => 'array',
					'items' => $module,
				),
			),
			array( 'version', 'wp_version', 'abilities_api_level', 'modules' )
		);
	}

	/**
	 * Builds the catalog.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$wanted  = isset( $input['module'] ) ? sanitize_key( (string) $input['module'] ) : '';
		$plugin  = Plugin::instance();
		$options = $plugin->options();
		$modules = array();

		$core = array(
			'id'              => 'core',
			'label'           => __( 'Core', 'super-abilities' ),
			'description'     => __( 'Always available abilities that describe this plugin.', 'super-abilities' ),
			'enabled'         => true,
			'risk'            => 'low',
			'default_enabled' => true,
			'abilities'       => array( $this->describe_ability( $this ) ),
		);

		if ( '' === $wanted || 'core' === $wanted ) {
			$modules[] = $core;
		}

		foreach ( $plugin->modules()->all() as $id => $module ) {
			if ( '' !== $wanted && $wanted !== $id ) {
				continue;
			}

			$modules[] = array(
				'id'              => (string) $id,
				'label'           => (string) $module->label(),
				'description'     => (string) $module->description(),
				'enabled'         => $options->is_module_enabled( (string) $id ),
				'risk'            => (string) $module->risk(),
				'default_enabled' => (bool) $module->default_enabled(),
				'abilities'       => $this->describe_module_abilities( $module ),
			);
		}

		return array(
			'version'             => (string) SUPER_ABILITIES_VERSION,
			'wp_version'          => Version::wp(),
			'abilities_api_level' => Version::abilities_api_level(),
			'modules'             => $modules,
		);
	}

	/**
	 * Describes every ability a module declares, whether or not it is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param Module_Interface $module Module instance.
	 * @return array<int, array<string, mixed>>
	 */
	protected function describe_module_abilities( Module_Interface $module ) {
		$described = array();

		foreach ( $module->abilities() as $class_name ) {
			$class_name = (string) $class_name;

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$ability = new $class_name();

			if ( ! $ability instanceof Abstract_Ability ) {
				continue;
			}

			$described[] = $this->describe_ability( $ability );
		}

		return $described;
	}

	/**
	 * Describes one ability.
	 *
	 * @since 0.1.0
	 *
	 * @param Abstract_Ability $ability Ability instance.
	 * @return array<string, mixed>
	 */
	protected function describe_ability( Abstract_Ability $ability ) {
		$annotations = $ability->annotations();

		return array(
			'name'        => $ability->name(),
			'label'       => (string) $ability->label(),
			'summary'     => $ability->summary(),
			'annotations' => array(
				'readonly'    => ! empty( $annotations['readonly'] ),
				'destructive' => ! empty( $annotations['destructive'] ),
				'idempotent'  => ! empty( $annotations['idempotent'] ),
			),
			'capability'  => array_values( array_map( 'strval', $ability->capability() ) ),
		);
	}
}
