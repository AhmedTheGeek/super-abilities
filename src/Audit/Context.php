<?php
/**
 * Per-request audit context.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Audit;

use SuperAbilities\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the facts that are the same for every audit row written in one request.
 *
 * Everything here is static on purpose: the listener, the logger and the abilities
 * all need the same request id and transport, and nothing about a request changes
 * once it has started. The one moving part is the job context, which the jobs
 * module opens and closes around each job run.
 *
 * @since 0.1.0
 */
class Context {

	/**
	 * Maximum length of the `client` column.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CLIENT_MAX = 191;

	/**
	 * The request id shared by every row written in this request.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected static $request_id = '';

	/**
	 * The active job context, or an empty array when no job is running.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	protected static $job = array();

	/**
	 * Last object noted per ability name.
	 *
	 * @since 0.1.0
	 * @var array<string, array<string, mixed>>
	 */
	protected static $objects = array();

	/**
	 * Last error code noted per ability name.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	protected static $errors = array();

	/**
	 * Hashes of the rows already written in this request, used to avoid duplicates.
	 *
	 * @since 0.1.0
	 * @var array<string, int>
	 */
	protected static $written = array();

	/**
	 * Hashes of the calls already recorded as denied in this request.
	 *
	 * @since 0.1.0
	 * @var array<string, bool>
	 */
	protected static $denied = array();

	/**
	 * Registers the job context hooks fired by the jobs module.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'super_abilities_job_context', array( __CLASS__, 'begin_job' ) );
		add_action( 'super_abilities_job_context_end', array( __CLASS__, 'end_job' ) );
	}

	/**
	 * The request id, generated once per request.
	 *
	 * @since 0.1.0
	 *
	 * @return string 32 lowercase hex characters.
	 */
	public static function request_id() {
		if ( '' === self::$request_id ) {
			self::$request_id = str_replace( '-', '', wp_generate_uuid4() );
		}

		return self::$request_id;
	}

	/**
	 * How the caller reached us.
	 *
	 * The job context wins over everything else, because a job always runs inside
	 * cron or WP-CLI and the interesting fact is that it was a job.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of `job`, `wp-cli`, `cron`, `rest`, `mcp` or `internal`.
	 */
	public static function transport() {
		if ( ! empty( self::$job ) ) {
			return 'job';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}

		$uri = self::request_uri();

		if ( '' !== $uri ) {
			if ( false !== strpos( $uri, '/wp-abilities/v1/' ) ) {
				return 'rest';
			}

			if ( false !== strpos( $uri, '/mcp' ) ) {
				return 'mcp';
			}
		}

		return 'internal';
	}

	/**
	 * Who the caller says they are.
	 *
	 * @since 0.1.0
	 *
	 * @return string User agent, truncated to the column width, or `job:{uuid}`.
	 */
	public static function client() {
		if ( ! empty( self::$job ) ) {
			$uuid = isset( self::$job['uuid'] ) ? (string) self::$job['uuid'] : '';

			return 'job:' . $uuid;
		}

		$agent = '';

		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitized on the same line.
		}

		if ( mb_strlen( $agent ) > self::CLIENT_MAX ) {
			$agent = mb_substr( $agent, 0, self::CLIENT_MAX );
		}

		return $agent;
	}

	/**
	 * The user the row should be attributed to.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function user_id() {
		if ( ! empty( self::$job ) && isset( self::$job['user_id'] ) ) {
			return (int) self::$job['user_id'];
		}

		return (int) get_current_user_id();
	}

	/**
	 * The application password the request authenticated with, when any.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null 36 character uuid, or null.
	 */
	public static function app_password_uuid() {
		if ( ! empty( self::$job ) ) {
			$uuid = isset( self::$job['app_password_uuid'] ) ? (string) self::$job['app_password_uuid'] : '';

			return '' === $uuid ? null : $uuid;
		}

		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return null;
		}

		$uuid = rest_get_authenticated_app_password();

		return is_string( $uuid ) && '' !== $uuid ? $uuid : null;
	}

	/**
	 * The caller IP, or an empty string when IP storage is switched off.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function ip() {
		if ( ! self::store_ip() ) {
			return '';
		}

		$ip = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitized on the same line.
		}

		/**
		 * Filters the IP address recorded in the audit log.
		 *
		 * Return an empty string to skip storing an IP, or rewrite the value to read a
		 * proxy header that you trust on your own infrastructure.
		 *
		 * @since 0.1.0
		 *
		 * @param string $ip The value of `REMOTE_ADDR`.
		 */
		$ip = (string) apply_filters( 'super_abilities_audit_ip', $ip );

		return substr( $ip, 0, 45 );
	}

	/**
	 * The id of the job the current call belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @return int Zero when no job is running.
	 */
	public static function job_id() {
		if ( empty( self::$job ) || ! isset( self::$job['job_id'] ) ) {
			return 0;
		}

		return (int) self::$job['job_id'];
	}

	/**
	 * Opens a job context.
	 *
	 * Hooked to `super_abilities_job_context`, which the jobs module fires before it
	 * runs a job's items.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $context Context with `job_id`, `uuid`, `user_id` and
	 *                       `app_password_uuid` keys.
	 * @return void
	 */
	public static function begin_job( $context ) {
		if ( ! is_array( $context ) ) {
			return;
		}

		self::$job = array(
			'job_id'            => isset( $context['job_id'] ) ? (int) $context['job_id'] : 0,
			'uuid'              => isset( $context['uuid'] ) ? (string) $context['uuid'] : '',
			'user_id'           => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			'app_password_uuid' => isset( $context['app_password_uuid'] ) ? (string) $context['app_password_uuid'] : '',
		);
	}

	/**
	 * Closes the job context.
	 *
	 * Hooked to `super_abilities_job_context_end`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function end_job() {
		self::$job = array();
	}

	/**
	 * The active job context.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Empty when no job is running.
	 */
	public static function job() {
		return self::$job;
	}

	/**
	 * Records the object an ability acted on.
	 *
	 * Only the last object per ability name is kept, which is what the audit row
	 * needs. Hooked to `super_abilities_note_object`.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $ability Fully namespaced ability name.
	 * @param string     $type    Object type, e.g. `post` or `plugin`.
	 * @param int|string $id      Object id.
	 * @return void
	 */
	public static function note_object( $ability, $type, $id ) {
		$ability = (string) $ability;

		if ( '' === $ability ) {
			return;
		}

		self::$objects[ $ability ] = array(
			'type' => substr( (string) $type, 0, 50 ),
			'id'   => substr( is_scalar( $id ) ? (string) $id : '', 0, 191 ),
		);
	}

	/**
	 * The object last noted for an ability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @return array<string, mixed>|null
	 */
	public static function object_for( $ability ) {
		$ability = (string) $ability;

		return isset( self::$objects[ $ability ] ) ? self::$objects[ $ability ] : null;
	}

	/**
	 * Records the error code an ability returned.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param string $code    Error code.
	 * @return void
	 */
	public static function note_error( $ability, $code ) {
		$ability = (string) $ability;

		if ( '' === $ability ) {
			return;
		}

		self::$errors[ $ability ] = substr( (string) $code, 0, 100 );
	}

	/**
	 * The error code last noted for an ability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @return string Empty string when the ability reported no error.
	 */
	public static function error_for( $ability ) {
		$ability = (string) $ability;

		return isset( self::$errors[ $ability ] ) ? self::$errors[ $ability ] : '';
	}

	/**
	 * Forgets the error code noted for an ability.
	 *
	 * Called once the code has been written to a row, so that a later call to the
	 * same ability in the same request does not inherit it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @return void
	 */
	public static function clear_error( $ability ) {
		unset( self::$errors[ (string) $ability ] );
	}

	/**
	 * A stable hash for one ability call, used to recognise it again.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Ability input.
	 * @return string
	 */
	public static function hash( $ability, $input ) {
		$encoded = wp_json_encode( $input );

		return md5( (string) $ability . '|' . ( is_string( $encoded ) ? $encoded : '' ) );
	}

	/**
	 * Records that a row was written for an ability call.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Ability input.
	 * @return void
	 */
	public static function mark_written( $ability, $input ) {
		$ability = (string) $ability;

		if ( '' === $ability ) {
			return;
		}

		$key                   = self::hash( $ability, $input );
		self::$written[ $key ] = isset( self::$written[ $key ] ) ? self::$written[ $key ] + 1 : 1;

		$name_key                   = 'name:' . $ability;
		self::$written[ $name_key ] = isset( self::$written[ $name_key ] ) ? self::$written[ $name_key ] + 1 : 1;
	}

	/**
	 * Whether a row was already written for this exact ability call.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Ability input.
	 * @return bool
	 */
	public static function was_written( $ability, $input ) {
		return isset( self::$written[ self::hash( $ability, $input ) ] );
	}

	/**
	 * Records that a call was already written as denied.
	 *
	 * The listener needs this so that an attempt our own permission gate refused is not
	 * written a second time as `rejected` when the request shuts down.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Ability input.
	 * @return void
	 */
	public static function mark_denied( $ability, $input ) {
		$ability = (string) $ability;

		if ( '' === $ability ) {
			return;
		}

		self::$denied[ self::hash( $ability, $input ) ] = true;
	}

	/**
	 * Whether this exact call was already written as denied.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @param mixed  $input   Ability input.
	 * @return bool
	 */
	public static function was_denied( $ability, $input ) {
		return isset( self::$denied[ self::hash( $ability, $input ) ] );
	}

	/**
	 * Whether any row was written for an ability in this request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @return bool
	 */
	public static function has_rows_for( $ability ) {
		return isset( self::$written[ 'name:' . (string) $ability ] );
	}

	/**
	 * Clears every piece of per-request state.
	 *
	 * Only needed by the test suite, which runs many requests in one process.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function reset() {
		self::$request_id = '';
		self::$job        = array();
		self::$objects    = array();
		self::$errors     = array();
		self::$written    = array();
		self::$denied     = array();
	}

	/**
	 * Whether the `audit_store_ip` setting is on.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	protected static function store_ip() {
		if ( ! Plugin::has_instance() ) {
			return (bool) \SuperAbilities\Options::DEFAULTS['audit_store_ip'];
		}

		return (bool) Plugin::instance()->options()->get( 'audit_store_ip', true );
	}

	/**
	 * The request URI, unslashed and sanitized.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitized on the same line.
	}
}
