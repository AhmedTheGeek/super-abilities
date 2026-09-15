<?php
/**
 * Deletes redirect rules.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Removes one rule or a batch of rules.
 *
 * @since 0.2.0
 */
class Redirect_Delete extends Abstract_Ability {

	/**
	 * Largest batch accepted in one call.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_IDS = 200;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirect-delete';
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
		return __( 'Delete redirects', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes redirect rules by id. Pass a single id, optionally with the fingerprint you read so the call is refused if the rule changed, or a list of ids to remove a batch of up to two hundred. Deletion is final: the rows are removed, not disabled, so use redirect-update with enabled false when you only want to park a rule. Ids that do not exist are reported rather than treated as an error, which makes the call safe to retry. Pass dry_run to see what would go.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::destructive();
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
				'id'                   => Schema::id( __( 'Single rule id to delete.', 'super-abilities' ) ),
				'ids'                  => array(
					'type'        => 'array',
					'items'       => Schema::id(),
					'maxItems'    => self::MAX_IDS,
					'description' => __( 'Rule ids to delete, up to two hundred.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint of the rule as you last read it. Only allowed together with a single id.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would be deleted and delete nothing.', 'super-abilities' ),
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
				'deleted'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many rules were removed. Zero on a dry run.', 'super-abilities' ),
				),
				'dry_run'   => array( 'type' => 'boolean' ),
				'ids'       => array(
					'type'        => 'array',
					'items'       => Schema::id(),
					'description' => __( 'Ids that exist and were, or would be, deleted.', 'super-abilities' ),
				),
				'missing'   => array(
					'type'        => 'array',
					'items'       => Schema::id(),
					'description' => __( 'Ids that no longer exist.', 'super-abilities' ),
				),
				'remaining' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many rules the site has left.', 'super-abilities' ),
				),
			),
			array( 'deleted', 'dry_run', 'ids', 'missing', 'remaining' )
		);
	}

	/**
	 * Deletes the rules.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$dry_run = ! empty( $input['dry_run'] );
		$ids     = array();

		if ( isset( $input['id'] ) && (int) $input['id'] > 0 ) {
			$ids[] = (int) $input['id'];
		}

		if ( isset( $input['ids'] ) && is_array( $input['ids'] ) ) {
			foreach ( $input['ids'] as $id ) {
				$id = (int) $id;

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			return $this->error(
				'invalid_input',
				__( 'Pass an id, or a list of ids, to delete.', 'super-abilities' ),
				400
			);
		}

		if ( count( $ids ) > self::MAX_IDS ) {
			return $this->error(
				'invalid_input',
				sprintf(
					/* translators: %d: Maximum number of ids. */
					__( 'At most %d rules can be deleted in one call.', 'super-abilities' ),
					self::MAX_IDS
				),
				400
			);
		}

		$fingerprint = isset( $input['expected_fingerprint'] ) ? (string) $input['expected_fingerprint'] : '';

		if ( '' !== $fingerprint && count( $ids ) > 1 ) {
			return $this->error(
				'invalid_input',
				__( 'A fingerprint only identifies one rule, so it cannot be used with a list of ids.', 'super-abilities' ),
				400
			);
		}

		$present = array();
		$missing = array();

		foreach ( $ids as $id ) {
			if ( null === Store::get( $id ) ) {
				$missing[] = $id;
			} else {
				$present[] = $id;
			}
		}

		if ( '' !== $fingerprint ) {
			$current = Store::get( $ids[0] );

			if ( null === $current ) {
				return $this->error(
					'not_found',
					__( 'No redirect rule has that id.', 'super-abilities' ),
					404
				);
			}

			$stale = $this->guard_fingerprint( $input, (string) $current['fingerprint'] );

			if ( null !== $stale ) {
				return $stale;
			}
		}

		if ( $dry_run ) {
			return array(
				'deleted'   => 0,
				'dry_run'   => true,
				'ids'       => $present,
				'missing'   => $missing,
				'remaining' => count( Store::all() ),
			);
		}

		$deleted = empty( $present ) ? 0 : Store::delete( $present );

		foreach ( $present as $id ) {
			$this->note_object( 'redirect', $id );
		}

		return array(
			'deleted'   => (int) $deleted,
			'dry_run'   => false,
			'ids'       => $present,
			'missing'   => $missing,
			'remaining' => count( Store::all() ),
		);
	}
}
