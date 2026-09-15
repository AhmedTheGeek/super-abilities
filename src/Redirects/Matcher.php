<?php
/**
 * Request to rule matching.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which rule a request matches and what response it produces.
 *
 * Both methods are pure: they take the rule set as an argument and touch neither the
 * database nor the response, which is what makes the runtime handler testable.
 *
 * @since 0.2.0
 */
class Matcher {

	/**
	 * Finds the rule that answers a request.
	 *
	 * The query-less source is tried first, which can only match rules with
	 * `match_query` off; the source with its normalized query string is tried second
	 * and only matches rules with `match_query` on. Disabled rules are skipped.
	 *
	 * @since 0.2.0
	 *
	 * @param string                           $path  Normalized request path.
	 * @param string                           $query Normalized request query string.
	 * @param array<int, array<string, mixed>> $rules Rule rows.
	 * @return array<string, mixed>|null The matching rule, or null.
	 */
	public static function match( $path, $query, array $rules ) {
		$path  = Rules::normalize_path( $path );
		$query = Rules::normalize_query( $query );

		if ( '' === $path ) {
			return null;
		}

		$with_query = Rules::key( $path, $query, true );

		$plain = null;
		$exact = null;

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['source'] ) || empty( $rule['enabled'] ) ) {
				continue;
			}

			$source      = (string) $rule['source'];
			$match_query = ! empty( $rule['match_query'] );

			if ( ! $match_query && null === $plain && $source === $path ) {
				$plain = $rule;
				continue;
			}

			if ( $match_query && null === $exact && '' !== $query && $source === $with_query ) {
				$exact = $rule;
			}
		}

		if ( null !== $plain ) {
			return $plain;
		}

		return $exact;
	}

	/**
	 * Turns a matched rule into the response it should produce.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $rule Matched rule row.
	 * @return array{status: int, target: string, gone: bool, external: bool, safe: bool}
	 */
	public static function resolve( array $rule ) {
		$status = isset( $rule['status'] ) ? (int) $rule['status'] : 301;

		if ( ! in_array( $status, Rules::STATUSES, true ) ) {
			$status = 301;
		}

		if ( 410 === $status ) {
			return array(
				'status'   => 410,
				'target'   => '',
				'gone'     => true,
				'external' => false,
				'safe'     => true,
			);
		}

		$target   = isset( $rule['target'] ) ? trim( (string) $rule['target'] ) : '';
		$external = Rules::is_external( $target );

		return array(
			'status'   => $status,
			'target'   => $target,
			'gone'     => false,
			'external' => $external,
			'safe'     => ! $external,
		);
	}
}
