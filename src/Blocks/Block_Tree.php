<?php
/**
 * Block tree parsing, addressing and editing.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Blocks;

use SuperAbilities\Support\Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * A parsed block tree whose nodes are addressed by dotted, zero based paths.
 *
 * `parse_blocks()` produces a list of blocks, each of which may carry its own
 * `innerBlocks` list. This class gives every one of those blocks a stable address:
 * the zero based index of each list it is reached through, joined with `.`, so `0`
 * is the first top level block, `2.1` the second child of the third one and `2.1.0`
 * the first child of that. Freeform fragments, which the parser reports with a null
 * block name, take part in the indexing like any other block and are named
 * `core/freeform` on the way out.
 *
 * Every edit keeps `innerContent` consistent with `innerBlocks`, because that is the
 * only thing `serialize_block()` looks at: its string chunks are emitted verbatim and
 * each null is replaced by the next inner block. A block whose null count no longer
 * matches its child count serializes into broken markup, so the null placeholders are
 * rebuilt whenever a child list changes length, reusing the wrapper markup that was
 * already there.
 *
 * @since 0.2.0
 */
class Block_Tree {

	/**
	 * Pattern every valid block path matches.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const PATH_PATTERN = '^\d+(\.\d+)*$';

	/**
	 * Pattern a block path matches, allowing the empty string for the top level list.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const PATH_OR_ROOT_PATTERN = '^(\d+(\.\d+)*)?$';

	/**
	 * Name reported for blocks the parser gives no name to.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const FREEFORM = 'core/freeform';

	/**
	 * Longest attribute value kept in `summary()`.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const ATTR_VALUE_LIMIT = 120;

	/**
	 * Accepted insert positions.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const POSITIONS = array( 'before', 'after', 'append', 'prepend' );

	/**
	 * The top level blocks.
	 *
	 * @since 0.2.0
	 * @var array<int, array<string, mixed>>
	 */
	protected $blocks = array();

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Optional. Blocks in `parse_blocks()` shape. Default empty array.
	 */
	public function __construct( array $blocks = array() ) {
		$this->blocks = self::normalize_list( $blocks );
	}

	/**
	 * Parses post content into a tree.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Post content.
	 * @return Block_Tree
	 */
	public static function parse( $content ) {
		return new self( parse_blocks( (string) $content ) );
	}

	/**
	 * Parses a markup fragment into a list of blocks, dropping whitespace only freeform.
	 *
	 * Pretty printed markup produces a freeform block for every gap between two blocks.
	 * Those are meaningful inside a post, where they carry the author's line breaks, but
	 * never when a caller hands us markup to insert, so they are dropped here.
	 *
	 * @since 0.2.0
	 *
	 * @param string $markup Block markup.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse_fragment( $markup ) {
		$blocks = array();

		foreach ( self::normalize_list( parse_blocks( (string) $markup ) ) as $block ) {
			if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) {
				continue;
			}

			$blocks[] = $block;
		}

		return $blocks;
	}

	/**
	 * Whether a value is a syntactically valid block path.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $path Candidate path.
	 * @return bool
	 */
	public static function is_path( $path ) {
		return is_string( $path ) && '' !== $path && 1 === preg_match( '/' . self::PATH_PATTERN . '/', $path );
	}

	/**
	 * Whether a path addresses a node inside another node's subtree, or the node itself.
	 *
	 * @since 0.2.0
	 *
	 * @param string $candidate Path to test.
	 * @param string $ancestor  Path of the possible ancestor.
	 * @return bool
	 */
	public static function is_within( $candidate, $ancestor ) {
		$candidate = (string) $candidate;
		$ancestor  = (string) $ancestor;

		if ( '' === $ancestor ) {
			return true;
		}

		return $candidate === $ancestor || 0 === strpos( $candidate, $ancestor . '.' );
	}

	/**
	 * The path of the list a node lives in, or an empty string for a top level node.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Block path.
	 * @return string
	 */
	public static function parent_path( $path ) {
		$position = strrpos( (string) $path, '.' );

		return false === $position ? '' : substr( (string) $path, 0, $position );
	}

	/**
	 * Public block name for an internal, possibly null, name.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $name Internal block name.
	 * @return string
	 */
	public static function public_name( $name ) {
		return ( is_string( $name ) && '' !== $name ) ? $name : self::FREEFORM;
	}

	/**
	 * Internal block name for a caller supplied name.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $name Caller supplied name.
	 * @return string|null Null for a freeform fragment.
	 */
	public static function internal_name( $name ) {
		$name = is_string( $name ) ? trim( $name ) : '';

		return ( '' === $name || self::FREEFORM === $name ) ? null : $name;
	}

	/**
	 * Every top level block.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function blocks() {
		return $this->blocks;
	}

	/**
	 * The block at a path.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Block path.
	 * @return array<string, mixed>|null Null when the path addresses nothing.
	 */
	public function get( $path ) {
		$segments = self::segments( $path );

		if ( null === $segments ) {
			return null;
		}

		$siblings = $this->blocks;

		foreach ( $segments as $depth => $index ) {
			if ( ! isset( $siblings[ $index ] ) ) {
				return null;
			}

			if ( count( $segments ) - 1 === $depth ) {
				return $siblings[ $index ];
			}

			$siblings = $siblings[ $index ]['innerBlocks'];
		}

		return null;
	}

	/**
	 * Replaces the block at a path.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $path  Block path.
	 * @param array<string, mixed> $block Replacement block, in `parse_blocks()` shape.
	 * @return bool False when the path addresses nothing.
	 */
	public function set( $path, array $block ) {
		$segments = self::segments( $path );

		if ( null === $segments ) {
			return false;
		}

		$replacement = self::normalize( $block );

		$result = self::apply(
			$this->blocks,
			$segments,
			static function ( array $siblings, $index ) use ( $replacement ) {
				if ( ! isset( $siblings[ $index ] ) ) {
					return null;
				}

				$siblings[ $index ] = $replacement;

				return $siblings;
			}
		);

		if ( null === $result ) {
			return false;
		}

		$this->blocks = $result;

		return true;
	}

	/**
	 * Inserts one block relative to a path.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null          $path     Anchor path, or an empty string for the top level list.
	 * @param array<string, mixed> $block    Block to insert, in `parse_blocks()` shape.
	 * @param string               $position One of `before`, `after`, `append` or `prepend`.
	 * @return string|null Path of the inserted block, or null when the anchor addresses nothing.
	 */
	public function insert( $path, array $block, $position = 'after' ) {
		$paths = $this->insert_many( $path, array( $block ), $position );

		if ( null === $paths || array() === $paths ) {
			return null;
		}

		return $paths[0];
	}

	/**
	 * Inserts several blocks relative to a path, keeping their order.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null                      $path     Anchor path, or an empty string for the top level list.
	 * @param array<int, array<string, mixed>> $blocks   Blocks to insert.
	 * @param string                           $position One of `before`, `after`, `append` or `prepend`.
	 * @return array<int, string>|null Paths of the inserted blocks, or null when the anchor addresses nothing.
	 */
	public function insert_many( $path, array $blocks, $position = 'after' ) {
		$position = self::normalize_position( $position );
		$incoming = array();

		foreach ( $blocks as $block ) {
			if ( is_array( $block ) ) {
				$incoming[] = self::normalize( $block );
			}
		}

		if ( array() === $incoming ) {
			return array();
		}

		if ( null === $path || '' === $path ) {
			if ( 'prepend' === $position || 'before' === $position ) {
				$offset       = 0;
				$this->blocks = array_merge( $incoming, $this->blocks );
			} else {
				$offset       = count( $this->blocks );
				$this->blocks = array_merge( $this->blocks, $incoming );
			}

			return self::inserted_paths( '', $offset, count( $incoming ) );
		}

		$segments = self::segments( $path );

		if ( null === $segments ) {
			return null;
		}

		if ( 'append' === $position || 'prepend' === $position ) {
			$anchor = $this->get( $path );

			if ( null === $anchor ) {
				return null;
			}

			$offset = 'prepend' === $position ? 0 : count( $anchor['innerBlocks'] );

			$result = self::apply(
				$this->blocks,
				$segments,
				static function ( array $siblings, $index ) use ( $incoming, $offset ) {
					if ( ! isset( $siblings[ $index ] ) ) {
						return null;
					}

					$children = $siblings[ $index ]['innerBlocks'];
					array_splice( $children, $offset, 0, $incoming );

					$siblings[ $index ]['innerBlocks'] = $children;
					$siblings[ $index ]                = self::rebuild( $siblings[ $index ] );

					return $siblings;
				}
			);

			if ( null === $result ) {
				return null;
			}

			$this->blocks = $result;

			return self::inserted_paths( $path, $offset, count( $incoming ) );
		}

		$index  = $segments[ count( $segments ) - 1 ];
		$offset = 'before' === $position ? $index : $index + 1;

		$result = self::apply(
			$this->blocks,
			$segments,
			static function ( array $siblings, $index ) use ( $incoming, $offset ) {
				if ( ! isset( $siblings[ $index ] ) ) {
					return null;
				}

				array_splice( $siblings, $offset, 0, $incoming );

				return $siblings;
			}
		);

		if ( null === $result ) {
			return null;
		}

		$this->blocks = $result;

		return self::inserted_paths( self::parent_path( $path ), $offset, count( $incoming ) );
	}

	/**
	 * Removes the block at a path.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Block path.
	 * @return bool False when the path addresses nothing.
	 */
	public function remove( $path ) {
		$segments = self::segments( $path );

		if ( null === $segments ) {
			return false;
		}

		$result = self::apply(
			$this->blocks,
			$segments,
			static function ( array $siblings, $index ) {
				if ( ! isset( $siblings[ $index ] ) ) {
					return null;
				}

				array_splice( $siblings, $index, 1 );

				return $siblings;
			}
		);

		if ( null === $result ) {
			return false;
		}

		$this->blocks = $result;

		return true;
	}

	/**
	 * Removes several blocks, deepest and last first so the remaining paths stay valid.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $paths Block paths.
	 * @return array<int, string> The paths that were actually removed, in removal order.
	 */
	public function remove_many( array $paths ) {
		$removed = array();

		foreach ( self::sort_deepest_first( $paths ) as $path ) {
			if ( $this->remove( $path ) ) {
				$removed[] = $path;
			}
		}

		return $removed;
	}

	/**
	 * Moves a block somewhere else in the same tree.
	 *
	 * Callers must reject a target inside the moved block's own subtree first, with
	 * {@see Block_Tree::is_within()}; this method assumes that check has been made.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $from     Path of the block to move.
	 * @param string|null $to       Target path, or an empty string for the top level list.
	 * @param string      $position One of `before`, `after`, `append` or `prepend`.
	 * @return string|null The block's new path, or null when either path addresses nothing.
	 */
	public function move( $from, $to, $position = 'after' ) {
		$block = $this->get( $from );

		if ( null === $block ) {
			return null;
		}

		$root = ( null === $to || '' === $to );

		if ( ! $root && null === $this->get( $to ) ) {
			return null;
		}

		if ( ! $this->remove( $from ) ) {
			return null;
		}

		$target = $root ? '' : self::shift_after_removal( (string) $to, (string) $from );

		return $this->insert( $target, $block, $position );
	}

	/**
	 * The post content this tree serializes to.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function serialize() {
		return serialize_blocks( $this->blocks );
	}

	/**
	 * The markup of one subtree, or of the whole tree for an empty path.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $path Optional. Block path. Default null, the whole tree.
	 * @return string|null Null when the path addresses nothing.
	 */
	public function serialize_path( $path = null ) {
		if ( null === $path || '' === $path ) {
			return $this->serialize();
		}

		$block = $this->get( $path );

		return null === $block ? null : serialize_block( $block );
	}

	/**
	 * Fingerprint of the whole tree, taken over its serialized markup.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function fingerprint() {
		return Fingerprint::of_string( $this->serialize() );
	}

	/**
	 * Fingerprint of a single block, taken over its own serialized markup.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Block path.
	 * @return string Empty string when the path addresses nothing.
	 */
	public function fingerprint_of( $path ) {
		$markup = $this->serialize_path( $path );

		return null === $markup ? '' : Fingerprint::of_string( $markup );
	}

	/**
	 * Number of blocks in the tree, or in one subtree including its root.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $path Optional. Block path. Default null, the whole tree.
	 * @return int
	 */
	public function size( $path = null ) {
		return count( $this->summary( $path ) );
	}

	/**
	 * A flat description of every block, in document order.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $path Optional. Limit to this subtree, root included. Default null.
	 * @return array<int, array<string, mixed>>
	 */
	public function summary( $path = null ) {
		$out = array();

		if ( null === $path || '' === $path ) {
			self::collect_list( $this->blocks, '', $out );

			return $out;
		}

		$block = $this->get( $path );

		if ( null === $block ) {
			return array();
		}

		self::collect_node( $block, (string) $path, $out );

		return $out;
	}

	/**
	 * A nested description of the tree, trimmed to a depth.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $path           Optional. Limit to this subtree, root included. Default null.
	 * @param int         $depth          Optional. Levels to include, 0 for every level. Default 3.
	 * @param bool        $include_raw    Optional. Whether to include `inner_html` and `inner_content`. Default false.
	 * @param bool        $include_attrs  Optional. Whether to include `attrs`. Default true.
	 * @return array<int, array<string, mixed>>|null Null when the path addresses nothing.
	 */
	public function nodes( $path = null, $depth = 3, $include_raw = false, $include_attrs = true ) {
		$depth = max( 0, (int) $depth );

		if ( null === $path || '' === $path ) {
			$nodes = array();

			foreach ( $this->blocks as $index => $block ) {
				$nodes[] = self::node( $block, (string) $index, $depth, (bool) $include_raw, (bool) $include_attrs );
			}

			return $nodes;
		}

		$block = $this->get( $path );

		if ( null === $block ) {
			return null;
		}

		return array( self::node( $block, (string) $path, $depth, (bool) $include_raw, (bool) $include_attrs ) );
	}

	/**
	 * Applies a callback to every text node of a subtree.
	 *
	 * Only the string chunks of `innerContent` are visited, and inside them only the
	 * parts that sit outside an HTML tag, so attribute values inside the markup, the
	 * JSON attributes of the block and the block comment delimiters are never touched.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null              $path     Limit to this subtree, or an empty string for the whole tree.
	 * @param callable(string): string $callback Transformation applied to each text run.
	 * @return array<int, string>|null Paths whose text changed, or null when the path addresses nothing.
	 */
	public function map_text( $path, callable $callback ) {
		$changed = array();

		if ( null === $path || '' === $path ) {
			$this->blocks = self::map_list( $this->blocks, '', $callback, $changed );

			return $changed;
		}

		$segments = self::segments( $path );

		if ( null === $segments || null === $this->get( $path ) ) {
			return null;
		}

		$target = (string) $path;

		$result = self::apply(
			$this->blocks,
			$segments,
			static function ( array $siblings, $index ) use ( $target, $callback, &$changed ) {
				if ( ! isset( $siblings[ $index ] ) ) {
					return null;
				}

				$siblings[ $index ] = self::map_node( $siblings[ $index ], $target, $callback, $changed );

				return $siblings;
			}
		);

		if ( null === $result ) {
			return null;
		}

		$this->blocks = $result;

		return $changed;
	}

	/**
	 * Applies a callback to the text runs of an HTML string, leaving tags alone.
	 *
	 * @since 0.2.0
	 *
	 * @param string                   $html     HTML fragment.
	 * @param callable(string): string $callback Transformation applied to each text run.
	 * @return string
	 */
	public static function map_html_text( $html, callable $callback ) {
		$parts = preg_split( '/(<[^>]*>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return (string) $html;
		}

		foreach ( $parts as $index => $part ) {
			// Odd offsets hold the captured tags; only the runs between them are text.
			if ( 1 === $index % 2 || '' === $part ) {
				continue;
			}

			$parts[ $index ] = (string) call_user_func( $callback, (string) $part );
		}

		return implode( '', $parts );
	}

	/**
	 * Builds a block from the `{name, attrs, inner_html, inner_blocks}` input shape.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $raw Caller supplied block.
	 * @return array<string, mixed>
	 */
	public static function from_input( array $raw ) {
		$inner_html = isset( $raw['inner_html'] ) ? (string) $raw['inner_html'] : '';

		$block = array(
			'blockName'    => self::internal_name( isset( $raw['name'] ) ? $raw['name'] : '' ),
			'attrs'        => ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) ? $raw['attrs'] : array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $inner_html,
			'innerContent' => '' === $inner_html ? array() : array( $inner_html ),
		);

		$children = ( isset( $raw['inner_blocks'] ) && is_array( $raw['inner_blocks'] ) ) ? $raw['inner_blocks'] : array();

		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$block['innerBlocks'][] = self::from_input( $child );
			}
		}

		return self::normalize( $block );
	}

	/**
	 * Sorts paths so that deeper and later nodes come first.
	 *
	 * Removing in this order keeps every path that has not been removed yet valid: a
	 * child is always taken out before its parent, and a later sibling before an
	 * earlier one.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $paths Block paths.
	 * @return array<int, string> Unique, valid paths in removal order.
	 */
	public static function sort_deepest_first( array $paths ) {
		$valid = array();

		foreach ( $paths as $path ) {
			if ( self::is_path( $path ) ) {
				$valid[] = (string) $path;
			}
		}

		$valid = array_values( array_unique( $valid ) );

		usort(
			$valid,
			static function ( $a, $b ) {
				return self::compare_paths( $b, $a );
			}
		);

		return $valid;
	}

	/**
	 * Compares two paths in document order.
	 *
	 * A node sorts before its own descendants, and siblings sort by index.
	 *
	 * @since 0.2.0
	 *
	 * @param string $a First path.
	 * @param string $b Second path.
	 * @return int Less than, equal to or greater than zero.
	 */
	public static function compare_paths( $a, $b ) {
		$first  = self::segments( $a );
		$second = self::segments( $b );

		$first  = null === $first ? array() : $first;
		$second = null === $second ? array() : $second;

		$shared = min( count( $first ), count( $second ) );

		for ( $i = 0; $i < $shared; $i++ ) {
			if ( $first[ $i ] !== $second[ $i ] ) {
				return $first[ $i ] < $second[ $i ] ? -1 : 1;
			}
		}

		return count( $first ) <=> count( $second );
	}

	/**
	 * Rewrites a path so that it still addresses the same node after a removal.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path    Path to rewrite.
	 * @param string $removed Path that was removed.
	 * @return string
	 */
	public static function shift_after_removal( $path, $removed ) {
		$target = self::segments( $path );
		$gone   = self::segments( $removed );

		if ( null === $target || null === $gone ) {
			return (string) $path;
		}

		$level = count( $gone ) - 1;

		if ( count( $target ) <= $level ) {
			return (string) $path;
		}

		for ( $i = 0; $i < $level; $i++ ) {
			if ( $target[ $i ] !== $gone[ $i ] ) {
				return (string) $path;
			}
		}

		if ( $target[ $level ] > $gone[ $level ] ) {
			--$target[ $level ];
		}

		return implode( '.', $target );
	}

	/**
	 * Brings one block into the exact shape `serialize_block()` expects.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $block Block in a loose `parse_blocks()` shape.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $block ) {
		$name = array_key_exists( 'blockName', $block ) ? $block['blockName'] : null;

		$normalized = array(
			'blockName'    => ( is_string( $name ) && '' !== $name ) ? $name : null,
			'attrs'        => ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) ? $block['attrs'] : array(),
			'innerBlocks'  => self::normalize_list( ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) ? $block['innerBlocks'] : array() ),
			'innerHTML'    => isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '',
			'innerContent' => array(),
		);

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $chunk ) {
				$normalized['innerContent'][] = null === $chunk ? null : (string) $chunk;
			}
		} elseif ( '' !== $normalized['innerHTML'] ) {
			$normalized['innerContent'] = array( $normalized['innerHTML'] );
		}

		if ( self::count_placeholders( $normalized['innerContent'] ) !== count( $normalized['innerBlocks'] ) ) {
			return self::rebuild( $normalized );
		}

		$normalized['innerHTML'] = self::inner_html( $normalized['innerContent'] );

		return $normalized;
	}

	/**
	 * Normalizes a list of blocks and reindexes it.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, mixed> $blocks Blocks.
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_list( array $blocks ) {
		$siblings = array();

		foreach ( $blocks as $block ) {
			if ( is_array( $block ) ) {
				$siblings[] = self::normalize( $block );
			}
		}

		return $siblings;
	}

	/**
	 * Rebuilds `innerContent` and `innerHTML` for a block whose child list changed.
	 *
	 * The wrapper markup is preserved: the string chunks before the first placeholder
	 * become the opening markup, those after the last one the closing markup, and the
	 * first gap between two placeholders becomes the separator between children. A
	 * block that had no children yet is split on its own outermost element, so a group
	 * keeps its `<div class="wp-block-group">` wrapper around whatever is inserted.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $block Normalized block.
	 * @return array<string, mixed>
	 */
	public static function rebuild( array $block ) {
		$target  = count( $block['innerBlocks'] );
		$chunks  = is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
		$prefix  = '';
		$suffix  = '';
		$gap     = '';
		$current = '';
		$seen    = 0;
		$gaps    = array();

		if ( self::count_placeholders( $chunks ) > 0 ) {
			foreach ( $chunks as $chunk ) {
				if ( null === $chunk ) {
					if ( 0 === $seen ) {
						$prefix = $current;
					} else {
						$gaps[] = $current;
					}

					$current = '';
					++$seen;

					continue;
				}

				$current .= (string) $chunk;
			}

			$suffix = $current;
			$gap    = isset( $gaps[0] ) ? $gaps[0] : '';
		} else {
			list( $prefix, $suffix ) = self::split_wrapper( self::inner_html( $chunks ) );
		}

		if ( 0 === $target ) {
			$html                  = $prefix . $suffix;
			$block['innerContent'] = '' === $html ? array() : array( $html );
			$block['innerHTML']    = $html;

			return $block;
		}

		$content = array();

		if ( '' !== $prefix ) {
			$content[] = $prefix;
		}

		for ( $i = 0; $i < $target; $i++ ) {
			if ( $i > 0 && '' !== $gap ) {
				$content[] = $gap;
			}

			$content[] = null;
		}

		if ( '' !== $suffix ) {
			$content[] = $suffix;
		}

		$block['innerContent'] = $content;
		$block['innerHTML']    = self::inner_html( $content );

		return $block;
	}

	/**
	 * Splits a fragment into the markup before and after its outermost element's content.
	 *
	 * @since 0.2.0
	 *
	 * @param string $html HTML fragment.
	 * @return array{0: string, 1: string} Opening markup and closing markup.
	 */
	public static function split_wrapper( $html ) {
		$html = (string) $html;

		if ( preg_match( '#^(\s*<([a-z][a-z0-9-]*)\b[^>]*>)(.*)(</\2>\s*)$#is', $html, $matches ) ) {
			return array( $matches[1] . $matches[3], $matches[4] );
		}

		return array( $html, '' );
	}

	/**
	 * Normalizes an insert position.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $position Caller supplied position.
	 * @return string One of `before`, `after`, `append` or `prepend`.
	 */
	public static function normalize_position( $position ) {
		$position = is_string( $position ) ? strtolower( trim( $position ) ) : '';

		return in_array( $position, self::POSITIONS, true ) ? $position : 'after';
	}

	/**
	 * Splits a path into its integer segments.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $path Candidate path.
	 * @return array<int, int>|null Null when the path is not valid.
	 */
	protected static function segments( $path ) {
		if ( ! self::is_path( $path ) ) {
			return null;
		}

		return array_map( 'intval', explode( '.', (string) $path ) );
	}

	/**
	 * Walks down to the list holding an addressed node and rewrites it.
	 *
	 * The callback receives that list and the index of the addressed node inside it and
	 * returns the new list, or null to abort. Every ancestor whose own child list
	 * changed length afterwards is rebuilt, which is the only case where the null
	 * placeholders of `innerContent` can go out of step.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>>                                                         $siblings     Block list at this level.
	 * @param array<int, int>                                                                          $segments Remaining path segments.
	 * @param callable(array<int, array<string, mixed>>, int): (array<int, array<string, mixed>>|null) $op       List rewrite.
	 * @return array<int, array<string, mixed>>|null Null when the path addresses nothing.
	 */
	protected static function apply( array $siblings, array $segments, callable $op ) {
		$index = (int) array_shift( $segments );

		if ( array() === $segments ) {
			return call_user_func( $op, $siblings, $index );
		}

		if ( ! isset( $siblings[ $index ] ) ) {
			return null;
		}

		$before = count( $siblings[ $index ]['innerBlocks'] );
		$child  = self::apply( $siblings[ $index ]['innerBlocks'], $segments, $op );

		if ( null === $child ) {
			return null;
		}

		$siblings[ $index ]['innerBlocks'] = $child;

		if ( count( $child ) !== $before ) {
			$siblings[ $index ] = self::rebuild( $siblings[ $index ] );
		}

		return $siblings;
	}

	/**
	 * The paths a run of inserted blocks ended up at.
	 *
	 * @since 0.2.0
	 *
	 * @param string $prefix Path of the list they were inserted into, empty for the top level.
	 * @param int    $offset Index of the first inserted block.
	 * @param int    $total  How many blocks were inserted.
	 * @return array<int, string>
	 */
	protected static function inserted_paths( $prefix, $offset, $total ) {
		$paths = array();

		for ( $i = 0; $i < $total; $i++ ) {
			$index   = (int) $offset + $i;
			$paths[] = '' === (string) $prefix ? (string) $index : $prefix . '.' . $index;
		}

		return $paths;
	}

	/**
	 * Appends the flat description of a list of blocks.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $siblings   Blocks.
	 * @param string                           $prefix Path of the list, empty for the top level.
	 * @param array<int, array<string, mixed>> $out    Accumulator, passed by reference.
	 * @return void
	 */
	protected static function collect_list( array $siblings, $prefix, array &$out ) {
		foreach ( $siblings as $index => $block ) {
			self::collect_node( $block, '' === (string) $prefix ? (string) $index : $prefix . '.' . $index, $out );
		}
	}

	/**
	 * Appends the flat description of one block and its subtree.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>             $block Block.
	 * @param string                           $path  Path of the block.
	 * @param array<int, array<string, mixed>> $out   Accumulator, passed by reference.
	 * @return void
	 */
	protected static function collect_node( array $block, $path, array &$out ) {
		$out[] = array(
			'path'              => (string) $path,
			'name'              => self::public_name( $block['blockName'] ),
			'attrs'             => self::attrs_subset( $block['attrs'] ),
			'inner_html_length' => strlen( $block['innerHTML'] ),
			'inner_blocks'      => count( $block['innerBlocks'] ),
			'fingerprint'       => Fingerprint::of_string( serialize_block( $block ) ),
		);

		self::collect_list( $block['innerBlocks'], (string) $path, $out );
	}

	/**
	 * Builds the nested description of one block.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $block         Block.
	 * @param string               $path          Path of the block.
	 * @param int                  $depth         Levels still to include, 0 for every level.
	 * @param bool                 $include_raw   Whether to include `inner_html` and `inner_content`.
	 * @param bool                 $include_attrs Whether to include `attrs`.
	 * @return array<string, mixed>
	 */
	protected static function node( array $block, $path, $depth, $include_raw, $include_attrs ) {
		$node = array(
			'path'               => (string) $path,
			'name'               => self::public_name( $block['blockName'] ),
			'inner_blocks_count' => count( $block['innerBlocks'] ),
			'inner_blocks'       => array(),
		);

		if ( $include_attrs ) {
			$node['attrs'] = $block['attrs'];
		}

		if ( $include_raw ) {
			$node['inner_html']    = $block['innerHTML'];
			$node['inner_content'] = $block['innerContent'];
		}

		if ( 0 !== $depth && $depth <= 1 ) {
			return $node;
		}

		$child_depth = 0 === $depth ? 0 : $depth - 1;

		foreach ( $block['innerBlocks'] as $index => $child ) {
			$node['inner_blocks'][] = self::node( $child, $path . '.' . $index, $child_depth, $include_raw, $include_attrs );
		}

		return $node;
	}

	/**
	 * Applies a text callback to a list of blocks.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $siblings     Blocks.
	 * @param string                           $prefix   Path of the list, empty for the top level.
	 * @param callable(string): string         $callback Transformation.
	 * @param array<int, string>               $changed  Accumulator of changed paths, by reference.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function map_list( array $siblings, $prefix, callable $callback, array &$changed ) {
		foreach ( $siblings as $index => $block ) {
			$path               = '' === (string) $prefix ? (string) $index : $prefix . '.' . $index;
			$siblings[ $index ] = self::map_node( $block, $path, $callback, $changed );
		}

		return $siblings;
	}

	/**
	 * Applies a text callback to one block and its subtree.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>     $block    Block.
	 * @param string                   $path     Path of the block.
	 * @param callable(string): string $callback Transformation.
	 * @param array<int, string>       $changed  Accumulator of changed paths, by reference.
	 * @return array<string, mixed>
	 */
	protected static function map_node( array $block, $path, callable $callback, array &$changed ) {
		$content = $block['innerContent'];
		$dirty   = false;

		foreach ( $content as $index => $chunk ) {
			if ( null === $chunk ) {
				continue;
			}

			$replaced = self::map_html_text( (string) $chunk, $callback );

			if ( $replaced !== $chunk ) {
				$content[ $index ] = $replaced;
				$dirty             = true;
			}
		}

		if ( $dirty ) {
			$block['innerContent'] = $content;
			$block['innerHTML']    = self::inner_html( $content );
			$changed[]             = (string) $path;
		}

		$block['innerBlocks'] = self::map_list( $block['innerBlocks'], (string) $path, $callback, $changed );

		return $block;
	}

	/**
	 * Concatenates the string chunks of an `innerContent` list.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string|null> $chunks Inner content chunks.
	 * @return string
	 */
	protected static function inner_html( array $chunks ) {
		$html = '';

		foreach ( $chunks as $chunk ) {
			if ( null !== $chunk ) {
				$html .= (string) $chunk;
			}
		}

		return $html;
	}

	/**
	 * How many inner block placeholders an `innerContent` list holds.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string|null> $chunks Inner content chunks.
	 * @return int
	 */
	protected static function count_placeholders( array $chunks ) {
		$total = 0;

		foreach ( $chunks as $chunk ) {
			if ( null === $chunk ) {
				++$total;
			}
		}

		return $total;
	}

	/**
	 * The scalar attributes of a block, with long strings cut short.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return array<string, mixed>
	 */
	protected static function attrs_subset( array $attrs ) {
		$subset = array();

		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) || null === $value ) {
				continue;
			}

			if ( is_string( $value ) && strlen( $value ) > self::ATTR_VALUE_LIMIT ) {
				$value = substr( $value, 0, self::ATTR_VALUE_LIMIT );
			}

			$subset[ (string) $key ] = $value;
		}

		return $subset;
	}
}
