<?php
/**
 * Plugin and theme update ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Packages;
use SuperAbilities\Extensions\Preflight;
use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Extensions\Silent_Skin;
use SuperAbilities\Extensions\Smoke_Test;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Extensions\Upgrades;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Updates one installed plugin or theme, with a restore point and an automatic rollback.
 *
 * @since 0.2.0
 */
class Extension_Update extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-update';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Update a plugin or theme', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Updates one installed plugin or theme to the version WordPress.org offers. The pre-flight check runs first and any blocker aborts the call; a restore point of the current files is taken next and the update is abandoned if it cannot be written. After the files are replaced the site is smoke tested, and if the home page or the admin heartbeat starts returning HTTP 500 the restore point is put back automatically and rolled_back comes back true with the reason. Pass dry_run true to get the pre-flight result and change nothing. Requires update_plugins for plugins and update_themes for themes.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
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
		return $this->guard( 'update', $input );
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
				'run_smoke_test'       => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Request the home page and the admin heartbeat afterwards, and roll back when they fail. Default true.', 'super-abilities' ),
				),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Run the pre-flight check and stop, changing nothing. Default false.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from extensions-list. The update is refused with 409 when the installed version or active state changed since.', 'super-abilities' ) ),
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
				'updated'          => array( 'type' => 'boolean' ),
				'dry_run'          => array( 'type' => 'boolean' ),
				'type'             => array( 'type' => 'string' ),
				'slug'             => array( 'type' => 'string' ),
				'old_version'      => array( 'type' => 'string' ),
				'new_version'      => array( 'type' => 'string' ),
				'restore_point_id' => array( 'type' => array( 'string', 'null' ) ),
				'rolled_back'      => array(
					'type'        => 'boolean',
					'description' => __( 'True when the smoke test failed and the restore point was put back.', 'super-abilities' ),
				),
				'rollback_reason'  => array( 'type' => array( 'string', 'null' ) ),
				'smoke'            => self::smoke_schema(),
				'preflight'        => self::preflight_schema(),
				'package'          => self::package_schema(),
				'messages'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'duration_ms'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'fingerprint'      => Schema::fingerprint( __( 'Fingerprint of the extension after the call.', 'super-abilities' ) ),
			),
			array( 'updated', 'dry_run', 'type', 'slug', 'old_version', 'new_version', 'restore_point_id', 'rolled_back', 'rollback_reason', 'smoke', 'preflight', 'package', 'messages', 'duration_ms', 'fingerprint' )
		);
	}

	/**
	 * Performs the update.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$started = microtime( true );

		$type     = $this->input_type( $input );
		$dry_run  = ! empty( $input['dry_run'] );
		$smoke_on = ! isset( $input['run_smoke_test'] ) || ! empty( $input['run_smoke_test'] );

		$described = $this->installed( $type, $this->input_slug( $input ) );

		if ( is_wp_error( $described ) ) {
			return $described;
		}

		$stale = $this->guard_fingerprint( $input, Target::fingerprint( $described ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$slug        = (string) $described['slug'];
		$old_version = (string) $described['version'];

		Upgrades::bootstrap();

		$package       = Packages::resolve(
			array(
				'type' => $type,
				'slug' => $slug,
			)
		);
		$package_info  = is_wp_error( $package ) ? array() : $package;
		$package_error = is_wp_error( $package ) ? $package : null;

		$preflight = Preflight::check(
			array(
				'type'    => $type,
				'slug'    => $slug,
				'action'  => 'update',
				'package' => $package_info,
			)
		);

		$result = array(
			'updated'          => false,
			'dry_run'          => $dry_run,
			'type'             => $type,
			'slug'             => $slug,
			'old_version'      => $old_version,
			'new_version'      => $old_version,
			'restore_point_id' => null,
			'rolled_back'      => false,
			'rollback_reason'  => null,
			'smoke'            => Smoke_Test::skipped(),
			'preflight'        => $preflight,
			'package'          => Packages::public_info( $package_info ),
			'messages'         => array(),
			'duration_ms'      => 0,
			'fingerprint'      => Target::fingerprint( $described ),
		);

		if ( $dry_run ) {
			$result['duration_ms'] = $this->elapsed( $started );

			return $result;
		}

		if ( null !== $package_error ) {
			return $package_error;
		}

		if ( ! $preflight['ok'] ) {
			return $this->error(
				'preflight_failed',
				__( 'The pre-flight check found blockers, so nothing was updated.', 'super-abilities' ),
				409,
				array(
					'blockers'  => $preflight['blockers'],
					'preflight' => $preflight,
				)
			);
		}

		$this->note_object( $type, $slug );

		$restore_point = Restore_Point::create( $type, $slug );

		if ( is_wp_error( $restore_point ) ) {
			return $this->error(
				'restore_point_failed',
				sprintf(
					/* translators: %s: Reason the restore point could not be written. */
					__( 'No restore point could be written, so the update was not attempted: %s', 'super-abilities' ),
					$restore_point->get_error_message()
				),
				500
			);
		}

		$result['restore_point_id'] = (string) $restore_point['id'];

		// The upgrader reads the update transient to find the package, so refresh it first.
		if ( Target::TYPE_THEME === $type ) {
			wp_update_themes();
		} else {
			wp_update_plugins();
		}

		$skin     = new Silent_Skin();
		$upgrader = Target::TYPE_THEME === $type ? new \Theme_Upgrader( $skin ) : new \Plugin_Upgrader( $skin );
		$outcome  = $upgrader->upgrade( $slug );

		$result['messages'] = $skin->messages();

		if ( is_wp_error( $outcome ) || true !== $outcome ) {
			$message = is_wp_error( $outcome )
				? Redactor::text( $outcome->get_error_message() )
				: __( 'The upgrader did not replace the files. Its messages explain why.', 'super-abilities' );

			return $this->error(
				'update_failed',
				$message,
				500,
				array(
					'messages'         => $result['messages'],
					'errors'           => $skin->errors(),
					'restore_point_id' => $result['restore_point_id'],
				)
			);
		}

		$result['updated'] = true;

		$after                 = Target::describe( $type, $slug );
		$result['new_version'] = null === $after ? $old_version : (string) $after['version'];
		$result['fingerprint'] = Target::fingerprint( $after );

		if ( $smoke_on ) {
			$result['smoke'] = Smoke_Test::run();

			if ( ! $result['smoke']['passed'] ) {
				$restored = Restore_Point::restore( $result['restore_point_id'] );

				$result['rolled_back']     = ! is_wp_error( $restored );
				$result['rollback_reason'] = $this->rollback_reason( $result['smoke'], is_wp_error( $restored ) ? $restored : null );

				$reverted              = Target::describe( $type, $slug );
				$result['new_version'] = null === $reverted ? $old_version : (string) $reverted['version'];
				$result['fingerprint'] = Target::fingerprint( $reverted );
				$result['updated']     = ! $result['rolled_back'];
			}
		}

		$result['duration_ms'] = $this->elapsed( $started );

		return $result;
	}

	/**
	 * Explains why the update was rolled back.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $smoke  Smoke test result.
	 * @param WP_Error|null        $failed Error from the restore attempt, when it failed.
	 * @return string
	 */
	protected function rollback_reason( array $smoke, $failed ) {
		$probes = array();

		foreach ( (array) $smoke['probes'] as $probe ) {
			if ( isset( $probe['status'] ) && 'failed' === $probe['status'] ) {
				$probes[] = (string) $probe['name'];
			}
		}

		if ( empty( $probes ) && empty( $smoke['plugin_file_present'] ) ) {
			$probes[] = 'plugin_file_missing';
		}

		$reason = sprintf(
			/* translators: %s: Comma separated list of failed smoke test probes. */
			__( 'The smoke test failed (%s), so the restore point was put back.', 'super-abilities' ),
			implode( ', ', empty( $probes ) ? array( 'unknown' ) : $probes )
		);

		if ( null !== $failed ) {
			$reason .= ' ' . sprintf(
				/* translators: %s: Reason the restore failed. */
				__( 'Putting it back failed too: %s', 'super-abilities' ),
				$failed->get_error_message()
			);
		}

		return $reason;
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
