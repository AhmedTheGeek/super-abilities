<?php
/**
 * Runs a scheduled cron event immediately.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors `wp cron event run`: reschedules the event, removes the due instance and
 * fires the hook in the current request.
 *
 * @since 0.1.0
 */
class Cron_Run extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'cron-run';
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
		return __( 'Run a cron event', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs a scheduled WP-Cron event right now, the way WP-CLI does: a recurring event is rescheduled first, the due instance is removed, then the hook fires inside this request with its output captured. Whatever the hook does happens for real, so the effect is as destructive as the hook itself; a hook that triggers a PHP fatal error cannot be caught and will end the request. Call super-abilities/cron-list first to see the exact hook, args and timestamp.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
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
					'description' => __( 'Hook name of the event to run.', 'super-abilities' ),
				),
				'args'      => array(
					'type'        => 'array',
					'description' => __( 'Arguments identifying the event, exactly as cron-list reports them. Omit to match the event that has no arguments or, when several exist, the soonest one.', 'super-abilities' ),
				),
				'timestamp' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Unix timestamp of the instance to run, as reported by cron-list. Omit to run the soonest instance.', 'super-abilities' ),
				),
				'force'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Fire the hook even when nothing is scheduled for it. Default false.', 'super-abilities' ),
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
				'hook'          => array( 'type' => 'string' ),
				'ran'           => array( 'type' => 'boolean' ),
				'timestamp'     => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Scheduled time of the instance that ran, or null when the hook was forced.', 'super-abilities' ),
				),
				'rescheduled'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the recurring event was put back on the schedule.', 'super-abilities' ),
				),
				'duration_ms'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'output_length' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Number of characters the hook printed. The output itself is discarded.', 'super-abilities' ),
				),
				'error'         => array(
					'type'        => 'string',
					'description' => __( 'Message of the exception the hook threw, when it threw one.', 'super-abilities' ),
				),
			),
			array( 'hook', 'ran', 'timestamp', 'rescheduled', 'duration_ms', 'output_length' )
		);
	}

	/**
	 * Runs the event.
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

		$event = self::find_event( $hook, $args, $timestamp );

		if ( null === $event && ! $force ) {
			return $this->error(
				'not_found',
				__( 'No scheduled event matches that hook, arguments and timestamp. Pass force to fire the hook anyway.', 'super-abilities' ),
				404,
				array( 'hook' => $hook )
			);
		}

		$this->note_object( 'hook', $hook );

		$run_args    = null === $event ? (array) $args : (array) $event['args'];
		$rescheduled = false;

		if ( null !== $event ) {
			/*
			 * Core's wp-cron.php and WP-CLI reschedule first and unschedule afterwards.
			 * That order is only safe for events that are already due: `wp_reschedule_event()`
			 * puts a future event back at `time() + interval`, which for an event that is not
			 * due yet can be the very timestamp we are about to unschedule, so the recurring
			 * event would disappear. Unscheduling the instance we took before writing the next
			 * one keeps the schedule intact either way.
			 */
			wp_unschedule_event( (int) $event['timestamp'], $hook, $run_args );

			if ( is_string( $event['schedule'] ) && '' !== $event['schedule'] ) {
				wp_reschedule_event( (int) $event['timestamp'], $event['schedule'], $hook, $run_args );

				/*
				 * The return value is not a reliable signal: `_set_cron_array()` reports failure
				 * whenever the option value happens not to change. Ask the schedule instead.
				 */
				$rescheduled = false !== wp_next_scheduled( $hook, $run_args );
			}
		}

		$started = microtime( true );
		$error   = '';

		ob_start();

		try {
			do_action_ref_array( $hook, $run_args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook name comes from the cron schedule and belongs to whoever scheduled it.
		} catch ( \Throwable $throwable ) {
			$error = $throwable->getMessage();
		}

		$output   = (string) ob_get_clean();
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		$result = array(
			'hook'          => $hook,
			'ran'           => true,
			'timestamp'     => null === $event ? null : Time::iso( (int) $event['timestamp'] ),
			'rescheduled'   => $rescheduled,
			'duration_ms'   => $duration,
			'output_length' => strlen( $output ),
		);

		if ( '' !== $error ) {
			$result['error'] = Redactor::text( $error );
		}

		return $result;
	}

	/**
	 * Finds the event instance to run.
	 *
	 * @since 0.1.0
	 *
	 * @param string                 $hook      Hook name.
	 * @param array<int, mixed>|null $args      Arguments to match, or null to match any.
	 * @param int                    $timestamp Timestamp to match, or 0 for the soonest.
	 * @return array<string, mixed>|null
	 */
	protected static function find_event( $hook, $args, $timestamp ) {
		$signature = null === $args ? '' : md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- This is exactly how WordPress keys cron event arguments.

		foreach ( Cron_List::events( $hook ) as $event ) {
			if ( $timestamp > 0 && (int) $event['timestamp'] !== $timestamp ) {
				continue;
			}

			if ( null !== $args && md5( serialize( $event['args'] ) ) !== $signature ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- This is exactly how WordPress keys cron event arguments.
				continue;
			}

			return $event;
		}

		return null;
	}
}
