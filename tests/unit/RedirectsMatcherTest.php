<?php
/**
 * Tests for the pure request matcher and the runtime decision.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Redirects\Matcher;
use SuperAbilities\Redirects\Runtime;

class RedirectsMatcherTest extends WP_UnitTestCase {

	/**
	 * Builds a rule row the way the store would hand it to the matcher.
	 */
	private function rule( $id, $source, $target, $status = 301, $match_query = 0, $enabled = 1 ) {
		return array(
			'id'          => $id,
			'source'      => $source,
			'target'      => $target,
			'status'      => $status,
			'match_query' => $match_query,
			'enabled'     => $enabled,
		);
	}

	public function test_match_finds_a_plain_source() {
		$rules = array( $this->rule( 1, '/old', '/new' ) );

		$matched = Matcher::match( '/Old/', '', $rules );

		$this->assertIsArray( $matched );
		$this->assertSame( 1, $matched['id'] );
	}

	public function test_match_ignores_the_query_when_match_query_is_off() {
		$rules = array( $this->rule( 1, '/old', '/new' ) );

		$this->assertIsArray( Matcher::match( '/old', 'ref=news', $rules ) );
	}

	public function test_match_requires_the_query_when_match_query_is_on() {
		$rules = array( $this->rule( 1, '/old?ref=news', '/new', 301, 1 ) );

		$this->assertIsArray( Matcher::match( '/old', 'ref=news', $rules ) );
		$this->assertIsArray( Matcher::match( '/old', 'REF=news', $rules ) );
		$this->assertNull( Matcher::match( '/old', 'ref=other', $rules ) );
		$this->assertNull( Matcher::match( '/old', '', $rules ) );
	}

	public function test_match_ignores_disabled_rules() {
		$rules = array( $this->rule( 1, '/old', '/new', 301, 0, 0 ) );

		$this->assertNull( Matcher::match( '/old', '', $rules ) );
	}

	public function test_match_returns_null_for_an_unknown_path() {
		$this->assertNull( Matcher::match( '/nothing', '', array( $this->rule( 1, '/old', '/new' ) ) ) );
		$this->assertNull( Matcher::match( '', '', array( $this->rule( 1, '/old', '/new' ) ) ) );
	}

	public function test_resolve_handles_a_relative_target() {
		$resolved = Matcher::resolve( $this->rule( 1, '/old', '/new', 302 ) );

		$this->assertSame( 302, $resolved['status'] );
		$this->assertSame( '/new', $resolved['target'] );
		$this->assertFalse( $resolved['gone'] );
		$this->assertFalse( $resolved['external'] );
		$this->assertTrue( $resolved['safe'] );
	}

	public function test_resolve_marks_an_external_target_unsafe() {
		$resolved = Matcher::resolve( $this->rule( 1, '/go', 'https://8.8.8.8/landing' ) );

		$this->assertTrue( $resolved['external'] );
		$this->assertFalse( $resolved['safe'] );
	}

	public function test_resolve_handles_410() {
		$resolved = Matcher::resolve( $this->rule( 1, '/gone', '', 410 ) );

		$this->assertTrue( $resolved['gone'] );
		$this->assertSame( 410, $resolved['status'] );
		$this->assertSame( '', $resolved['target'] );
	}

	public function test_resolve_falls_back_to_301_for_a_broken_status() {
		$this->assertSame( 301, Matcher::resolve( $this->rule( 1, '/a', '/b', 999 ) )['status'] );
	}

	public function test_decide_returns_the_target_and_status() {
		$rules = array( $this->rule( 5, '/old', '/new', 308 ) );

		$decision = Runtime::decide( '/old', '', $rules );

		$this->assertIsArray( $decision );
		$this->assertSame( 5, $decision['rule_id'] );
		$this->assertSame( 308, $decision['status'] );
		$this->assertSame( '/new', $decision['target'] );
		$this->assertTrue( $decision['safe'] );
	}

	public function test_decide_returns_null_when_nothing_matches() {
		$this->assertNull( Runtime::decide( '/nothing', '', array( $this->rule( 1, '/old', '/new' ) ) ) );
	}

	public function test_decide_skips_a_rule_that_would_redirect_to_itself() {
		$rules = array( $this->rule( 1, '/old', '/old?ref=1' ) );

		$this->assertNull( Runtime::decide( '/old', '', $rules ) );
	}

	public function test_decide_skips_a_rule_with_an_empty_target() {
		$this->assertNull( Runtime::decide( '/old', '', array( $this->rule( 1, '/old', '' ) ) ) );
	}

	public function test_decide_answers_410_without_a_target() {
		$decision = Runtime::decide( '/gone', '', array( $this->rule( 1, '/gone', '', 410 ) ) );

		$this->assertIsArray( $decision );
		$this->assertTrue( $decision['gone'] );
		$this->assertSame( 410, $decision['status'] );
	}

	public function test_decide_marks_an_external_target_as_unsafe() {
		$rules = array( $this->rule( 1, '/go', 'https://8.8.8.8/landing' ) );

		$decision = Runtime::decide( '/go', '', $rules );

		$this->assertIsArray( $decision );
		$this->assertTrue( $decision['external'] );
		$this->assertFalse( $decision['safe'] );
	}

	public function test_request_uri_reads_the_server_value() {
		$before = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

		$_SERVER['REQUEST_URI'] = '/old-page/?ref=news';

		$this->assertSame( '/old-page/?ref=news', Runtime::request_uri() );

		if ( null === $before ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $before;
		}
	}

	public function test_is_front_end_request_is_false_in_the_admin() {
		set_current_screen( 'dashboard' );

		$this->assertFalse( Runtime::is_front_end_request() );

		set_current_screen( 'front' );

		$this->assertTrue( Runtime::is_front_end_request() );
	}
}
