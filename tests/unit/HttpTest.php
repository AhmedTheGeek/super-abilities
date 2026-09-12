<?php
/**
 * Tests for the guarded HTTP support class.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Support\Http;

class HttpTest extends WP_UnitTestCase {

	public function test_https_urls_pass() {
		$this->assertSame( 'https://downloads.wordpress.org/plugin/hello.zip', Http::validate_url( 'https://downloads.wordpress.org/plugin/hello.zip' ) );
	}

	public function test_plain_http_is_rejected_by_default() {
		$result = Http::validate_url( 'http://example.com/a.zip' );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_plain_http_can_be_allowed() {
		$this->assertSame(
			'http://example.com/a.zip',
			Http::validate_url( 'http://example.com/a.zip', array( 'allow_http' => true ) )
		);
	}

	public function test_non_http_schemes_are_rejected() {
		$this->assertWPError( Http::validate_url( 'file:///etc/passwd' ) );
		$this->assertWPError( Http::validate_url( 'ftp://example.com/a.zip' ) );
		$this->assertWPError( Http::validate_url( '' ) );
	}

	public function test_loopback_and_private_hosts_are_rejected() {
		$this->assertWPError( Http::validate_url( 'https://127.0.0.1/a.zip' ) );
		$this->assertWPError( Http::validate_url( 'https://192.168.1.10/a.zip' ) );
		$this->assertWPError( Http::validate_url( 'https://10.0.0.5/a.zip' ) );
	}

	public function test_host_allowlist_is_enforced() {
		$args = array( 'hosts' => array( 'downloads.wordpress.org' ) );

		$this->assertSame(
			'https://downloads.wordpress.org/a.zip',
			Http::validate_url( 'https://downloads.wordpress.org/a.zip', $args )
		);
		$this->assertWPError( Http::validate_url( 'https://evil.example.com/a.zip', $args ) );
	}

	public function test_host_allowlist_matches_subdomains() {
		$args = array( 'hosts' => array( 'wordpress.org' ) );

		$this->assertSame(
			'https://downloads.wordpress.org/a.zip',
			Http::validate_url( 'https://downloads.wordpress.org/a.zip', $args )
		);
		$this->assertWPError( Http::validate_url( 'https://notwordpress.org/a.zip', $args ) );
	}

	public function test_user_agent_names_the_plugin() {
		$this->assertStringStartsWith( 'SuperAbilities/' . SUPER_ABILITIES_VERSION, Http::user_agent() );
	}
}
