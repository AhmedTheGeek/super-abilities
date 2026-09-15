<?php
/**
 * Access to the user global styles post.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Design;

use SuperAbilities\Support\Error;
use SuperAbilities\Support\Fingerprint;
use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `wp_global_styles` post that holds the user's theme.json.
 *
 * WordPress keeps user global styles in a single post per theme, of the
 * `wp_global_styles` post type, whose content is the user half of theme.json
 * encoded as JSON. Core creates that post on demand, which is why this works on
 * classic themes too even though nothing renders the result there.
 *
 * Nothing in this class ever touches a theme.json file on disk.
 *
 * @since 0.2.0
 */
class Global_Styles {

	/**
	 * Post type holding user global styles.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const POST_TYPE = 'wp_global_styles';

	/**
	 * Top level keys a user global styles document may contain.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const DATA_KEYS = array( 'settings', 'styles' );

	/**
	 * The stylesheet slug of the active theme.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function theme() {
		return (string) get_stylesheet();
	}

	/**
	 * The theme.json schema version this site writes.
	 *
	 * WordPress 7.1 renamed `WP_Theme_JSON::LATEST_VERSION` to `LATEST_SCHEMA`, so
	 * both spellings are tried before falling back to the oldest version core still
	 * understands.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public static function version() {
		if ( ! class_exists( 'WP_Theme_JSON' ) ) {
			return 2;
		}

		foreach ( array( 'WP_Theme_JSON::LATEST_SCHEMA', 'WP_Theme_JSON::LATEST_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				$version = (int) constant( $constant );

				if ( $version > 0 ) {
					return $version;
				}
			}
		}

		return 2;
	}

	/**
	 * Whether the active theme (or its parent) ships a theme.json.
	 *
	 * Without one, core refuses to create the user global styles post, so there is
	 * nothing to read or write.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function theme_has_theme_json() {
		return function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json();
	}

	/**
	 * Id of the user global styles post for the active theme.
	 *
	 * Core creates the post when it does not exist yet.
	 *
	 * @since 0.2.0
	 *
	 * @return int Post id, or 0 when core could not provide one.
	 */
	public static function post_id() {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return 0;
		}

		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}

	/**
	 * The stored user global styles document.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Global styles post id.
	 * @return array<string, mixed> Decoded document, always with `settings` and `styles` keys.
	 */
	public static function read( $post_id ) {
		$post = $post_id > 0 ? get_post( (int) $post_id ) : null;
		$raw  = null === $post ? '' : (string) $post->post_content;
		$data = '' === trim( $raw ) ? array() : json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return self::shape( $data );
	}

	/**
	 * Normalizes a document to `settings` plus `styles`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Raw document.
	 * @return array<string, mixed>
	 */
	public static function shape( array $data ) {
		$shaped = array();

		foreach ( self::DATA_KEYS as $key ) {
			$shaped[ $key ] = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
		}

		return $shaped;
	}

	/**
	 * Fingerprints a user global styles document.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Document as returned by {@see Global_Styles::read()}.
	 * @return string
	 */
	public static function fingerprint( array $data ) {
		return Fingerprint::of_array( self::shape( $data ) );
	}

	/**
	 * Checks a document by round-tripping it through `WP_Theme_JSON`.
	 *
	 * The document is stored exactly as the caller sent it, the way core's own
	 * global styles endpoint stores it, so that the site editor keeps reading the
	 * shape it wrote. The round trip is what tells us the document is usable at
	 * all, and comparing it against what the sanitizer kept tells the caller which
	 * paths WordPress is going to ignore.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Document to validate.
	 * @return array{document: array<string, mixed>, dropped: array<int, string>}|WP_Error
	 */
	public static function validate( array $data ) {
		if ( ! class_exists( 'WP_Theme_JSON' ) ) {
			return Error::make(
				'unsupported',
				__( 'This WordPress install has no theme.json support.', 'super-abilities' )
			);
		}

		foreach ( self::DATA_KEYS as $key ) {
			if ( isset( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
				return Error::make(
					'invalid_input',
					sprintf(
						/* translators: %s: Property name, `settings` or `styles`. */
						__( 'The "%s" property must be an object.', 'super-abilities' ),
						$key
					)
				);
			}
		}

		$document         = self::shape( $data );
		$probe            = $document;
		$probe['version'] = self::version();

		try {
			$theme_json = new WP_Theme_JSON( $probe, 'custom' );
			$raw        = $theme_json->get_raw_data();
		} catch ( \Throwable $throwable ) {
			return Error::make(
				'invalid_input',
				sprintf(
					/* translators: %s: Error message from WP_Theme_JSON. */
					__( 'WordPress rejected the global styles document: %s', 'super-abilities' ),
					$throwable->getMessage()
				)
			);
		}

		if ( ! is_array( $raw ) ) {
			return Error::make(
				'invalid_input',
				__( 'WordPress returned no usable global styles document.', 'super-abilities' )
			);
		}

		return array(
			'document' => $document,
			'dropped'  => self::missing_paths( $document, self::shape( $raw ), '' ),
		);
	}

	/**
	 * Lists the paths present in one tree and missing from another.
	 *
	 * Only the first three levels are reported, which is deep enough to name a
	 * misspelled style property without producing a wall of text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $sent   The document the caller sent.
	 * @param array<string, mixed> $kept   The document WordPress kept.
	 * @param string               $prefix Path prefix, empty at the top level.
	 * @param int                  $depth  Current depth.
	 * @return array<int, string>
	 */
	protected static function missing_paths( array $sent, array $kept, $prefix, $depth = 0 ) {
		$paths = array();

		foreach ( $sent as $key => $value ) {
			if ( is_int( $key ) ) {
				continue;
			}

			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( ! array_key_exists( $key, $kept ) ) {
				$paths[] = $path;

				continue;
			}

			if ( $depth < 2 && is_array( $value ) && is_array( $kept[ $key ] ) ) {
				$paths = array_merge( $paths, self::missing_paths( $value, $kept[ $key ], $path, $depth + 1 ) );
			}
		}

		return $paths;
	}

	/**
	 * Recursively merges a patch into a document.
	 *
	 * Objects are merged key by key, lists and scalars replace what was there, and
	 * a null value removes the key, which is the only way to unset a style.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $base  Current document.
	 * @param array<string, mixed> $patch Incoming changes.
	 * @return array<string, mixed>
	 */
	public static function merge( array $base, array $patch ) {
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $base[ $key ] );

				continue;
			}

			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $value ) && ! self::is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );

				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Writes a document to the global styles post.
	 *
	 * The write goes through `wp_update_post()` so that a revision is created and
	 * the change can be rolled back from the site editor.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $post_id Global styles post id.
	 * @param array<string, mixed> $data    Document to store.
	 * @return int|WP_Error The post id on success.
	 */
	public static function write( $post_id, array $data ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( null === $post || self::POST_TYPE !== $post->post_type ) {
			return Error::make(
				'not_found',
				__( 'This site has no global styles post for the active theme.', 'super-abilities' )
			);
		}

		$document                                = self::shape( $data );
		$document['isGlobalStylesUserThemeJSON'] = true;
		$document['version']                     = self::version();

		$encoded = wp_json_encode( $document );

		if ( ! is_string( $encoded ) ) {
			return Error::make(
				'invalid_input',
				__( 'The global styles document could not be encoded as JSON.', 'super-abilities' )
			);
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $encoded,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}

		return (int) $updated;
	}

	/**
	 * The merged settings and styles WordPress resolves for the front end.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function merged() {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return array();
		}

		$merged = WP_Theme_JSON_Resolver::get_merged_data( 'custom' );
		$raw    = $merged->get_raw_data();

		return is_array( $raw ) ? self::shape( $raw ) : array();
	}

	/**
	 * Whether an array is a list rather than a map.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, mixed> $value Value to test.
	 * @return bool
	 */
	protected static function is_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
