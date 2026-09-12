<?php
/**
 * Ability execution listener.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Audit;

use SuperAbilities\Support\Version;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Abilities API hooks into audit rows.
 *
 * What core tells us depends on the WordPress version.
 *
 * On 7.1 and newer, `wp_ability_invoked` fires for every attempt, so a pending
 * record is opened there, marked permitted by `wp_before_execute_ability` and
 * closed by `wp_after_execute_ability`. Records that never reach the before hook
 * were either denied or sent invalid input, which core does not distinguish, so
 * they are flushed on `shutdown` as `rejected`.
 *
 * On 6.9 and 7.0 only the before and after hooks exist, and they carry two and
 * three arguments respectively, so every callback reads `func_get_args()`.
 *
 * Core returns early when an ability's execute callback returns a `WP_Error`, so
 * `wp_after_execute_ability` never fires for a failed call on any version. Errors
 * therefore arrive either through `super_abilities_ability_error` (our own
 * abilities, which report their own failures) or at `shutdown`, where a record
 * that was permitted but never completed is written as `error`.
 *
 * Finally, a denial or a validation failure that happens inside the REST run
 * controller bypasses `WP_Ability::execute()` altogether, so a
 * `rest_request_after_callbacks` fallback classifies those responses for
 * abilities that produced no row of their own.
 *
 * @since 0.1.0
 */
class Listener {

	/**
	 * REST route of the ability run endpoint, as a matchable pattern.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const RUN_ROUTE_PATTERN = '#^/wp-abilities/v1/abilities/(?P<name>[a-z0-9-]+/[a-z0-9-]+)/run$#';

	/**
	 * Row writer.
	 *
	 * @since 0.1.0
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Open records, oldest first.
	 *
	 * @since 0.1.0
	 * @var array<int, array<string, mixed>>
	 */
	protected $pending = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Logger $logger Row writer.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Registers the execution hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		if ( Version::has_invoked_hook() ) {
			add_action( 'wp_ability_invoked', array( $this, 'on_invoked' ), 10, PHP_INT_MAX );
		}

		add_action( 'wp_before_execute_ability', array( $this, 'on_before' ), 10, PHP_INT_MAX );
		add_action( 'wp_after_execute_ability', array( $this, 'on_after' ), 10, PHP_INT_MAX );

		// Runs after Logger::on_ability_error(), which records the error code.
		add_action( 'super_abilities_ability_error', array( $this, 'on_error' ), 20, 3 );

		add_filter( 'rest_request_after_callbacks', array( $this, 'on_rest_response' ), 10, 3 );

		add_action( 'shutdown', array( $this, 'flush' ), 5 );
	}

	/**
	 * Opens a pending record for an attempt. WordPress 7.1 and newer only.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_invoked() {
		$args  = func_get_args();
		$name  = isset( $args[0] ) ? (string) $args[0] : '';
		$input = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();

		if ( '' === $name || ! $this->logger->should_log( $name ) ) {
			return;
		}

		$this->open( $name, $input );
	}

	/**
	 * Marks a record permitted and starts its timer.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_before() {
		$args  = func_get_args();
		$name  = isset( $args[0] ) ? (string) $args[0] : '';
		$input = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();

		if ( '' === $name || ! $this->logger->should_log( $name ) ) {
			return;
		}

		$index = $this->find( $name, false );

		if ( $index < 0 ) {
			$index = $this->open( $name, $input );
		}

		$this->pending[ $index ]['permitted'] = true;
		$this->pending[ $index ]['input']     = $input;
		$this->pending[ $index ]['started']   = hrtime( true );
	}

	/**
	 * Closes a record once the ability finished.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function on_after() {
		$args   = func_get_args();
		$name   = isset( $args[0] ) ? (string) $args[0] : '';
		$input  = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();
		$result = isset( $args[2] ) ? $args[2] : null;

		if ( '' === $name || ! $this->logger->should_log( $name ) ) {
			return;
		}

		$index = $this->find( $name, true );

		if ( $index < 0 ) {
			$index = $this->find( $name, false );
		}

		if ( $index < 0 ) {
			$index = $this->open( $name, $input );
		}

		$record = $this->take( $index );

		$is_error = is_wp_error( $result );

		$this->write(
			$record,
			$is_error ? 'error' : 'ok',
			$is_error && $result instanceof WP_Error ? (string) $result->get_error_code() : ''
		);
	}

	/**
	 * Closes a record when one of our own abilities reports an error.
	 *
	 * Core returns before `wp_after_execute_ability` when the execute callback fails,
	 * so this is where a failed call to one of our abilities is written.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Fully namespaced ability name.
	 * @param mixed  $input Validated input.
	 * @param mixed  $error The returned error.
	 * @return void
	 */
	public function on_error( $name, $input, $error ) {
		$name = (string) $name;

		if ( '' === $name || ! $this->logger->should_log( $name ) ) {
			return;
		}

		$index = $this->find( $name, true );

		if ( $index < 0 ) {
			$index = $this->find( $name, false );
		}

		if ( $index < 0 ) {
			$index = $this->open( $name, is_array( $input ) ? $input : array() );
		}

		$record = $this->take( $index );

		$this->write(
			$record,
			'error',
			$error instanceof WP_Error ? (string) $error->get_error_code() : ''
		);
	}

	/**
	 * Classifies a failed ability run over REST.
	 *
	 * Denials and validation failures happen in the run endpoint's permission
	 * callback, which never enters `WP_Ability::execute()`, so no execution hook sees
	 * them. Rows are only written when nothing else recorded this call.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $response Result of the dispatched request.
	 * @param mixed $handler  Route handler. Unused.
	 * @param mixed $request  The request object.
	 * @return mixed The unchanged response.
	 */
	public function on_rest_response( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the filter signature.
		if ( ! $response instanceof WP_Error || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$name = $this->ability_from_request( $request );

		if ( '' === $name || ! $this->logger->should_log( $name ) ) {
			return $response;
		}

		if ( Context::has_rows_for( $name ) || $this->find( $name, false ) >= 0 || $this->find( $name, true ) >= 0 ) {
			return $response;
		}

		$data    = $response->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$outcome = '';

		if ( 401 === $status || 403 === $status ) {
			$outcome = 'denied';
		} elseif ( 400 === $status ) {
			$outcome = 'invalid';
		}

		if ( '' === $outcome ) {
			return $response;
		}

		$input = $this->input_from_request( $request );

		$this->logger->log(
			array(
				'ability'    => $name,
				'outcome'    => $outcome,
				'error_code' => (string) $response->get_error_code(),
				'input'      => $input,
			)
		);

		return $response;
	}

	/**
	 * Writes whatever is still open at the end of the request.
	 *
	 * A record that was permitted but never completed is an error; a record that was
	 * never permitted was either denied or given invalid input, and core does not let
	 * us tell those two apart, so it is written as `rejected`. Attempts our own
	 * permission gate already recorded as `denied` are skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush() {
		$records       = $this->pending;
		$this->pending = array();

		foreach ( $records as $record ) {
			if ( Context::was_denied( $record['name'], $record['input'] ) ) {
				continue;
			}

			if ( ! empty( $record['permitted'] ) ) {
				$this->write( $record, 'error', Context::error_for( $record['name'] ) );
				continue;
			}

			$this->write( $record, 'rejected', '' );
		}
	}

	/**
	 * The records that are still open. Test helper.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pending() {
		return $this->pending;
	}

	/**
	 * Opens a record and returns its index.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name  Fully namespaced ability name.
	 * @param array<string, mixed> $input Ability input.
	 * @return int
	 */
	protected function open( $name, array $input ) {
		$this->pending[] = array(
			'name'      => (string) $name,
			'input'     => $input,
			'permitted' => false,
			'started'   => null,
		);

		return count( $this->pending ) - 1;
	}

	/**
	 * Finds the most recent open record for an ability in a given state.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name      Fully namespaced ability name.
	 * @param bool   $permitted Whether to look for a permitted record.
	 * @return int The index, or -1 when there is none.
	 */
	protected function find( $name, $permitted ) {
		$name = (string) $name;

		for ( $index = count( $this->pending ) - 1; $index >= 0; $index-- ) {
			if ( ! isset( $this->pending[ $index ] ) ) {
				continue;
			}

			if ( $this->pending[ $index ]['name'] !== $name ) {
				continue;
			}

			if ( ! empty( $this->pending[ $index ]['permitted'] ) === (bool) $permitted ) {
				return $index;
			}
		}

		return -1;
	}

	/**
	 * Removes a record from the pending list and returns it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $index Record index.
	 * @return array<string, mixed>
	 */
	protected function take( $index ) {
		$index = (int) $index;

		$record = isset( $this->pending[ $index ] ) ? $this->pending[ $index ] : array(
			'name'      => '',
			'input'     => array(),
			'permitted' => false,
			'started'   => null,
		);

		unset( $this->pending[ $index ] );

		$this->pending = array_values( $this->pending );

		return $record;
	}

	/**
	 * Writes a record as a row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $record     Pending record.
	 * @param string               $outcome    Row outcome.
	 * @param string               $error_code Optional. Error code. Default empty.
	 * @return void
	 */
	protected function write( array $record, $outcome, $error_code = '' ) {
		$name = isset( $record['name'] ) ? (string) $record['name'] : '';

		if ( '' === $name ) {
			return;
		}

		$input = isset( $record['input'] ) && is_array( $record['input'] ) ? $record['input'] : array();

		if ( '' === (string) $error_code ) {
			$error_code = Context::error_for( $name );
		}

		$this->logger->log(
			array(
				'ability'     => $name,
				'outcome'     => (string) $outcome,
				'error_code'  => (string) $error_code,
				'input'       => $input,
				'duration_ms' => self::duration_ms( isset( $record['started'] ) ? $record['started'] : null ),
			)
		);

		Context::clear_error( $name );
	}

	/**
	 * Milliseconds elapsed since an `hrtime()` reading.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $started Nanosecond reading, or null when the call was never timed.
	 * @return int
	 */
	protected static function duration_ms( $started ) {
		if ( ! is_int( $started ) && ! is_float( $started ) ) {
			return 0;
		}

		$elapsed = ( hrtime( true ) - $started ) / 1000000;

		return $elapsed < 0 ? 0 : (int) round( $elapsed );
	}

	/**
	 * Reads the ability name out of a REST run request.
	 *
	 * Core registers the route as `/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)/run`, and
	 * `WP_REST_Request::get_route()` returns that pattern rather than the requested
	 * path, so the URL parameter is the reliable source and the route pattern is only
	 * a fallback.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return string Empty string when the request is not an ability run.
	 */
	protected function ability_from_request( WP_REST_Request $request ) {
		$route = (string) $request->get_route();

		if ( false === strpos( $route, '/wp-abilities/v1/abilities/' ) || '/run' !== substr( $route, -4 ) ) {
			return '';
		}

		$params = $request->get_url_params();
		$name   = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';

		if ( '' === $name && preg_match( self::RUN_ROUTE_PATTERN, $route, $matches ) ) {
			$name = $matches['name'];
		}

		$name = strtolower( $name );

		return preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ? $name : '';
	}

	/**
	 * Reads the input of a REST run request, for its keys only.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return array<string, mixed>
	 */
	protected function input_from_request( WP_REST_Request $request ) {
		$input = $request->get_param( 'input' );

		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		return is_array( $input ) ? $input : array();
	}
}
