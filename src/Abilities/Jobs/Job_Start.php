<?php
/**
 * Queues a background job.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Jobs;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Jobs\Job;
use SuperAbilities\Jobs\Queue;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs another ability in the background, once per input object.
 *
 * @since 0.1.0
 */
class Job_Start extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'job-start';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'jobs';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Start a background job', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Queues another ability to run in the background, once for every input object you pass, and returns a job identifier you can poll. Use it for work that would time out in a single call, such as regenerating hundreds of images or checksumming many plugins. A job inherits everything about the ability it runs: it is exactly as destructive as that ability, every item goes through that ability\'s own input validation and permission callback, and the whole job runs as you, the calling user. You must already be allowed to run the target ability with every one of the inputs you pass, which is checked before the job is accepted. Jobs are executed by WP-Cron, so progress depends on this site receiving cron requests.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'read' );
	}

	/**
	 * Input schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'ability' => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9-]+/[a-z0-9-]+$',
					'description' => __( 'Fully namespaced name of the ability to run, for example "super-abilities/integrity-check". The job control abilities themselves cannot be queued.', 'super-abilities' ),
				),
				'input'   => array(
					'type'        => 'object',
					'description' => __( 'Input for a single item job. Pass either this or "inputs", never both.', 'super-abilities' ),
				),
				'inputs'  => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 500,
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'One input object per item, executed in the order given. Pass either this or "input", never both.', 'super-abilities' ),
				),
				'options' => Schema::object(
					array(
						'stop_on_error' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Stop the job at the first failing item and mark the rest as skipped. Defaults to false, which runs every item.', 'super-abilities' ),
						),
						'keep_results'  => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Store each item result so that job-status can return it. Turn this off for large results you do not need.', 'super-abilities' ),
						),
						'label'         => array(
							'type'        => 'string',
							'maxLength'   => 191,
							'description' => __( 'Short label to recognise this job by later.', 'super-abilities' ),
						),
					)
				),
			),
			array( 'ability' )
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'job'  => Schema::object(
					array(
						'uuid'        => array(
							'type'        => 'string',
							'description' => __( 'Identifier to pass to job-status and job-cancel.', 'super-abilities' ),
						),
						'ability'     => array( 'type' => 'string' ),
						'label'       => array( 'type' => 'string' ),
						'status'      => array(
							'type' => 'string',
							'enum' => Job::STATUSES,
						),
						'total_items' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'created_at'  => Schema::iso_datetime( __( 'When the job was queued.', 'super-abilities' ) ),
					),
					array( 'uuid', 'ability', 'label', 'status', 'total_items', 'created_at' )
				),
				'note' => array(
					'type'        => 'string',
					'description' => __( 'How to follow this job.', 'super-abilities' ),
				),
			),
			array( 'job', 'note' )
		);
	}

	/**
	 * Queues the job.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$ability = isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
		$single  = isset( $input['input'] ) ? $input['input'] : null;
		$many    = isset( $input['inputs'] ) ? $input['inputs'] : null;

		$has_single = is_array( $single ) || is_object( $single );
		$has_many   = is_array( $many );

		if ( $has_single === $has_many ) {
			return $this->error(
				'invalid_input',
				__( 'Pass exactly one of "input", for a single item job, or "inputs", for one item per object.', 'super-abilities' ),
				400
			);
		}

		if ( $has_single ) {
			$items = array( is_object( $single ) ? get_object_vars( $single ) : (array) $single );
		} else {
			$items = array_values( (array) $many );
		}

		$options = isset( $input['options'] ) ? $input['options'] : array();

		if ( is_object( $options ) ) {
			$options = get_object_vars( $options );
		}

		$job = Queue::enqueue(
			$ability,
			$items,
			is_array( $options ) ? $options : array(),
			get_current_user_id(),
			$this->app_password_uuid()
		);

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$this->note_object( 'job', $job->uuid() );

		return array(
			'job'  => $job->to_receipt(),
			'note' => sprintf(
				/* translators: %s: Job UUID. */
				__( 'Poll super-abilities/job-status with uuid "%s" to follow progress. The job runs through WP-Cron as you, and every item is permission checked again when it runs. Call super-abilities/job-cancel to stop it.', 'super-abilities' ),
				$job->uuid()
			),
		);
	}

	/**
	 * The application password the caller authenticated with, when there was one.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	protected function app_password_uuid() {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return null;
		}

		$uuid = rest_get_authenticated_app_password();

		return is_string( $uuid ) && '' !== $uuid ? $uuid : null;
	}
}
