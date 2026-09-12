<?php
/**
 * Tests for the Fingerprint support class.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Support\Fingerprint;

class FingerprintTest extends WP_UnitTestCase {

	public function test_of_string_uses_the_documented_format() {
		$fingerprint = Fingerprint::of_string( 'hello' );

		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $fingerprint );
		$this->assertSame( 'fp1:' . substr( hash( 'sha256', 'hello' ), 0, 20 ), $fingerprint );
	}

	public function test_of_string_is_stable_and_sensitive() {
		$this->assertSame( Fingerprint::of_string( 'a' ), Fingerprint::of_string( 'a' ) );
		$this->assertNotSame( Fingerprint::of_string( 'a' ), Fingerprint::of_string( 'b' ) );
	}

	public function test_of_array_ignores_key_order() {
		$one = Fingerprint::of_array(
			array(
				'b' => 2,
				'a' => 1,
			)
		);
		$two = Fingerprint::of_array(
			array(
				'a' => 1,
				'b' => 2,
			)
		);

		$this->assertSame( $one, $two );
	}

	public function test_of_array_ignores_nested_key_order() {
		$one = Fingerprint::of_array(
			array(
				'outer' => array(
					'z' => 1,
					'y' => 2,
				),
			)
		);
		$two = Fingerprint::of_array(
			array(
				'outer' => array(
					'y' => 2,
					'z' => 1,
				),
			)
		);

		$this->assertSame( $one, $two );
	}

	public function test_of_array_reacts_to_values() {
		$this->assertNotSame(
			Fingerprint::of_array( array( 'a' => 1 ) ),
			Fingerprint::of_array( array( 'a' => 2 ) )
		);
	}

	public function test_matches_tolerates_a_missing_prefix() {
		$fingerprint = Fingerprint::of_string( 'hello' );
		$bare        = substr( $fingerprint, strlen( 'fp1:' ) );

		$this->assertTrue( Fingerprint::matches( $fingerprint, $fingerprint ) );
		$this->assertTrue( Fingerprint::matches( $bare, $fingerprint ) );
		$this->assertTrue( Fingerprint::matches( ' ' . strtoupper( $bare ) . ' ', $fingerprint ) );
		$this->assertFalse( Fingerprint::matches( Fingerprint::of_string( 'other' ), $fingerprint ) );
		$this->assertFalse( Fingerprint::matches( '', $fingerprint ) );
	}
}
