<?php
/**
 * Creates a redirect rule.
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
 * Stores one new redirect after every guard has passed.
 *
 * @since 0.2.0
 */
class Redirect_Create extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirect-create';
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
		return __( 'Create a redirect', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Creates one redirect rule. The source is normalized to a lowercase path with a leading slash and no trailing slash, and is refused when WordPress needs it, when it is the permalink of a published post unless allow_existing_content is passed, when a rule already owns it, or when following the existing rules from the new target would come back to it. Targets must be a relative path or an absolute http or https URL that passes wp_http_validate_url(), and may only leave this host when allow_external is passed. Status 410 stores no target and answers "Gone". Pass dry_run to run every check and store nothing.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
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
				'source'                 => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Path to redirect from, for example "/old-page". A full URL on this site is accepted and reduced to its path.', 'super-abilities' ),
				),
				'target'                 => array(
					'type'        => 'string',
					'description' => __( 'Where to send visitors: a path starting with a slash, or an absolute http or https URL. Must be empty for status 410.', 'super-abilities' ),
				),
				'status'                 => array(
					'type'        => 'integer',
					'enum'        => Rules::STATUSES,
					'default'     => 301,
					'description' => __( 'HTTP status to send. 301 is permanent, 302 and 307 temporary, 308 permanent without changing the method, 410 means the URL is gone.', 'super-abilities' ),
				),
				'match_query'            => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Whether the query string is part of the source. With it off the rule matches the path whatever the query string is.', 'super-abilities' ),
				),
				'enabled'                => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether the rule starts out being served.', 'super-abilities' ),
				),
				'note'                   => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => __( 'Free text note stored with the rule, for example why it exists.', 'super-abilities' ),
				),
				'allow_external'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow a target on another host. Off by default, because sending your visitors elsewhere is rarely intended.', 'super-abilities' ),
				),
				'allow_existing_content' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow a source that is the permalink of an existing published post, page or attachment. That content becomes unreachable at that URL.', 'super-abilities' ),
				),
				'dry_run'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Run every check and report the outcome without writing anything.', 'super-abilities' ),
				),
			),
			array( 'source' )
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
				'created'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a rule was written.', 'super-abilities' ),
				),
				'dry_run'      => array( 'type' => 'boolean' ),
				'redirect'     => Rule_Schema::rule(),
				'preview'      => self::preview_schema(),
				'warnings'     => Rule_Schema::warnings(),
				'chain'        => Rule_Schema::chain(),
				'chain_length' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'resolves_to'  => array( 'type' => 'string' ),
			),
			array( 'created', 'dry_run', 'preview', 'warnings', 'chain', 'chain_length', 'resolves_to' )
		);
	}

	/**
	 * Schema of the normalized rule a write would store.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_schema() {
		return Schema::object(
			array(
				'source'      => array( 'type' => 'string' ),
				'target'      => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'integer' ),
				'match_query' => array( 'type' => 'boolean' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'note'        => array( 'type' => 'string' ),
				'external'    => array( 'type' => 'boolean' ),
			),
			array( 'source', 'target', 'status', 'match_query', 'enabled', 'note', 'external' )
		);
	}

	/**
	 * Turns a preflight result into the `preview` object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $checked Result of {@see Rules::preflight()}.
	 * @return array<string, mixed>
	 */
	public static function preview( array $checked ) {
		return array(
			'source'      => (string) $checked['source'],
			'target'      => (string) $checked['target'],
			'status'      => (int) $checked['status'],
			'match_query' => ! empty( $checked['match_query'] ),
			'enabled'     => ! empty( $checked['enabled'] ),
			'note'        => (string) $checked['note'],
			'external'    => ! empty( $checked['external'] ),
		);
	}

	/**
	 * Creates the rule.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$dry_run = ! empty( $input['dry_run'] );

		$checked = Rules::preflight(
			array(
				'source'      => isset( $input['source'] ) ? (string) $input['source'] : '',
				'target'      => isset( $input['target'] ) ? (string) $input['target'] : '',
				'status'      => isset( $input['status'] ) ? (int) $input['status'] : 301,
				'match_query' => ! empty( $input['match_query'] ),
				'enabled'     => ! isset( $input['enabled'] ) || ! empty( $input['enabled'] ),
				'note'        => isset( $input['note'] ) ? (string) $input['note'] : '',
			),
			array(
				'allow_external'         => ! empty( $input['allow_external'] ),
				'allow_existing_content' => ! empty( $input['allow_existing_content'] ),
			),
			Store::all()
		);

		if ( is_wp_error( $checked['error'] ) ) {
			return $checked['error'];
		}

		$result = array(
			'created'      => false,
			'dry_run'      => $dry_run,
			'preview'      => self::preview( $checked ),
			'warnings'     => $checked['warnings'],
			'chain'        => $checked['chain'],
			'chain_length' => (int) $checked['chain_length'],
			'resolves_to'  => (string) $checked['resolves_to'],
		);

		if ( $dry_run ) {
			return $result;
		}

		$stored = Store::insert( $checked );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$this->note_object( 'redirect', (int) $stored['id'] );

		$result['created']  = true;
		$result['redirect'] = $stored;

		return $result;
	}
}
