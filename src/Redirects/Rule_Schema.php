<?php
/**
 * Shared output schema fragments for the redirect abilities.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Redirects;

use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one stored rule, so that all eight abilities agree on its shape.
 *
 * @since 0.2.0
 */
class Rule_Schema {

	/**
	 * Output schema for a single rule.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function rule() {
		return Schema::object(
			array(
				'id'          => Schema::id( __( 'Rule id.', 'super-abilities' ) ),
				'source'      => array(
					'type'        => 'string',
					'description' => __( 'Normalized source, always starting with a slash, with the query string appended when match_query is true.', 'super-abilities' ),
				),
				'match_query' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the query string is part of the source.', 'super-abilities' ),
				),
				'target'      => array(
					'type'        => 'string',
					'description' => __( 'Where visitors are sent. Empty for status 410.', 'super-abilities' ),
				),
				'status'      => array(
					'type'        => 'integer',
					'enum'        => Rules::STATUSES,
					'description' => __( 'HTTP status sent to the client.', 'super-abilities' ),
				),
				'enabled'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the rule is served.', 'super-abilities' ),
				),
				'hits'        => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many times the rule answered a request.', 'super-abilities' ),
				),
				'last_hit'    => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'When the rule last answered a request, or null when it never did.', 'super-abilities' ),
				),
				'created'     => Schema::iso_datetime( __( 'When the rule was created.', 'super-abilities' ) ),
				'updated'     => Schema::iso_datetime( __( 'When the rule was last written.', 'super-abilities' ) ),
				'created_by'  => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'User id that created the rule.', 'super-abilities' ),
				),
				'note'        => array(
					'type'        => 'string',
					'description' => __( 'Free text note stored with the rule.', 'super-abilities' ),
				),
				'fingerprint' => Schema::fingerprint( __( 'Pass this back as expected_fingerprint to reject a stale write.', 'super-abilities' ) ),
			),
			array(
				'id',
				'source',
				'match_query',
				'target',
				'status',
				'enabled',
				'hits',
				'last_hit',
				'created',
				'updated',
				'created_by',
				'note',
				'fingerprint',
			)
		);
	}

	/**
	 * Output schema for a followed redirect chain.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function chain() {
		return array(
			'type'        => 'array',
			'description' => __( 'The rules a visitor would pass through, in order.', 'super-abilities' ),
			'items'       => Schema::object(
				array(
					'id'     => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'source' => array( 'type' => 'string' ),
					'target' => array( 'type' => 'string' ),
					'status' => array( 'type' => 'integer' ),
				),
				array( 'id', 'source', 'target', 'status' )
			),
		);
	}

	/**
	 * Output schema for the warning list a write returns.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function warnings() {
		return array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Problems that did not stop the write, such as a chain that could be shortened.', 'super-abilities' ),
		);
	}
}
