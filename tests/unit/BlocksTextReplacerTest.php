<?php
/**
 * Tests for the prepared search and replace used by blocks-replace-text.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Blocks\Text_Replacer;

class BlocksTextReplacerTest extends WP_UnitTestCase {

	private function prepared( $search, $replace, $case_sensitive = false, $regex = false ) {
		$replacer = new Text_Replacer( $search, $replace, $case_sensitive, $regex );

		$this->assertNull( $replacer->prepare(), 'The replacer refused a search it should have accepted.' );

		return $replacer;
	}

	public function test_plain_replacement_ignores_case_by_default() {
		$replacer = $this->prepared( 'gutenberg', 'Gutenberg' );

		$this->assertSame( 'Gutenberg and Gutenberg', $replacer->apply( 'gutenberg and GUTENBERG' ) );
		$this->assertSame( 2, $replacer->total() );
		$this->assertNull( $replacer->error() );
	}

	public function test_case_sensitive_replacement() {
		$replacer = $this->prepared( 'cat', 'dog', true );

		$this->assertSame( 'dog Cat CAT', $replacer->apply( 'cat Cat CAT' ) );
		$this->assertSame( 1, $replacer->total() );
	}

	public function test_the_count_adds_up_across_calls() {
		$replacer = $this->prepared( 'a', 'b' );

		$replacer->apply( 'aaa' );
		$replacer->apply( 'aa' );

		$this->assertSame( 5, $replacer->total() );
	}

	public function test_an_empty_replacement_deletes_the_matches() {
		$replacer = $this->prepared( ' draft', '' );

		$this->assertSame( 'Post', $replacer->apply( 'Post draft' ) );
		$this->assertSame( 1, $replacer->total() );
	}

	public function test_an_empty_search_is_refused() {
		$replacer = new Text_Replacer( '', 'x' );
		$error    = $replacer->prepare();

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
	}

	public function test_regex_replacement_with_a_backreference() {
		$replacer = $this->prepared( '(\d{4})-(\d{2})', '$2/$1', true, true );

		$this->assertSame( 'Released 09/2026 here', $replacer->apply( 'Released 2026-09 here' ) );
		$this->assertSame( 1, $replacer->total() );
		$this->assertNull( $replacer->error() );
	}

	public function test_regex_matching_is_case_insensitive_by_default() {
		$this->assertSame( 'x x', $this->prepared( 'a+', 'x', false, true )->apply( 'a AA' ) );
		$this->assertSame( 'x AA', $this->prepared( 'a+', 'x', true, true )->apply( 'a AA' ) );
	}

	public function test_a_pattern_may_use_the_delimiter_character() {
		$replacer = $this->prepared( 'a@b', 'here', false, true );

		$this->assertSame( 'mail here now', $replacer->apply( 'mail a@b now' ) );
		$this->assertSame( 1, $replacer->total() );
	}

	public function test_compile_escapes_an_unescaped_delimiter_only_once() {
		$this->assertSame( '@a\@b@ui', Text_Replacer::compile( 'a@b', false ) );
		$this->assertSame( '@a\@b@u', Text_Replacer::compile( 'a\@b', true ) );
	}

	public function test_an_overlong_pattern_is_refused() {
		$replacer = new Text_Replacer( str_repeat( 'a', Text_Replacer::MAX_PATTERN_LENGTH + 1 ), 'x', false, true );
		$error    = $replacer->prepare();

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
		$this->assertStringContainsString( (string) Text_Replacer::MAX_PATTERN_LENGTH, $error->get_error_message() );

		// One character shorter is fine.
		$this->prepared( str_repeat( 'a', Text_Replacer::MAX_PATTERN_LENGTH ), 'x', false, true );
	}

	public function test_a_pattern_that_does_not_compile_is_refused_before_anything_runs() {
		foreach ( array( '(unclosed', 'a{2,1}', '[z-a]', '*' ) as $bad ) {
			$replacer = new Text_Replacer( $bad, 'x', false, true );
			$error    = $replacer->prepare();

			$this->assertWPError( $error, "Accepted the invalid pattern '{$bad}'." );
			$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
		}
	}

	public function test_modifiers_cannot_be_smuggled_in_through_the_search() {
		// The body is wrapped in delimiters, so a trailing modifier is just literal text.
		$replacer = $this->prepared( 'a@e', 'x', false, true );

		$this->assertSame( 'x', $replacer->apply( 'a@e' ) );
	}

	public function test_content_that_is_not_valid_utf8_fails_the_call() {
		$replacer = $this->prepared( 'a', 'b', false, true );

		// The pattern runs with the `u` modifier, so a broken byte sequence makes PCRE
		// return null rather than a string; that must surface as an error, not as
		// silently dropped content.
		$this->assertSame( "\xC3\x28", $replacer->apply( "\xC3\x28" ) );

		$error = $replacer->error();

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
		$this->assertSame( 0, $replacer->total() );
	}

	public function test_a_pattern_that_gives_up_on_the_content_reports_an_error() {
		$replacer = $this->prepared( '(a+)+b', 'x', false, true );
		$subject  = str_repeat( 'a', 5000 );

		$replacer->apply( $subject );

		$error = $replacer->error();

		if ( null === $error ) {
			$this->markTestSkipped( 'This PCRE build completed the pathological pattern without hitting a limit.' );
		}

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );

		// Once it has failed it stops touching the text at all.
		$this->assertSame( 'ab', $replacer->apply( 'ab' ) );
	}
}
