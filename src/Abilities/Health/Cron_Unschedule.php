<?php
/**
 * Removes cron events.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Unschedules a hook, or one instance of it, while protecting the events WordPress
 * needs to keep working.
 *
 * @since 0.1.0
 */
class Cron_Unschedule extends Abstract_Ability {

	/**
	 * Hooks that must not be removed without `force`.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const PROTECTED_HOOKS = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_scheduled_delete',
		'delete_expired_transients',
		'recovery_mode_clean_expired_keys',
		'wp_site_health_scheduled_check',
		'wp_privacy_delete_old_export_files',
		'wp_scheduled_auto_draft_delete',
		'wp_https_detection',
		'wp_update_user_counts',
		'wp_delete_temp_updater_backups',
		'wp_privacy_personal_data_cleanup_requests',
	);

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'cron-unschedule';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'health';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Unschedule a cron event', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Removes scheduled WP-Cron events. Without a timestamp every event for the hook is removed; with one only that instance is. Hooks WordPress itself depends on, such as wp_version_check or delete_expired_transients, and the plugin\'s own super_abilities_ hooks are refused unless force is true, because removing them silently breaks updates, cleanups or background jobs. Calling it again after the events are gone is safe: removed is then zero.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::destructive();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'manage_options' );
	}

	/**
	 * Input schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'hook'      => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Hook name to unschedule.', 'super-abilities' ),
				),
				'args'      => array(
					'type'        => 'array',
					'description' => __( 'Arguments identifying the event, exactly as cron-list reports them. Only used together with a timestamp.', 'super-abilities' ),
				),
				'timestamp' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Unix timestamp of the single instance to remove. Omit to remove every event for the hook.', 'super-abilities' ),
				),
				'force'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Remove the event even when the hook is protected. Default false.', 'super-abilities' ),
				),
			),
			array( 'hook' )
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'hook'      => array( 'type' => 'string' ),
				'removed'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many event instances were removed by this call.', 'super-abilities' ),
				),
				'remaining' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many events for this hook are still scheduled.', 'super-abilities' ),
				),
			),
			array( 'hook', 'removed', 'remaining' )
		);
	}

	/**
	 * Removes the events.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$hook = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';

		if ( '' === $hook ) {
			return $this->error( 'invalid_input', __( 'A hook name is required.', 'super-abilities' ), 400 );
		}

		$force     = ! empty( $input['force'] );
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : 0;
		$args      = isset( $input['args'] ) && is_array( $input['args'] ) ? array_values( $input['args'] ) : null;

		if ( ! $force && self::is_protected( $hook ) ) {
			return $this->error(
				'protected_hook',
				sprintf(
					/* translators: %s: Cron hook name. */
					__( 'The hook "%s" is required by WordPress or by this plugin and was not removed. Pass force to override, and expect updates, cleanups or background jobs to stop.', 'super-abilities' ),
					$hook
				),
				403,
				array(
					'hook'      => $hook,
					'protected' => true,
				)
			);
		}

		$this->note_object( 'hook', $hook );

		$removed = 0;

		if ( $timestamp > 0 ) {
			foreach ( Cron_List::events( $hook ) as $event ) {
				if ( (int) $event['timestamp'] !== $timestamp ) {
					continue;
				}

				if ( null !== $args && md5( serialize( $event['args'] ) ) !== md5( serialize( $args ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- This is exactly how WordPress keys cron event arguments.
					continue;
				}

				if ( true === wp_unschedule_event( $timestamp, $hook, (array) $event['args'] ) ) {
					++$removed;
				}
			}
		} else {
			$unscheduled = wp_unschedule_hook( $hook );
			$removed     = is_numeric( $unscheduled ) ? (int) $unscheduled : 0;
		}

		return array(
			'hook'      => $hook,
			'removed'   => max( 0, $removed ),
			'remaining' => count( Cron_List::events( $hook ) ),
		);
	}

	/**
	 * Whether a hook may only be removed with `force`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function is_protected( $hook ) {
		$hook = (string) $hook;

		/**
		 * Filters the cron hooks that `cron-unschedule` refuses to remove.
		 *
		 * Hooks starting with `super_abilities_` are always protected on top of this list.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string> $hooks Protected hook names.
		 */
		$protected = (array) apply_filters( 'super_abilities_protected_cron_hooks', self::PROTECTED_HOOKS );

		if ( in_array( $hook, array_map( 'strval', $protected ), true ) ) {
			return true;
		}

		return 0 === strpos( $hook, 'super_abilities_' );
	}
}
