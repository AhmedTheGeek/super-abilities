<?php
/**
 * Plugin settings access.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single autoloaded settings option.
 *
 * @since 0.1.0
 */
class Options {

	/**
	 * Option name holding every setting.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'super_abilities_settings';

	/**
	 * Settings API option group.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const GROUP = 'super_abilities';

	/**
	 * Default values for every setting.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	const DEFAULTS = array(
		'modules'                  => array(
			'audit'      => true,
			'jobs'       => true,
			'health'     => true,
			'security'   => true,
			'design'     => true,
			'blocks'     => true,
			'extensions' => false,
			'media'      => true,
			'access'     => false,
			'redirects'  => false,
		),
		'audit_retention_days'     => 90,
		'audit_third_party'        => true,
		'audit_store_ip'           => true,
		'jobs_time_budget'         => 20,
		'jobs_max_items'           => 500,
		'jobs_retention_days'      => 30,
		'extensions_allow_zip_url' => false,
		'extensions_zip_hosts'     => array(),
		'media_import_max_bytes'   => 20971520,
		'delete_data_on_uninstall' => true,
	);

	/**
	 * Plugin instance, used to resolve module defaults.
	 *
	 * @since 0.1.0
	 * @var Plugin|null
	 */
	protected $plugin;

	/**
	 * Cached merged settings.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>|null
	 */
	protected $cache = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Plugin|null $plugin Optional. Plugin instance used to look up module defaults.
	 */
	public function __construct( ?Plugin $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Returns the stored option value without any defaults applied.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function raw() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Returns every setting, with defaults applied.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = $this->raw();
		$values = array_merge( self::DEFAULTS, $stored );

		$modules           = isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array();
		$values['modules'] = array_merge( self::DEFAULTS['modules'], $modules );
		$this->cache       = $values;

		return $this->cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Optional. Value returned when the key is unknown. Default null.
	 * @return mixed
	 */
	public function get( $key, $default_value = null ) {
		$values = $this->all();

		if ( array_key_exists( $key, $values ) ) {
			return $values[ $key ];
		}

		return $default_value;
	}

	/**
	 * Writes one or many settings.
	 *
	 * @since 0.1.0
	 *
	 * @param string|array<string, mixed> $key   Setting key, or a map of key => value.
	 * @param mixed                       $value Optional. Value when `$key` is a string. Default null.
	 * @return bool True when the option was written.
	 */
	public function set( $key, $value = null ) {
		$changes = is_array( $key ) ? $key : array( (string) $key => $value );
		$stored  = array_merge( $this->raw(), $changes );

		$this->cache = null;

		return (bool) update_option( self::OPTION, $stored, true );
	}

	/**
	 * Clears the in-memory cache.
	 *
	 * Also hooked to `add_option_super_abilities_settings` and
	 * `update_option_super_abilities_settings`, so a write from anywhere in the
	 * request invalidates it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush() {
		$this->cache = null;
	}

	/**
	 * Whether a module is enabled.
	 *
	 * Falls back to the module's own `default_enabled()` when the setting was never
	 * stored, then to the shipped defaults.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function is_module_enabled( $id ) {
		$id     = (string) $id;
		$stored = $this->raw();

		if ( isset( $stored['modules'] ) && is_array( $stored['modules'] ) && array_key_exists( $id, $stored['modules'] ) ) {
			return (bool) $stored['modules'][ $id ];
		}

		if ( $this->plugin instanceof Plugin ) {
			$modules = $this->plugin->modules();

			$module = $modules->get( $id );

			if ( $module instanceof Module_Interface ) {
				return $module->default_enabled();
			}
		}

		$defaults = self::DEFAULTS['modules'];

		return isset( $defaults[ $id ] ) ? (bool) $defaults[ $id ] : false;
	}

	/**
	 * Sanitizes submitted settings for the Settings API.
	 *
	 * Checkboxes that are absent from the submission are stored as `false`, which is
	 * correct because every checkbox lives on the same settings screen.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$options = new self();

		if ( ! is_array( $input ) ) {
			return $options->raw();
		}

		$clean            = array();
		$clean['modules'] = array();

		$submitted_modules = isset( $input['modules'] ) && is_array( $input['modules'] ) ? $input['modules'] : array();

		foreach ( array_keys( self::DEFAULTS['modules'] ) as $module_id ) {
			$clean['modules'][ $module_id ] = ! empty( $submitted_modules[ $module_id ] );
		}

		$clean['audit_retention_days'] = self::clamp_int( $input, 'audit_retention_days', 1, 3650, self::DEFAULTS['audit_retention_days'] );
		$clean['jobs_time_budget']     = self::clamp_int( $input, 'jobs_time_budget', 5, 300, self::DEFAULTS['jobs_time_budget'] );
		$clean['jobs_max_items']       = self::clamp_int( $input, 'jobs_max_items', 1, 5000, self::DEFAULTS['jobs_max_items'] );
		$clean['jobs_retention_days']  = self::clamp_int( $input, 'jobs_retention_days', 1, 3650, self::DEFAULTS['jobs_retention_days'] );

		$clean['media_import_max_bytes'] = self::clamp_int( $input, 'media_import_max_bytes', 1024, 536870912, self::DEFAULTS['media_import_max_bytes'] );

		$clean['audit_third_party']        = ! empty( $input['audit_third_party'] );
		$clean['audit_store_ip']           = ! empty( $input['audit_store_ip'] );
		$clean['extensions_allow_zip_url'] = ! empty( $input['extensions_allow_zip_url'] );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		$clean['extensions_zip_hosts'] = self::sanitize_hosts( isset( $input['extensions_zip_hosts'] ) ? $input['extensions_zip_hosts'] : array() );

		$options->flush();

		/**
		 * Filters the sanitized settings before they are stored.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $clean Sanitized settings.
		 * @param mixed                $input Raw submitted value.
		 */
		return (array) apply_filters( 'super_abilities_sanitize_settings', $clean, $input );
	}

	/**
	 * Reads an integer from a submission and clamps it into range.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input         Submitted values.
	 * @param string               $key           Key to read.
	 * @param int                  $min           Minimum allowed value.
	 * @param int                  $max           Maximum allowed value.
	 * @param int                  $default_value Fallback when the key is missing or empty.
	 * @return int
	 */
	protected static function clamp_int( array $input, $key, $min, $max, $default_value ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return (int) $default_value;
		}

		return max( (int) $min, min( (int) $max, (int) $input[ $key ] ) );
	}

	/**
	 * Normalizes a host allowlist coming from a textarea or an array.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw hosts value.
	 * @return array<int, string> Lowercased host names.
	 */
	protected static function sanitize_hosts( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$hosts = array();

		foreach ( $value as $host ) {
			$host = strtolower( trim( (string) $host ) );

			if ( '' === $host ) {
				continue;
			}

			if ( false !== strpos( $host, '//' ) ) {
				$parsed = wp_parse_url( $host, PHP_URL_HOST );
				$host   = is_string( $parsed ) ? $parsed : '';
			}

			$host = preg_replace( '/[^a-z0-9.\-]/', '', $host );

			if ( '' === $host || ! is_string( $host ) ) {
				continue;
			}

			$hosts[ $host ] = $host;
		}

		return array_values( $hosts );
	}
}
