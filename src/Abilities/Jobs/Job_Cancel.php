<?php
/**
 * Cancels a background job.
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
 * Stops a background job as soon as the runner can.
 *
 * @since 0.1.0
 */
class Job_Cancel extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'job-cancel';
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
		return __( 'Cancel a background job', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Stops a background job. A job that has not started yet is cancelled immediately, together with all of its items. A job that is already running is asked to stop, and the runner checks that request before every remaining item, so the item in flight still finishes. Items that already ran are not undone. Calling this on a job that has already finished changes nothing and reports already_finished. Only the user who started the job and administrators can cancel it.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write_idempotent();
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
	 * Restricts the job to its owner and to administrators.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool|\WP_Error
	 */
	public function permission( array $input ) {
		$uuid = isset( $input['uuid'] ) ? (string) $input['uuid'] : '';
		$job  = '' === $uuid ? null : Queue::get( $uuid );

		if ( ! $job instanceof Job ) {
			// Unknown jobs are answered with a 404 by execute(), for everybody alike.
			return true;
		}

		if ( ! Queue::can_access( $job ) ) {
			return $this->error(
				'forbidden',
				__( 'Only the user who started this job and administrators can cancel it.', 'super-abilities' ),
				403
			);
		}

		return true;
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
				'uuid' => array(
					'type'        => 'string',
					'description' => __( 'Job identifier returned by job-start.', 'super-abilities' ),
				),
			),
			array( 'uuid' )
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
				'job'              => Schema::object(
					array(
						'uuid'             => array( 'type' => 'string' ),
						'ability'          => array( 'type' => 'string' ),
						'label'            => array( 'type' => 'string' ),
						'status'           => array(
							'type' => 'string',
							'enum' => Job::STATUSES,
						),
						'cancel_requested' => array( 'type' => 'boolean' ),
						'user_id'          => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'total_items'      => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'done_items'       => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'failed_items'     => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'progress_percent' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
						'stop_on_error'    => array( 'type' => 'boolean' ),
						'keep_results'     => array( 'type' => 'boolean' ),
						'last_error'       => array( 'type' => 'string' ),
						'created_at'       => array( 'type' => 'string' ),
						'started_at'       => array( 'type' => 'string' ),
						'finished_at'      => array( 'type' => 'string' ),
						'updated_at'       => array( 'type' => 'string' ),
					),
					array( 'uuid', 'ability', 'label', 'status', 'cancel_requested', 'total_items', 'progress_percent' )
				),
				'already_finished' => array(
					'type'        => 'boolean',
					'description' => __( 'True when the job had already reached a final status, so nothing was changed.', 'super-abilities' ),
				),
			),
			array( 'job', 'already_finished' )
		);
	}

	/**
	 * Cancels the job.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$uuid = isset( $input['uuid'] ) ? trim( (string) $input['uuid'] ) : '';
		$job  = '' === $uuid ? null : Queue::get( $uuid );

		if ( ! $job instanceof Job ) {
			return $this->error(
				'not_found',
				__( 'No job with that identifier exists on this site.', 'super-abilities' ),
				404
			);
		}

		if ( ! Queue::can_access( $job ) ) {
			return $this->error(
				'forbidden',
				__( 'Only the user who started this job and administrators can cancel it.', 'super-abilities' ),
				403
			);
		}

		$this->note_object( 'job', $job->uuid() );

		if ( $job->is_finished() ) {
			return array(
				'job'              => $job->to_array(),
				'already_finished' => true,
			);
		}

		$cancelled = Queue::request_cancel( $job );

		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}

		return array(
			'job'              => $cancelled->to_array(),
			'already_finished' => false,
		);
	}
}
