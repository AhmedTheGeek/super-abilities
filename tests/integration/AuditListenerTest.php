<?php
/**
 * Tests for the audit listener and logger against a real registry.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Audit\Context;
use SuperAbilities\Audit\Listener;
use SuperAbilities\Install;
use SuperAbilities\Modules\Audit_Module;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Schema;

class SA_Audit_Fixture_Ability extends Abstract_Ability {

	public function slug() {
		return 'audit-fixture';
	}

	public function module() {
		return 'audit';
	}

	public function label() {
		return 'Audit fixture';
	}

	public function description() {
		return 'Succeeds or fails on demand. Only used by the test suite.';
	}

	public function annotations() {
		return self::readonly();
	}

	public function capability() {
		return array( 'read' );
	}

	public function input_schema() {
		return Schema::object(
			array(
				'mode'    => array(
					'type' => 'string',
					'enum' => array( 'ok', 'fail' ),
				),
				'post_id' => array( 'type' => 'integer' ),
			)
		);
	}

	public function output_schema() {
		return Schema::object( array( 'ok' => array( 'type' => 'boolean' ) ), array( 'ok' ) );
	}

	public function execute( array $input ) {
		if ( isset( $input['mode'] ) && 'fail' === $input['mode'] ) {
			return $this->error( 'not_found', 'Nothing here.', 404 );
		}

		return array( 'ok' => true );
	}
}

class AuditListenerTest extends WP_UnitTestCase {

	/**
	 * Highest audit row id before the current test started.
	 *
	 * @var int
	 */
	protected $baseline = 0;

	/**
	 * The callback registering the fixture abilities.
	 *
	 * @var callable|null
	 */
	protected $registrar = null;

	public function set_up() {
		parent::set_up();

		Context::reset();

		$this->baseline  = $this->max_id();
		$this->registrar = array( $this, 'register_fixtures' );

		add_action( 'wp_abilities_api_init', $this->registrar );

		self::reset_registry();

		// Rebuilds the registry, which fires wp_abilities_api_init again.
		wp_get_abilities();
	}

	public function tear_down() {
		$listener = $this->listener();

		if ( $listener instanceof Listener ) {
			$listener->flush();
		}

		if ( null !== $this->registrar ) {
			remove_action( 'wp_abilities_api_init', $this->registrar );
		}

		self::reset_registry();
		Context::reset();

		parent::tear_down();
	}

	/**
	 * Registers one ability of ours and one pretending to belong to another plugin.
	 */
	public function register_fixtures() {
		Plugin::instance()->registrar()->register( new SA_Audit_Fixture_Ability() );

		wp_register_ability(
			'sa-test/probe',
			array(
				'label'               => 'Third party probe',
				'description'         => 'Stands in for an ability registered by another plugin.',
				'category'            => 'super-abilities-audit',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'slug' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
					'required'   => array( 'ok' ),
				),
				'execute_callback'    => static function () {
					return array( 'ok' => true );
				},
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	public function test_a_successful_call_writes_an_ok_row() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ability = wp_get_ability( 'super-abilities/audit-fixture' );

		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'mode'    => 'ok',
				'post_id' => 41,
			)
		);

		$this->assertSame( array( 'ok' => true ), $result );

		$rows = $this->rows( 'super-abilities/audit-fixture' );

		$this->assertCount( 1, $rows );

		$row = $rows[0];

		$this->assertSame( 'ok', $row['outcome'] );
		$this->assertSame( '', $row['error_code'] );
		$this->assertSame( Context::request_id(), $row['request_id'] );
		$this->assertSame( get_current_user_id(), (int) $row['user_id'] );
		$this->assertContains( $row['transport'], array( 'rest', 'mcp', 'wp-cli', 'cron', 'job', 'internal' ) );
		$this->assertSame( array( 'mode', 'post_id' ), json_decode( $row['input_keys'], true ) );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( '41', $row['object_id'] );
		$this->assertSame( 0, (int) $row['job_id'] );
	}

	public function test_an_ability_that_returns_an_error_writes_an_error_row() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( 'super-abilities/audit-fixture' )->execute( array( 'mode' => 'fail' ) );

		$this->assertWPError( $result );

		$rows = $this->rows( 'super-abilities/audit-fixture' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['outcome'] );
		$this->assertSame( 'super_abilities_not_found', $rows[0]['error_code'] );
		$this->assertSame( array( 'mode' ), json_decode( $rows[0]['input_keys'], true ) );

		// The error code must not leak into the next call to the same ability.
		$this->assertSame( '', Context::error_for( 'super-abilities/audit-fixture' ) );
	}

	public function test_an_anonymous_caller_writes_a_denied_row() {
		wp_set_current_user( 0 );

		$fired = array();

		add_action(
			'super_abilities_permission_denied',
			static function ( $name, $input, $code ) use ( &$fired ) {
				$fired[] = array( $name, $code );
			},
			10,
			3
		);

		$denied = ( new SA_Audit_Fixture_Ability() )->check_permission( array( 'mode' => 'ok' ) );

		$this->assertWPError( $denied );
		$this->assertSame( array( array( 'super-abilities/audit-fixture', 'not_logged_in' ) ), $fired );

		$rows = $this->rows( 'super-abilities/audit-fixture' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['outcome'] );
		$this->assertSame( 'not_logged_in', $rows[0]['error_code'] );
		$this->assertSame( 0, (int) $rows[0]['user_id'] );
	}

	public function test_a_denied_attempt_is_not_written_twice_on_shutdown() {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'super-abilities/audit-fixture' );
		$result  = $ability->execute( array( 'mode' => 'ok' ) );

		$this->assertWPError( $result );

		$listener = $this->listener();

		$this->assertInstanceOf( Listener::class, $listener );

		$listener->flush();

		$rows = $this->rows( 'super-abilities/audit-fixture' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['outcome'] );
	}

	public function test_third_party_abilities_are_logged() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( 'sa-test/probe' )->execute( array( 'slug' => 'akismet' ) );

		$this->assertSame( array( 'ok' => true ), $result );

		$rows = $this->rows( 'sa-test/probe' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
		$this->assertSame( array( 'slug' ), json_decode( $rows[0]['input_keys'], true ) );
		$this->assertSame( 'slug', $rows[0]['object_type'] );
		$this->assertSame( 'akismet', $rows[0]['object_id'] );
	}

	public function test_third_party_abilities_can_be_excluded() {
		$options = Plugin::instance()->options();
		$options->set( 'audit_third_party', false );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		wp_get_ability( 'sa-test/probe' )->execute( array( 'slug' => 'akismet' ) );
		wp_get_ability( 'super-abilities/audit-fixture' )->execute( array( 'mode' => 'ok' ) );

		$options->set( 'audit_third_party', true );

		$this->assertCount( 0, $this->rows( 'sa-test/probe' ) );
		$this->assertCount( 1, $this->rows( 'super-abilities/audit-fixture' ) );
	}

	public function test_site_events_are_recorded() {
		$logger = $this->module() instanceof Audit_Module ? $this->module()->logger() : null;

		$this->assertNotNull( $logger );

		do_action( 'activated_plugin', 'akismet/akismet.php', false );

		$rows = $this->rows( 'event/plugin-activated' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
		$this->assertSame( 'plugin', $rows[0]['object_type'] );
		$this->assertSame( 'akismet/akismet.php', $rows[0]['object_id'] );
	}

	public function test_the_audit_query_ability_reads_the_rows_back() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		wp_get_ability( 'super-abilities/audit-fixture' )->execute(
			array(
				'mode'    => 'ok',
				'post_id' => 7,
			)
		);

		$result = wp_get_ability( 'super-abilities/audit-query' )->execute(
			array(
				'ability'  => 'super-abilities/audit-fixture',
				'since'    => '-5 minutes',
				'per_page' => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 10, $result['per_page'] );
		$this->assertCount( 1, $result['items'] );

		$item = $result['items'][0];

		$this->assertSame( 'super-abilities/audit-fixture', $item['ability'] );
		$this->assertSame( 'ok', $item['outcome'] );
		$this->assertSame( array( 'mode', 'post_id' ), $item['input_keys'] );
		$this->assertSame( 'post', $item['object_type'] );
		$this->assertSame( '7', $item['object_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['created_at'] );
		$this->assertSame( wp_get_current_user()->user_login, $item['user_login'] );
	}

	public function test_the_audit_summary_ability_aggregates_the_rows() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ability = wp_get_ability( 'super-abilities/audit-fixture' );

		$ability->execute( array( 'mode' => 'ok' ) );
		$ability->execute( array( 'mode' => 'fail' ) );

		$summary = wp_get_ability( 'super-abilities/audit-summary' )->execute(
			array(
				'since'    => '-5 minutes',
				'group_by' => 'ability',
			)
		);

		$this->assertIsArray( $summary );
		$this->assertGreaterThanOrEqual( 2, $summary['total'] );
		$this->assertArrayHasKey( 'ok', $summary['by_outcome'] );
		$this->assertArrayHasKey( 'error', $summary['by_outcome'] );
		$this->assertGreaterThanOrEqual( 1, $summary['distinct_users'] );

		$by_key = wp_list_pluck( $summary['groups'], 'count', 'key' );

		$this->assertArrayHasKey( 'super-abilities/audit-fixture', $by_key );
		$this->assertSame( 2, $by_key['super-abilities/audit-fixture'] );

		$codes = wp_list_pluck( $summary['top_error_codes'], 'count', 'code' );

		$this->assertArrayHasKey( 'super_abilities_not_found', $codes );
	}

	public function test_the_summary_can_group_by_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		wp_get_ability( 'super-abilities/audit-fixture' )->execute( array( 'mode' => 'ok' ) );

		$summary = wp_get_ability( 'super-abilities/audit-summary' )->execute(
			array(
				'since'    => '-5 minutes',
				'group_by' => 'user',
			)
		);

		$labels = wp_list_pluck( $summary['groups'], 'label', 'key' );

		$this->assertArrayHasKey( (string) $user_id, $labels );
		$this->assertSame( get_userdata( $user_id )->user_login, $labels[ (string) $user_id ] );
	}

	public function test_the_pruner_deletes_rows_outside_the_retention_window() {
		global $wpdb;

		$table = Install::table( 'audit_log' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) ),
				'request_id' => str_repeat( 'a', 32 ),
				'ability'    => 'super-abilities/audit-fixture',
				'outcome'    => 'ok',
			)
		);

		$this->assertCount( 1, $this->rows( 'super-abilities/audit-fixture' ) );

		$module = $this->module();

		$this->assertInstanceOf( Audit_Module::class, $module );

		$deleted = $module->pruner()->prune();

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertCount( 0, $this->rows( 'super-abilities/audit-fixture' ) );
	}

	/**
	 * Rows written for an ability since this test started.
	 *
	 * @param string $ability Fully namespaced ability name.
	 * @return array<int, array<string, mixed>>
	 */
	protected function rows( $ability ) {
		global $wpdb;

		$table = Install::table( 'audit_log' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE ability = %s AND id > %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ability,
				$this->baseline
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The highest audit row id currently stored.
	 *
	 * @return int
	 */
	protected function max_id() {
		global $wpdb;

		$table = Install::table( 'audit_log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM `{$table}`" );
	}

	/**
	 * The booted audit module.
	 *
	 * @return \SuperAbilities\Module_Interface|null
	 */
	protected function module() {
		return Plugin::instance()->modules()->get( 'audit' );
	}

	/**
	 * The booted listener.
	 *
	 * @return Listener|null
	 */
	protected function listener() {
		$module = $this->module();

		return $module instanceof Audit_Module ? $module->listener() : null;
	}

	/**
	 * Drops the abilities registry singleton so that `wp_abilities_api_init` fires again.
	 */
	protected static function reset_registry() {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return;
		}

		$property = new ReflectionProperty( 'WP_Abilities_Registry', 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}
}
