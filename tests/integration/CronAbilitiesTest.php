<?php
/**
 * Tests for the cron abilities.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Health\Cron_Health;
use SuperAbilities\Abilities\Health\Cron_List;
use SuperAbilities\Abilities\Health\Cron_Run;
use SuperAbilities\Abilities\Health\Cron_Unschedule;

class CronAbilitiesTest extends WP_UnitTestCase {

	const HOOK = 'sa_test_cron_hook';

	public static $runs = 0;

	public static $last_args = array();

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		self::$runs      = 0;
		self::$last_args = array();

		add_action( self::HOOK, array( __CLASS__, 'record_run' ), 10, 2 );
	}

	public function tear_down() {
		wp_unschedule_hook( self::HOOK );

		parent::tear_down();
	}

	public static function record_run( $one = null, $two = null ) {
		++self::$runs;

		self::$last_args = array( $one, $two );

		echo 'ran';
	}

	private function events_for( $hook ) {
		$result = ( new Cron_List() )->execute( array( 'hook' => $hook ) );

		return $result['events'];
	}

	public function test_cron_list_shows_a_scheduled_event() {
		$timestamp = time() + HOUR_IN_SECONDS;

		wp_schedule_event( $timestamp, 'hourly', self::HOOK );

		$result = ( new Cron_List() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['now'] );
		$this->assertCount( 1, $result['events'] );

		$event = $result['events'][0];

		$this->assertSame( self::HOOK, $event['hook'] );
		$this->assertSame( 'hourly', $event['schedule'] );
		$this->assertSame( HOUR_IN_SECONDS, $event['interval'] );
		$this->assertSame( $timestamp, $event['timestamp'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ), $event['next_run'] );
		$this->assertSame( 0, $event['overdue_seconds'] );
		$this->assertSame( array(), $event['args'] );
	}

	public function test_cron_list_reports_overdue_and_single_events() {
		$timestamp = time() - 600;

		wp_schedule_single_event( $timestamp, self::HOOK, array( 'a', 2 ) );

		$event = $this->events_for( self::HOOK )[0];

		$this->assertFalse( $event['schedule'] );
		$this->assertSame( 0, $event['interval'] );
		$this->assertSame( array( 'a', 2 ), $event['args'] );
		$this->assertGreaterThanOrEqual( 600, $event['overdue_seconds'] );
	}

	public function test_cron_list_without_a_hook_lists_everything() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );

		$hooks = wp_list_pluck( ( new Cron_List() )->execute( array() )['events'], 'hook' );

		$this->assertContains( self::HOOK, $hooks );
	}

	public function test_cron_run_fires_the_hook_and_reschedules_it() {
		$timestamp = time() + HOUR_IN_SECONDS;

		wp_schedule_event( $timestamp, 'hourly', self::HOOK );

		$result = ( new Cron_Run() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, self::$runs, 'The hook callback must have run exactly once.' );
		$this->assertSame( self::HOOK, $result['hook'] );
		$this->assertTrue( $result['ran'] );
		$this->assertTrue( $result['rescheduled'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ), $result['timestamp'] );
		$this->assertSame( strlen( 'ran' ), $result['output_length'], 'Hook output is counted, not returned.' );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertGreaterThanOrEqual( 0, $result['duration_ms'] );

		$next = wp_next_scheduled( self::HOOK );

		$this->assertNotFalse( $next, 'A recurring event stays on the schedule.' );
		$this->assertNotSame( $timestamp, $next, 'The instance that ran was replaced.' );
	}

	public function test_cron_run_passes_the_event_arguments() {
		wp_schedule_single_event( time() + 60, self::HOOK, array( 'first', 'second' ) );

		( new Cron_Run() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertSame( 1, self::$runs );
		$this->assertSame( array( 'first', 'second' ), self::$last_args );
		$this->assertFalse( wp_next_scheduled( self::HOOK, array( 'first', 'second' ) ), 'A single event is gone once it ran.' );
	}

	public function test_cron_run_matches_a_specific_timestamp() {
		$soon  = time() + 60;
		$later = time() + 600;

		wp_schedule_single_event( $soon, self::HOOK, array( 'soon' ) );
		wp_schedule_single_event( $later, self::HOOK, array( 'later' ) );

		$result = ( new Cron_Run() )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => $later,
			)
		);

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $later ), $result['timestamp'] );
		$this->assertSame( array( 'later', null ), self::$last_args );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 'soon' ) ), 'The other instance is untouched.' );
	}

	public function test_cron_run_refuses_an_unscheduled_hook_unless_forced() {
		$result = ( new Cron_Run() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 0, self::$runs );

		$forced = ( new Cron_Run() )->execute(
			array(
				'hook'  => self::HOOK,
				'force' => true,
			)
		);

		$this->assertTrue( $forced['ran'] );
		$this->assertNull( $forced['timestamp'] );
		$this->assertFalse( $forced['rescheduled'] );
		$this->assertSame( 1, self::$runs );
	}

	public function test_cron_run_reports_a_thrown_exception() {
		add_action(
			self::HOOK,
			static function () {
				throw new RuntimeException( 'hook exploded' );
			}
		);

		wp_schedule_single_event( time() + 60, self::HOOK );

		$result = ( new Cron_Run() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertTrue( $result['ran'] );
		$this->assertSame( 'hook exploded', $result['error'] );
	}

	public function test_cron_unschedule_removes_every_instance_and_is_idempotent() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		wp_schedule_single_event( time() + 60, self::HOOK, array( 'x' ) );

		$result = ( new Cron_Unschedule() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertSame( self::HOOK, $result['hook'] );
		$this->assertSame( 2, $result['removed'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertFalse( wp_next_scheduled( self::HOOK ) );

		$again = ( new Cron_Unschedule() )->execute( array( 'hook' => self::HOOK ) );

		$this->assertSame( 0, $again['removed'] );
		$this->assertSame( 0, $again['remaining'] );
	}

	public function test_cron_unschedule_with_a_timestamp_removes_one_instance() {
		$soon  = time() + 60;
		$later = time() + 600;

		wp_schedule_single_event( $soon, self::HOOK, array( 'soon' ) );
		wp_schedule_single_event( $later, self::HOOK, array( 'later' ) );

		$result = ( new Cron_Unschedule() )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => $later,
				'args'      => array( 'later' ),
			)
		);

		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( 1, $result['remaining'] );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 'soon' ) ) );
		$this->assertFalse( wp_next_scheduled( self::HOOK, array( 'later' ) ) );
	}

	public function test_cron_unschedule_refuses_wp_version_check_without_force() {
		$result = ( new Cron_Unschedule() )->execute( array( 'hook' => 'wp_version_check' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_protected_hook', $result->get_error_code() );

		$data = $result->get_error_data();

		$this->assertSame( 403, $data['status'] );
		$this->assertSame( 'wp_version_check', $data['hook'] );
		$this->assertTrue( $data['protected'] );
	}

	public function test_cron_unschedule_protects_our_own_hooks_but_force_wins() {
		$hook = 'super_abilities_test_hook';

		wp_schedule_single_event( time() + 60, $hook );

		$this->assertTrue( Cron_Unschedule::is_protected( $hook ) );
		$this->assertWPError( ( new Cron_Unschedule() )->execute( array( 'hook' => $hook ) ) );

		$forced = ( new Cron_Unschedule() )->execute(
			array(
				'hook'  => $hook,
				'force' => true,
			)
		);

		$this->assertSame( 1, $forced['removed'] );
		$this->assertSame( 0, $forced['remaining'] );
		$this->assertFalse( wp_next_scheduled( $hook ) );
	}

	public function test_protected_hook_list_is_filterable() {
		$filter = static function ( $hooks ) {
			$hooks[] = 'sa_extra_protected_hook';

			return $hooks;
		};

		add_filter( 'super_abilities_protected_cron_hooks', $filter );

		$this->assertTrue( Cron_Unschedule::is_protected( 'sa_extra_protected_hook' ) );
		$this->assertFalse( Cron_Unschedule::is_protected( self::HOOK ) );

		remove_filter( 'super_abilities_protected_cron_hooks', $filter );
	}

	public function test_cron_health_counts_overdue_events_and_reports_a_verdict() {
		wp_schedule_single_event( time() - 3600, self::HOOK );

		// Keep the loopback probe off the network.
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '',
					'headers'  => array(),
				);
			}
		);

		$result = ( new Cron_Health() )->execute( array() );

		$this->assertGreaterThanOrEqual( 1, $result['overdue_events'] );
		$this->assertGreaterThanOrEqual( $result['overdue_events'], $result['total_events'] );
		$this->assertIsBool( $result['disable_wp_cron'] );
		$this->assertIsBool( $result['alternate_wp_cron'] );
		$this->assertSame( 'ok', $result['loopback']['status'] );
		$this->assertSame( 200, $result['loopback']['http_code'] );
		$this->assertNotSame( '', $result['verdict'] );
	}

	public function test_cron_abilities_declare_the_expected_annotations() {
		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			( new Cron_List() )->annotations()
		);

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			( new Cron_Run() )->annotations()
		);

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			( new Cron_Unschedule() )->annotations()
		);
	}
}
