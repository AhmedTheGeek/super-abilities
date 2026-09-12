<?php
/**
 * Tests that every health ability runs and returns output matching its own schema.
 *
 * Core validates ability output with `rest_validate_value_from_schema()` on every
 * call, so a schema mismatch here is a production failure.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Health\Cron_Health;
use SuperAbilities\Abilities\Health\Cron_List;
use SuperAbilities\Abilities\Health\Cron_Run;
use SuperAbilities\Abilities\Health\Cron_Unschedule;
use SuperAbilities\Abilities\Health\Debug_Log_Read;
use SuperAbilities\Abilities\Health\Error_Triage;
use SuperAbilities\Abilities\Health\Health_Run;
use SuperAbilities\Abilities\Health\Health_Summary;
use SuperAbilities\Modules\Health_Module;
use SuperAbilities\Plugin;

class HealthAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Direct Site Health tests that touch neither the network nor the filesystem.
	 */
	const CHEAP_TESTS = 'php_default_timezone,php_sessions,sql_server';

	private $log_file = '';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Nothing in this file may reach the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );

		$this->log_file = get_temp_dir() . 'sa-health-test-' . wp_generate_password( 8, false ) . '.log';
	}

	public function tear_down() {
		if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plain PHP is correct for a test fixture in the temp directory.
		}

		parent::tear_down();
	}

	/**
	 * Answers every outbound request: 200 for our own loopback, an error otherwise.
	 */
	public function block_http( $preempt, $args, $url ) {
		if ( false !== strpos( (string) $url, 'wp-cron.php' ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
				'headers'  => array(),
			);
		}

		return new WP_Error( 'http_request_failed', 'Outbound HTTP is blocked in tests.' );
	}

	private function use_log( $contents ) {
		file_put_contents( $this->log_file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Plain PHP is correct for a test fixture in the temp directory.

		$path = $this->log_file;

		add_filter(
			'super_abilities_debug_log_path',
			static function () use ( $path ) {
				return $path;
			}
		);
	}

	private function log_line( $level, $message, $offset = 0 ) {
		return sprintf( '[%s UTC] PHP %s:  %s', gmdate( 'd-M-Y H:i:s', time() + $offset ), $level, $message );
	}

	/**
	 * Runs an ability and asserts the result validates against its output schema.
	 *
	 * @param Abstract_Ability     $ability Ability instance.
	 * @param array<string, mixed> $input   Input to pass.
	 * @return array<string, mixed> The validated result.
	 */
	private function run_ability( Abstract_Ability $ability, array $input = array() ) {
		$result = $ability->execute( $input );

		$this->assertNotWPError( $result, $ability->name() . ' returned an error.' );
		$this->assertIsArray( $result );

		return $this->assert_matches_schema( $ability, $result );
	}

	private function assert_matches_schema( Abstract_Ability $ability, array $result ) {
		$schema = $ability->output_schema();

		$this->assertNotEmpty( $schema, $ability->name() . ' declares no output schema.' );

		$valid = rest_validate_value_from_schema( $result, $schema, 'output' );

		if ( is_wp_error( $valid ) ) {
			$this->fail( $ability->name() . ' output does not match its schema: ' . $valid->get_error_message() );
		}

		$this->assertTrue( $valid );

		return $result;
	}

	public function test_module_declares_the_eight_abilities() {
		$module = new Health_Module( Plugin::instance() );

		$this->assertSame( 'health', $module->id() );
		$this->assertTrue( $module->default_enabled() );
		$this->assertSame( 'low', $module->risk() );
		$this->assertCount( 8, $module->abilities() );

		foreach ( $module->abilities() as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), $class_name . ' is missing.' );

			$ability = new $class_name();

			$this->assertInstanceOf( Abstract_Ability::class, $ability );
			$this->assertSame( 'health', $ability->module() );
			$this->assertSame( 'super-abilities-health', $ability->category() );
			$this->assertNotEmpty( $ability->capability() );
			$this->assertNotEmpty( $ability->input_schema() );
			$this->assertNotEmpty( $ability->output_schema() );
		}
	}

	public function test_health_run_executes_cheap_direct_tests() {
		$ability = new Health_Run();

		$result = $this->run_ability(
			$ability,
			array(
				'tests'         => self::CHEAP_TESTS,
				'include_async' => false,
			)
		);

		$this->assertGreaterThanOrEqual( 1, $result['ran'] );
		$this->assertSame( $result['ran'], count( $result['tests'] ) );
		$this->assertSame( $result['ran'], array_sum( $result['summary'] ) );
		$this->assertSame( array(), $result['skipped'], 'No asynchronous test was requested.' );

		$slugs = wp_list_pluck( $result['tests'], 'test' );

		$this->assertContains( 'php_default_timezone', $slugs );

		foreach ( $result['tests'] as $test ) {
			$this->assertContains( $test['status'], array( 'good', 'recommended', 'critical' ) );
			$this->assertNotSame( '', $test['label'] );
			$this->assertStringNotContainsString( '<', $test['description'], 'Descriptions are stripped of markup.' );
			$this->assertStringNotContainsString( '<', $test['actions'] );
		}
	}

	public function test_health_run_reports_the_identifier_callers_can_pass_back() {
		// Core registers the `debug_enabled` test with the method name `is_in_debug_mode`.
		$result = $this->run_ability(
			new Health_Run(),
			array(
				'tests'         => array( 'is_in_debug_mode' ),
				'include_async' => false,
			)
		);

		$this->assertSame( 1, $result['ran'] );
		$this->assertSame( 'debug_enabled', $result['tests'][0]['test'] );

		$again = $this->run_ability(
			new Health_Run(),
			array(
				'tests'         => array( 'debug_enabled' ),
				'include_async' => false,
			)
		);

		$this->assertSame( 1, $again['ran'] );
	}

	public function test_health_run_skips_async_tests_when_asked_to() {
		$result = $this->run_ability(
			new Health_Run(),
			array(
				'tests'         => array( 'loopback_requests', 'php_default_timezone' ),
				'include_async' => false,
			)
		);

		$this->assertSame( 1, $result['ran'] );
		$this->assertCount( 1, $result['skipped'] );
		$this->assertSame( 'loopback_requests', $result['skipped'][0]['test'] );
		$this->assertNotSame( '', $result['skipped'][0]['reason'] );
	}

	public function test_health_run_filters_by_status() {
		$unfiltered = $this->run_ability(
			new Health_Run(),
			array(
				'tests'         => self::CHEAP_TESTS,
				'include_async' => false,
			)
		);

		$result = $this->run_ability(
			new Health_Run(),
			array(
				'tests'         => self::CHEAP_TESTS,
				'include_async' => false,
				'status'        => 'critical',
			)
		);

		$this->assertSame( $unfiltered['ran'], $result['ran'], 'Counts cover every test that ran.' );

		foreach ( $result['tests'] as $test ) {
			$this->assertSame( 'critical', $test['status'] );
		}

		$this->assertCount( $result['summary']['critical'], $result['tests'] );
	}

	public function test_health_summary_returns_redacted_sections() {
		$result = $this->run_ability( new Health_Summary() );

		$this->assertSame( wp_get_environment_type(), $result['environment'] );
		$this->assertArrayHasKey( 'good', $result['counts'] );
		$this->assertIsInt( $result['counts']['critical'] );

		foreach ( array( 'wp-core', 'wp-server', 'wp-database', 'wp-constants' ) as $section_id ) {
			$this->assertArrayHasKey( $section_id, $result['sections'] );
			$this->assertArrayHasKey( 'fields', $result['sections'][ $section_id ] );
		}

		$this->assertArrayNotHasKey( 'wp-paths-sizes', $result['sections'] );

		$encoded = wp_json_encode( $result );

		$this->assertStringNotContainsString( DB_PASSWORD ? DB_PASSWORD : 'no-password-set', $encoded );
		$this->assertStringNotContainsString( AUTH_SALT, $encoded );

		foreach ( $result['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$this->assertArrayNotHasKey( 'private', $field );
			}
		}
	}

	public function test_health_summary_counts_come_from_the_cached_transient() {
		set_transient(
			Health_Summary::COUNTS_TRANSIENT,
			wp_json_encode(
				array(
					'good'        => 7,
					'recommended' => 2,
					'critical'    => 1,
				)
			)
		);

		$result = $this->run_ability( new Health_Summary() );

		$this->assertSame( 7, $result['counts']['good'] );
		$this->assertSame( 2, $result['counts']['recommended'] );
		$this->assertSame( 1, $result['counts']['critical'] );

		delete_transient( Health_Summary::COUNTS_TRANSIENT );
	}

	public function test_debug_log_read_parses_a_real_file() {
		$this->use_log(
			implode(
				"\n",
				array(
					$this->log_line( 'Warning', 'Undefined variable $a in ' . WP_PLUGIN_DIR . '/foo/foo.php on line 12', -60 ),
					$this->log_line( 'Warning', 'Undefined variable $a in ' . WP_PLUGIN_DIR . '/foo/foo.php on line 12', -30 ),
					$this->log_line( 'Fatal error', 'Uncaught Error: Call to undefined function bar() in ' . WP_PLUGIN_DIR . '/foo/foo.php:20' ),
					'Stack trace:',
					'#0 {main}',
					'  thrown in ' . WP_PLUGIN_DIR . '/foo/foo.php on line 20',
				)
			) . "\n"
		);

		$result = $this->run_ability( new Debug_Log_Read() );

		$this->assertTrue( $result['enabled'] );
		$this->assertTrue( $result['exists'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertGreaterThan( 0, $result['size_bytes'] );
		$this->assertCount( 3, $result['entries'] );
		$this->assertCount( 2, $result['groups'] );

		$fatal = $result['entries'][2];

		$this->assertSame( 'fatal', $fatal['level'] );
		$this->assertSame( 20, $fatal['line'] );
		$this->assertSame( 'foo', $fatal['plugin'] );
		$this->assertStringContainsString( 'Stack trace:', $fatal['raw_excerpt'] );
		$this->assertStringNotContainsString( untrailingslashit( ABSPATH ), $fatal['file'], 'Paths are redacted.' );

		$this->assertSame( 2, $result['groups'][0]['count'] );
	}

	public function test_debug_log_read_filters_by_level_and_time() {
		$this->use_log(
			implode(
				"\n",
				array(
					$this->log_line( 'Warning', 'old warning in /srv/wp-content/plugins/foo/foo.php on line 1', -7200 ),
					$this->log_line( 'Fatal error', 'recent boom in /srv/wp-content/plugins/foo/foo.php on line 2', -10 ),
				)
			) . "\n"
		);

		$only_fatal = $this->run_ability( new Debug_Log_Read(), array( 'level' => 'fatal' ) );

		$this->assertCount( 1, $only_fatal['entries'] );
		$this->assertSame( 'fatal', $only_fatal['entries'][0]['level'] );

		$recent = $this->run_ability( new Debug_Log_Read(), array( 'since' => '-30 minutes' ) );

		$this->assertCount( 1, $recent['entries'] );
		$this->assertStringContainsString( 'recent boom', $recent['entries'][0]['message'] );

		$ungrouped = $this->run_ability( new Debug_Log_Read(), array( 'group' => false ) );

		$this->assertSame( array(), $ungrouped['groups'] );
		$this->assertCount( 2, $ungrouped['entries'] );
	}

	public function test_debug_log_read_honours_the_lines_limit() {
		$lines = array();

		for ( $i = 0; $i < 20; $i++ ) {
			$lines[] = $this->log_line( 'Notice', 'entry ' . $i . ' in /srv/wp-content/plugins/foo/foo.php on line ' . $i, $i - 100 );
		}

		$this->use_log( implode( "\n", $lines ) . "\n" );

		$result = $this->run_ability( new Debug_Log_Read(), array( 'lines' => 5 ) );

		$this->assertCount( 5, $result['entries'] );
		$this->assertStringContainsString( 'entry 19', $result['entries'][4]['message'] );
	}

	public function test_debug_log_read_is_graceful_when_logging_is_off() {
		add_filter( 'super_abilities_debug_log_path', '__return_empty_string' );

		$result = $this->run_ability( new Debug_Log_Read() );

		$this->assertFalse( $result['enabled'] );
		$this->assertFalse( $result['exists'] );
		$this->assertSame( '', $result['path'] );
		$this->assertSame( array(), $result['entries'] );
		$this->assertSame( array(), $result['groups'] );
	}

	public function test_debug_log_read_is_graceful_when_the_file_is_missing() {
		$missing = $this->log_file;

		add_filter(
			'super_abilities_debug_log_path',
			static function () use ( $missing ) {
				return $missing;
			}
		);

		$result = $this->run_ability( new Debug_Log_Read() );

		$this->assertTrue( $result['enabled'] );
		$this->assertFalse( $result['exists'] );
		$this->assertSame( array(), $result['entries'] );
	}

	public function test_error_triage_groups_fatals_and_warns_about_missing_pieces() {
		$this->use_log( $this->log_line( 'Fatal error', 'Uncaught Error: boom in ' . WP_PLUGIN_DIR . '/foo/foo.php:3', -60 ) . "\n" );

		$result = $this->run_ability( new Error_Triage() );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['since'] );
		$this->assertCount( 1, $result['fatals'] );
		$this->assertSame( 'fatal', $result['fatals'][0]['level'] );
		$this->assertSame( 'foo', $result['fatals'][0]['plugin'] );
		$this->assertIsArray( $result['recent_changes'] );
		$this->assertIsArray( $result['suspects'] );
		$this->assertIsArray( $result['warnings'] );
	}

	public function test_error_triage_ignores_errors_outside_the_window() {
		$this->use_log( $this->log_line( 'Fatal error', 'Uncaught Error: ancient in /srv/wp-content/plugins/foo/foo.php:3', -3 * DAY_IN_SECONDS ) . "\n" );

		$result = $this->run_ability( new Error_Triage(), array( 'since' => '-1 hour' ) );

		$this->assertSame( array(), $result['fatals'] );
		$this->assertSame( array(), $result['suspects'] );
	}

	public function test_error_triage_warns_when_the_audit_module_is_off() {
		$options = Plugin::instance()->options();
		$modules = (array) $options->get( 'modules' );

		$options->set( 'modules', array_merge( $modules, array( 'audit' => false ) ) );

		add_filter( 'super_abilities_debug_log_path', '__return_empty_string' );

		$result = $this->run_ability( new Error_Triage() );

		$this->assertSame( array(), $result['recent_changes'] );
		$this->assertNotEmpty( $result['warnings'] );

		$options->set( 'modules', $modules );
	}

	public function test_error_triage_rejects_an_unparseable_since() {
		$result = ( new Error_Triage() )->execute( array( 'since' => 'the day before whenever' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_cron_list_output_matches_its_schema() {
		wp_schedule_event( time() + 60, 'hourly', 'sa_health_schema_hook', array( 'x' ) );
		wp_schedule_single_event( time() + 120, 'sa_health_schema_single' );

		$result = $this->run_ability( new Cron_List() );

		$this->assertNotEmpty( $result['events'] );

		$hooks = wp_list_pluck( $result['events'], 'hook' );

		$this->assertContains( 'sa_health_schema_hook', $hooks );
		$this->assertContains( 'sa_health_schema_single', $hooks );

		wp_unschedule_hook( 'sa_health_schema_hook' );
		wp_unschedule_hook( 'sa_health_schema_single' );
	}

	public function test_cron_list_output_survives_associative_event_arguments() {
		// Nothing stops a plugin from scheduling an event with keyed arguments.
		wp_schedule_single_event( time() + 60, 'sa_health_assoc_hook', array( 'key' => 'value' ) );

		$result = $this->run_ability( new Cron_List(), array( 'hook' => 'sa_health_assoc_hook' ) );

		$this->assertCount( 1, $result['events'] );
		$this->assertSame( array( 'key' => 'value' ), $result['events'][0]['args'] );

		wp_unschedule_hook( 'sa_health_assoc_hook' );
	}

	public function test_cron_health_output_matches_its_schema() {
		$result = $this->run_ability( new Cron_Health() );

		$this->assertSame( 'ok', $result['loopback']['status'] );
		$this->assertIsInt( $result['total_events'] );
		$this->assertNotSame( '', $result['verdict'] );
	}

	/**
	 * Guards the guard: proves the schema assertion above can actually fail.
	 */
	public function test_schema_validation_rejects_wrong_output() {
		$schema = ( new Cron_Health() )->output_schema();
		$result = ( new Cron_Health() )->execute( array() );

		$this->assertTrue( rest_validate_value_from_schema( $result, $schema, 'output' ) );

		$wrong_type                 = $result;
		$wrong_type['total_events'] = array( 'nope' );

		$this->assertWPError( rest_validate_value_from_schema( $wrong_type, $schema, 'output' ) );

		$extra_key             = $result;
		$extra_key['surprise'] = true;

		$this->assertWPError( rest_validate_value_from_schema( $extra_key, $schema, 'output' ) );

		$missing = $result;
		unset( $missing['verdict'] );

		$this->assertWPError( rest_validate_value_from_schema( $missing, $schema, 'output' ) );
	}

	public function test_cron_write_ability_output_matches_its_schema() {
		wp_schedule_event( time() - 60, 'hourly', 'sa_health_write_hook' );

		$this->assert_matches_schema(
			new Cron_Run(),
			( new Cron_Run() )->execute( array( 'hook' => 'sa_health_write_hook' ) )
		);

		$this->assert_matches_schema(
			new Cron_Unschedule(),
			( new Cron_Unschedule() )->execute( array( 'hook' => 'sa_health_write_hook' ) )
		);

		$this->assertFalse( wp_next_scheduled( 'sa_health_write_hook' ) );
	}
}
