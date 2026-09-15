<?php
/**
 * Restore point inventory ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the restore points this site holds.
 *
 * @since 0.2.0
 */
class Restore_Points_List extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'restore-points-list';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'List restore points', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the restore points this site holds, newest first, with the plugin or theme they belong to, the version they hold, when they were taken, how large they are and whether the extension was active at the time. Restore points are created automatically before an update or a delete and the newest twenty are kept; pass one of these ids to extension-rollback to put those files back.', 'super-abilities' );
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
				'type' => array(
					'type'        => 'string',
					'enum'        => array( 'plugin', 'theme', 'all' ),
					'default'     => 'all',
					'description' => __( 'Only restore points of this kind. Default all.', 'super-abilities' ),
				),
				'slug' => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => __( 'Only restore points for this plugin file, plugin folder name or theme stylesheet.', 'super-abilities' ),
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
				'items'       => array(
					'type'  => 'array',
					'items' => self::restore_point_schema(),
				),
				'total'       => array( 'type' => 'integer' ),
				'total_bytes' => array( 'type' => 'integer' ),
				'max_kept'    => array(
					'type'        => 'integer',
					'description' => __( 'How many restore points this site keeps before the oldest are deleted.', 'super-abilities' ),
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the restore point list.', 'super-abilities' ) ),
			),
			array( 'items', 'total', 'total_bytes', 'max_kept', 'fingerprint' )
		);
	}

	/**
	 * Builds the list.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$type = isset( $input['type'] ) ? strtolower( trim( (string) $input['type'] ) ) : 'all';
		$slug = $this->input_slug( $input );

		$filters = array( 'slug' => $slug );

		if ( 'plugin' === $type || 'theme' === $type ) {
			$filters['type'] = $type;
		}

		$items = array();
		$bytes = 0;

		foreach ( Restore_Point::all( $filters ) as $meta ) {
			$row     = self::public_restore_point( $meta );
			$bytes  += (int) $row['size_bytes'];
			$items[] = $row;
		}

		return array(
			'items'       => $items,
			'total'       => count( $items ),
			'total_bytes' => $bytes,
			'max_kept'    => Restore_Point::MAX,
			'fingerprint' => Fingerprint::of_array( wp_list_pluck( $items, 'created', 'id' ) ),
		);
	}
}
