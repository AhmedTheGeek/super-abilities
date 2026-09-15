<?php
/**
 * Content safety checks for block writes.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Blocks;

use SuperAbilities\Support\Error;
use WP_Block_Type_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses block payloads that would put executable content into post content.
 *
 * An agent writing blocks is writing HTML that the site will render for every
 * visitor, so the markup is checked before it is serialized rather than afterwards:
 * inline scripts, PHP open tags and `javascript:` URLs in block attributes are
 * rejected outright, and with `strict` a block name that no block type has
 * registered is rejected too, which catches typos before they turn into an invalid
 * block warning in the editor.
 *
 * @since 0.2.0
 */
class Block_Guard {

	/**
	 * Substrings that must not appear anywhere in block markup.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const FORBIDDEN_MARKUP = array( '<script', '<?php', '<?=', '</script' );

	/**
	 * Checks one block and its whole subtree.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $block  Normalized block.
	 * @param bool                 $strict Optional. Whether unregistered block names are refused. Default false.
	 * @param string               $path   Optional. Path reported in the error data. Default empty.
	 * @return WP_Error|null Null when the block is acceptable.
	 */
	public static function check_block( array $block, $strict = false, $path = '' ) {
		$name = Block_Tree::public_name( isset( $block['blockName'] ) ? $block['blockName'] : null );

		if ( $strict && ! self::is_registered( $name ) ) {
			return self::fail(
				'unknown_block',
				sprintf(
					/* translators: %s: Block name. */
					__( 'No block type named "%s" is registered on this site. Drop strict to write it anyway.', 'super-abilities' ),
					$name
				),
				$path,
				array( 'name' => $name )
			);
		}

		$chunks = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();

		foreach ( $chunks as $chunk ) {
			if ( null === $chunk ) {
				continue;
			}

			$error = self::check_markup( (string) $chunk, $path );

			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$error = self::check_attrs( $attrs, $path );

		if ( $error instanceof WP_Error ) {
			return $error;
		}

		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$child_path = '' === (string) $path ? (string) $index : $path . '.' . $index;
			$error      = self::check_block( $child, $strict, $child_path );

			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Checks a list of blocks and their subtrees.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Normalized blocks.
	 * @param bool                             $strict Optional. Whether unregistered block names are refused. Default false.
	 * @return WP_Error|null Null when every block is acceptable.
	 */
	public static function check_blocks( array $blocks, $strict = false ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$error = self::check_block( $block, $strict, (string) $index );

			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Checks a raw markup string.
	 *
	 * @since 0.2.0
	 *
	 * @param string $markup Markup to check.
	 * @param string $path   Optional. Path reported in the error data. Default empty.
	 * @return WP_Error|null Null when the markup is acceptable.
	 */
	public static function check_markup( $markup, $path = '' ) {
		$markup = (string) $markup;

		foreach ( self::FORBIDDEN_MARKUP as $needle ) {
			if ( false !== stripos( $markup, $needle ) ) {
				return self::fail(
					'unsafe_content',
					sprintf(
						/* translators: %s: The rejected substring, for example "<script". */
						__( 'The content contains "%s", which this ability never writes into post content.', 'super-abilities' ),
						$needle
					),
					$path,
					array( 'found' => $needle )
				);
			}
		}

		return null;
	}

	/**
	 * Checks block attributes for script URLs and embedded markup.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, mixed> $attrs Block attributes.
	 * @param string                   $path  Optional. Path reported in the error data. Default empty.
	 * @return WP_Error|null Null when the attributes are acceptable.
	 */
	public static function check_attrs( array $attrs, $path = '' ) {
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				$error = self::check_attrs( $value, $path );

				if ( $error instanceof WP_Error ) {
					return $error;
				}

				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( 1 === preg_match( '~(?:javascript|vbscript)\s*(?:&#\d+;|&colon;|:)~i', $value ) ) {
				return self::fail(
					'unsafe_content',
					sprintf(
						/* translators: %s: Block attribute name. */
						__( 'The block attribute "%s" holds a script URL, which this ability never writes into post content.', 'super-abilities' ),
						(string) $key
					),
					$path,
					array( 'attr' => (string) $key )
				);
			}

			$error = self::check_markup( $value, $path );

			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Whether a block name belongs to a registered block type.
	 *
	 * Freeform fragments are always accepted: they are what the parser calls the
	 * classic HTML a post written before the block editor is made of.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name Block name.
	 * @return bool
	 */
	public static function is_registered( $name ) {
		$name = (string) $name;

		if ( '' === $name || Block_Tree::FREEFORM === $name ) {
			return true;
		}

		if ( ! class_exists( WP_Block_Type_Registry::class ) ) {
			return true;
		}

		return null !== WP_Block_Type_Registry::get_instance()->get_registered( $name );
	}

	/**
	 * Builds the error a failed check returns.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $code    Unprefixed error code.
	 * @param string               $message Human readable message.
	 * @param string               $path    Block path, or an empty string.
	 * @param array<string, mixed> $data    Optional. Extra error data. Default empty array.
	 * @return WP_Error
	 */
	protected static function fail( $code, $message, $path, array $data = array() ) {
		$data['status'] = 400;

		if ( '' !== (string) $path ) {
			$data['path'] = (string) $path;
		}

		return Error::make( $code, $message, $data );
	}
}
