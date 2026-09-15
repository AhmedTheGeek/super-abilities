<?php
/**
 * Reads one block pattern.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Patterns;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Returns one pattern with its markup, by registered name or by post id.
 *
 * @since 0.2.0
 */
class Pattern_Read extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'pattern-read';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'design';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Read a block pattern', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Returns one block pattern with its raw block markup and a tally of the block types it uses. Identify a registered pattern by name, for example core/query-standard-posts, or a user pattern by the id of its wp_block post. Reading a user pattern also checks that you may read that post. The fingerprint is what pattern-write compares against.', 'super-abilities' );
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
		return array( 'edit_posts' );
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
				'name' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Registered pattern name including its namespace. Use this or id, not both.', 'super-abilities' ),
				),
				'id'   => Schema::id( __( 'Post id of a user pattern. Use this or name, not both.', 'super-abilities' ) ),
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
		return Patterns::item_schema( true );
	}

	/**
	 * Per-object permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool|\WP_Error
	 */
	public function permission( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( $id <= 0 ) {
			return true;
		}

		if ( ! current_user_can( 'read_post', $id ) ) {
			return $this->error(
				'forbidden',
				__( 'You are not allowed to read that pattern.', 'super-abilities' ),
				403
			);
		}

		return true;
	}

	/**
	 * Reads the pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( '' === $name && $id <= 0 ) {
			return $this->error(
				'invalid_input',
				__( 'Send either a registered pattern name or the id of a user pattern.', 'super-abilities' ),
				400
			);
		}

		if ( '' !== $name && $id > 0 ) {
			return $this->error(
				'invalid_input',
				__( 'Send a name or an id, not both.', 'super-abilities' ),
				400
			);
		}

		if ( $id > 0 ) {
			$post = Patterns::post( $id );

			if ( null === $post ) {
				return $this->error(
					'not_found',
					__( 'No user pattern with that id exists.', 'super-abilities' ),
					404,
					array( 'id' => $id )
				);
			}

			return Patterns::from_post( $post, true );
		}

		$pattern = Patterns::registered_one( $name, true );

		if ( null === $pattern ) {
			return $this->error(
				'not_found',
				__( 'No pattern is registered under that name.', 'super-abilities' ),
				404,
				array( 'name' => $name )
			);
		}

		return $pattern;
	}
}
