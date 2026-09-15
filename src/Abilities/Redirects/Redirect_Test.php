<?php
/**
 * Simulates a request against the rule set.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Rule_Schema;
use SuperAbilities\Redirects\Rules;
use SuperAbilities\Redirects\Runtime;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "what happens if I request this path" without any side effect.
 *
 * @since 0.2.0
 */
class Redirect_Test extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirect-test';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'redirects';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Test a path against the redirects', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs one path through the same matcher the front end uses and reports what would happen: which rule matches, the status and target it would send, every hop of the chain, where a visitor ends up and whether the chain loops. When no rule matches it reports what WordPress would serve instead, from url_to_postid, so you can tell a missing redirect from a working page. Nothing is written and no hit counter moves.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'manage_options' );
	}

	/**
	 * Plugin version this ability shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'path'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Path to test, for example "/old-page". A full URL on this site is accepted and reduced to its path and query.', 'super-abilities' ),
				),
				'query' => array(
					'type'        => 'string',
					'description' => __( 'Query string to test with, with or without the leading question mark. Merged with any query string already in path.', 'super-abilities' ),
				),
			),
			array( 'path' )
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'path'           => array( 'type' => 'string' ),
				'query'          => array( 'type' => 'string' ),
				'matched'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether an enabled rule matches the path.', 'super-abilities' ),
				),
				'would_redirect' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the front end would really act. False when a rule matches but acting on it would send the visitor back to the same URL.', 'super-abilities' ),
				),
				'status'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Status that would be sent, or 0 when nothing would happen.', 'super-abilities' ),
				),
				'redirect'       => Rule_Schema::rule(),
				'final_target'   => array(
					'type'        => 'string',
					'description' => __( 'Where the visitor ends up after the whole chain. Empty when the chain ends in a 410 or nothing matches.', 'super-abilities' ),
				),
				'hops'           => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Number of redirects in the chain.', 'super-abilities' ),
				),
				'loop'           => array( 'type' => 'boolean' ),
				'chain'          => Rule_Schema::chain(),
				'reserved'       => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The reserved path prefix this path falls under, or null when it is free to redirect.', 'super-abilities' ),
				),
				'would_serve'    => Schema::object(
					array(
						'known'  => array( 'type' => 'boolean' ),
						'id'     => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'type'   => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
					),
					array( 'known', 'id', 'type', 'status' )
				),
			),
			array( 'path', 'query', 'matched', 'would_redirect', 'status', 'final_target', 'hops', 'loop', 'chain', 'reserved', 'would_serve' )
		);
	}

	/**
	 * Simulates the request.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$raw   = isset( $input['path'] ) ? (string) $input['path'] : '';
		$parts = Rules::split( $raw );

		if ( '' === $parts['path'] ) {
			return $this->error(
				'invalid_input',
				__( 'Pass a path such as "/old-page".', 'super-abilities' ),
				400
			);
		}

		$query = $parts['query'];

		if ( isset( $input['query'] ) && '' !== trim( (string) $input['query'] ) ) {
			$extra = Rules::normalize_query( (string) $input['query'] );
			$query = '' === $query ? $extra : Rules::normalize_query( $query . '&' . $extra );
		}

		$rules    = Store::enabled();
		$followed = Rules::follow( $parts['path'], $query, $rules );
		$decision = Runtime::decide( $parts['path'], $query, $rules );
		$matched  = ! empty( $followed['chain'] );

		$result = array(
			'path'           => $parts['path'],
			'query'          => $query,
			'matched'        => $matched,
			'would_redirect' => null !== $decision,
			'status'         => null === $decision ? 0 : (int) $decision['status'],
			'final_target'   => (string) $followed['resolves_to'],
			'hops'           => (int) $followed['chain_length'],
			'loop'           => (bool) $followed['loop'],
			'chain'          => $followed['chain'],
			'reserved'       => Rules::reserved_match( $parts['path'] ),
			'would_serve'    => array(
				'known'  => false,
				'id'     => 0,
				'type'   => '',
				'status' => 'unknown',
			),
		);

		if ( $matched ) {
			$rule = Store::get( (int) $followed['chain'][0]['id'] );

			if ( null !== $rule ) {
				$result['redirect'] = $rule;
			}
		}

		$content = Rules::content_at( $parts['path'] );

		if ( is_array( $content ) ) {
			$result['would_serve'] = array(
				'known'  => true,
				'id'     => (int) $content['id'],
				'type'   => (string) $content['type'],
				'status' => (string) $content['status'],
			);
		}

		return $result;
	}
}
