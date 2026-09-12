<?php
/**
 * Tests for the debug log parser.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Health\Debug_Log_Parser;

class DebugLogParserTest extends WP_UnitTestCase {

	/**
	 * A realistic log slice: a warning, a fatal error with a stack trace, a
	 * deprecation, a notice inside a theme, a non-PHP line and a repeat.
	 */
	private function sample() {
		return implode(
			"\n",
			array(
				'[12-Sep-2026 10:00:00 UTC] PHP Warning:  Undefined variable $foo in /var/www/html/wp-content/plugins/foo/foo.php on line 12',
				'[12-Sep-2026 10:00:01 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function bar() in /var/www/html/wp-content/plugins/foo/foo.php:12',
				'Stack trace:',
				"#0 /var/www/html/wp-includes/class-wp-hook.php(324): foo_init('')",
				'#1 {main}',
				'  thrown in /var/www/html/wp-content/plugins/foo/foo.php on line 12',
				'[12-Sep-2026 10:00:02 UTC] PHP Deprecated:  Function wp_old() is deprecated since version 6.0! in /var/www/html/wp-includes/functions.php on line 5453',
				'[12-Sep-2026 10:00:03 UTC] PHP Notice:  Trying to access array offset in /var/www/html/wp-content/themes/twentytwenty/functions.php on line 3',
				'[12-Sep-2026 10:00:04 UTC] Some random non-PHP line written by a plugin',
				'[12-Sep-2026 10:00:05 UTC] PHP Warning:  Undefined variable $foo in /var/www/html/wp-content/plugins/foo/foo.php on line 12',
				'Plain line without a timestamp at all',
			)
		);
	}

	public function test_empty_input_gives_no_entries() {
		$this->assertSame( array(), Debug_Log_Parser::parse( '' ) );
		$this->assertSame( array(), Debug_Log_Parser::parse( "\n  \n" ) );
	}

	public function test_stack_traces_stay_with_their_entry() {
		$entries = Debug_Log_Parser::parse( $this->sample() );

		$this->assertCount( 7, $entries );

		$fatal = $entries[1];

		$this->assertSame( 'fatal', $fatal['level'] );
		$this->assertStringContainsString( 'Uncaught Error: Call to undefined function bar()', $fatal['message'] );
		$this->assertStringContainsString( 'Stack trace:', $fatal['raw_excerpt'] );
		$this->assertStringContainsString( '#1 {main}', $fatal['raw_excerpt'] );
		$this->assertStringNotContainsString( 'Stack trace:', $fatal['message'] );
	}

	public function test_file_and_line_are_read_from_both_notations() {
		$entries = Debug_Log_Parser::parse( $this->sample() );

		// "in file.php on line 12".
		$this->assertSame( '/var/www/html/wp-content/plugins/foo/foo.php', $entries[0]['file'] );
		$this->assertSame( 12, $entries[0]['line'] );

		// "in file.php:12".
		$this->assertSame( '/var/www/html/wp-content/plugins/foo/foo.php', $entries[1]['file'] );
		$this->assertSame( 12, $entries[1]['line'] );
	}

	public function test_timestamps_become_iso_utc() {
		$entries = Debug_Log_Parser::parse( $this->sample() );

		$this->assertSame( '2026-09-12T10:00:00Z', $entries[0]['time'] );
		$this->assertNull( $entries[6]['time'] );
	}

	public function test_levels_are_classified() {
		$entries = Debug_Log_Parser::parse( $this->sample() );
		$levels  = wp_list_pluck( $entries, 'level' );

		$this->assertSame(
			array( 'warning', 'fatal', 'deprecated', 'notice', 'other', 'warning', 'other' ),
			$levels
		);

		foreach ( $levels as $level ) {
			$this->assertContains( $level, Debug_Log_Parser::LEVELS );
		}
	}

	public function test_parse_errors_are_fatal() {
		$entries = Debug_Log_Parser::parse( '[12-Sep-2026 10:00:06 UTC] PHP Parse error: syntax error, unexpected token ";" in /var/www/html/wp-content/plugins/bar/bar.php on line 4' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'fatal', $entries[0]['level'] );
		$this->assertSame( 'bar', $entries[0]['plugin'] );
	}

	public function test_non_php_lines_are_level_other_without_a_location() {
		$entries = Debug_Log_Parser::parse( $this->sample() );

		$this->assertSame( 'other', $entries[4]['level'] );
		$this->assertSame( 'Some random non-PHP line written by a plugin', $entries[4]['message'] );
		$this->assertSame( '', $entries[4]['file'] );
		$this->assertSame( 0, $entries[4]['line'] );
		$this->assertArrayNotHasKey( 'plugin', $entries[4] );
		$this->assertArrayNotHasKey( 'theme', $entries[4] );
	}

	public function test_plugins_and_themes_are_attributed() {
		$entries = Debug_Log_Parser::parse( $this->sample() );

		$this->assertSame( 'foo', $entries[0]['plugin'] );
		$this->assertArrayNotHasKey( 'theme', $entries[0] );
		$this->assertSame( 'twentytwenty', $entries[3]['theme'] );
		$this->assertArrayNotHasKey( 'plugin', $entries[3] );

		// Core files belong to neither.
		$this->assertArrayNotHasKey( 'plugin', $entries[2] );
		$this->assertArrayNotHasKey( 'theme', $entries[2] );
	}

	public function test_attribute_handles_mu_plugins_and_windows_paths() {
		$this->assertSame( array( 'plugin' => 'mu' ), Debug_Log_Parser::attribute( '/srv/wp-content/mu-plugins/mu/mu.php' ) );
		$this->assertSame( array( 'theme' => 'child' ), Debug_Log_Parser::attribute( 'C:\\sites\\wp\\wp-content\\themes\\child\\functions.php' ) );
		$this->assertSame( array(), Debug_Log_Parser::attribute( '/srv/wp-includes/post.php' ) );
		$this->assertSame( array(), Debug_Log_Parser::attribute( '' ) );
	}

	public function test_grouping_counts_repeats_and_tracks_the_window() {
		$groups = Debug_Log_Parser::group( Debug_Log_Parser::parse( $this->sample() ) );

		$this->assertSame( 2, $groups[0]['count'], 'The repeated warning is the biggest group.' );
		$this->assertSame( 'warning', $groups[0]['level'] );
		$this->assertSame( '2026-09-12T10:00:00Z', $groups[0]['first_seen'] );
		$this->assertSame( '2026-09-12T10:00:05Z', $groups[0]['last_seen'] );
		$this->assertSame( 12, $groups[0]['line'] );
		$this->assertSame( 'foo', $groups[0]['plugin'] );

		// Six distinct signatures out of seven entries.
		$this->assertCount( 6, $groups );
		$this->assertSame( 7, array_sum( wp_list_pluck( $groups, 'count' ) ) );
	}

	public function test_grouping_separates_different_lines_of_the_same_file() {
		$entries = Debug_Log_Parser::parse(
			implode(
				"\n",
				array(
					'[12-Sep-2026 10:00:00 UTC] PHP Warning:  Boom in /srv/wp-content/plugins/foo/foo.php on line 1',
					'[12-Sep-2026 10:00:01 UTC] PHP Warning:  Boom in /srv/wp-content/plugins/foo/foo.php on line 2',
				)
			)
		);

		$this->assertCount( 2, Debug_Log_Parser::group( $entries ) );
	}

	public function test_count_entries_hint_counts_stamped_lines() {
		$this->assertSame( 6, Debug_Log_Parser::count_entries_hint( $this->sample() ) );
		$this->assertSame( 0, Debug_Log_Parser::count_entries_hint( '' ) );
		$this->assertSame( 2, Debug_Log_Parser::count_entries_hint( "first line\nsecond line" ) );
	}

	public function test_excerpts_are_capped() {
		$long    = str_repeat( 'x', Debug_Log_Parser::EXCERPT_LIMIT + 500 );
		$entries = Debug_Log_Parser::parse( '[12-Sep-2026 10:00:00 UTC] PHP Warning:  ' . $long );

		$this->assertLessThanOrEqual( Debug_Log_Parser::EXCERPT_LIMIT + 3, strlen( $entries[0]['raw_excerpt'] ) );
		$this->assertStringEndsWith( '...', $entries[0]['raw_excerpt'] );
	}
}
