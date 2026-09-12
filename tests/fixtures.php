<?php
/**
 * Test fixtures: a module that is off by default plus one ability inside it.
 *
 * Loaded before `plugins_loaded`, so the fixture module takes part in the real
 * registration pass and integration tests can assert on it.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abstract_Module;
use SuperAbilities\Support\Schema;

class SA_Fixture_Ability extends Abstract_Ability {

	public function slug() {
		return 'fixture-noop';
	}

	public function module() {
		return 'fixture';
	}

	public function label() {
		return 'Fixture noop';
	}

	public function description() {
		return 'Does nothing at all. Only used by the test suite.';
	}

	public function annotations() {
		return self::readonly();
	}

	public function capability() {
		return array( 'manage_options' );
	}

	public function input_schema() {
		return Schema::object( array() );
	}

	public function output_schema() {
		return Schema::object( array( 'ok' => array( 'type' => 'boolean' ) ), array( 'ok' ) );
	}

	public function execute( array $input ) {
		return array( 'ok' => true );
	}
}

class SA_Fixture_Module extends Abstract_Module {

	public function id() {
		return 'fixture';
	}

	public function label() {
		return 'Fixture';
	}

	public function description() {
		return 'Test only module.';
	}

	public function default_enabled() {
		return false;
	}

	public function risk() {
		return 'high';
	}

	public function abilities() {
		return array( SA_Fixture_Ability::class );
	}
}

add_filter(
	'super_abilities_modules',
	static function ( $modules, $plugin ) {
		$modules['fixture'] = new SA_Fixture_Module( $plugin );

		return $modules;
	},
	10,
	2
);
