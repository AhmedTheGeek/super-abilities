<?php
/**
 * Tests for the Time support class.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Support\Time;

class TimeTest extends WP_UnitTestCase {

	public function test_parse_accepts_unix_timestamps() {
		$this->assertSame( 1757664000, Time::parse( 1757664000 ) );
		$this->assertSame( 1757664000, Time::parse( '1757664000' ) );
	}

	public function test_parse_accepts_iso_8601() {
		$this->assertSame( strtotime( '2026-09-12T10:00:00+0000' ), Time::parse( '2026-09-12T10:00:00Z' ) );
	}

	public function test_parse_reads_mysql_datetimes_as_utc() {
		$this->assertSame( strtotime( '2026-09-12 10:00:00 UTC' ), Time::parse( '2026-09-12 10:00:00' ) );
		$this->assertSame( strtotime( '2026-09-12 00:00:00 UTC' ), Time::parse( '2026-09-12' ) );
	}

	public function test_parse_accepts_relative_expressions() {
		$parsed = Time::parse( '-24 hours' );

		$this->assertNotNull( $parsed );
		$this->assertLessThan( 5, abs( ( time() - DAY_IN_SECONDS ) - $parsed ) );
	}

	public function test_parse_rejects_garbage() {
		$this->assertNull( Time::parse( 'not a date at all' ) );
		$this->assertNull( Time::parse( '' ) );
		$this->assertNull( Time::parse( null ) );
		$this->assertNull( Time::parse( array() ) );
	}

	public function test_iso_is_utc() {
		$this->assertSame( '2026-09-12T10:00:00Z', Time::iso( strtotime( '2026-09-12 10:00:00 UTC' ) ) );
	}

	public function test_mysql_is_utc() {
		$this->assertSame( '2026-09-12 10:00:00', Time::mysql( strtotime( '2026-09-12 10:00:00 UTC' ) ) );
	}

	public function test_iso_from_mysql_round_trips() {
		$this->assertSame( '2026-09-12T10:00:00Z', Time::iso_from_mysql( '2026-09-12 10:00:00' ) );
		$this->assertSame( '', Time::iso_from_mysql( '' ) );
	}
}
