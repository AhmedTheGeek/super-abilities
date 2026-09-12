<?php
/**
 * Pending updates overview ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Security;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the pending core, plugin and theme updates and their compatibility.
 *
 * @since 0.1.0
 */
class Updates_Overview extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'updates-overview';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'security';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Updates overview', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the pending core, plugin and theme updates together with the auto-update state of each item and whether the new version still supports the PHP and WordPress versions this site runs. Reads the cached update data by default; pass refresh true to ask WordPress.org first, which takes a few seconds. This ability never installs anything.', 'super-abilities' );
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
		return array( 'update_plugins' );
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
				'refresh' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Ask WordPress.org for fresh update data before answering. Slower, and rate limited upstream.', 'super-abilities' ),
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
		$plugin_update = Schema::object(
			array(
				'plugin'         => array( 'type' => 'string' ),
				'slug'           => array( 'type' => 'string' ),
				'name'           => array( 'type' => 'string' ),
				'current'        => array( 'type' => 'string' ),
				'new_version'    => array( 'type' => 'string' ),
				'requires_php'   => array( 'type' => array( 'string', 'null' ) ),
				'requires_wp'    => array( 'type' => array( 'string', 'null' ) ),
				'compatible_php' => array( 'type' => 'boolean' ),
				'compatible_wp'  => array( 'type' => 'boolean' ),
				'auto_update'    => array( 'type' => 'boolean' ),
			),
			array( 'plugin', 'slug', 'name', 'current', 'new_version', 'requires_php', 'requires_wp', 'compatible_php', 'compatible_wp', 'auto_update' )
		);

		$theme_update = Schema::object(
			array(
				'stylesheet'     => array( 'type' => 'string' ),
				'name'           => array( 'type' => 'string' ),
				'current'        => array( 'type' => 'string' ),
				'new_version'    => array( 'type' => 'string' ),
				'requires_php'   => array( 'type' => array( 'string', 'null' ) ),
				'requires_wp'    => array( 'type' => array( 'string', 'null' ) ),
				'compatible_php' => array( 'type' => 'boolean' ),
				'compatible_wp'  => array( 'type' => 'boolean' ),
				'auto_update'    => array( 'type' => 'boolean' ),
			),
			array( 'stylesheet', 'name', 'current', 'new_version', 'requires_php', 'requires_wp', 'compatible_php', 'compatible_wp', 'auto_update' )
		);

		return Schema::object(
			array(
				'core'       => Schema::object(
					array(
						'current'           => array( 'type' => 'string' ),
						'available'         => array(
							'type'  => 'array',
							'items' => Schema::object(
								array(
									'version'  => array( 'type' => 'string' ),
									'response' => array( 'type' => 'string' ),
									'locale'   => array( 'type' => 'string' ),
								),
								array( 'version', 'response', 'locale' )
							),
						),
						'auto_update_major' => array( 'type' => 'string' ),
						'auto_update'       => array( 'type' => 'boolean' ),
					),
					array( 'current', 'available', 'auto_update_major', 'auto_update' )
				),
				'plugins'    => Schema::object(
					array(
						'total'       => array( 'type' => 'integer' ),
						'auto_update' => array( 'type' => 'boolean' ),
						'updates'     => array(
							'type'  => 'array',
							'items' => $plugin_update,
						),
					),
					array( 'total', 'auto_update', 'updates' )
				),
				'themes'     => Schema::object(
					array(
						'total'       => array( 'type' => 'integer' ),
						'auto_update' => array( 'type' => 'boolean' ),
						'updates'     => array(
							'type'  => 'array',
							'items' => $theme_update,
						),
					),
					array( 'total', 'auto_update', 'updates' )
				),
				'checked_at' => array( 'type' => array( 'string', 'null' ) ),
				'refreshed'  => array( 'type' => 'boolean' ),
			),
			array( 'core', 'plugins', 'themes', 'checked_at', 'refreshed' )
		);
	}

	/**
	 * Builds the overview.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$refresh = ! empty( $input['refresh'] );

		if ( $refresh ) {
			wp_update_plugins();
			wp_update_themes();
			wp_version_check();
		}

		$plugin_data = get_site_transient( 'update_plugins' );
		$theme_data  = get_site_transient( 'update_themes' );

		return array(
			'core'       => $this->core(),
			'plugins'    => $this->plugins( is_object( $plugin_data ) ? $plugin_data : null ),
			'themes'     => $this->themes( is_object( $theme_data ) ? $theme_data : null ),
			'checked_at' => $this->checked_at( is_object( $plugin_data ) ? $plugin_data : null ),
			'refreshed'  => $refresh,
		);
	}

	/**
	 * Core section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function core() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates   = get_core_updates();
		$available = array();

		if ( is_array( $updates ) ) {
			foreach ( $updates as $update ) {
				if ( ! is_object( $update ) ) {
					continue;
				}

				$available[] = array(
					'version'  => isset( $update->current ) ? (string) $update->current : '',
					'response' => isset( $update->response ) ? (string) $update->response : '',
					'locale'   => isset( $update->locale ) ? (string) $update->locale : '',
				);
			}
		}

		return array(
			'current'           => (string) get_bloginfo( 'version' ),
			'available'         => $available,
			'auto_update_major' => (string) get_site_option( 'auto_update_core_major', 'unset' ),
			'auto_update'       => $this->core_auto_updates_enabled(),
		);
	}

	/**
	 * Whether the automatic updater is allowed to run at all.
	 *
	 * `wp_is_auto_update_enabled_for_type()` only answers for `plugin` and `theme`, so
	 * the updater itself is asked about core.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	protected function core_auto_updates_enabled() {
		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		$updater = new \WP_Automatic_Updater();

		return ! $updater->is_disabled();
	}

	/**
	 * Plugins section.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null $transient Value of the `update_plugins` site transient.
	 * @return array<string, mixed>
	 */
	protected function plugins( $transient ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$enabled   = array_map( 'strval', (array) get_site_option( 'auto_update_plugins', array() ) );
		$responses = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$updates   = array();

		foreach ( $responses as $file => $update ) {
			$file = (string) $file;
			$data = isset( $installed[ $file ] ) && is_array( $installed[ $file ] ) ? $installed[ $file ] : array();

			$requires_php = $this->string_or_null( is_object( $update ) && isset( $update->requires_php ) ? $update->requires_php : null );
			$requires_wp  = $this->string_or_null( is_object( $update ) && isset( $update->requires ) ? $update->requires : null );

			$updates[] = array(
				'plugin'         => $file,
				'slug'           => is_object( $update ) && isset( $update->slug ) ? (string) $update->slug : ( false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ) ),
				'name'           => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'current'        => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'new_version'    => is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : '',
				'requires_php'   => $requires_php,
				'requires_wp'    => $requires_wp,
				'compatible_php' => null === $requires_php || is_php_version_compatible( $requires_php ),
				'compatible_wp'  => null === $requires_wp || is_wp_version_compatible( $requires_wp ),
				'auto_update'    => in_array( $file, $enabled, true ),
			);
		}

		return array(
			'total'       => count( $installed ),
			'auto_update' => function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' ),
			'updates'     => $updates,
		);
	}

	/**
	 * Themes section.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null $transient Value of the `update_themes` site transient.
	 * @return array<string, mixed>
	 */
	protected function themes( $transient ) {
		$installed = wp_get_themes();
		$enabled   = array_map( 'strval', (array) get_site_option( 'auto_update_themes', array() ) );
		$responses = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$updates   = array();

		foreach ( $responses as $stylesheet => $update ) {
			$stylesheet = (string) $stylesheet;
			$update     = is_array( $update ) ? $update : array();
			$theme      = isset( $installed[ $stylesheet ] ) ? $installed[ $stylesheet ] : null;

			$requires_php = $this->string_or_null( isset( $update['requires_php'] ) ? $update['requires_php'] : null );
			$requires_wp  = $this->string_or_null( isset( $update['requires'] ) ? $update['requires'] : null );

			$updates[] = array(
				'stylesheet'     => $stylesheet,
				'name'           => null === $theme ? '' : (string) $theme->get( 'Name' ),
				'current'        => null === $theme ? '' : (string) $theme->get( 'Version' ),
				'new_version'    => isset( $update['new_version'] ) ? (string) $update['new_version'] : '',
				'requires_php'   => $requires_php,
				'requires_wp'    => $requires_wp,
				'compatible_php' => null === $requires_php || is_php_version_compatible( $requires_php ),
				'compatible_wp'  => null === $requires_wp || is_wp_version_compatible( $requires_wp ),
				'auto_update'    => in_array( $stylesheet, $enabled, true ),
			);
		}

		return array(
			'total'       => count( $installed ),
			'auto_update' => function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'theme' ),
			'updates'     => $updates,
		);
	}

	/**
	 * When core last checked for updates.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null $transient Value of the `update_plugins` site transient.
	 * @return string|null ISO 8601 UTC timestamp, or null when nothing has been checked.
	 */
	protected function checked_at( $transient ) {
		$checked = isset( $transient->last_checked ) ? (int) $transient->last_checked : 0;

		return $checked > 0 ? Time::iso( $checked ) : null;
	}

	/**
	 * Normalizes a version requirement into a non-empty string, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw requirement.
	 * @return string|null
	 */
	protected function string_or_null( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
