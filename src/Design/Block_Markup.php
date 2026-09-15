<?php
/**
 * Block markup validation shared by the template and pattern writers.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Design;

use SuperAbilities\Support\Error;
use SuperAbilities\Support\Fingerprint;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses, summarises and sanity checks a string of block markup.
 *
 * Templates, template parts and patterns all store the same thing: serialized
 * block markup. Before writing any of it we make sure it survives a
 * `parse_blocks()` / `serialize_blocks()` round trip without losing blocks, and
 * that it carries no executable payload.
 *
 * @since 0.2.0
 */
class Block_Markup {

	/**
	 * Fragments that must never appear in stored block markup.
	 *
	 * Block markup is rendered on the front end, and templates are rendered for
	 * every visitor, so a script tag or a PHP opening tag stored here would be a
	 * privilege escalation from `edit_theme_options` to code execution.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const FORBIDDEN = array( '<script', '<?php', '<?=', '</script' );

	/**
	 * Largest markup string we accept, in bytes.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_BYTES = 2097152;

	/**
	 * Validates a markup string and returns its block summary.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $content Raw markup from the caller.
	 * @return array<string, mixed>|WP_Error Summary as returned by {@see Block_Markup::summary()}.
	 */
	public static function check( $content ) {
		if ( ! is_string( $content ) ) {
			return Error::make(
				'invalid_input',
				__( 'Block markup must be a string.', 'super-abilities' )
			);
		}

		if ( strlen( $content ) > self::MAX_BYTES ) {
			return Error::make(
				'invalid_input',
				sprintf(
					/* translators: %d: Maximum size in bytes. */
					__( 'Block markup is larger than the %d byte limit.', 'super-abilities' ),
					self::MAX_BYTES
				)
			);
		}

		$forbidden = self::forbidden_fragment( $content );

		if ( '' !== $forbidden ) {
			return Error::make(
				'unsafe_content',
				sprintf(
					/* translators: %s: The forbidden fragment, for example "<script". */
					__( 'Block markup may not contain "%s".', 'super-abilities' ),
					$forbidden
				),
				array(
					'status'   => 400,
					'fragment' => $forbidden,
				)
			);
		}

		$blocks = parse_blocks( $content );
		$before = self::count_nodes( $blocks );
		$after  = self::count_nodes( parse_blocks( serialize_blocks( $blocks ) ) );

		if ( $before !== $after ) {
			return Error::make(
				'invalid_block_markup',
				__( 'The block markup does not survive a parse and re-serialize round trip, which means at least one block delimiter is malformed.', 'super-abilities' ),
				array(
					'status'        => 400,
					'blocks_parsed' => $before,
					'blocks_kept'   => $after,
				)
			);
		}

		return self::summary( $content );
	}

	/**
	 * The fragments that may not appear in stored block markup.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function forbidden_fragments() {
		/**
		 * Filters the fragments that block markup may not contain.
		 *
		 * Templates and patterns are rendered for every visitor, so the defaults keep
		 * `edit_theme_options` from turning into code execution. Add to this list to
		 * refuse more, for example `<iframe` or `onerror=`.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, string> $fragments Case insensitive fragments to refuse.
		 */
		$fragments = (array) apply_filters( 'super_abilities_forbidden_block_markup', self::FORBIDDEN );
		$clean     = array();

		foreach ( $fragments as $fragment ) {
			if ( ! is_scalar( $fragment ) ) {
				continue;
			}

			$fragment = (string) $fragment;

			if ( '' !== $fragment ) {
				$clean[] = $fragment;
			}
		}

		return $clean;
	}

	/**
	 * The first forbidden fragment found in the markup.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Markup to scan.
	 * @return string The fragment, or an empty string when the markup is clean.
	 */
	public static function forbidden_fragment( $content ) {
		$content = (string) $content;

		foreach ( self::forbidden_fragments() as $fragment ) {
			if ( false !== stripos( $content, $fragment ) ) {
				return $fragment;
			}
		}

		return '';
	}

	/**
	 * Summarises which blocks a markup string contains.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Markup to summarise.
	 * @return array{block_count: int, blocks: array<int, array{name: string, count: int}>}
	 */
	public static function summary( $content ) {
		$counts = array();

		self::tally( parse_blocks( (string) $content ), $counts );

		ksort( $counts );

		$blocks = array();
		$total  = 0;

		foreach ( $counts as $name => $count ) {
			$blocks[] = array(
				'name'  => (string) $name,
				'count' => (int) $count,
			);

			$total += (int) $count;
		}

		return array(
			'block_count' => $total,
			'blocks'      => $blocks,
		);
	}

	/**
	 * Fingerprints a markup string.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Markup to fingerprint.
	 * @return string
	 */
	public static function fingerprint( $content ) {
		return Fingerprint::of_string( (string) $content );
	}

	/**
	 * Counts every parsed node, including the freeform ones.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return int
	 */
	protected static function count_nodes( array $blocks ) {
		$total = 0;

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// `parse_blocks()` emits a freeform node for whitespace between blocks;
			// ignore those so that re-serializing is allowed to normalize them.
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

			if ( null === $name && '' === trim( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '' ) ) {
				continue;
			}

			++$total;

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$total += self::count_nodes( $block['innerBlocks'] );
			}
		}

		return $total;
	}

	/**
	 * Recursively tallies block names.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<string, int>               $counts Tally, passed by reference.
	 * @return void
	 */
	protected static function tally( array $blocks, array &$counts ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';

			if ( '' !== $name ) {
				$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::tally( $block['innerBlocks'], $counts );
			}
		}
	}
}
