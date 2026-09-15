<?php
/**
 * Front end redirect handler.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Answers front end requests that a stored rule matches.
 *
 * The decision is taken by {@see Runtime::decide()}, which is pure and testable; only
 * {@see Runtime::handle()} touches headers and exits, so the test suite never has to
 * call `wp_redirect()`.
 *
 * @since 0.2.0
 */
class Runtime {

	/**
	 * Value sent in the `X-Redirect-By` header.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const REDIRECT_BY = 'super-abilities';

	/**
	 * Hooks the handler just before the canonical redirect runs.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Whether this request may be redirected at all.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function is_front_end_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		return true;
	}

	/**
	 * The request URI of the current request, unslashed and sanitized.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Decides what to do with a request, without touching the response.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                $path  Request path.
	 * @param string                                $query Optional. Request query string. Default empty.
	 * @param array<int, array<string, mixed>>|null $rules Optional. Rule set to use. Default the enabled rules.
	 * @return array<string, mixed>|null Null when nothing matches, or when acting would loop.
	 */
	public static function decide( $path, $query = '', $rules = null ) {
		$path  = Rules::normalize_path( $path );
		$query = Rules::normalize_query( $query );

		if ( '' === $path ) {
			return null;
		}

		$rules = is_array( $rules ) ? $rules : Store::enabled();
		$rule  = Matcher::match( $path, $query, $rules );

		if ( null === $rule ) {
			return null;
		}

		$resolved = Matcher::resolve( $rule );

		if ( ! $resolved['gone'] ) {
			if ( '' === $resolved['target'] ) {
				return null;
			}

			if ( ! $resolved['external'] ) {
				$target = Rules::split( $resolved['target'] );

				// Never answer a request with a redirect back to the same place.
				if ( $target['path'] === $path && ( $target['query'] === $query || '' === $target['query'] ) ) {
					return null;
				}

				if ( Rules::normalize_source( $resolved['target'], ! empty( $rule['match_query'] ) ) === (string) $rule['source'] ) {
					return null;
				}
			}
		}

		return array(
			'rule_id'  => isset( $rule['id'] ) ? (int) $rule['id'] : 0,
			'source'   => (string) $rule['source'],
			'status'   => (int) $resolved['status'],
			'target'   => (string) $resolved['target'],
			'gone'     => (bool) $resolved['gone'],
			'external' => (bool) $resolved['external'],
			'safe'     => (bool) $resolved['safe'],
		);
	}

	/**
	 * Redirects the current request when a rule matches it.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! self::is_front_end_request() ) {
			return;
		}

		$uri = self::request_uri();

		if ( '' === $uri ) {
			return;
		}

		$parts    = Rules::split( $uri );
		$decision = self::decide( $parts['path'], $parts['query'] );

		if ( null === $decision ) {
			return;
		}

		Store::record_hit( (int) $decision['rule_id'] );

		$this->send( $decision );
	}

	/**
	 * Sends the response a decision describes and ends the request.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decision Result of {@see Runtime::decide()}.
	 * @return void
	 */
	protected function send( array $decision ) {
		if ( ! headers_sent() ) {
			header( 'X-Redirect-By: ' . self::REDIRECT_BY );
		}

		if ( ! empty( $decision['gone'] ) ) {
			nocache_headers();
			status_header( 410 );
			header( 'Content-Type: text/plain; charset=utf-8' );

			echo esc_html__( 'Gone', 'super-abilities' );
			exit;
		}

		$target = (string) $decision['target'];
		$status = (int) $decision['status'];

		if ( empty( $decision['safe'] ) ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External targets are deliberate, were validated by Rules::validate_target() and were only stored after an administrator passed allow_external.
			wp_redirect( $target, $status, self::REDIRECT_BY );
			exit;
		}

		wp_safe_redirect( $target, $status, self::REDIRECT_BY );
		exit;
	}
}
