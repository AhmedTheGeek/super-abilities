<?php
/**
 * Search and replace over block text.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Blocks;

use SuperAbilities\Support\Error;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A prepared, counted search and replace applied to one text run at a time.
 *
 * The caller prepares the replacer once, hands `apply()` to
 * {@see Block_Tree::map_text()} and then reads `total()` and `error()`. Regular
 * expressions are accepted as a bare body, without delimiters, so that a caller
 * cannot smuggle in modifiers such as `e`; the body is wrapped in `@` delimiters
 * with any unescaped delimiter escaped first, compiled once so that a syntax error
 * is reported before anything is written, and `preg_last_error()` is checked after
 * every call so that a pattern that backtracks past the PCRE limits fails the whole
 * write instead of silently dropping content.
 *
 * @since 0.2.0
 */
class Text_Replacer {

	/**
	 * Longest accepted regular expression body.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_PATTERN_LENGTH = 200;

	/**
	 * The string or pattern body to look for.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	protected $search;

	/**
	 * The replacement.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	protected $replace;

	/**
	 * Whether matching is case sensitive.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	protected $case_sensitive;

	/**
	 * Whether the search is a regular expression body.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	protected $regex;

	/**
	 * The compiled pattern, once `prepare()` has run.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	protected $pattern = '';

	/**
	 * How many replacements were made so far.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	protected $total = 0;

	/**
	 * The first error a replacement ran into, if any.
	 *
	 * @since 0.2.0
	 * @var WP_Error|null
	 */
	protected $error = null;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param string $search         String, or regular expression body, to look for.
	 * @param string $replace        Replacement.
	 * @param bool   $case_sensitive Optional. Whether matching is case sensitive. Default false.
	 * @param bool   $regex          Optional. Whether `$search` is a regular expression body. Default false.
	 */
	public function __construct( $search, $replace, $case_sensitive = false, $regex = false ) {
		$this->search         = (string) $search;
		$this->replace        = (string) $replace;
		$this->case_sensitive = (bool) $case_sensitive;
		$this->regex          = (bool) $regex;
	}

	/**
	 * Validates the search and compiles the pattern.
	 *
	 * @since 0.2.0
	 *
	 * @return WP_Error|null Null when the replacer is ready to use.
	 */
	public function prepare() {
		if ( '' === $this->search ) {
			return Error::make(
				'invalid_input',
				__( 'The search string must not be empty.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->regex ) {
			return null;
		}

		if ( strlen( $this->search ) > self::MAX_PATTERN_LENGTH ) {
			return Error::make(
				'invalid_input',
				sprintf(
					/* translators: %d: Maximum number of characters. */
					__( 'A regular expression may be at most %d characters long.', 'super-abilities' ),
					self::MAX_PATTERN_LENGTH
				),
				array( 'status' => 400 )
			);
		}

		$this->pattern = self::compile( $this->search, $this->case_sensitive );

		// A pattern that does not compile makes preg_match() return false rather than 0.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The compile warning is turned into the error below.
		if ( false === @preg_match( $this->pattern, '' ) ) {
			return Error::make(
				'invalid_input',
				__( 'The regular expression could not be compiled. Pass the pattern body only, without delimiters or modifiers.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * Wraps a pattern body in `@` delimiters.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body           Pattern body, without delimiters.
	 * @param bool   $case_sensitive Whether matching is case sensitive.
	 * @return string
	 */
	public static function compile( $body, $case_sensitive ) {
		$escaped = preg_replace( '/(?<!\\\\)@/', '\\\\@', (string) $body );
		$escaped = is_string( $escaped ) ? $escaped : (string) $body;

		return '@' . $escaped . '@u' . ( $case_sensitive ? '' : 'i' );
	}

	/**
	 * Replaces every match inside one text run.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text Text run.
	 * @return string
	 */
	public function apply( $text ) {
		$text = (string) $text;

		if ( null !== $this->error ) {
			return $text;
		}

		$count = 0;

		if ( $this->regex ) {
			$replaced = preg_replace( $this->pattern, $this->replace, $text, -1, $count );

			if ( PREG_NO_ERROR !== preg_last_error() || ! is_string( $replaced ) ) {
				$this->error = Error::make(
					'invalid_input',
					__( 'The regular expression failed while running against the post content. Simplify the pattern and try again.', 'super-abilities' ),
					array( 'status' => 400 )
				);

				return $text;
			}
		} elseif ( $this->case_sensitive ) {
			$replaced = str_replace( $this->search, $this->replace, $text, $count );
		} else {
			$replaced = str_ireplace( $this->search, $this->replace, $text, $count );
		}

		$this->total += (int) $count;

		return (string) $replaced;
	}

	/**
	 * How many replacements were made.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function total() {
		return $this->total;
	}

	/**
	 * The error a replacement ran into, if any.
	 *
	 * @since 0.2.0
	 *
	 * @return WP_Error|null
	 */
	public function error() {
		return $this->error;
	}
}
