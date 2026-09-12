<?php
/**
 * Reports whether WP-Cron is actually running.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Health\Loopback;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Combines the cron constants, the spawn lock, our heartbeat and a loopback probe
 * into one verdict about WP-Cron.
 *
 * @since 0.1.0
 */
class Cron_Health extends Abstract_Ability {

	/**
	 * Number of seconds after which an event counts as overdue.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const OVERDUE_AFTER = 300;

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'cron-health';
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
		return __( 'Check cron health', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reports whether WP-Cron can run on this site: the DISABLE_WP_CRON and ALTERNATE_WP_CRON constants, the age of the doing_cron spawn lock, how many events are overdue, how long ago the Super Abilities heartbeat last fired, and a live loopback request to wp-cron.php. Call this whenever a background job or a scheduled event does not seem to run. The loopback probe makes one outbound HTTP request, so the call takes a few seconds.', 'super-abilities' );
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
		return Schema::object( array() );
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
				'disable_wp_cron'             => array( 'type' => 'boolean' ),
				'alternate_wp_cron'           => array( 'type' => 'boolean' ),
				'doing_cron_lock_age_seconds' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Age of the doing_cron spawn lock, or null when no run is in progress.', 'super-abilities' ),
				),
				'overdue_events'              => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Events overdue by more than five minutes.', 'super-abilities' ),
				),
				'total_events'                => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'heartbeat_age_seconds'       => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Seconds since the hourly Super Abilities heartbeat last ran, or null when it has never run.', 'super-abilities' ),
				),
				'loopback'                    => Schema::object(
					array(
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'ok', 'failed', 'unverified' ),
						),
						'http_code' => array( 'type' => 'integer' ),
						'message'   => array( 'type' => 'string' ),
					),
					array( 'status', 'http_code', 'message' )
				),
				'verdict'                     => array( 'type' => 'string' ),
			),
			array( 'disable_wp_cron', 'alternate_wp_cron', 'doing_cron_lock_age_seconds', 'overdue_events', 'total_events', 'heartbeat_age_seconds', 'loopback', 'verdict' )
		);
	}

	/**
	 * Collects the cron diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$now       = Time::now();
		$events    = Cron_List::events( '', $now );
		$overdue   = 0;
		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alternate = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;

		foreach ( $events as $event ) {
			if ( (int) $event['overdue_seconds'] > self::OVERDUE_AFTER ) {
				++$overdue;
			}
		}

		$result = array(
			'disable_wp_cron'             => (bool) $disabled,
			'alternate_wp_cron'           => (bool) $alternate,
			'doing_cron_lock_age_seconds' => self::lock_age( $now ),
			'overdue_events'              => $overdue,
			'total_events'                => count( $events ),
			'heartbeat_age_seconds'       => self::heartbeat_age( $now ),
			'loopback'                    => Loopback::test(),
		);

		$result['verdict'] = self::verdict( $result );

		return $result;
	}

	/**
	 * Age of the `doing_cron` spawn lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $now Reference timestamp.
	 * @return int|null Null when no lock is held.
	 */
	protected static function lock_age( $now ) {
		$lock = get_transient( 'doing_cron' );

		if ( ! is_scalar( $lock ) || '' === (string) $lock ) {
			return null;
		}

		$started = (float) $lock;

		if ( $started <= 0 ) {
			return null;
		}

		return max( 0, (int) round( $now - $started ) );
	}

	/**
	 * Seconds since our hourly heartbeat last ran.
	 *
	 * @since 0.1.0
	 *
	 * @param int $now Reference timestamp.
	 * @return int|null Null when the heartbeat has never run.
	 */
	protected static function heartbeat_age( $now ) {
		$last = (int) get_option( Plugin::HEARTBEAT_OPTION, 0 );

		if ( $last < 1 ) {
			return null;
		}

		return max( 0, $now - $last );
	}

	/**
	 * Turns the raw numbers into one sentence an agent can act on.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $result Collected diagnostics.
	 * @return string
	 */
	protected static function verdict( array $result ) {
		$notes = array();

		if ( $result['disable_wp_cron'] ) {
			$notes[] = __( 'DISABLE_WP_CRON is set, so WordPress never spawns cron itself: a system cron must request wp-cron.php.', 'super-abilities' );
		}

		if ( $result['alternate_wp_cron'] ) {
			$notes[] = __( 'ALTERNATE_WP_CRON is set, so cron runs through a visitor redirect.', 'super-abilities' );
		}

		if ( 'failed' === $result['loopback']['status'] && ! $result['disable_wp_cron'] ) {
			$notes[] = __( 'The loopback request to wp-cron.php failed, which usually means WP-Cron cannot start on its own.', 'super-abilities' );
		}

		if ( 'unverified' === $result['loopback']['status'] ) {
			$notes[] = __( 'The loopback request could not be verified.', 'super-abilities' );
		}

		if ( null === $result['heartbeat_age_seconds'] ) {
			$notes[] = __( 'The Super Abilities heartbeat has never run, so no cron event has fired since the plugin was activated.', 'super-abilities' );
		} elseif ( $result['heartbeat_age_seconds'] > 2 * HOUR_IN_SECONDS ) {
			$notes[] = sprintf(
				/* translators: %d: Number of hours. */
				__( 'The hourly heartbeat last ran about %d hours ago, so cron is behind.', 'super-abilities' ),
				(int) floor( $result['heartbeat_age_seconds'] / HOUR_IN_SECONDS )
			);
		}

		if ( $result['overdue_events'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: Number of events. */
				_n(
					'%d event is more than five minutes overdue.',
					'%d events are more than five minutes overdue.',
					(int) $result['overdue_events'],
					'super-abilities'
				),
				(int) $result['overdue_events']
			);
		}

		if ( array() === $notes ) {
			return __( 'WP-Cron looks healthy: loopback works, the heartbeat is recent and no event is overdue.', 'super-abilities' );
		}

		return implode( ' ', $notes );
	}
}
