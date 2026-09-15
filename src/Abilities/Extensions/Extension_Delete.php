<?php
/**
 * Plugin and theme delete ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Extensions\Upgrades;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the files of an installed plugin or theme, after taking a restore point.
 *
 * @since 0.2.0
 */
class Extension_Delete extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-delete';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Delete a plugin or theme', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the files of an installed plugin or theme. A restore point is taken first, so the files can be put back with extension-rollback; the plugin\'s own data in the database is not part of that and an uninstall routine may have removed it. Active plugins and the active theme are refused: deactivate or switch away first. Plugins on the protected list, including Super Abilities itself, are refused unless force is true. Pass expected_version to make the call fail when the installed version is not the one you read, and dry_run to see what would happen. Requires delete_plugins for plugins and delete_themes for themes.', 'super-abilities' );
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
		return array( 'delete_plugins' );
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
		return $this->guard( 'delete', $input );
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
				'type'                 => self::type_property(),
				'slug'                 => self::slug_property( __( 'Plugin file, such as akismet/akismet.php, or theme stylesheet directory.', 'super-abilities' ) ),
				'expected_version'     => array(
					'type'        => 'string',
					'maxLength'   => 64,
					'description' => __( 'Refuse the delete when the installed version is not exactly this.', 'super-abilities' ),
				),
				'force'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Delete even a protected plugin or theme. Default false.', 'super-abilities' ),
				),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Report what would be deleted and change nothing. Default false.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from extensions-list. The delete is refused with 409 when it no longer matches.', 'super-abilities' ) ),
			),
			array( 'type', 'slug' )
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
				'deleted'          => array( 'type' => 'boolean' ),
				'dry_run'          => array( 'type' => 'boolean' ),
				'type'             => array( 'type' => 'string' ),
				'slug'             => array( 'type' => 'string' ),
				'name'             => array( 'type' => 'string' ),
				'version'          => array( 'type' => 'string' ),
				'restore_point_id' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Restore point taken before the delete. Pass it to extension-rollback to put the files back.', 'super-abilities' ),
				),
			),
			array( 'deleted', 'dry_run', 'type', 'slug', 'name', 'version', 'restore_point_id' )
		);
	}

	/**
	 * Deletes the files.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$type     = $this->input_type( $input );
		$dry_run  = ! empty( $input['dry_run'] );
		$force    = ! empty( $input['force'] );
		$expected = isset( $input['expected_version'] ) ? trim( (string) $input['expected_version'] ) : '';

		Upgrades::bootstrap();

		$described = $this->installed( $type, $this->input_slug( $input ) );

		if ( is_wp_error( $described ) ) {
			return $described;
		}

		$stale = $this->guard_fingerprint( $input, Target::fingerprint( $described ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$slug    = (string) $described['slug'];
		$version = (string) $described['version'];

		if ( '' !== $expected && $expected !== $version ) {
			return $this->error(
				'version_mismatch',
				sprintf(
					/* translators: 1: Expected version. 2: Installed version. */
					__( 'You expected version %1$s but version %2$s is installed, so nothing was deleted.', 'super-abilities' ),
					$expected,
					'' === $version ? '(unknown)' : $version
				),
				409,
				array(
					'expected_version'  => $expected,
					'installed_version' => $version,
				)
			);
		}

		if ( ! $force && Guard::is_protected( $type, $slug ) ) {
			return $this->error(
				'protected_extension',
				sprintf(
					/* translators: %s: Plugin file or theme stylesheet. */
					__( '"%s" is on the protected list and was not deleted. Pass force to override.', 'super-abilities' ),
					$slug
				),
				403,
				array(
					'slug'      => $slug,
					'protected' => true,
				)
			);
		}

		if ( ! empty( $described['active'] ) || ! empty( $described['network_active'] ) ) {
			return $this->error(
				'still_active',
				Target::TYPE_THEME === $type
					? __( 'That theme is the active theme. Activate a different theme first.', 'super-abilities' )
					: __( 'That plugin is still active. Deactivate it first with super-abilities/extension-deactivate.', 'super-abilities' ),
				409,
				array( 'slug' => $slug )
			);
		}

		$result = array(
			'deleted'          => false,
			'dry_run'          => $dry_run,
			'type'             => $type,
			'slug'             => $slug,
			'name'             => (string) $described['name'],
			'version'          => $version,
			'restore_point_id' => null,
		);

		if ( $dry_run ) {
			return $result;
		}

		$this->note_object( $type, $slug );

		$restore_point = Restore_Point::create( $type, $slug );

		if ( is_wp_error( $restore_point ) ) {
			return $this->error(
				'restore_point_failed',
				sprintf(
					/* translators: %s: Reason the restore point could not be written. */
					__( 'No restore point could be written, so nothing was deleted: %s', 'super-abilities' ),
					$restore_point->get_error_message()
				),
				500
			);
		}

		$result['restore_point_id'] = (string) $restore_point['id'];

		$deleted = Target::TYPE_THEME === $type ? delete_theme( $slug ) : delete_plugins( array( $slug ) );

		if ( is_wp_error( $deleted ) ) {
			return $this->error(
				'delete_failed',
				Redactor::text( $deleted->get_error_message() ),
				500,
				array(
					'slug'             => $slug,
					'restore_point_id' => $result['restore_point_id'],
				)
			);
		}

		if ( true !== $deleted ) {
			return $this->error(
				'delete_failed',
				__( 'WordPress did not delete the files. Check the filesystem permissions of wp-content.', 'super-abilities' ),
				500,
				array(
					'slug'             => $slug,
					'restore_point_id' => $result['restore_point_id'],
				)
			);
		}

		$result['deleted'] = true;

		return $result;
	}
}
