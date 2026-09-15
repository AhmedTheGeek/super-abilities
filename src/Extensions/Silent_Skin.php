<?php
/**
 * Output free upgrader skin.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

use SuperAbilities\Support\Redactor;
use WP_Error;
use WP_Upgrader_Skin;

defined( 'ABSPATH' ) || exit;

/**
 * Captures every upgrader message instead of printing it.
 *
 * `WP_Upgrader` talks to the user through its skin, which normally echoes HTML into an
 * admin page. Abilities answer a REST or CLI call, so every message is collected here
 * and handed back through the ability output, redacted because upgrader strings contain
 * absolute paths.
 *
 * Call {@see Upgrades::bootstrap()} before this class is loaded: it extends a class that
 * only exists once `wp-admin/includes/class-wp-upgrader.php` has been required.
 *
 * @since 0.2.0
 */
class Silent_Skin extends WP_Upgrader_Skin {

	/**
	 * Feedback messages, in the order the upgrader produced them.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	protected $captured = array();

	/**
	 * Messages the upgrader reported through `error()`.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	protected $failures = array();

	/**
	 * Answers the filesystem credentials request without printing a form.
	 *
	 * @since 0.2.0
	 *
	 * @param bool|WP_Error $error                        Optional. Whether the last connection failed. Default false.
	 * @param string        $context                      Optional. Directory tested for being writable. Default empty.
	 * @param bool          $allow_relaxed_file_ownership Optional. Whether group or world writable is acceptable. Default false.
	 * @return bool|array<string, mixed> Credentials, or false when they cannot be obtained.
	 */
	public function request_filesystem_credentials( $error = false, $context = '', $allow_relaxed_file_ownership = false ) {
		if ( '' !== (string) $context ) {
			$this->options['context'] = $context;
		}

		// request_filesystem_credentials() prints a form when it cannot answer silently.
		ob_start();
		$credentials = parent::request_filesystem_credentials( $error, $context, $allow_relaxed_file_ownership );
		ob_end_clean();

		return $credentials;
	}

	/**
	 * Swallows the header the admin skin would print.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function header() {}

	/**
	 * Swallows the footer the admin skin would print.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function footer() {}

	/**
	 * Records a feedback message.
	 *
	 * @since 0.2.0
	 *
	 * @param string|array<int|string, mixed>|WP_Error $feedback Message, message key or error.
	 * @param mixed                                    ...$args  Optional text replacements.
	 * @return void
	 */
	public function feedback( $feedback, ...$args ) {
		$message = $this->stringify( $feedback, $args );

		if ( '' !== $message ) {
			$this->captured[] = $message;
		}
	}

	/**
	 * Records an error message.
	 *
	 * @since 0.2.0
	 *
	 * @param string|WP_Error $errors Error or error message.
	 * @return void
	 */
	public function error( $errors ) {
		if ( is_string( $errors ) ) {
			$message = $this->stringify( $errors, array() );

			if ( '' !== $message ) {
				$this->captured[] = $message;
				$this->failures[] = $message;
			}

			return;
		}

		if ( ! is_wp_error( $errors ) || ! $errors->has_errors() ) {
			return;
		}

		foreach ( $errors->get_error_messages() as $error_message ) {
			$data    = $errors->get_error_data();
			$message = is_string( $data ) && '' !== $data ? $error_message . ' ' . wp_strip_all_tags( $data ) : (string) $error_message;
			$message = $this->stringify( $message, array() );

			if ( '' !== $message ) {
				$this->captured[] = $message;
				$this->failures[] = $message;
			}
		}
	}

	/**
	 * Does nothing: there is no "before" chrome to print.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function before() {}

	/**
	 * Does nothing: there is no "after" chrome to print.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function after() {}

	/**
	 * Keeps the upgrader from printing its own failure notice.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Error $wp_error The error the upgrader ran into.
	 * @return bool Always true, so the caller reports the failure instead.
	 */
	public function hide_process_failed( $wp_error ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The parent signature requires the error.
		return true;
	}

	/**
	 * Every captured message, redacted.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function messages() {
		return array_values( array_map( array( Redactor::class, 'text' ), $this->captured ) );
	}

	/**
	 * Only the messages that came from `error()`, redacted.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function errors() {
		return array_values( array_map( array( Redactor::class, 'text' ), $this->failures ) );
	}

	/**
	 * Forgets everything captured so far.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function reset() {
		$this->captured = array();
		$this->failures = array();
	}

	/**
	 * Turns whatever the upgrader passed into a plain, safe string.
	 *
	 * @since 0.2.0
	 *
	 * @param string|array<int|string, mixed>|WP_Error $feedback Raw feedback.
	 * @param array<int, mixed>                        $args     Text replacements.
	 * @return string Empty string when there is nothing worth keeping.
	 */
	protected function stringify( $feedback, array $args ) {
		if ( is_wp_error( $feedback ) ) {
			$feedback = $feedback->get_error_message();
		}

		if ( ! is_string( $feedback ) ) {
			return '';
		}

		if ( is_object( $this->upgrader ) && isset( $this->upgrader->strings[ $feedback ] ) ) {
			$feedback = (string) $this->upgrader->strings[ $feedback ];
		}

		if ( ! empty( $args ) && false !== strpos( $feedback, '%' ) ) {
			$replacements = array();

			foreach ( $args as $arg ) {
				$replacements[] = is_array( $arg ) || is_object( $arg ) ? '' : wp_strip_all_tags( (string) $arg );
			}

			try {
				$feedback = vsprintf( $feedback, $replacements );
			} catch ( \Throwable $throwable ) {
				// Upgrader strings and their arguments do not always line up. A mismatch
				// must never abort an install, so the unformatted string is kept.
				unset( $throwable );
			}
		}

		return trim( wp_strip_all_tags( $feedback ) );
	}
}
