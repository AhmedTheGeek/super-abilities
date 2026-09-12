<?php
/**
 * Lists the scheduled cron events.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the cron array and reports every scheduled event.
 *
 * @since 0.1.0
 */
class Cron_List extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'cron-list';
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
		return __( 'List cron events', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists every event in the WP-Cron schedule, soonest first, with its hook, arguments, recurrence, interval, next run time and how many seconds it is overdue. Events with a large overdue_seconds mean WP-Cron is not running; call super-abilities/cron-health to find out why.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
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
				'hook' => array(
					'type'        => 'string',
					'description' => __( 'Only return events for this hook name.', 'super-abilities' ),
				),
			)
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
		$event = Schema::object(
			array(
				'hook'            => array( 'type' => 'string' ),
				'args'            => array(
					'type'        => array( 'array', 'object' ),
					'description' => __( 'Arguments the hook is called with, exactly as stored in the schedule.', 'super-abilities' ),
				),
				'schedule'        => array(
					'type'        => array( 'string', 'boolean' ),
					'description' => __( 'Recurrence name such as "hourly", or false for a single event.', 'super-abilities' ),
				),
				'interval'        => array(
					'type'        => 'integer',
					'description' => __( 'Recurrence interval in seconds. Zero for single events.', 'super-abilities' ),
				),
				'timestamp'       => array(
					'type'        => 'integer',
					'description' => __( 'Unix timestamp of the next run, as stored in the cron array. Pass it back to cron-run or cron-unschedule.', 'super-abilities' ),
				),
				'next_run'        => array( 'type' => 'string' ),
				'overdue_seconds' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'hook', 'args', 'schedule', 'interval', 'timestamp', 'next_run', 'overdue_seconds' )
		);

		return Schema::object(
			array(
				'now'    => array( 'type' => 'string' ),
				'events' => array(
					'type'  => 'array',
					'items' => $event,
				),
			),
			array( 'now', 'events' )
		);
	}

	/**
	 * Lists the events.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$hook = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';
		$now  = Time::now();

		return array(
			'now'    => Time::iso( $now ),
			'events' => self::events( $hook, $now ),
		);
	}

	/**
	 * Flattens the cron array into a sorted list of events.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Optional. Only return events for this hook. Default empty.
	 * @param int    $now  Optional. Reference timestamp. Default the current time.
	 * @return array<int, array<string, mixed>>
	 */
	public static function events( $hook = '', $now = 0 ) {
		$now = (int) $now > 0 ? (int) $now : Time::now();

		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return array();
		}

		$hook   = (string) $hook;
		$events = array();

		ksort( $cron );

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook_name => $signatures ) {
				if ( '' !== $hook && $hook !== (string) $hook_name ) {
					continue;
				}

				if ( ! is_array( $signatures ) ) {
					continue;
				}

				foreach ( $signatures as $event ) {
					if ( ! is_array( $event ) ) {
						continue;
					}

					$schedule = isset( $event['schedule'] ) ? $event['schedule'] : false;

					$events[] = array(
						'hook'            => (string) $hook_name,
						'args'            => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array(),
						'schedule'        => is_string( $schedule ) && '' !== $schedule ? $schedule : false,
						'interval'        => isset( $event['interval'] ) ? (int) $event['interval'] : 0,
						'timestamp'       => (int) $timestamp,
						'next_run'        => Time::iso( (int) $timestamp ),
						'overdue_seconds' => max( 0, $now - (int) $timestamp ),
					);
				}
			}
		}

		return $events;
	}
}
