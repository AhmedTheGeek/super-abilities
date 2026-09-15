<?php
/**
 * Restore point delete ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Removes one restore point from disk and from the index.
 *
 * @since 0.2.0
 */
class Restore_Point_Delete extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'restore-point-delete';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Delete a restore point', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes one restore point: its copied files are removed from the uploads folder and it disappears from the list. The plugin or theme it belonged to is not touched, but that version can no longer be rolled back to. Deleting a restore point that is already gone is not an error.', 'super-abilities' );
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
		return array( 'update_plugins' );
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
				'id' => array(
					'type'        => 'string',
					'pattern'     => '^rp_[0-9a-f]{12}$',
					'description' => __( 'Id of the restore point to delete, as reported by restore-points-list.', 'super-abilities' ),
				),
			),
			array( 'id' )
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
				'deleted'   => array( 'type' => 'boolean' ),
				'id'        => array( 'type' => 'string' ),
				'existed'   => array(
					'type'        => 'boolean',
					'description' => __( 'False when the id was already unknown, which is not an error.', 'super-abilities' ),
				),
				'remaining' => array( 'type' => 'integer' ),
			),
			array( 'deleted', 'id', 'existed', 'remaining' )
		);
	}

	/**
	 * Deletes the restore point.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$id = isset( $input['id'] ) ? trim( (string) $input['id'] ) : '';

		if ( ! preg_match( '/^rp_[0-9a-f]{12}$/', $id ) ) {
			return $this->error( 'invalid_input', __( 'That is not a restore point id.', 'super-abilities' ), 400 );
		}

		$meta = Restore_Point::get( $id );

		if ( null !== $meta ) {
			$this->note_object( (string) $meta['type'], (string) $meta['slug'] );
		}

		$existed = Restore_Point::delete( $id );

		return array(
			'deleted'   => $existed,
			'id'        => $id,
			'existed'   => $existed,
			'remaining' => count( Restore_Point::all() ),
		);
	}
}
