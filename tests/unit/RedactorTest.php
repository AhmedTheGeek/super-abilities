<?php
/**
 * Tests for the Redactor support class.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Support\Redactor;

class RedactorTest extends WP_UnitTestCase {

	public function test_secret_constant_values_are_masked() {
		$text = 'connecting as ' . DB_USER . ' with ' . AUTH_SALT;

		$redacted = Redactor::text( $text );

		$this->assertStringNotContainsString( AUTH_SALT, $redacted );
		$this->assertStringContainsString( Redactor::MASK, $redacted );
	}

	public function test_key_value_pairs_are_masked() {
		$redacted = Redactor::text( 'api_key=abc123 token: zzz999 password = hunter2' );

		$this->assertStringNotContainsString( 'abc123', $redacted );
		$this->assertStringNotContainsString( 'zzz999', $redacted );
		$this->assertStringNotContainsString( 'hunter2', $redacted );
	}

	public function test_abspath_is_replaced_with_a_token() {
		$redacted = Redactor::text( ABSPATH . 'wp-content/plugins/foo/foo.php' );

		$this->assertStringStartsWith( '{ABSPATH}', $redacted );
		$this->assertStringNotContainsString( untrailingslashit( ABSPATH ), $redacted );
	}

	public function test_emails_are_only_masked_on_request() {
		$this->assertStringContainsString( 'a@example.com', Redactor::text( 'mail a@example.com' ) );
		$this->assertStringNotContainsString( 'a@example.com', Redactor::text( 'mail a@example.com', true ) );
	}

	public function test_redact_walks_arrays_and_keeps_types() {
		$redacted = Redactor::redact(
			array(
				'n'      => 5,
				'flag'   => true,
				'nested' => array( 'secret=topsecretvalue' ),
			)
		);

		$this->assertSame( 5, $redacted['n'] );
		$this->assertTrue( $redacted['flag'] );
		$this->assertStringNotContainsString( 'topsecretvalue', $redacted['nested'][0] );
	}

	public function test_redact_debug_data_drops_private_fields() {
		$sections = array(
			'wp-core' => array(
				'label'  => 'WordPress',
				'fields' => array(
					'version' => array(
						'label' => 'Version',
						'value' => '6.9',
					),
					'dbpass'  => array(
						'label'   => 'Database password',
						'value'   => 'supersecret',
						'private' => true,
					),
				),
			),
		);

		$clean = Redactor::redact_debug_data( $sections );

		$this->assertArrayHasKey( 'version', $clean['wp-core']['fields'] );
		$this->assertArrayNotHasKey( 'dbpass', $clean['wp-core']['fields'] );
		$this->assertSame( 'WordPress', $clean['wp-core']['label'] );
	}
}
