<?php
/**
 * Updates a redirect rule.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Rule_Schema;
use SuperAbilities\Redirects\Rules;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Changes one rule, re-running every guard against the merged result.
 *
 * @since 0.2.0
 */
class Redirect_Update extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirect-update';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'redirects';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Update a redirect', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Changes any field of an existing redirect. Only the fields you send are touched, and the merged rule is put through the same guards as a new one: reserved sources, existing content at the source, duplicate sources, target validation and loop detection across the whole rule set. Send the fingerprint you read as expected_fingerprint and the call is refused with 409 if anyone changed the rule in the meantime. Setting the status to 410 clears the target. Pass dry_run to check without writing.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write_idempotent();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'manage_options' );
	}

	/**
	 * Plugin version this ability shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'id'                     => Schema::id( __( 'Rule id to change.', 'super-abilities' ) ),
				'source'                 => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'New source path.', 'super-abilities' ),
				),
				'target'                 => array(
					'type'        => 'string',
					'description' => __( 'New target. Ignored and cleared when the status is 410.', 'super-abilities' ),
				),
				'status'                 => array(
					'type'        => 'integer',
					'enum'        => Rules::STATUSES,
					'description' => __( 'New HTTP status.', 'super-abilities' ),
				),
				'match_query'            => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the query string is part of the source.', 'super-abilities' ),
				),
				'enabled'                => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the rule is served. Set it to false to park a rule without deleting it.', 'super-abilities' ),
				),
				'note'                   => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => __( 'New note.', 'super-abilities' ),
				),
				'expected_fingerprint'   => Schema::fingerprint( __( 'Fingerprint of the rule as you last read it. The write is refused with 409 when it no longer matches.', 'super-abilities' ) ),
				'allow_external'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow a target on another host.', 'super-abilities' ),
				),
				'allow_existing_content' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow a source that is the permalink of existing published content.', 'super-abilities' ),
				),
				'dry_run'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Run every check and report the outcome without writing anything.', 'super-abilities' ),
				),
			),
			array( 'id' )
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'updated'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the rule was written.', 'super-abilities' ),
				),
				'dry_run'      => array( 'type' => 'boolean' ),
				'changed'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Fields whose value this call changed.', 'super-abilities' ),
				),
				'redirect'     => Rule_Schema::rule(),
				'preview'      => Redirect_Create::preview_schema(),
				'warnings'     => Rule_Schema::warnings(),
				'chain'        => Rule_Schema::chain(),
				'chain_length' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'resolves_to'  => array( 'type' => 'string' ),
			),
			array( 'updated', 'dry_run', 'changed', 'preview', 'warnings', 'chain', 'chain_length', 'resolves_to' )
		);
	}

	/**
	 * Applies the change.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$dry_run = ! empty( $input['dry_run'] );
		$current = Store::get( $id );

		if ( null === $current ) {
			return $this->error(
				'not_found',
				__( 'No redirect rule has that id.', 'super-abilities' ),
				404
			);
		}

		$stale = $this->guard_fingerprint( $input, (string) $current['fingerprint'] );

		if ( null !== $stale ) {
			return $stale;
		}

		$merged = array(
			'source'      => array_key_exists( 'source', $input ) ? (string) $input['source'] : (string) $current['source'],
			'target'      => array_key_exists( 'target', $input ) ? (string) $input['target'] : (string) $current['target'],
			'status'      => array_key_exists( 'status', $input ) ? (int) $input['status'] : (int) $current['status'],
			'match_query' => array_key_exists( 'match_query', $input ) ? ! empty( $input['match_query'] ) : ! empty( $current['match_query'] ),
			'enabled'     => array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : ! empty( $current['enabled'] ),
			'note'        => array_key_exists( 'note', $input ) ? (string) $input['note'] : (string) $current['note'],
		);

		// A rule that becomes a 410 keeps no target, so clear it instead of refusing the call.
		if ( 410 === (int) $merged['status'] && ! array_key_exists( 'target', $input ) ) {
			$merged['target'] = '';
		}

		$checked = Rules::preflight(
			$merged,
			array(
				'allow_external'         => ! empty( $input['allow_external'] ),
				'allow_existing_content' => ! empty( $input['allow_existing_content'] ),
				'exclude_id'             => $id,
			),
			Store::all()
		);

		if ( is_wp_error( $checked['error'] ) ) {
			return $checked['error'];
		}

		$changed = array();

		foreach ( array( 'source', 'target', 'status', 'match_query', 'enabled', 'note' ) as $field ) {
			$before = 'match_query' === $field || 'enabled' === $field
				? ( empty( $current[ $field ] ) ? 0 : 1 )
				: $current[ $field ];
			$after  = 'match_query' === $field || 'enabled' === $field
				? ( empty( $checked[ $field ] ) ? 0 : 1 )
				: $checked[ $field ];

			if ( (string) $before !== (string) $after ) {
				$changed[] = $field;
			}
		}

		$result = array(
			'updated'      => false,
			'dry_run'      => $dry_run,
			'changed'      => $changed,
			'preview'      => Redirect_Create::preview( $checked ),
			'warnings'     => $checked['warnings'],
			'chain'        => $checked['chain'],
			'chain_length' => (int) $checked['chain_length'],
			'resolves_to'  => (string) $checked['resolves_to'],
		);

		if ( $dry_run ) {
			$result['redirect'] = $current;

			return $result;
		}

		$stored = Store::update(
			$id,
			array(
				'source'      => $checked['source'],
				'target'      => $checked['target'],
				'status'      => $checked['status'],
				'match_query' => $checked['match_query'],
				'enabled'     => $checked['enabled'],
				'note'        => $checked['note'],
			)
		);

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$this->note_object( 'redirect', $id );

		$result['updated']  = true;
		$result['redirect'] = $stored;

		return $result;
	}
}
