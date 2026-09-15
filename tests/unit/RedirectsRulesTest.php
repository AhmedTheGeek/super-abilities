<?php
/**
 * Tests for the pure redirect rules: normalization, guards and loop detection.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Redirects\Rules;

class RedirectsRulesTest extends WP_UnitTestCase {

	/**
	 * Builds a rule row the way the store would hand it to the rules.
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

	public function test_normalize_path_lowercases_and_trims() {
		$this->assertSame( '/old-page', Rules::normalize_path( '/Old-Page/' ) );
		$this->assertSame( '/old-page', Rules::normalize_path( 'old-page' ) );
		$this->assertSame( '/a/b', Rules::normalize_path( '//a//b//' ) );
		$this->assertSame( '/a/b', Rules::normalize_path( 'https://example.com/A/B/' ) );
		$this->assertSame( '/a', Rules::normalize_path( '/a?x=1' ) );
		$this->assertSame( '/', Rules::normalize_path( '/' ) );
		$this->assertSame( '', Rules::normalize_path( '' ) );
		$this->assertSame( '', Rules::normalize_path( '?x=1' ) );
	}

	public function test_normalize_query_sorts_and_lowercases() {
		$this->assertSame( 'a=1&b=2', Rules::normalize_query( '?B=2&a=1' ) );
		$this->assertSame( 'a=1&b=2', Rules::normalize_query( 'a=1&b=2' ) );
		$this->assertSame( 'a=1', Rules::normalize_query( '&a=1&' ) );
		$this->assertSame( '', Rules::normalize_query( '' ) );
	}

	public function test_split_separates_path_and_query() {
		$this->assertSame(
			array(
				'path'  => '/old',
				'query' => 'ref=news',
			),
			Rules::split( 'https://example.com/Old/?ref=news' )
		);
	}

	public function test_normalize_source_keeps_the_query_only_when_asked() {
		$this->assertSame( '/old', Rules::normalize_source( '/old?a=1', false ) );
		$this->assertSame( '/old?a=1', Rules::normalize_source( '/old?a=1', true ) );
		$this->assertSame( '/old', Rules::normalize_source( '/old', true ) );
	}

	public function test_reserved_paths_cover_core_entry_points() {
		$reserved = array(
			'/',
			'/wp-admin',
			'/wp-admin/edit.php',
			'/wp-login.php',
			'/wp-json',
			'/wp-json/wp/v2/posts',
			'/wp-content',
			'/wp-content/uploads/a.png',
			'/wp-includes',
			'/xmlrpc.php',
			'/wp-cron.php',
			'/wp-signup.php',
			'/wp-activate.php',
			'/wp-comments-post.php',
			'/feed',
			'/feed/atom',
			'/.well-known',
			'/.well-known/acme-challenge/x',
			'/robots.txt',
			'/sitemap.xml',
			'/wp-sitemap.xml',
			'/wp-sitemap-posts-post-1.xml',
		);

		foreach ( $reserved as $path ) {
			$this->assertTrue( Rules::is_reserved( $path ), $path . ' should be reserved.' );
		}

		$this->assertFalse( Rules::is_reserved( '/old-page' ) );
		$this->assertFalse( Rules::is_reserved( '/feeds-and-such' ) );
		$this->assertFalse( Rules::is_reserved( '/robots.txt.bak' ) );
	}

	public function test_reserved_paths_include_the_rest_prefix() {
		$this->assertTrue( Rules::is_reserved( '/' . rest_get_url_prefix() ) );
	}

	public function test_reserved_paths_are_filterable() {
		add_filter(
			'super_abilities_reserved_redirect_paths',
			static function ( $paths ) {
				$paths[] = '/shop';

				return $paths;
			}
		);

		$this->assertTrue( Rules::is_reserved( '/shop' ) );
		$this->assertTrue( Rules::is_reserved( '/shop/cart' ) );
		$this->assertSame( '/shop', Rules::reserved_match( '/Shop/Cart' ) );
	}

	public function test_validate_status_accepts_only_the_five() {
		foreach ( array( 301, 302, 307, 308, 410 ) as $status ) {
			$this->assertSame( $status, Rules::validate_status( $status ) );
		}

		$this->assertWPError( Rules::validate_status( 303 ) );
		$this->assertWPError( Rules::validate_status( 200 ) );
	}

	public function test_validate_target_accepts_relative_paths() {
		$target = Rules::validate_target( '/new-page' );

		$this->assertSame( '/new-page', $target['target'] );
		$this->assertFalse( $target['external'] );
	}

	public function test_validate_target_rejects_dangerous_schemes() {
		foreach ( array( 'javascript:alert(1)', 'data:text/html;base64,x', '//evil.example', 'ftp://example.com/x', 'mailto:a@b.c' ) as $bad ) {
			$error = Rules::validate_target( $bad, true );

			$this->assertWPError( $error, $bad . ' should be refused.' );
			$this->assertSame( 'super_abilities_invalid_target', $error->get_error_code() );
		}

		$this->assertWPError( Rules::validate_target( '' ) );
	}

	public function test_validate_target_gates_external_hosts() {
		$error = Rules::validate_target( 'https://8.8.8.8/landing' );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_external_target', $error->get_error_code() );

		$allowed = Rules::validate_target( 'https://8.8.8.8/landing', true );

		$this->assertTrue( $allowed['external'] );
		$this->assertSame( '8.8.8.8', $allowed['host'] );
	}

	public function test_validate_target_keeps_same_host_urls_internal() {
		$target = Rules::validate_target( home_url( '/new-page' ) );

		$this->assertFalse( $target['external'] );
	}

	public function test_login_url_is_allowed_as_a_target() {
		$target = Rules::validate_target( '/wp-login.php' );

		$this->assertSame( '/wp-login.php', $target['target'] );
	}

	public function test_preflight_refuses_a_reserved_source() {
		$checked = Rules::preflight(
			array(
				'source' => '/wp-admin/users.php',
				'target' => '/team',
			)
		);

		$this->assertWPError( $checked['error'] );
		$this->assertSame( 'super_abilities_reserved_path', $checked['error']->get_error_code() );
	}

	public function test_preflight_refuses_an_immediate_loop() {
		$checked = Rules::preflight(
			array(
				'source' => '/loop',
				'target' => '/loop',
			)
		);

		$this->assertWPError( $checked['error'] );
		$this->assertSame( 'super_abilities_loop_detected', $checked['error']->get_error_code() );
	}

	public function test_preflight_refuses_a_duplicate_source() {
		$rules = array( $this->rule( 7, '/old', '/new' ) );

		$checked = Rules::preflight(
			array(
				'source' => '/Old/',
				'target' => '/other',
			),
			array(),
			$rules
		);

		$this->assertWPError( $checked['error'] );
		$this->assertSame( 'super_abilities_already_exists', $checked['error']->get_error_code() );
		$this->assertSame( 7, $checked['error']->get_error_data()['existing_id'] );
	}

	public function test_preflight_allows_the_same_source_with_a_different_match_query() {
		$rules = array( $this->rule( 7, '/old', '/new' ) );

		$checked = Rules::preflight(
			array(
				'source'      => '/old?ref=news',
				'target'      => '/other',
				'match_query' => true,
			),
			array(),
			$rules
		);

		$this->assertNull( $checked['error'] );
		$this->assertSame( '/old?ref=news', $checked['source'] );
		$this->assertSame( 1, $checked['match_query'] );
	}

	public function test_preflight_requires_an_empty_target_for_410() {
		$gone = Rules::preflight(
			array(
				'source' => '/gone',
				'target' => '',
				'status' => 410,
			)
		);

		$this->assertNull( $gone['error'] );
		$this->assertSame( '', $gone['target'] );

		$bad = Rules::preflight(
			array(
				'source' => '/gone',
				'target' => '/somewhere',
				'status' => 410,
			)
		);

		$this->assertWPError( $bad['error'] );
	}

	public function test_preflight_detects_a_three_rule_cycle() {
		$rules = array(
			$this->rule( 1, '/b', '/c' ),
			$this->rule( 2, '/c', '/a' ),
		);

		$checked = Rules::preflight(
			array(
				'source' => '/a',
				'target' => '/b',
			),
			array(),
			$rules
		);

		$this->assertWPError( $checked['error'] );
		$this->assertSame( 'super_abilities_loop_detected', $checked['error']->get_error_code() );
	}

	public function test_preflight_allows_a_chain_but_warns_about_it() {
		$rules = array( $this->rule( 1, '/b', '/c' ) );

		$checked = Rules::preflight(
			array(
				'source' => '/a',
				'target' => '/b',
			),
			array(),
			$rules
		);

		$this->assertNull( $checked['error'] );
		$this->assertSame( 2, $checked['chain_length'] );
		$this->assertSame( '/c', $checked['resolves_to'] );
		$this->assertNotEmpty( $checked['warnings'] );
	}

	public function test_preflight_warns_when_the_new_source_is_an_existing_target() {
		$rules = array( $this->rule( 1, '/x', '/a' ) );

		$checked = Rules::preflight(
			array(
				'source' => '/a',
				'target' => '/b',
			),
			array(),
			$rules
		);

		$this->assertNull( $checked['error'] );
		$this->assertNotEmpty( $checked['warnings'] );
	}

	public function test_preflight_gates_external_targets() {
		$blocked = Rules::preflight(
			array(
				'source' => '/go',
				'target' => 'https://8.8.8.8/',
			)
		);

		$this->assertWPError( $blocked['error'] );

		$allowed = Rules::preflight(
			array(
				'source' => '/go',
				'target' => 'https://8.8.8.8/',
			),
			array( 'allow_external' => true )
		);

		$this->assertNull( $allowed['error'] );
		$this->assertTrue( $allowed['external'] );
	}

	public function test_preflight_refuses_a_source_that_is_published_content() {
		$this->set_permalink_structure( '/%postname%/' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'about-us',
				'post_status' => 'publish',
			)
		);

		$path = Rules::normalize_path( (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH ) );

		$checked = Rules::preflight(
			array(
				'source' => $path,
				'target' => '/team',
			)
		);

		$this->assertWPError( $checked['error'] );
		$this->assertSame( 'super_abilities_source_is_content', $checked['error']->get_error_code() );
		$this->assertSame( $page_id, $checked['error']->get_error_data()['object_id'] );

		$override = Rules::preflight(
			array(
				'source' => $path,
				'target' => '/team',
			),
			array( 'allow_existing_content' => true )
		);

		$this->assertNull( $override['error'] );
	}

	public function test_follow_reports_the_whole_chain() {
		$rules = array(
			$this->rule( 1, '/a', '/b' ),
			$this->rule( 2, '/b', '/c' ),
			$this->rule( 3, '/c', 'https://8.8.8.8/end' ),
		);

		$followed = Rules::follow( '/a', '', $rules );

		$this->assertSame( 3, $followed['chain_length'] );
		$this->assertSame( 'https://8.8.8.8/end', $followed['resolves_to'] );
		$this->assertFalse( $followed['loop'] );
	}

	public function test_follow_marks_an_existing_cycle() {
		$rules = array(
			$this->rule( 1, '/a', '/b' ),
			$this->rule( 2, '/b', '/a' ),
		);

		$followed = Rules::follow( '/a', '', $rules );

		$this->assertTrue( $followed['loop'] );
		$this->assertSame( '/a', $followed['loop_at'] );
	}

	public function test_follow_stops_at_a_410() {
		$rules = array(
			$this->rule( 1, '/a', '/b' ),
			$this->rule( 2, '/b', '', 410 ),
		);

		$followed = Rules::follow( '/a', '', $rules );

		$this->assertSame( 2, $followed['chain_length'] );
		$this->assertSame( '', $followed['resolves_to'] );
	}

	public function test_follow_ignores_disabled_rules() {
		$rules = array( $this->rule( 1, '/a', '/b', 301, 0, 0 ) );

		$this->assertSame( 0, Rules::follow( '/a', '', $rules )['chain_length'] );
	}

	public function test_without_drops_only_the_named_rule() {
		$rules = array(
			$this->rule( 1, '/a', '/b' ),
			$this->rule( 2, '/c', '/d' ),
		);

		$this->assertCount( 1, Rules::without( $rules, 1 ) );
		$this->assertCount( 2, Rules::without( $rules, 0 ) );
	}

	public function test_preflight_refuses_a_source_longer_than_the_column() {
		$checked = Rules::preflight(
			array(
				'source' => '/' . str_repeat( 'a', 200 ),
				'target' => '/short',
			)
		);

		$this->assertWPError( $checked['error'] );
		$this->assertSame( 'super_abilities_invalid_input', $checked['error']->get_error_code() );
	}
}
