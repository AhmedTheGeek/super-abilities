<?php
/**
 * Tests for ability and category registration.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Catalog_Ability;
use SuperAbilities\Plugin;

class RegistrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Touching the registry fires wp_abilities_api_init, which runs our Registrar.
		wp_get_abilities();
	}

	public function test_catalog_ability_is_registered() {
		$this->assertTrue( wp_has_ability( 'super-abilities/catalog' ) );

		$ability = wp_get_ability( 'super-abilities/catalog' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'super-abilities-core', $ability->get_category() );
		$this->assertTrue( wp_has_ability_category( 'super-abilities-core' ) );

		$meta = $ability->get_meta();

		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['public'] );
		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertSame( 'core', $meta['super_abilities']['module'] );
	}

	public function test_disabled_module_abilities_are_not_registered() {
		$this->assertFalse( Plugin::instance()->options()->is_module_enabled( 'fixture' ) );
		$this->assertFalse( wp_has_ability( 'super-abilities/fixture-noop' ) );
		$this->assertFalse( wp_has_ability_category( 'super-abilities-fixture' ) );
	}

	public function test_catalog_lists_disabled_modules() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( 'super-abilities/catalog' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( SUPER_ABILITIES_VERSION, $result['version'] );
		$this->assertContains( $result['abilities_api_level'], array( '6.9', '7.1' ) );

		$by_id = wp_list_pluck( $result['modules'], 'enabled', 'id' );

		$this->assertArrayHasKey( 'core', $by_id );
		$this->assertTrue( $by_id['core'] );
		$this->assertArrayHasKey( 'fixture', $by_id );
		$this->assertFalse( $by_id['fixture'] );

		foreach ( $result['modules'] as $module ) {
			if ( 'fixture' === $module['id'] ) {
				$this->assertSame( 'super-abilities/fixture-noop', $module['abilities'][0]['name'] );
				$this->assertSame( array( 'manage_options' ), $module['abilities'][0]['capability'] );
				$this->assertSame( 'Does nothing at all.', $module['abilities'][0]['summary'] );
			}
		}
	}

	public function test_anonymous_caller_is_denied_with_403() {
		wp_set_current_user( 0 );

		$denials = array();

		add_action(
			'super_abilities_permission_denied',
			static function ( $name, $input, $code ) use ( &$denials ) {
				$denials[] = array( $name, $code );
			},
			10,
			3
		);

		$ability = new Catalog_Ability();
		$result  = $ability->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'not_logged_in', $result->get_error_data()['reason'] );
		$this->assertSame( array( array( 'super-abilities/catalog', 'not_logged_in' ) ), $denials );
	}

	public function test_subscriber_is_denied_a_manage_options_ability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ( new SA_Fixture_Ability() )->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'insufficient_capability', $result->get_error_data()['reason'] );
		$this->assertSame( 'manage_options', $result->get_error_data()['required_capability'] );
	}

	public function test_run_wraps_exceptions() {
		$ability = new class() extends SA_Fixture_Ability {
			public function execute( array $input ) {
				throw new RuntimeException( 'boom' );
			}
		};

		$result = $ability->run( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_exception', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
