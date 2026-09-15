<?php
/**
 * Install source resolution.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Extensions;

use SuperAbilities\Plugin;
use SuperAbilities\Support\Error;
use SuperAbilities\Support\Http;
use SuperAbilities\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a `slug` or a `zip_url` into a package this site is allowed to download.
 *
 * A slug is looked up on WordPress.org through `plugins_api()` or `themes_api()`, which
 * always answer over HTTPS. A ZIP URL is only accepted when an administrator switched
 * on the `extensions_allow_zip_url` setting, and then only for HTTPS hosts that pass
 * `wp_http_validate_url()` and the `extensions_zip_hosts` allowlist. Local paths are
 * never a valid source.
 *
 * @since 0.2.0
 */
class Packages {

	/**
	 * Resolves the install source.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $args {
	 *     Source description.
	 *
	 *     @type string $type    Extension type, `plugin` or `theme`.
	 *     @type string $slug    WordPress.org slug. Ignored when `zip_url` is set.
	 *     @type string $zip_url HTTPS URL of a ZIP file.
	 *     @type bool   $probe   Whether to ask the host for the package size. Default true.
	 * }
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resolve( array $args ) {
		$type    = Target::normalize_type( isset( $args['type'] ) ? $args['type'] : '' );
		$slug    = isset( $args['slug'] ) ? trim( (string) $args['slug'] ) : '';
		$zip_url = isset( $args['zip_url'] ) ? trim( (string) $args['zip_url'] ) : '';
		$probe   = ! isset( $args['probe'] ) || ! empty( $args['probe'] );

		if ( '' !== $zip_url ) {
			return self::from_zip_url( $type, $zip_url, $probe );
		}

		if ( '' === $slug ) {
			return Error::make(
				'invalid_input',
				__( 'Pass either a WordPress.org slug or a zip_url.', 'super-abilities' )
			);
		}

		return self::from_wporg( $type, $slug, $probe );
	}

	/**
	 * Resolves a WordPress.org slug.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type  Extension type.
	 * @param string $slug  WordPress.org slug.
	 * @param bool   $probe Whether to ask for the package size.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_wporg( $type, $slug, $probe = true ) {
		$type = Target::normalize_type( $type );
		$slug = self::normalize_wporg_slug( $slug );

		if ( '' === $slug ) {
			return Error::make(
				'invalid_input',
				__( 'A WordPress.org slug may only contain letters, numbers, dots, dashes and underscores.', 'super-abilities' )
			);
		}

		Upgrades::bootstrap();

		if ( Target::TYPE_THEME === $type ) {
			$api = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'     => false,
						'requires'     => true,
						'requires_php' => true,
						'versions'     => false,
					),
				)
			);
		} else {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'          => false,
						'short_description' => false,
						'versions'          => false,
						'screenshots'       => false,
						'reviews'           => false,
						'banners'           => false,
						'icons'             => false,
						'requires'          => true,
						'requires_php'      => true,
						'tested'            => true,
					),
				)
			);
		}

		if ( is_wp_error( $api ) ) {
			return Error::make(
				'upstream_http',
				sprintf(
					/* translators: %s: Error message from the WordPress.org API. */
					__( 'WordPress.org could not be asked about that slug: %s', 'super-abilities' ),
					$api->get_error_message()
				),
				array( 'slug' => $slug )
			);
		}

		$info = is_object( $api ) ? get_object_vars( $api ) : (array) $api;

		if ( empty( $info ) ) {
			return Error::make( 'not_found', __( 'WordPress.org does not know that slug.', 'super-abilities' ), array( 'slug' => $slug ) );
		}

		$download = isset( $info['download_link'] ) ? (string) $info['download_link'] : '';
		$download = self::force_https( $download );

		if ( '' === $download ) {
			return Error::make(
				'not_found',
				__( 'WordPress.org returned no download link for that slug.', 'super-abilities' ),
				array( 'slug' => $slug )
			);
		}

		$validated = Http::validate_url( $download );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$last_updated = isset( $info['last_updated'] ) ? Time::parse( (string) $info['last_updated'] ) : null;

		return array(
			'source'       => 'wordpress.org',
			'type'         => $type,
			'slug'         => $slug,
			'name'         => isset( $info['name'] ) ? (string) wp_strip_all_tags( (string) $info['name'] ) : $slug,
			'version'      => isset( $info['version'] ) ? (string) $info['version'] : '',
			'requires_wp'  => Target::requirement( isset( $info['requires'] ) ? $info['requires'] : null ),
			'requires_php' => Target::requirement( isset( $info['requires_php'] ) ? $info['requires_php'] : null ),
			'tested'       => Target::requirement( isset( $info['tested'] ) ? $info['tested'] : null ),
			'last_updated' => null === $last_updated ? null : Time::iso( $last_updated ),
			'host'         => (string) wp_parse_url( $validated, PHP_URL_HOST ),
			'download_url' => (string) $validated,
			'size_bytes'   => $probe ? self::probe_size( (string) $validated ) : 0,
		);
	}

	/**
	 * Resolves a ZIP URL, if the site allows them at all.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type    Extension type.
	 * @param string $zip_url Candidate URL.
	 * @param bool   $probe   Whether to ask for the package size.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_zip_url( $type, $zip_url, $probe = true ) {
		$type = Target::normalize_type( $type );

		if ( ! self::zip_urls_allowed() ) {
			return Error::make(
				'forbidden',
				__( 'Installing from a ZIP URL is switched off for this site. An administrator can enable it under Settings, Super Abilities.', 'super-abilities' ),
				array( 'status' => 403 )
			);
		}

		$validated = Http::validate_url( $zip_url, array( 'hosts' => self::zip_hosts() ) );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$path = (string) wp_parse_url( $validated, PHP_URL_PATH );

		return array(
			'source'       => 'zip_url',
			'type'         => $type,
			'slug'         => self::normalize_wporg_slug( basename( $path, '.zip' ) ),
			'name'         => basename( $path ),
			'version'      => '',
			'requires_wp'  => null,
			'requires_php' => null,
			'tested'       => null,
			'last_updated' => null,
			'host'         => (string) wp_parse_url( $validated, PHP_URL_HOST ),
			'download_url' => (string) $validated,
			'size_bytes'   => $probe ? self::probe_size( (string) $validated ) : 0,
		);
	}

	/**
	 * The package description that is safe to return to a caller.
	 *
	 * The download URL never leaves the site: a ZIP URL may carry a token in its query
	 * string, so only its host is reported.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $package Resolved package.
	 * @return array<string, mixed>
	 */
	public static function public_info( array $package ) {
		return array(
			'source'       => isset( $package['source'] ) ? (string) $package['source'] : '',
			'slug'         => isset( $package['slug'] ) ? (string) $package['slug'] : '',
			'name'         => isset( $package['name'] ) ? (string) $package['name'] : '',
			'version'      => isset( $package['version'] ) ? (string) $package['version'] : '',
			'requires_wp'  => isset( $package['requires_wp'] ) ? $package['requires_wp'] : null,
			'requires_php' => isset( $package['requires_php'] ) ? $package['requires_php'] : null,
			'tested'       => isset( $package['tested'] ) ? $package['tested'] : null,
			'last_updated' => isset( $package['last_updated'] ) ? $package['last_updated'] : null,
			'host'         => isset( $package['host'] ) ? (string) $package['host'] : '',
			'size_bytes'   => isset( $package['size_bytes'] ) ? (int) $package['size_bytes'] : 0,
		);
	}

	/**
	 * Whether this site accepts ZIP URLs as an install source.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function zip_urls_allowed() {
		return (bool) Plugin::instance()->options()->get( 'extensions_allow_zip_url', false );
	}

	/**
	 * The host allowlist for ZIP URLs.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function zip_hosts() {
		$hosts = Plugin::instance()->options()->get( 'extensions_zip_hosts', array() );
		$clean = array();

		foreach ( (array) $hosts as $host ) {
			if ( is_array( $host ) || is_object( $host ) ) {
				continue;
			}

			$host = strtolower( trim( (string) $host ) );

			if ( '' !== $host ) {
				$clean[ $host ] = $host;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Reduces a caller supplied slug to the WordPress.org character set.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Raw slug.
	 * @return string Empty string when nothing usable is left.
	 */
	public static function normalize_wporg_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );

		if ( false !== strpos( $slug, '/' ) ) {
			$slug = dirname( $slug );
		}

		$slug = (string) preg_replace( '/\.php$/', '', $slug );
		$slug = (string) preg_replace( '/[^a-z0-9._-]/', '', $slug );

		return trim( $slug, '.-' );
	}

	/**
	 * Upgrades an `http://` WordPress.org download link to HTTPS.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	protected static function force_https( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		return (string) preg_replace( '#^http://#i', 'https://', $url );
	}

	/**
	 * Asks the host how large the package is.
	 *
	 * Failures are not an error: many hosts answer `HEAD` without a content length.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url Download URL.
	 * @return int Size in bytes, or zero when it is unknown.
	 */
	protected static function probe_size( $url ) {
		$head = Http::head( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $head ) || ! isset( $head['headers']['content-length'] ) ) {
			return 0;
		}

		$length = $head['headers']['content-length'];

		if ( is_array( $length ) ) {
			$length = reset( $length );
		}

		return max( 0, (int) $length );
	}
}
