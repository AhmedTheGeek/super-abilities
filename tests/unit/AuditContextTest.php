<?php
/**
 * Tests for the audit context and the pure pieces of the logger.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Audit\Audit_Query;
use SuperAbilities\Abilities\Audit\Audit_Summary;
use SuperAbilities\Audit\Context;
use SuperAbilities\Audit\Logger;
use SuperAbilities\Plugin;

class AuditContextTest extends WP_UnitTestCase {

	/**
	 * Server globals this class overwrites, saved so they can be put back.
	 *
	 * @var array<string, mixed>
	 */
	protected $server = array();

	public function set_up() {
		parent::set_up();

		Context::reset();

		foreach ( array( 'HTTP_USER_AGENT', 'REQUEST_URI', 'REMOTE_ADDR' ) as $key ) {
			$this->server[ $key ] = isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : null;
		}
	}

	public function tear_down() {
		Context::reset();
		Plugin::instance()->options()->set( 'audit_store_ip', true );

		foreach ( $this->server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		parent::tear_down();
	}

	public function test_request_id_is_32_hex_and_stable() {
		$first = Context::request_id();

		$this->assertSame( 32, strlen( $first ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $first );
		$this->assertSame( $first, Context::request_id() );
	}

	public function test_request_id_changes_after_reset() {
		$first = Context::request_id();

		Context::reset();

		$this->assertNotSame( $first, Context::request_id() );
	}

	public function test_transport_defaults_to_internal() {
		$_SERVER['REQUEST_URI'] = '/wp-admin/index.php';

		$this->assertSame( 'internal', Context::transport() );
	}

	public function test_transport_detects_the_abilities_rest_namespace() {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp-abilities/v1/abilities/super-abilities/catalog/run';

		$this->assertSame( 'rest', Context::transport() );
	}

	public function test_transport_detects_mcp() {
		$_SERVER['REQUEST_URI'] = '/wp-json/some-plugin/mcp';

		$this->assertSame( 'mcp', Context::transport() );
	}

	public function test_job_context_wins_over_every_other_transport() {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp-abilities/v1/abilities/x/y/run';

		do_action(
			'super_abilities_job_context',
			array(
				'job_id'            => 12,
				'uuid'              => 'ec0f4c9a-0000-4000-8000-000000000000',
				'user_id'           => 7,
				'app_password_uuid' => 'aaaaaaaa-0000-4000-8000-000000000000',
			)
		);

		$this->assertSame( 'job', Context::transport() );
		$this->assertSame( 12, Context::job_id() );
		$this->assertSame( 7, Context::user_id() );
		$this->assertSame( 'job:ec0f4c9a-0000-4000-8000-000000000000', Context::client() );
		$this->assertSame( 'aaaaaaaa-0000-4000-8000-000000000000', Context::app_password_uuid() );

		do_action( 'super_abilities_job_context_end' );

		$this->assertSame( 'rest', Context::transport() );
		$this->assertSame( 0, Context::job_id() );
	}

	public function test_client_is_the_truncated_user_agent() {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 300 );

		$this->assertSame( 191, strlen( Context::client() ) );
	}

	public function test_ip_is_filterable_and_can_be_switched_off() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$this->assertSame( '203.0.113.7', Context::ip() );

		add_filter( 'super_abilities_audit_ip', static fn () => '198.51.100.9' );

		$this->assertSame( '198.51.100.9', Context::ip() );

		Plugin::instance()->options()->set( 'audit_store_ip', false );

		$this->assertSame( '', Context::ip() );
	}

	public function test_noted_objects_are_kept_per_ability() {
		Context::note_object( 'super-abilities/a', 'post', 41 );
		Context::note_object( 'super-abilities/a', 'post', 42 );
		Context::note_object( 'super-abilities/b', 'plugin', 'akismet/akismet.php' );

		$this->assertSame(
			array(
				'type' => 'post',
				'id'   => '42',
			),
			Context::object_for( 'super-abilities/a' )
		);
		$this->assertSame(
			array(
				'type' => 'plugin',
				'id'   => 'akismet/akismet.php',
			),
			Context::object_for( 'super-abilities/b' )
		);
		$this->assertNull( Context::object_for( 'super-abilities/c' ) );
	}

	public function test_note_error_round_trips() {
		$this->assertSame( '', Context::error_for( 'super-abilities/a' ) );

		Context::note_error( 'super-abilities/a', 'super_abilities_not_found' );

		$this->assertSame( 'super_abilities_not_found', Context::error_for( 'super-abilities/a' ) );

		Context::clear_error( 'super-abilities/a' );

		$this->assertSame( '', Context::error_for( 'super-abilities/a' ) );
	}

	public function test_written_rows_are_tracked_by_name_and_input() {
		$this->assertFalse( Context::was_written( 'super-abilities/a', array( 'id' => 1 ) ) );
		$this->assertFalse( Context::has_rows_for( 'super-abilities/a' ) );

		Context::mark_written( 'super-abilities/a', array( 'id' => 1 ) );

		$this->assertTrue( Context::was_written( 'super-abilities/a', array( 'id' => 1 ) ) );
		$this->assertFalse( Context::was_written( 'super-abilities/a', array( 'id' => 2 ) ) );
		$this->assertTrue( Context::has_rows_for( 'super-abilities/a' ) );
	}

	public function test_denied_calls_are_tracked_separately() {
		$this->assertFalse( Context::was_denied( 'super-abilities/a', array( 'id' => 1 ) ) );

		Context::mark_written( 'super-abilities/a', array( 'id' => 1 ) );

		$this->assertFalse( Context::was_denied( 'super-abilities/a', array( 'id' => 1 ) ) );

		Context::mark_denied( 'super-abilities/a', array( 'id' => 1 ) );

		$this->assertTrue( Context::was_denied( 'super-abilities/a', array( 'id' => 1 ) ) );
		$this->assertFalse( Context::was_denied( 'super-abilities/a', array( 'id' => 2 ) ) );
	}

	public function test_input_keys_stores_only_top_level_keys() {
		$encoded = Logger::input_keys(
			array(
				'post_id' => 42,
				'ops'     => array( array( 'op' => 'insert' ) ),
				'secret'  => 'hunter2',
			)
		);

		$this->assertSame( array( 'post_id', 'ops', 'secret' ), json_decode( $encoded, true ) );
		$this->assertStringNotContainsString( 'hunter2', $encoded );
		$this->assertStringNotContainsString( 'insert', $encoded );
	}

	public function test_extract_object_uses_the_first_matching_key() {
		$this->assertSame(
			array(
				'type' => 'post',
				'id'   => '42',
			),
			Logger::extract_object(
				'super-abilities/blocks-get',
				array(
					'post_id' => 42,
					'depth'   => 2,
				)
			)
		);

		$this->assertSame(
			array(
				'type' => 'plugin',
				'id'   => 'akismet/akismet.php',
			),
			Logger::extract_object( 'super-abilities/x', array( 'plugin' => 'akismet/akismet.php' ) )
		);

		$this->assertSame(
			array(
				'type' => 'object',
				'id'   => '9',
			),
			Logger::extract_object(
				'super-abilities/x',
				array(
					'id'      => 9,
					'post_id' => 3,
				)
			)
		);

		$this->assertNull( Logger::extract_object( 'super-abilities/x', array( 'page' => 2 ) ) );
	}

	public function test_extract_object_is_filterable() {
		add_filter(
			'super_abilities_audit_object',
			static function ( $guess, $ability, $input ) {
				return array(
					'type' => 'custom',
					'id'   => $ability . ':' . count( $input ),
				);
			},
			10,
			3
		);

		$this->assertSame(
			array(
				'type' => 'custom',
				'id'   => 'super-abilities/x:1',
			),
			Logger::extract_object( 'super-abilities/x', array( 'page' => 2 ) )
		);
	}

	public function test_decode_input_keys_tolerates_garbage() {
		$this->assertSame( array(), Audit_Query::decode_input_keys( '' ) );
		$this->assertSame( array(), Audit_Query::decode_input_keys( 'not json' ) );
		$this->assertSame( array(), Audit_Query::decode_input_keys( null ) );
		$this->assertSame( array( 'a', 'b' ), Audit_Query::decode_input_keys( '["a","b"]' ) );
	}

	public function test_percentile_uses_nearest_rank() {
		$this->assertSame( 0, Audit_Summary::percentile( array(), 95 ) );
		$this->assertSame( 5, Audit_Summary::percentile( array( 5 ), 95 ) );
		$this->assertSame( 100, Audit_Summary::percentile( range( 1, 100 ), 100 ) );
		$this->assertSame( 95, Audit_Summary::percentile( range( 1, 100 ), 95 ) );
		$this->assertSame( 50, Audit_Summary::percentile( range( 1, 100 ), 50 ) );
		$this->assertSame( 9, Audit_Summary::percentile( array( 9, 1, 4, 3, 2 ), 95 ) );
	}

	public function test_should_log_honours_the_third_party_setting() {
		$options = Plugin::instance()->options();
		$logger  = new Logger( $options );

		$this->assertTrue( $logger->should_log( 'super-abilities/audit-query' ) );
		$this->assertTrue( $logger->should_log( 'core/read-settings' ) );
		$this->assertTrue( $logger->should_log( 'event/plugin-activated' ) );
		$this->assertFalse( $logger->should_log( '' ) );

		$options->set( 'audit_third_party', false );

		$logger = new Logger( $options );

		$this->assertTrue( $logger->should_log( 'super-abilities/audit-query' ) );
		$this->assertTrue( $logger->should_log( 'event/plugin-activated' ) );
		$this->assertFalse( $logger->should_log( 'core/read-settings' ) );

		$options->set( 'audit_third_party', true );
	}
}
