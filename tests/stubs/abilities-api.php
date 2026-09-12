<?php
/**
 * Static analysis stubs for the WordPress Abilities API.
 *
 * This file is never loaded at runtime. It exists so PHPStan understands the
 * Abilities API on WordPress versions whose stubs package predates it.
 *
 * @package SuperAbilities
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Ability' ) ) {

	/**
	 * An ability registered with the Abilities API.
	 *
	 * @since 6.9.0
	 */
	class WP_Ability {

		/**
		 * Constructor.
		 *
		 * @param string               $name Ability name, including its namespace.
		 * @param array<string, mixed> $args Registration arguments.
		 */
		public function __construct( string $name, array $args ) {}

		/**
		 * Ability name, including its namespace.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return '';
		}

		/**
		 * Human readable label.
		 *
		 * @return string
		 */
		public function get_label(): string {
			return '';
		}

		/**
		 * Detailed description.
		 *
		 * @return string
		 */
		public function get_description(): string {
			return '';
		}

		/**
		 * Category slug.
		 *
		 * @return string
		 */
		public function get_category(): string {
			return '';
		}

		/**
		 * Input schema.
		 *
		 * @return array<string, mixed>
		 */
		public function get_input_schema(): array {
			return array();
		}

		/**
		 * Output schema.
		 *
		 * @return array<string, mixed>
		 */
		public function get_output_schema(): array {
			return array();
		}

		/**
		 * Metadata.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return array();
		}

		/**
		 * One metadata item.
		 *
		 * @param string $key           Metadata key.
		 * @param mixed  $default_value Fallback value.
		 * @return mixed
		 */
		public function get_meta_item( string $key, $default_value = null ) {
			return $default_value;
		}

		/**
		 * Normalizes raw input.
		 *
		 * @param mixed $input Raw input.
		 * @return mixed
		 */
		public function normalize_input( $input = null ) {
			return $input;
		}

		/**
		 * Validates input against the input schema.
		 *
		 * @param mixed $input Input to validate.
		 * @return true|WP_Error
		 */
		public function validate_input( $input = null ) {
			return true;
		}

		/**
		 * Runs the permission callback.
		 *
		 * @param mixed $input Validated input.
		 * @return bool|WP_Error
		 */
		public function check_permissions( $input = null ) {
			return true;
		}

		/**
		 * Runs the ability.
		 *
		 * @param mixed $input Raw input.
		 * @return mixed|WP_Error
		 */
		public function execute( $input = null ) {
			return null;
		}
	}
}

if ( ! class_exists( 'WP_Ability_Category' ) ) {

	/**
	 * An ability category.
	 *
	 * @since 6.9.0
	 */
	class WP_Ability_Category {

		/**
		 * Constructor.
		 *
		 * @param string               $slug Category slug.
		 * @param array<string, mixed> $args Registration arguments.
		 */
		public function __construct( string $slug, array $args ) {}

		/**
		 * Category slug.
		 *
		 * @return string
		 */
		public function get_slug(): string {
			return '';
		}

		/**
		 * Human readable label.
		 *
		 * @return string
		 */
		public function get_label(): string {
			return '';
		}

		/**
		 * Description.
		 *
		 * @return string
		 */
		public function get_description(): string {
			return '';
		}

		/**
		 * Metadata.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return array();
		}
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {

	/**
	 * Registers an ability.
	 *
	 * @param string               $name Ability name, including its namespace.
	 * @param array<string, mixed> $args Registration arguments.
	 * @return WP_Ability|null
	 */
	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		return null;
	}

	/**
	 * Unregisters an ability.
	 *
	 * @param string $name Ability name.
	 * @return WP_Ability|null
	 */
	function wp_unregister_ability( string $name ): ?WP_Ability {
		return null;
	}

	/**
	 * Whether an ability is registered.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	function wp_has_ability( string $name ): bool {
		return false;
	}

	/**
	 * Returns one registered ability.
	 *
	 * @param string $name Ability name.
	 * @return WP_Ability|null
	 */
	function wp_get_ability( string $name ): ?WP_Ability {
		return null;
	}

	/**
	 * Returns registered abilities, keyed by ability name.
	 *
	 * @param array<string, mixed> $args Optional filter arguments.
	 * @return array<string, WP_Ability>
	 */
	function wp_get_abilities( array $args = array() ): array {
		return array();
	}

	/**
	 * Registers an ability category.
	 *
	 * @param string               $slug Category slug.
	 * @param array<string, mixed> $args Registration arguments.
	 * @return WP_Ability_Category|null
	 */
	function wp_register_ability_category( string $slug, array $args ): ?WP_Ability_Category {
		return null;
	}

	/**
	 * Unregisters an ability category.
	 *
	 * @param string $slug Category slug.
	 * @return WP_Ability_Category|null
	 */
	function wp_unregister_ability_category( string $slug ): ?WP_Ability_Category {
		return null;
	}

	/**
	 * Whether an ability category is registered.
	 *
	 * @param string $slug Category slug.
	 * @return bool
	 */
	function wp_has_ability_category( string $slug ): bool {
		return false;
	}

	/**
	 * Returns one registered ability category.
	 *
	 * @param string $slug Category slug.
	 * @return WP_Ability_Category|null
	 */
	function wp_get_ability_category( string $slug ): ?WP_Ability_Category {
		return null;
	}

	/**
	 * Returns every registered ability category, keyed by slug.
	 *
	 * @return array<string, WP_Ability_Category>
	 */
	function wp_get_ability_categories(): array {
		return array();
	}
}
