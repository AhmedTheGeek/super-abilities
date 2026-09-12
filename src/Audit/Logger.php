<?php
/**
 * Audit row writer.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Audit;

use SuperAbilities\Install;
use SuperAbilities\Options;
use SuperAbilities\Support\Time;
use WP_Error;
use WP_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Writes rows into the audit table.
 *
 * The table never stores input values, only the top level input keys, so that an
 * audit trail can be read by anyone with `manage_options` without leaking the
 * contents of the calls it describes.
 *
 * @since 0.1.0
 */
class Logger {

	/**
	 * Ability name prefix used by the synthetic site event rows.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const EVENT_PREFIX = 'event/';

	/**
	 * Ability names this plugin owns.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OWN_PREFIX = 'super-abilities/';

	/**
	 * Maximum number of input keys stored per row.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_INPUT_KEYS = 50;

	/**
	 * Input keys the object heuristic looks at, in priority order.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const OBJECT_KEYS = array(
		'id'            => 'object',
		'ID'            => 'object',
		'post_id'       => 'post',
		'user_id'       => 'user',
		'attachment_id' => 'attachment',
		'term_id'       => 'term',
		'plugin'        => 'plugin',
		'theme'         => 'theme',
		'slug'          => 'slug',
		'option'        => 'option',
		'hook'          => 'hook',
		'uuid'          => 'job',
	);

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 * @var Options
	 */
	protected $options;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Options $options Settings.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Registers the hooks that produce rows without going through the listener.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'super_abilities_permission_denied', array( $this, 'on_permission_denied' ), 10, 3 );
		add_action( 'super_abilities_ability_error', array( $this, 'on_ability_error' ), 10, 3 );
		add_action( 'super_abilities_note_object', array( $this, 'on_note_object' ), 10, 3 );

		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( $this, 'on_theme_switched' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	/**
	 * Whether calls to an ability should be recorded.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @return bool
	 */
	public function should_log( $ability ) {
		$ability = (string) $ability;

		if ( '' === $ability ) {
			return false;
		}

		if ( 0 === strpos( $ability, self::OWN_PREFIX ) || 0 === strpos( $ability, self::EVENT_PREFIX ) ) {
			return true;
		}

		return (bool) $this->options->get( 'audit_third_party', true );
	}

	/**
	 * Writes one audit row.
	 *
	 * Every column gets a default, so callers only pass what they know.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row Partial row. Recognised keys are `ability`,
	 *                                  `outcome`, `error_code`, `input`, `input_keys`,
	 *                                  `object_type`, `object_id`, `duration_ms`,
	 *                                  `request_id`, `transport`, `client`, `user_id`,
	 *                                  `app_password_uuid`, `ip`, `job_id` and `created_at`.
	 * @return int The inserted row id, or 0 when the insert failed.
	 */
	public function log( array $row ) {
		global $wpdb;

		$ability = isset( $row['ability'] ) ? (string) $row['ability'] : '';

		if ( '' === $ability ) {
			return 0;
		}

		$input = isset( $row['input'] ) && is_array( $row['input'] ) ? $row['input'] : array();

		$object = $this->resolve_object( $row, $ability, $input );

		$data = array(
			'created_at'        => isset( $row['created_at'] ) ? (string) $row['created_at'] : gmdate( 'Y-m-d H:i:s' ),
			'request_id'        => isset( $row['request_id'] ) ? (string) $row['request_id'] : Context::request_id(),
			'ability'           => substr( $ability, 0, 191 ),
			'transport'         => substr( isset( $row['transport'] ) ? (string) $row['transport'] : Context::transport(), 0, 20 ),
			'client'            => substr( isset( $row['client'] ) ? (string) $row['client'] : Context::client(), 0, 191 ),
			'user_id'           => isset( $row['user_id'] ) ? (int) $row['user_id'] : Context::user_id(),
			'app_password_uuid' => array_key_exists( 'app_password_uuid', $row ) ? $row['app_password_uuid'] : Context::app_password_uuid(),
			'outcome'           => substr( isset( $row['outcome'] ) ? (string) $row['outcome'] : 'ok', 0, 20 ),
			'error_code'        => substr( isset( $row['error_code'] ) ? (string) $row['error_code'] : '', 0, 100 ),
			'input_keys'        => isset( $row['input_keys'] ) ? (string) $row['input_keys'] : self::input_keys( $input ),
			'object_type'       => substr( (string) $object['type'], 0, 50 ),
			'object_id'         => substr( (string) $object['id'], 0, 191 ),
			'duration_ms'       => max( 0, isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0 ),
			'ip'                => substr( isset( $row['ip'] ) ? (string) $row['ip'] : Context::ip(), 0, 45 ),
			'job_id'            => isset( $row['job_id'] ) ? (int) $row['job_id'] : Context::job_id(),
		);

		if ( null !== $data['app_password_uuid'] ) {
			$data['app_password_uuid'] = substr( (string) $data['app_password_uuid'], 0, 36 );
		}

		/**
		 * Filters an audit row just before it is written.
		 *
		 * Return an empty array to drop the row entirely.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $data  The row about to be inserted.
		 * @param array<string, mixed> $input The ability input, for context only.
		 */
		$data = (array) apply_filters( 'super_abilities_audit_row', $data, $input );

		if ( empty( $data ) ) {
			return 0;
		}

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to our own table; there is no API and nothing to cache.
		$inserted = $wpdb->insert( Install::table( 'audit_log' ), $data, $formats );

		if ( ! $inserted ) {
			return 0;
		}

		Context::mark_written( $ability, $input );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Writes a row describing a site event rather than an ability call.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $event   Event slug, e.g. `plugin-activated`.
	 * @param array<string, mixed> $context Optional. Extra row data. Default empty array.
	 * @return int The inserted row id, or 0 when the insert failed.
	 */
	public function log_event( $event, array $context = array() ) {
		$event = sanitize_key( (string) $event );

		if ( '' === $event ) {
			return 0;
		}

		$context['ability'] = self::EVENT_PREFIX . $event;
		$context['outcome'] = 'ok';

		if ( ! $this->should_log( $context['ability'] ) ) {
			return 0;
		}

		return $this->log( $context );
	}

	/**
	 * Records a denied attempt.
	 *
	 * Hooked to `super_abilities_permission_denied`, which `Abstract_Ability` fires.
	 * This is the only way to see denied calls on WordPress 6.9 and 7.0.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Validated input.
	 * @param string $code    Reason code.
	 * @return void
	 */
	public function on_permission_denied( $ability, $input, $code ) {
		if ( ! $this->should_log( $ability ) ) {
			return;
		}

		$row_input = is_array( $input ) ? $input : array();

		$this->log(
			array(
				'ability'    => (string) $ability,
				'outcome'    => 'denied',
				'error_code' => (string) $code,
				'input'      => $row_input,
			)
		);

		Context::mark_denied( $ability, $row_input );
	}

	/**
	 * Remembers the error code an ability returned.
	 *
	 * The row itself is written by the listener, which knows how long the call took.
	 * Hooked to `super_abilities_ability_error`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Validated input.
	 * @param mixed  $error   The returned error.
	 * @return void
	 */
	public function on_ability_error( $ability, $input, $error ) {
		if ( ! $error instanceof WP_Error ) {
			return;
		}

		Context::note_error( (string) $ability, (string) $error->get_error_code() );
	}

	/**
	 * Remembers the object an ability reported.
	 *
	 * Hooked to `super_abilities_note_object`.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $ability Fully namespaced ability name.
	 * @param string     $type    Object type.
	 * @param int|string $id      Object id.
	 * @return void
	 */
	public function on_note_object( $ability, $type, $id ) {
		Context::note_object( $ability, $type, $id );
	}

	/**
	 * Records a plugin activation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return void
	 */
	public function on_plugin_activated( $plugin ) {
		$this->log_event(
			'plugin-activated',
			array(
				'object_type' => 'plugin',
				'object_id'   => (string) $plugin,
			)
		);
	}

	/**
	 * Records a plugin deactivation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin Plugin file, relative to the plugins directory.
	 * @return void
	 */
	public function on_plugin_deactivated( $plugin ) {
		$this->log_event(
			'plugin-deactivated',
			array(
				'object_type' => 'plugin',
				'object_id'   => (string) $plugin,
			)
		);
	}

	/**
	 * Records a theme switch.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $new_name  Name of the new theme.
	 * @param WP_Theme|null $new_theme Optional. The new theme. Default null.
	 * @return void
	 */
	public function on_theme_switched( $new_name, $new_theme = null ) {
		$stylesheet = $new_theme instanceof WP_Theme ? (string) $new_theme->get_stylesheet() : (string) $new_name;

		$this->log_event(
			'theme-switched',
			array(
				'object_type' => 'theme',
				'object_id'   => $stylesheet,
			)
		);
	}

	/**
	 * Records the completion of a core, plugin or theme upgrade.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $upgrader   The upgrader instance. Unused.
	 * @param mixed $hook_extra Optional. Upgrade context. Default empty array.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, $hook_extra = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the hook signature.
		$extra = is_array( $hook_extra ) ? $hook_extra : array();
		$type  = isset( $extra['type'] ) ? (string) $extra['type'] : '';
		$slugs = array();

		foreach ( array( 'plugins', 'themes', 'translations' ) as $key ) {
			if ( ! isset( $extra[ $key ] ) || ! is_array( $extra[ $key ] ) ) {
				continue;
			}

			foreach ( $extra[ $key ] as $item ) {
				if ( is_array( $item ) && isset( $item['slug'] ) ) {
					$slugs[] = (string) $item['slug'];
				} elseif ( is_scalar( $item ) ) {
					$slugs[] = (string) $item;
				}
			}
		}

		foreach ( array( 'plugin', 'theme' ) as $key ) {
			if ( isset( $extra[ $key ] ) && is_scalar( $extra[ $key ] ) ) {
				$slugs[] = (string) $extra[ $key ];
			}
		}

		$this->log_event(
			'upgrader-complete',
			array(
				'object_type' => $type,
				'object_id'   => implode( ',', array_unique( $slugs ) ),
				'input_keys'  => self::input_keys( $extra ),
			)
		);
	}

	/**
	 * The JSON array of top level input keys stored in the `input_keys` column.
	 *
	 * Values are never stored, only the shape of the call.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return string JSON encoded array of strings.
	 */
	public static function input_keys( array $input ) {
		$keys = array();

		foreach ( array_keys( $input ) as $key ) {
			$keys[] = substr( (string) $key, 0, 100 );

			if ( count( $keys ) >= self::MAX_INPUT_KEYS ) {
				break;
			}
		}

		$encoded = wp_json_encode( $keys );

		return is_string( $encoded ) ? $encoded : '[]';
	}

	/**
	 * Guesses which object an ability call was about, from its input keys.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $ability Fully namespaced ability name.
	 * @param array<string, mixed> $input   Ability input.
	 * @return array<string, mixed>|null Array with `type` and `id`, or null.
	 */
	public static function extract_object( $ability, array $input ) {
		$object = null;

		foreach ( self::OBJECT_KEYS as $key => $type ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}

			$value = (string) $input[ $key ];

			if ( '' === $value ) {
				continue;
			}

			$object = array(
				'type' => $type,
				'id'   => $value,
			);

			break;
		}

		/**
		 * Filters the object an audit row is attributed to.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed>|null $object  Array with `type` and `id`, or null when
		 *                                           no object could be guessed.
		 * @param string                    $ability Fully namespaced ability name.
		 * @param array<string, mixed>      $input   Ability input.
		 */
		$object = apply_filters( 'super_abilities_audit_object', $object, (string) $ability, $input );

		if ( ! is_array( $object ) || ! isset( $object['type'] ) ) {
			return null;
		}

		return array(
			'type' => (string) $object['type'],
			'id'   => isset( $object['id'] ) && is_scalar( $object['id'] ) ? (string) $object['id'] : '',
		);
	}

	/**
	 * Picks the object for a row: the explicit one, then the noted one, then the guess.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row     Partial row.
	 * @param string               $ability Fully namespaced ability name.
	 * @param array<string, mixed> $input   Ability input.
	 * @return array<string, string>
	 */
	protected function resolve_object( array $row, $ability, array $input ) {
		if ( isset( $row['object_type'] ) && '' !== (string) $row['object_type'] ) {
			return array(
				'type' => (string) $row['object_type'],
				'id'   => isset( $row['object_id'] ) && is_scalar( $row['object_id'] ) ? (string) $row['object_id'] : '',
			);
		}

		$noted = Context::object_for( $ability );

		if ( is_array( $noted ) ) {
			return array(
				'type' => isset( $noted['type'] ) ? (string) $noted['type'] : '',
				'id'   => isset( $noted['id'] ) ? (string) $noted['id'] : '',
			);
		}

		$guess = self::extract_object( $ability, $input );

		if ( is_array( $guess ) ) {
			return $guess;
		}

		return array(
			'type' => '',
			'id'   => '',
		);
	}

	/**
	 * The MySQL datetime the current row should carry.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function now() {
		return Time::mysql( Time::now() );
	}
}
