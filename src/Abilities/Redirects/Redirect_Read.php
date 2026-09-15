<?php
/**
 * Reads one redirect rule.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Rule_Schema;
use SuperAbilities\Redirects\Rules;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Returns one rule by id or by source, together with the chain it starts.
 *
 * @since 0.2.0
 */
class Redirect_Read extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirect-read';
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
		return __( 'Read a redirect', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Returns one redirect rule, looked up by id or by source path, with its fingerprint. It also follows the rest of the rule set from that rule and reports every hop a visitor would make, where they end up and whether the chain loops, which is the fastest way to see why a URL behaves the way it does.', 'super-abilities' );
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
				'id'     => Schema::id( __( 'Rule id. Either this or source is required.', 'super-abilities' ) ),
				'source' => array(
					'type'        => 'string',
					'description' => __( 'Source path, for example "/old-page". Normalized the same way it was when stored.', 'super-abilities' ),
				),
			)
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
				'redirect'     => Rule_Schema::rule(),
				'chain'        => Rule_Schema::chain(),
				'chain_length' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many redirects a visitor would follow.', 'super-abilities' ),
				),
				'resolves_to'  => array(
					'type'        => 'string',
					'description' => __( 'Where the chain ends. Empty when the chain ends in a 410.', 'super-abilities' ),
				),
				'loop'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether following the chain comes back to somewhere it already was.', 'super-abilities' ),
				),
			),
			array( 'redirect', 'chain', 'chain_length', 'resolves_to', 'loop' )
		);
	}

	/**
	 * Returns the rule.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$source = isset( $input['source'] ) ? (string) $input['source'] : '';

		if ( $id < 1 && '' === trim( $source ) ) {
			return $this->error(
				'invalid_input',
				__( 'Pass either an id or a source path.', 'super-abilities' ),
				400
			);
		}

		$rule = $id > 0 ? Store::get( $id ) : Store::find_by_source( $source );

		if ( null === $rule ) {
			return $this->error(
				'not_found',
				__( 'No redirect rule matches that id or source.', 'super-abilities' ),
				404
			);
		}

		// Analyze with the rule itself injected as enabled, so that a disabled rule still
		// reports the chain it would produce once it is switched on.
		$followed = Rules::analyze(
			array(
				'source'      => (string) $rule['source'],
				'match_query' => empty( $rule['match_query'] ) ? 0 : 1,
				'target'      => (string) $rule['target'],
				'status'      => (int) $rule['status'],
			),
			Store::all(),
			(int) $rule['id']
		);

		return array(
			'redirect'     => $rule,
			'chain'        => $followed['chain'],
			'chain_length' => (int) $followed['chain_length'],
			'resolves_to'  => (string) $followed['resolves_to'],
			'loop'         => (bool) $followed['loop'],
		);
	}
}
