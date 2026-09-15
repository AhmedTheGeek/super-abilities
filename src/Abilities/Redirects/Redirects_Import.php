<?php
/**
 * Imports a batch of redirect rules.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Redirects;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Redirects\Rules;
use SuperAbilities\Redirects\Store;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a whole batch of rules before writing any of it.
 *
 * Every rule is checked against the rules already stored *and* against the rules
 * earlier in the same batch, so an import can never leave the site half migrated: if
 * any rule fails, nothing is written and `errors` names the offending index.
 *
 * @since 0.2.0
 */
class Redirects_Import extends Abstract_Ability {

	/**
	 * Largest batch accepted in one call.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_RULES = 500;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'redirects-import';
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
		return __( 'Import redirects', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Imports up to five hundred redirect rules in one call. Every rule is validated first, against the rules already on the site and against the earlier rules of the same batch, and the import is all or nothing: if any rule fails a guard, nothing is written and errors lists the failures by their index in the input. A source that already has a rule is skipped by default, or rewritten when on_conflict is update. Pass dry_run to validate a file before committing to it.', 'super-abilities' );
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
				'rules'                  => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_RULES,
					'description' => __( 'The rules to import, in order.', 'super-abilities' ),
					'items'       => Schema::object(
						array(
							'source'      => array(
								'type'      => 'string',
								'minLength' => 1,
							),
							'target'      => array( 'type' => 'string' ),
							'status'      => array(
								'type' => 'integer',
								'enum' => Rules::STATUSES,
							),
							'match_query' => array( 'type' => 'boolean' ),
							'enabled'     => array( 'type' => 'boolean' ),
							'note'        => array(
								'type'      => 'string',
								'maxLength' => 255,
							),
						),
						array( 'source' )
					),
				),
				'on_conflict'            => array(
					'type'        => 'string',
					'enum'        => array( 'skip', 'update' ),
					'default'     => 'skip',
					'description' => __( 'What to do when a source already has a rule: leave the stored rule alone, or rewrite it from the imported one.', 'super-abilities' ),
				),
				'allow_external'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow targets on other hosts.', 'super-abilities' ),
				),
				'allow_existing_content' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow sources that are permalinks of existing published content.', 'super-abilities' ),
				),
				'dry_run'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Validate the whole batch and write nothing.', 'super-abilities' ),
				),
			),
			array( 'rules' )
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
				'written'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether anything was written. False on a dry run and whenever errors is not empty.', 'super-abilities' ),
				),
				'dry_run'  => array( 'type' => 'boolean' ),
				'created'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'updated'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'skipped'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'ids'      => array(
					'type'        => 'array',
					'items'       => Schema::id(),
					'description' => __( 'Ids of the rules that were created or updated.', 'super-abilities' ),
				),
				'errors'   => array(
					'type'        => 'array',
					'description' => __( 'One entry per rule that failed a guard. Non empty means nothing was written.', 'super-abilities' ),
					'items'       => Schema::object(
						array(
							'index'   => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'source'  => array( 'type' => 'string' ),
							'code'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
						array( 'index', 'source', 'code', 'message' )
					),
				),
				'warnings' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Chain and loop warnings collected while validating, prefixed with the rule index.', 'super-abilities' ),
				),
			),
			array( 'written', 'dry_run', 'created', 'updated', 'skipped', 'ids', 'errors', 'warnings' )
		);
	}

	/**
	 * Validates and applies the batch.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$rules = isset( $input['rules'] ) && is_array( $input['rules'] ) ? array_values( $input['rules'] ) : array();

		if ( empty( $rules ) ) {
			return $this->error(
				'invalid_input',
				__( 'Pass at least one rule to import.', 'super-abilities' ),
				400
			);
		}

		if ( count( $rules ) > self::MAX_RULES ) {
			return $this->error(
				'invalid_input',
				sprintf(
					/* translators: %d: Maximum number of rules. */
					__( 'At most %d rules can be imported in one call. Split the file.', 'super-abilities' ),
					self::MAX_RULES
				),
				400
			);
		}

		$on_conflict = isset( $input['on_conflict'] ) ? (string) $input['on_conflict'] : 'skip';
		$on_conflict = 'update' === $on_conflict ? 'update' : 'skip';
		$dry_run     = ! empty( $input['dry_run'] );
		$options     = array(
			'allow_external'         => ! empty( $input['allow_external'] ),
			'allow_existing_content' => ! empty( $input['allow_existing_content'] ),
		);

		$working  = Store::all();
		$plans    = array();
		$errors   = array();
		$warnings = array();
		$skipped  = 0;

		foreach ( $rules as $index => $raw ) {
			if ( is_object( $raw ) ) {
				$raw = get_object_vars( $raw );
			}

			if ( ! is_array( $raw ) ) {
				$errors[] = $this->failure( (int) $index, '', 'invalid_input', __( 'Every entry must be an object describing one rule.', 'super-abilities' ) );
				continue;
			}

			$candidate = array(
				'source'      => isset( $raw['source'] ) ? (string) $raw['source'] : '',
				'target'      => isset( $raw['target'] ) ? (string) $raw['target'] : '',
				'status'      => isset( $raw['status'] ) ? (int) $raw['status'] : 301,
				'match_query' => ! empty( $raw['match_query'] ),
				'enabled'     => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
				'note'        => isset( $raw['note'] ) ? (string) $raw['note'] : '',
			);

			$source = Rules::normalize_source( $candidate['source'], $candidate['match_query'] );

			if ( '' === $source ) {
				$errors[] = $this->failure( (int) $index, (string) $candidate['source'], 'invalid_input', __( 'A source path is required.', 'super-abilities' ) );
				continue;
			}

			$duplicate  = Rules::find_duplicate( $source, $candidate['match_query'] ? 1 : 0, $working );
			$exclude_id = 0;

			if ( null !== $duplicate ) {
				$existing_id = (int) $duplicate['id'];

				if ( $existing_id < 1 ) {
					$errors[] = $this->failure(
						(int) $index,
						$source,
						'duplicate_in_batch',
						__( 'An earlier rule in this batch already uses that source.', 'super-abilities' )
					);
					continue;
				}

				if ( 'skip' === $on_conflict ) {
					++$skipped;
					continue;
				}

				$exclude_id = $existing_id;
			}

			$checked = Rules::preflight( $candidate, array_merge( $options, array( 'exclude_id' => $exclude_id ) ), $working );

			if ( is_wp_error( $checked['error'] ) ) {
				$errors[] = $this->failure(
					(int) $index,
					$source,
					(string) $checked['error']->get_error_code(),
					(string) $checked['error']->get_error_message()
				);
				continue;
			}

			foreach ( $checked['warnings'] as $warning ) {
				$warnings[] = sprintf(
					/* translators: 1: Rule index in the input. 2: Warning message. */
					__( 'Rule %1$d: %2$s', 'super-abilities' ),
					(int) $index,
					(string) $warning
				);
			}

			$plans[] = array(
				'index' => (int) $index,
				'id'    => $exclude_id,
				'rule'  => $checked,
			);

			if ( $exclude_id > 0 ) {
				$working = Rules::without( $working, $exclude_id );
			}

			$working[] = array(
				'id'          => $exclude_id,
				'source'      => $checked['source'],
				'match_query' => (int) $checked['match_query'],
				'target'      => $checked['target'],
				'status'      => (int) $checked['status'],
				'enabled'     => (int) $checked['enabled'],
			);
		}

		$result = array(
			'written'  => false,
			'dry_run'  => $dry_run,
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => $skipped,
			'ids'      => array(),
			'errors'   => $errors,
			'warnings' => $warnings,
		);

		if ( ! empty( $errors ) || $dry_run ) {
			if ( empty( $errors ) ) {
				foreach ( $plans as $plan ) {
					if ( (int) $plan['id'] > 0 ) {
						++$result['updated'];
					} else {
						++$result['created'];
					}
				}
			}

			return $result;
		}

		foreach ( $plans as $plan ) {
			$rule = $plan['rule'];

			if ( (int) $plan['id'] > 0 ) {
				$stored = Store::update(
					(int) $plan['id'],
					array(
						'source'      => $rule['source'],
						'target'      => $rule['target'],
						'status'      => $rule['status'],
						'match_query' => $rule['match_query'],
						'enabled'     => $rule['enabled'],
						'note'        => $rule['note'],
					)
				);
			} else {
				$stored = Store::insert( $rule );
			}

			if ( is_wp_error( $stored ) ) {
				$result['errors'][] = $this->failure(
					(int) $plan['index'],
					(string) $rule['source'],
					(string) $stored->get_error_code(),
					(string) $stored->get_error_message()
				);
				continue;
			}

			$this->note_object( 'redirect', (int) $stored['id'] );

			$result['ids'][] = (int) $stored['id'];

			if ( (int) $plan['id'] > 0 ) {
				++$result['updated'];
			} else {
				++$result['created'];
			}
		}

		$result['written'] = ! empty( $result['ids'] );

		return $result;
	}

	/**
	 * Builds one entry of the `errors` list.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $index   Index of the rule in the input.
	 * @param string $source  Source as given.
	 * @param string $code    Error code.
	 * @param string $message Human readable message.
	 * @return array<string, mixed>
	 */
	private function failure( $index, $source, $code, $message ) {
		return array(
			'index'   => (int) $index,
			'source'  => (string) $source,
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}
}
