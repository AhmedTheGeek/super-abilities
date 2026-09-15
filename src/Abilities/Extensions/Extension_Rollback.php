<?php
/**
 * Restore point rollback ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Extensions\Smoke_Test;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Extensions\Upgrades;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the files of a restore point back, undoing an update.
 *
 * @since 0.2.0
 */
class Extension_Rollback extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-rollback';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Roll a plugin or theme back', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Replaces the files of a plugin or theme with the contents of a restore point, which is how an update is undone. Address the restore point by id, or pass type and slug to use the newest one for that extension. The current files are moved aside first and put back if the copy fails, so a failed rollback leaves the site as it was. If the extension was active when the restore point was taken and is inactive now, it is activated again, and the site is smoke tested afterwards. Requires update_plugins for plugins and update_themes for themes.', 'super-abilities' );
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
	 * Checks the type capability, the network rules and DISALLOW_FILE_MODS.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	public function permission( array $input ) {
		$type = $this->resolved_type( $input );

		$denied = Guard::require_cap( 'update', $type );

		if ( null !== $denied ) {
			return $denied;
		}

		$denied = Guard::require_network( 'update', $type );

		if ( null !== $denied ) {
			return $denied;
		}

		$denied = Guard::require_file_mods( 'update', $type );

		return null === $denied ? true : $denied;
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
				'restore_point_id' => array(
					'type'        => 'string',
					'pattern'     => '^rp_[0-9a-f]{12}$',
					'description' => __( 'Id of the restore point to put back, as reported by restore-points-list.', 'super-abilities' ),
				),
				'type'             => self::type_property(),
				'slug'             => self::slug_property( __( 'Plugin file or theme stylesheet whose newest restore point should be used, instead of an id.', 'super-abilities' ) ),
				'run_smoke_test'   => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Request the home page and the admin heartbeat afterwards. Default true.', 'super-abilities' ),
				),
				'dry_run'          => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report which restore point would be used and change nothing. Default false.', 'super-abilities' ),
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
				'restored'         => array( 'type' => 'boolean' ),
				'dry_run'          => array( 'type' => 'boolean' ),
				'type'             => array( 'type' => 'string' ),
				'slug'             => array( 'type' => 'string' ),
				'restore_point_id' => array( 'type' => 'string' ),
				'restore_point'    => self::restore_point_schema(),
				'previous_version' => array(
					'type'        => 'string',
					'description' => __( 'Version that was installed before the rollback.', 'super-abilities' ),
				),
				'restored_version' => array(
					'type'        => 'string',
					'description' => __( 'Version that is installed after the rollback.', 'super-abilities' ),
				),
				'reactivated'      => array(
					'type'        => 'boolean',
					'description' => __( 'True when the extension was active at snapshot time, inactive now, and was activated again.', 'super-abilities' ),
				),
				'activation_error' => array( 'type' => array( 'string', 'null' ) ),
				'smoke'            => self::smoke_schema(),
				'duration_ms'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'fingerprint'      => Schema::fingerprint( __( 'Fingerprint of the extension after the call.', 'super-abilities' ) ),
			),
			array( 'restored', 'dry_run', 'type', 'slug', 'restore_point_id', 'restore_point', 'previous_version', 'restored_version', 'reactivated', 'activation_error', 'smoke', 'duration_ms', 'fingerprint' )
		);
	}

	/**
	 * Performs the rollback.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$started  = microtime( true );
		$dry_run  = ! empty( $input['dry_run'] );
		$smoke_on = ! isset( $input['run_smoke_test'] ) || ! empty( $input['run_smoke_test'] );

		$meta = $this->find_restore_point( $input );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		Upgrades::bootstrap();

		$type      = Target::normalize_type( $meta['type'] );
		$slug      = (string) $meta['slug'];
		$described = Target::describe( $type, $slug );

		$result = array(
			'restored'         => false,
			'dry_run'          => $dry_run,
			'type'             => $type,
			'slug'             => $slug,
			'restore_point_id' => (string) $meta['id'],
			'restore_point'    => self::public_restore_point( $meta ),
			'previous_version' => null === $described ? '' : (string) $described['version'],
			'restored_version' => null === $described ? '' : (string) $described['version'],
			'reactivated'      => false,
			'activation_error' => null,
			'smoke'            => Smoke_Test::skipped(),
			'duration_ms'      => 0,
			'fingerprint'      => Target::fingerprint( $described ),
		);

		if ( $dry_run ) {
			$result['duration_ms'] = $this->elapsed( $started );

			return $result;
		}

		$this->note_object( $type, $slug );

		$restored = Restore_Point::restore( (string) $meta['id'] );

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		$result['restored'] = true;

		$after                      = Target::describe( $type, $slug );
		$result['restored_version'] = null === $after ? '' : (string) $after['version'];
		$result['fingerprint']      = Target::fingerprint( $after );

		if ( ! empty( $meta['was_active'] ) && null !== $after && empty( $after['active'] ) ) {
			$activation                 = $this->reactivate( $type, $slug, ! empty( $meta['network'] ) );
			$result['reactivated']      = $activation['activated'];
			$result['activation_error'] = $activation['error'];

			if ( $activation['activated'] ) {
				$result['fingerprint'] = Target::fingerprint( Target::describe( $type, $slug ) );
			}
		}

		if ( $smoke_on ) {
			$result['smoke'] = Smoke_Test::run();
		}

		$result['duration_ms'] = $this->elapsed( $started );

		return $result;
	}

	/**
	 * The type the caller is talking about, for the permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return string
	 */
	protected function resolved_type( array $input ) {
		$id = isset( $input['restore_point_id'] ) ? trim( (string) $input['restore_point_id'] ) : '';

		if ( '' !== $id ) {
			$meta = Restore_Point::get( $id );

			if ( null !== $meta ) {
				return Target::normalize_type( $meta['type'] );
			}
		}

		return $this->input_type( $input );
	}

	/**
	 * Finds the restore point the input names.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function find_restore_point( array $input ) {
		$id = isset( $input['restore_point_id'] ) ? trim( (string) $input['restore_point_id'] ) : '';

		if ( '' !== $id ) {
			$meta = Restore_Point::get( $id );

			if ( null === $meta ) {
				return $this->error(
					'not_found',
					__( 'That restore point does not exist. Call super-abilities/restore-points-list to see the ones that do.', 'super-abilities' ),
					404,
					array( 'restore_point_id' => $id )
				);
			}

			return $meta;
		}

		$slug = $this->input_slug( $input );

		if ( '' === $slug ) {
			return $this->error(
				'invalid_input',
				__( 'Pass a restore_point_id, or a type and slug to use the newest restore point for that extension.', 'super-abilities' ),
				400
			);
		}

		$meta = Restore_Point::newest_for( $this->input_type( $input ), $slug );

		if ( null === $meta ) {
			return $this->error(
				'not_found',
				__( 'There is no restore point for that plugin or theme.', 'super-abilities' ),
				404,
				array( 'slug' => $slug )
			);
		}

		return $meta;
	}

	/**
	 * Activates an extension that was active when the restore point was taken.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type    Extension type.
	 * @param string $slug    Plugin file or theme stylesheet.
	 * @param bool   $network Whether it was active network wide.
	 * @return array{activated: bool, error: string|null}
	 */
	protected function reactivate( $type, $slug, $network ) {
		if ( Target::TYPE_THEME === $type ) {
			if ( ! current_user_can( 'switch_themes' ) ) {
				return array(
					'activated' => false,
					'error'     => __( 'The theme was active before but switching back requires the "switch_themes" capability.', 'super-abilities' ),
				);
			}

			switch_theme( $slug );

			return array(
				'activated' => get_stylesheet() === $slug,
				'error'     => null,
			);
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array(
				'activated' => false,
				'error'     => __( 'The plugin was active before but activating it requires the "activate_plugins" capability.', 'super-abilities' ),
			);
		}

		$network = $network && is_multisite() && current_user_can( 'manage_network_plugins' );

		try {
			$activated = activate_plugin( $slug, '', $network, false );
		} catch ( \Throwable $throwable ) {
			return array(
				'activated' => false,
				'error'     => Redactor::text( $throwable->getMessage() ),
			);
		}

		if ( is_wp_error( $activated ) ) {
			return array(
				'activated' => false,
				'error'     => Redactor::text( $activated->get_error_message() ),
			);
		}

		return array(
			'activated' => true,
			'error'     => null,
		);
	}

	/**
	 * Milliseconds since a `microtime( true )` mark.
	 *
	 * @since 0.2.0
	 *
	 * @param float $started Start time.
	 * @return int
	 */
	protected function elapsed( $started ) {
		return (int) round( ( microtime( true ) - (float) $started ) * 1000 );
	}
}
