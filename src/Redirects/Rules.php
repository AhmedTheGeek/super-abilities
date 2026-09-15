<?php
/**
 * Redirect normalization, guards and chain analysis.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Redirects;

use SuperAbilities\Support\Error;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The pure rules behind every redirect write.
 *
 * Nothing in here touches the database: the caller passes the current rule set in,
 * which keeps normalization, the reserved path list, target validation and loop
 * detection unit testable and side effect free.
 *
 * @since 0.2.0
 */
class Rules {

	/**
	 * Redirect statuses this module is willing to send.
	 *
	 * @since 0.2.0
	 * @var array<int, int>
	 */
	const STATUSES = array( 301, 302, 307, 308, 410 );

	/**
	 * Paths that can never be the source of a redirect.
	 *
	 * A path is reserved when it equals one of these entries, when it sits below one
	 * of them, or when it starts with the stem of an entry ending in `*`.
	 *
	 * @since 0.2.0
	 * @var array<int, string>
	 */
	const RESERVED_PATHS = array(
		'/',
		'/wp-admin',
		'/wp-login.php',
		'/wp-json',
		'/wp-content',
		'/wp-includes',
		'/xmlrpc.php',
		'/wp-cron.php',
		'/wp-signup.php',
		'/wp-activate.php',
		'/wp-comments-post.php',
		'/feed',
		'/.well-known',
		'/robots.txt',
		'/sitemap.xml',
		'/wp-sitemap*',
	);

	/**
	 * Maximum number of hops followed while analysing a chain.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_HOPS = 10;

	/**
	 * Longest source we can store, because the column is indexed.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_SOURCE_LENGTH = 191;

	/**
	 * Normalizes the path part of a source.
	 *
	 * Accepts a bare path, a path with a query string or a full URL, strips the scheme
	 * and host, lowercases what is left, collapses repeated slashes, forces a leading
	 * slash and removes the trailing slash from everything but the root.
	 *
	 * @since 0.2.0
	 *
	 * @param string $raw Raw path, path with query, or absolute URL.
	 * @return string Normalized path. Empty string when nothing usable was given.
	 */
	public static function normalize_path( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( false === strpos( $raw, '://' ) ) {
			// `//a/b` is a host to `wp_parse_url()`, but as a source it means the path `/a/b`.
			$raw = (string) preg_replace( '#^/{2,}#', '/', $raw );
		}

		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );

		if ( '' === $path && 0 !== strpos( $raw, '/' ) && false === strpos( $raw, '://' ) ) {
			// A value `wp_parse_url()` read as a host rather than a path, such as `old-page`.
			$bare = (string) preg_replace( '/[?#].*$/', '', $raw );
			$path = '' === $bare ? '' : '/' . $bare;
		}

		$path = strtolower( $path );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );

		if ( '' === $path ) {
			return '';
		}

		if ( 0 !== strpos( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return '/' === $path ? '/' : untrailingslashit( $path );
	}

	/**
	 * Normalizes a query string so that equivalent queries compare equal.
	 *
	 * Parameters are lowercased and sorted, so `?b=2&a=1` and `?a=1&b=2` normalize to
	 * the same string. The leading `?` is never part of the result.
	 *
	 * @since 0.2.0
	 *
	 * @param string $raw Raw query string, with or without a leading `?`.
	 * @return string Normalized query string, or an empty string.
	 */
	public static function normalize_query( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( false !== strpos( $raw, '?' ) ) {
			$raw = (string) substr( $raw, (int) strpos( $raw, '?' ) + 1 );
		}

		$raw   = strtolower( $raw );
		$pairs = array();

		foreach ( explode( '&', $raw ) as $pair ) {
			$pair = trim( $pair );

			if ( '' !== $pair ) {
				$pairs[] = $pair;
			}
		}

		sort( $pairs );

		return implode( '&', array_unique( $pairs ) );
	}

	/**
	 * Splits a request URI into a normalized path and a normalized query string.
	 *
	 * @since 0.2.0
	 *
	 * @param string $request Request URI, path with query, or absolute URL.
	 * @return array{path: string, query: string}
	 */
	public static function split( $request ) {
		$request = (string) $request;
		$query   = (string) wp_parse_url( $request, PHP_URL_QUERY );

		return array(
			'path'  => self::normalize_path( $request ),
			'query' => self::normalize_query( $query ),
		);
	}

	/**
	 * Builds the stored form of a source.
	 *
	 * With `match_query` off the query string is dropped; with it on the normalized
	 * query is appended after a `?`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $raw         Raw source.
	 * @param bool   $match_query Whether the query string is part of the source.
	 * @return string Normalized source, or an empty string when the input was unusable.
	 */
	public static function normalize_source( $raw, $match_query = false ) {
		$parts = self::split( $raw );

		if ( '' === $parts['path'] ) {
			return '';
		}

		if ( ! $match_query || '' === $parts['query'] ) {
			return $parts['path'];
		}

		return $parts['path'] . '?' . $parts['query'];
	}

	/**
	 * The lookup key a path and query pair resolves to for a given rule flavour.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path        Normalized path.
	 * @param string $query       Normalized query string.
	 * @param bool   $match_query Whether the query is part of the key.
	 * @return string
	 */
	public static function key( $path, $query, $match_query = false ) {
		$path  = (string) $path;
		$query = (string) $query;

		if ( ! $match_query || '' === $query ) {
			return $path;
		}

		return $path . '?' . $query;
	}

	/**
	 * Every path prefix that may not be redirected on this site.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public static function reserved_paths() {
		$paths = self::RESERVED_PATHS;

		$paths[] = '/' . ltrim( (string) rest_get_url_prefix(), '/' );

		foreach ( array( wp_login_url(), admin_url(), content_url(), includes_url() ) as $url ) {
			$path = self::normalize_path( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );

			if ( '' !== $path && '/' !== $path ) {
				$paths[] = $path;
			}
		}

		$home = self::normalize_path( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( '' !== $home && '/' !== $home ) {
			foreach ( self::RESERVED_PATHS as $entry ) {
				if ( '/' !== $entry ) {
					$paths[] = $home . $entry;
				}
			}
		}

		/**
		 * Filters the path prefixes that can never be the source of a redirect.
		 *
		 * Entries are compared against the normalized, lowercased request path. A path
		 * matches when it equals an entry, when it sits below it, or when the entry ends
		 * in `*` and the path starts with the stem. The root `/` only ever matches itself.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, string> $paths Reserved path prefixes.
		 */
		$paths = (array) apply_filters( 'super_abilities_reserved_redirect_paths', array_values( array_unique( $paths ) ) );

		$clean = array();

		foreach ( $paths as $entry ) {
			if ( is_array( $entry ) || is_object( $entry ) ) {
				continue;
			}

			$entry = strtolower( trim( (string) $entry ) );

			if ( '' !== $entry ) {
				$clean[] = $entry;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The reserved prefix a path falls under, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Normalized path.
	 * @return string|null The matching reserved entry, or null when the path is free.
	 */
	public static function reserved_match( $path ) {
		$path = self::normalize_path( $path );

		if ( '' === $path ) {
			return null;
		}

		foreach ( self::reserved_paths() as $entry ) {
			if ( '*' === substr( $entry, -1 ) ) {
				$stem = rtrim( substr( $entry, 0, -1 ), '/' );

				if ( '' !== $stem && 0 === strpos( $path, $stem ) ) {
					return $entry;
				}

				continue;
			}

			$entry = '/' === $entry ? '/' : untrailingslashit( $entry );

			if ( $path === $entry ) {
				return $entry;
			}

			if ( '/' !== $entry && 0 === strpos( $path, $entry . '/' ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Whether a path may not be redirected.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	public static function is_reserved( $path ) {
		return null !== self::reserved_match( $path );
	}

	/**
	 * The host this site answers on.
	 *
	 * @since 0.2.0
	 *
	 * @return string Lowercased host, or an empty string when it cannot be determined.
	 */
	public static function site_host() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return strtolower( $host );
	}

	/**
	 * Whether a target points at another host.
	 *
	 * @since 0.2.0
	 *
	 * @param string $target Target as stored.
	 * @return bool False for relative targets and for absolute URLs on this host.
	 */
	public static function is_external( $target ) {
		$target = trim( (string) $target );

		if ( '' === $target || 0 === strpos( $target, '/' ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		return self::site_host() !== $host;
	}

	/**
	 * Published content already living at a path, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Normalized path.
	 * @return array{id: int, type: string, status: string}|null
	 */
	public static function content_at( $path ) {
		$path = self::normalize_path( $path );

		if ( '' === $path || '/' === $path ) {
			return null;
		}

		$post_id = (int) url_to_postid( home_url( $path ) );

		if ( $post_id < 1 ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
			'id'     => (int) $post->ID,
			'type'   => (string) $post->post_type,
			'status' => (string) $post->post_status,
		);
	}

	/**
	 * Whether content found at a path counts as publicly served content.
	 *
	 * @since 0.2.0
	 *
	 * @param array{id: int, type: string, status: string}|null $content Result of {@see Rules::content_at()}.
	 * @return bool
	 */
	public static function content_is_public( $content ) {
		if ( ! is_array( $content ) ) {
			return false;
		}

		$status = isset( $content['status'] ) ? (string) $content['status'] : '';

		if ( 'publish' === $status ) {
			return true;
		}

		return 'attachment' === ( isset( $content['type'] ) ? (string) $content['type'] : '' ) && 'inherit' === $status;
	}

	/**
	 * Validates and normalizes a redirect target.
	 *
	 * @since 0.2.0
	 *
	 * @param string $target         Raw target.
	 * @param bool   $allow_external Whether targets on other hosts are allowed.
	 * @return array{target: string, external: bool, host: string}|WP_Error
	 */
	public static function validate_target( $target, $allow_external = false ) {
		$target = trim( (string) $target );

		if ( '' === $target ) {
			return Error::make(
				'invalid_input',
				__( 'A target is required for every status other than 410.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 === strpos( $target, '//' ) ) {
			return Error::make(
				'invalid_target',
				__( 'Protocol relative targets such as "//example.com" are not allowed. Write the full https URL instead.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) && ! preg_match( '#^https?://#i', $target ) ) {
			return Error::make(
				'invalid_target',
				__( 'Only relative paths and absolute http or https URLs can be used as a target.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 === strpos( $target, '/' ) ) {
			$target = '/' . ltrim( (string) preg_replace( '#/{2,}#', '/', $target ), '/' );

			return array(
				'target'   => $target,
				'external' => false,
				'host'     => self::site_host(),
			);
		}

		if ( ! preg_match( '#^https?://#i', $target ) ) {
			return Error::make(
				'invalid_target',
				__( 'A target must either start with a slash or be an absolute http or https URL.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		if ( ! wp_http_validate_url( $target ) ) {
			return Error::make(
				'invalid_target',
				__( 'That target URL was refused by WordPress as unsafe. Private, loopback and non standard port addresses cannot be used.', 'super-abilities' ),
				array( 'status' => 400 )
			);
		}

		$host     = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		$external = '' !== $host && self::site_host() !== $host;

		if ( $external && ! $allow_external ) {
			return Error::make(
				'external_target',
				sprintf(
					/* translators: %s: Host name of the redirect target. */
					__( 'The target points at "%s", which is not this site. Pass allow_external to send visitors off site.', 'super-abilities' ),
					$host
				),
				array(
					'status' => 403,
					'host'   => $host,
				)
			);
		}

		return array(
			'target'   => $target,
			'external' => $external,
			'host'     => $host,
		);
	}

	/**
	 * Validates a redirect status.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $status Raw status.
	 * @return int|WP_Error
	 */
	public static function validate_status( $status ) {
		$status = (int) $status;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return Error::make(
				'invalid_input',
				sprintf(
					/* translators: %s: Comma separated list of allowed HTTP statuses. */
					__( 'The status must be one of %s.', 'super-abilities' ),
					implode( ', ', array_map( 'strval', self::STATUSES ) )
				),
				array( 'status' => 400 )
			);
		}

		return $status;
	}

	/**
	 * Follows the redirect chain starting at a path.
	 *
	 * @since 0.2.0
	 *
	 * @param string                           $path  Normalized start path.
	 * @param string                           $query Normalized start query string.
	 * @param array<int, array<string, mixed>> $rules Rule rows to follow.
	 * @param int                              $max   Optional. Maximum hops. Default {@see Rules::MAX_HOPS}.
	 * @return array{chain: array<int, array<string, mixed>>, chain_length: int, resolves_to: string, loop: bool, loop_at: string, truncated: bool}
	 */
	public static function follow( $path, $query, array $rules, $max = self::MAX_HOPS ) {
		$max = max( 1, (int) $max );

		$chain       = array();
		$visited     = array();
		$loop        = false;
		$loop_at     = '';
		$truncated   = false;
		$resolves_to = '';
		$current     = array(
			'path'  => self::normalize_path( $path ),
			'query' => self::normalize_query( $query ),
		);

		while ( true ) {
			$seen = self::key( $current['path'], $current['query'], true );

			if ( isset( $visited[ $seen ] ) ) {
				$loop    = true;
				$loop_at = $seen;
				break;
			}

			$visited[ $seen ] = true;

			$rule = Matcher::match( $current['path'], $current['query'], $rules );

			if ( null === $rule ) {
				break;
			}

			$status      = (int) $rule['status'];
			$target      = 410 === $status ? '' : (string) $rule['target'];
			$chain[]     = array(
				'id'     => isset( $rule['id'] ) ? (int) $rule['id'] : 0,
				'source' => (string) $rule['source'],
				'target' => $target,
				'status' => $status,
			);
			$resolves_to = $target;

			if ( 410 === $status ) {
				break;
			}

			if ( count( $chain ) >= $max ) {
				$truncated = true;
				break;
			}

			if ( self::is_external( $target ) ) {
				break;
			}

			$current = self::split( $target );
		}

		return array(
			'chain'        => $chain,
			'chain_length' => count( $chain ),
			'resolves_to'  => $resolves_to,
			'loop'         => $loop,
			'loop_at'      => $loop_at,
			'truncated'    => $truncated,
		);
	}

	/**
	 * Runs every guard against a rule that is about to be written.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>             $data    Raw rule: `source`, `target`, `status`, `match_query`, `enabled`, `note`.
	 * @param array<string, mixed>             $options Optional. `allow_external`, `allow_existing_content`, `exclude_id`.
	 * @param array<int, array<string, mixed>> $rules   Optional. Existing rule rows, used for duplicate and loop detection.
	 * @return array<string, mixed> Normalized rule with `error` set to a `WP_Error` when a guard refused it.
	 */
	public static function preflight( array $data, array $options = array(), array $rules = array() ) {
		$allow_external = ! empty( $options['allow_external'] );
		$allow_content  = ! empty( $options['allow_existing_content'] );
		$exclude_id     = isset( $options['exclude_id'] ) ? (int) $options['exclude_id'] : 0;
		$match_query    = ! empty( $data['match_query'] );
		$enabled        = ! isset( $data['enabled'] ) || ! empty( $data['enabled'] );
		$note           = isset( $data['note'] ) ? sanitize_text_field( (string) $data['note'] ) : '';
		$raw_source     = isset( $data['source'] ) ? (string) $data['source'] : '';
		$raw_target     = isset( $data['target'] ) ? (string) $data['target'] : '';

		$result = array(
			'source'       => '',
			'target'       => '',
			'status'       => 301,
			'match_query'  => $match_query ? 1 : 0,
			'enabled'      => $enabled ? 1 : 0,
			'note'         => $note,
			'external'     => false,
			'chain'        => array(),
			'chain_length' => 0,
			'resolves_to'  => '',
			'warnings'     => array(),
			'error'        => null,
		);

		$status = self::validate_status( isset( $data['status'] ) ? $data['status'] : 301 );

		if ( is_wp_error( $status ) ) {
			$result['error'] = $status;

			return $result;
		}

		$result['status'] = $status;

		$source = self::normalize_source( $raw_source, $match_query );

		if ( '' === $source ) {
			$result['error'] = Error::make(
				'invalid_input',
				__( 'A source path is required, for example "/old-page".', 'super-abilities' ),
				array( 'status' => 400 )
			);

			return $result;
		}

		if ( strlen( $source ) > self::MAX_SOURCE_LENGTH ) {
			$result['error'] = Error::make(
				'invalid_input',
				sprintf(
					/* translators: %d: Maximum number of characters. */
					__( 'The source is longer than %d characters, which is the most the index can hold.', 'super-abilities' ),
					self::MAX_SOURCE_LENGTH
				),
				array( 'status' => 400 )
			);

			return $result;
		}

		$result['source'] = $source;
		$parts            = self::split( $source );
		$reserved         = self::reserved_match( $parts['path'] );

		if ( null !== $reserved ) {
			$result['error'] = Error::make(
				'reserved_path',
				sprintf(
					/* translators: 1: Requested source path. 2: Reserved path prefix. */
					__( 'The source "%1$s" is reserved by WordPress ("%2$s") and cannot be redirected. Redirecting it would break the admin, the REST API, feeds or asset delivery.', 'super-abilities' ),
					$parts['path'],
					$reserved
				),
				array(
					'status'   => 403,
					'reserved' => $reserved,
				)
			);

			return $result;
		}

		if ( 410 === $status ) {
			if ( '' !== trim( $raw_target ) ) {
				$result['error'] = Error::make(
					'invalid_input',
					__( 'Status 410 tells the client the URL is gone for good, so the target must be empty.', 'super-abilities' ),
					array( 'status' => 400 )
				);

				return $result;
			}

			$result['target'] = '';
		} else {
			$target = self::validate_target( $raw_target, $allow_external );

			if ( is_wp_error( $target ) ) {
				$result['error'] = $target;

				return $result;
			}

			$result['target']   = $target['target'];
			$result['external'] = (bool) $target['external'];

			if ( ! $target['external'] && self::normalize_source( $target['target'], $match_query ) === $source ) {
				$result['error'] = Error::make(
					'loop_detected',
					sprintf(
						/* translators: %s: Redirect source. */
						__( 'The target resolves back to the source "%s", which is an immediate redirect loop.', 'super-abilities' ),
						$source
					),
					array( 'status' => 409 )
				);

				return $result;
			}
		}

		if ( ! $allow_content ) {
			$content = self::content_at( $parts['path'] );

			if ( self::content_is_public( $content ) && is_array( $content ) ) {
				$result['error'] = Error::make(
					'source_is_content',
					sprintf(
						/* translators: 1: Source path. 2: Post type. 3: Post id. */
						__( 'The source "%1$s" is the permalink of an existing published %2$s (id %3$d), so a redirect would hide it. Pass allow_existing_content to override.', 'super-abilities' ),
						$parts['path'],
						$content['type'],
						$content['id']
					),
					array(
						'status'    => 409,
						'object_id' => $content['id'],
						'post_type' => $content['type'],
					)
				);

				return $result;
			}
		}

		$duplicate = self::find_duplicate( $source, $result['match_query'], $rules, $exclude_id );

		if ( null !== $duplicate ) {
			$result['error'] = Error::make(
				'already_exists',
				sprintf(
					/* translators: 1: Source path. 2: Existing redirect id. */
					__( 'A redirect for "%1$s" already exists (id %2$d). Update that rule instead of creating a second one.', 'super-abilities' ),
					$source,
					(int) $duplicate['id']
				),
				array(
					'status'      => 409,
					'existing_id' => (int) $duplicate['id'],
				)
			);

			return $result;
		}

		$analysis = self::analyze( $result, $rules, $exclude_id );

		$result['chain']        = $analysis['chain'];
		$result['chain_length'] = $analysis['chain_length'];
		$result['resolves_to']  = $analysis['resolves_to'];

		if ( $analysis['loop'] && self::key( $parts['path'], $parts['query'], true ) === $analysis['loop_at'] ) {
			$result['error'] = Error::make(
				'loop_detected',
				sprintf(
					/* translators: %s: Redirect source. */
					__( 'Following the existing rules from this target leads back to "%s". Break the cycle before adding this redirect.', 'super-abilities' ),
					$source
				),
				array(
					'status' => 409,
					'chain'  => $analysis['chain'],
				)
			);

			return $result;
		}

		$result['warnings'] = self::warnings( $result, $analysis, $rules, $exclude_id );

		return $result;
	}

	/**
	 * Follows the chain a pending rule would create.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>             $rule       Normalized pending rule.
	 * @param array<int, array<string, mixed>> $rules      Existing rule rows.
	 * @param int                              $exclude_id Optional. Rule id being replaced. Default 0.
	 * @return array{chain: array<int, array<string, mixed>>, chain_length: int, resolves_to: string, loop: bool, loop_at: string, truncated: bool}
	 */
	public static function analyze( array $rule, array $rules, $exclude_id = 0 ) {
		$set   = self::without( $rules, (int) $exclude_id );
		$set[] = array(
			'id'          => (int) $exclude_id,
			'source'      => (string) $rule['source'],
			'match_query' => (int) $rule['match_query'],
			'target'      => (string) $rule['target'],
			'status'      => (int) $rule['status'],
			'enabled'     => 1,
		);

		$parts = self::split( (string) $rule['source'] );

		return self::follow( $parts['path'], $parts['query'], $set );
	}

	/**
	 * Builds the non fatal warnings for a pending rule.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>             $rule       Normalized pending rule.
	 * @param array<string, mixed>             $analysis   Result of {@see Rules::analyze()}.
	 * @param array<int, array<string, mixed>> $rules      Existing rule rows.
	 * @param int                              $exclude_id Optional. Rule id being replaced. Default 0.
	 * @return array<int, string>
	 */
	public static function warnings( array $rule, array $analysis, array $rules, $exclude_id = 0 ) {
		$warnings = array();
		$source   = (string) $rule['source'];

		if ( (int) $analysis['chain_length'] > 1 ) {
			$warnings[] = sprintf(
				/* translators: 1: Number of redirects in the chain. 2: Final target. */
				__( 'Visitors will follow %1$d redirects and end up at "%2$s". Point the source straight at the final target to keep it to one hop.', 'super-abilities' ),
				(int) $analysis['chain_length'],
				(string) $analysis['resolves_to']
			);
		}

		if ( ! empty( $analysis['loop'] ) ) {
			$warnings[] = sprintf(
				/* translators: %s: Path where the cycle was found. */
				__( 'The existing rules already loop at "%s". Visitors following this chain will be stopped by the browser.', 'super-abilities' ),
				(string) $analysis['loop_at']
			);
		}

		if ( ! empty( $analysis['truncated'] ) ) {
			$warnings[] = __( 'The chain is longer than ten hops and was not followed to the end.', 'super-abilities' );
		}

		foreach ( self::without( $rules, (int) $exclude_id ) as $existing ) {
			if ( empty( $existing['enabled'] ) ) {
				continue;
			}

			$existing_target = self::normalize_source( (string) $existing['target'], (bool) $rule['match_query'] );

			if ( '' !== $existing_target && $existing_target === $source && ! self::is_external( (string) $existing['target'] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: Existing redirect source. 2: New redirect source. */
					__( 'The existing redirect "%1$s" already points at "%2$s", so that rule now sends visitors through two hops.', 'super-abilities' ),
					(string) $existing['source'],
					$source
				);
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Finds a rule that already owns a source.
	 *
	 * @since 0.2.0
	 *
	 * @param string                           $source      Normalized source.
	 * @param int                              $match_query Whether the query is part of the source.
	 * @param array<int, array<string, mixed>> $rules       Existing rule rows.
	 * @param int                              $exclude_id  Optional. Rule id to ignore. Default 0.
	 * @return array<string, mixed>|null
	 */
	public static function find_duplicate( $source, $match_query, array $rules, $exclude_id = 0 ) {
		foreach ( self::without( $rules, (int) $exclude_id ) as $existing ) {
			if ( (string) $existing['source'] === (string) $source && (int) $existing['match_query'] === (int) $match_query ) {
				return $existing;
			}
		}

		return null;
	}

	/**
	 * Every rule except the one with a given id.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $rules Rule rows.
	 * @param int                              $id    Rule id to drop. Zero keeps everything.
	 * @return array<int, array<string, mixed>>
	 */
	public static function without( array $rules, $id ) {
		$id = (int) $id;

		if ( $id < 1 ) {
			return array_values( $rules );
		}

		$kept = array();

		foreach ( $rules as $rule ) {
			if ( isset( $rule['id'] ) && (int) $rule['id'] === $id ) {
				continue;
			}

			$kept[] = $rule;
		}

		return $kept;
	}
}
