<?php
/**
 * Base class for every ability this plugin registers.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities;

use SuperAbilities\Support\Error;
use SuperAbilities\Support\Fingerprint;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a small class into a fully formed core ability.
 *
 * Subclasses declare what the ability is; this class owns the plumbing: the
 * namespaced name, the category slug, the `wp_register_ability()` argument array,
 * the capability gate and the exception boundary.
 *
 * Every ability returns plain arrays, never objects, so that `output_schema`
 * validation works. Timestamps are ISO 8601 in UTC.
 *
 * @since 0.1.0
 */
abstract class Abstract_Ability {

	/**
	 * Ability namespace shared by every ability in this plugin.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'super-abilities';

	/**
	 * Prefix for the ability category slugs.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CATEGORY_PREFIX = 'super-abilities-';

	/**
	 * The unnamespaced ability slug, e.g. `audit-query`.
	 *
	 * Must match `[a-z0-9-]+`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Id of the module this ability belongs to, e.g. `audit`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract public function module();

	/**
	 * Short, translated, human readable label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Translated description. The first sentence is used as the catalog summary.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Semantic annotations: `readonly`, `destructive` and `idempotent`.
	 *
	 * Use one of {@see Abstract_Ability::readonly()}, {@see Abstract_Ability::write()},
	 * {@see Abstract_Ability::write_idempotent()} or {@see Abstract_Ability::destructive()}.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	abstract public function annotations();

	/**
	 * Capabilities the caller must have. All of them are required.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	abstract public function capability();

	/**
	 * JSON Schema for the ability input. Return an empty array for no input.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	abstract public function input_schema();

	/**
	 * JSON Schema for the ability output. Return an empty array to skip validation.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	abstract public function output_schema();

	/**
	 * Performs the work. Input has already been validated by core.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function execute( array $input );

	/**
	 * Per-object permission check, run after the capability gate.
	 *
	 * Return true to allow, false to deny with a generic 403, or a `WP_Error` to deny
	 * with a specific code and status.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool|WP_Error
	 */
	public function permission( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Subclasses read the input; the default answer is always yes.
		return true;
	}

	/**
	 * Plugin version this ability first shipped in.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.1.0';
	}

	/**
	 * The fully namespaced ability name, e.g. `super-abilities/audit-query`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	final public function name() {
		return self::ABILITY_NAMESPACE . '/' . $this->slug();
	}

	/**
	 * The ability category slug, e.g. `super-abilities-audit`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	final public function category() {
		return self::CATEGORY_PREFIX . $this->module();
	}

	/**
	 * The first sentence of the description, used by the catalog ability.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	final public function summary() {
		$description = trim( wp_strip_all_tags( $this->description() ) );

		if ( '' === $description ) {
			return '';
		}

		if ( preg_match( '/^(.+?[.!?])(\s|$)/u', $description, $matches ) ) {
			return trim( $matches[1] );
		}

		return $description;
	}

	/**
	 * Builds the argument array for `wp_register_ability()`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	final public function to_args() {
		$args = array(
			'label'               => $this->label(),
			'description'         => $this->description(),
			'category'            => $this->category(),
			'execute_callback'    => array( $this, 'run' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'meta'                => array(
				'annotations'     => $this->annotations(),
				'show_in_rest'    => true,
				'public'          => true,
				'mcp'             => array( 'public' => true ),
				'super_abilities' => array(
					'module'     => $this->module(),
					'capability' => array_values( $this->capability() ),
					'since'      => $this->since(),
				),
			),
		);

		$input_schema = $this->input_schema();

		if ( ! empty( $input_schema ) ) {
			$args['input_schema'] = $input_schema;
		}

		$output_schema = $this->output_schema();

		if ( ! empty( $output_schema ) ) {
			$args['output_schema'] = $output_schema;
		}

		/**
		 * Filters the arguments used to register one of our abilities.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $args    Registration arguments.
		 * @param string               $name    Fully namespaced ability name.
		 * @param Abstract_Ability     $ability Ability instance.
		 */
		return (array) apply_filters( 'super_abilities_ability_args', $args, $this->name(), $this );
	}

	/**
	 * The `permission_callback` registered with core.
	 *
	 * Requires a logged in user, then every capability from `capability()`, then the
	 * ability's own `permission()` check.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Validated input, as passed by core.
	 * @return true|WP_Error
	 */
	final public function check_permission( $input = null ) {
		$input = $this->normalize_input( $input );

		if ( ! is_user_logged_in() ) {
			return $this->deny(
				'not_logged_in',
				__( 'You must be logged in to use this ability.', 'super-abilities' ),
				$input
			);
		}

		foreach ( $this->capability() as $cap ) {
			$cap = (string) $cap;

			if ( '' === $cap ) {
				continue;
			}

			if ( ! current_user_can( $cap ) ) {
				return $this->deny(
					'insufficient_capability',
					sprintf(
						/* translators: %s: Capability name. */
						__( 'You are not allowed to use this ability. It requires the "%s" capability.', 'super-abilities' ),
						$cap
					),
					$input,
					array( 'required_capability' => $cap )
				);
			}
		}

		$allowed = $this->permission( $input );

		if ( is_wp_error( $allowed ) ) {
			/** This action is documented in src/Abilities/Abstract_Ability.php */
			do_action( 'super_abilities_permission_denied', $this->name(), $input, $allowed->get_error_code() );

			return $allowed;
		}

		if ( true !== $allowed ) {
			return $this->deny(
				'permission_denied',
				__( 'You are not allowed to use this ability on this object.', 'super-abilities' ),
				$input
			);
		}

		return true;
	}

	/**
	 * The `execute_callback` registered with core.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Validated input, as passed by core.
	 * @return array<string, mixed>|WP_Error
	 */
	final public function run( $input = null ) {
		$input = $this->normalize_input( $input );

		try {
			$result = $this->execute( $input );
		} catch ( \Throwable $throwable ) {
			$error = $this->error( 'exception', $throwable->getMessage(), 500 );

			/** This action is documented in src/Abilities/Abstract_Ability.php */
			do_action( 'super_abilities_ability_error', $this->name(), $input, $error );

			return $error;
		}

		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when an ability returns an error.
			 *
			 * @since 0.1.0
			 *
			 * @param string               $name    Fully namespaced ability name.
			 * @param array<string, mixed> $input   Validated input.
			 * @param WP_Error             $error   The returned error.
			 */
			do_action( 'super_abilities_ability_error', $this->name(), $input, $result );
		}

		return $result;
	}

	/**
	 * Builds a plugin error.
	 *
	 * The code is prefixed with `super_abilities_` when it is not prefixed already and
	 * the status ends up in `data.status`, which core turns into the REST status.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Human readable message.
	 * @param int                  $status  Optional. HTTP status. Default 400.
	 * @param array<string, mixed> $data    Optional. Extra error data. Default empty array.
	 * @return WP_Error
	 */
	protected function error( $code, $message, $status = 400, array $data = array() ) {
		$data['status'] = (int) $status;

		return new WP_Error( Error::code( $code ), (string) $message, $data );
	}

	/**
	 * Rejects a write whose `expected_fingerprint` no longer matches.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input  Validated input.
	 * @param string               $actual Fingerprint of the current state.
	 * @return WP_Error|null Null when the caller sent no fingerprint or it still matches.
	 */
	protected function guard_fingerprint( array $input, $actual ) {
		$expected = isset( $input['expected_fingerprint'] ) ? (string) $input['expected_fingerprint'] : '';

		if ( '' === $expected ) {
			return null;
		}

		if ( Fingerprint::matches( $expected, (string) $actual ) ) {
			return null;
		}

		return $this->error(
			'stale_fingerprint',
			__( 'The object changed since you read it. Read it again and retry with the current fingerprint.', 'super-abilities' ),
			409,
			array( 'current_fingerprint' => (string) $actual )
		);
	}

	/**
	 * Records which object this ability touched, for the audit log.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $type Object type, e.g. `post`, `user`, `plugin`, `option`.
	 * @param int|string $id   Object id.
	 * @return void
	 */
	protected function note_object( $type, $id ) {
		/**
		 * Fires when an ability reports the object it acted on.
		 *
		 * @since 0.1.0
		 *
		 * @param string     $name Fully namespaced ability name.
		 * @param string     $type Object type.
		 * @param int|string $id   Object id.
		 */
		do_action( 'super_abilities_note_object', $this->name(), (string) $type, $id );
	}

	/**
	 * Fires the denial action and builds the 403 error.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $code  Reason code, unprefixed.
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $input Validated input.
	 * @param array<string, mixed> $data  Optional. Extra error data. Default empty array.
	 * @return WP_Error
	 */
	private function deny( $code, $message, array $input, array $data = array() ) {
		/**
		 * Fires when a caller is refused access to one of our abilities.
		 *
		 * The audit module hooks this so that denied attempts are recorded even on
		 * WordPress 6.9 and 7.0, where core fires no hook for them.
		 *
		 * @since 0.1.0
		 *
		 * @param string               $name  Fully namespaced ability name.
		 * @param array<string, mixed> $input Validated input.
		 * @param string               $code  Reason code, one of `not_logged_in`,
		 *                                    `insufficient_capability` or `permission_denied`.
		 */
		do_action( 'super_abilities_permission_denied', $this->name(), $input, (string) $code );

		$data['status'] = 403;
		$data['reason'] = (string) $code;

		return new WP_Error( Error::code( 'forbidden' ), (string) $message, $data );
	}

	/**
	 * Coerces whatever core hands us into an array.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	private function normalize_input( $input ) {
		if ( is_array( $input ) ) {
			return $input;
		}

		if ( is_object( $input ) ) {
			return get_object_vars( $input );
		}

		return array();
	}

	/**
	 * Annotation preset for a read-only ability. Maps to GET over REST.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public static function readonly() {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Annotation preset for a non-idempotent, additive write. Maps to POST over REST.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public static function write() {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * Annotation preset for an idempotent, additive write. Maps to POST over REST.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public static function write_idempotent() {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Annotation preset for a destructive, idempotent write. Maps to DELETE over REST.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public static function destructive() {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		);
	}
}
