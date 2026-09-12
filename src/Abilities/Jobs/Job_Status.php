<?php
/**
 * Reads the progress of a background job.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Jobs;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Jobs\Job;
use SuperAbilities\Jobs\Queue;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a job's progress, optionally item by item, plus the state of the runner.
 *
 * Polling this ability also re-arms jobs that were queued on a host where the cron
 * loopback is blocked, which is reported back as `runner.kicked`.
 *
 * @since 0.1.0
 */
class Job_Status extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'job-status';
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
		return __( 'Background job status', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reports the progress of one background job, with per item statuses, errors and stored results on request. Also reports whether WP-Cron can actually run on this site, which is the usual reason a job stays queued. Only the user who started the job and administrators can read it.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
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
				__( 'Only the user who started this job and administrators can read it.', 'super-abilities' ),
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
				'uuid'          => array(
					'type'        => 'string',
					'description' => __( 'Job identifier returned by job-start.', 'super-abilities' ),
				),
				'include_items' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include the per item rows. Off by default, because a job can hold hundreds of items.', 'super-abilities' ),
				),
				'items_status'  => array(
					'type'        => 'string',
					'enum'        => Job::ITEM_STATUSES,
					'description' => __( 'Only return items in this status, for example "failed".', 'super-abilities' ),
				),
				'page'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page of items to return.', 'super-abilities' ),
				),
				'per_page'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => __( 'Number of items per page.', 'super-abilities' ),
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
		$item = Schema::object(
			array(
				'position'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'status'           => array(
					'type' => 'string',
					'enum' => Job::ITEM_STATUSES,
				),
				'error_code'       => array( 'type' => 'string' ),
				'error_message'    => array( 'type' => 'string' ),
				'attempts'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'input'            => array(
					'description' => __( 'The input this item was queued with. Only returned to the job owner and to administrators.', 'super-abilities' ),
					'type'        => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ),
				),
				'result'           => array(
					'description' => __( 'What the ability returned. Null when results are not kept, when the item has not finished, or when the stored result was truncated beyond repair.', 'super-abilities' ),
					'type'        => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ),
				),
				'result_truncated' => array( 'type' => 'boolean' ),
				'started_at'       => array( 'type' => 'string' ),
				'finished_at'      => array( 'type' => 'string' ),
			),
			array( 'position', 'status', 'error_code', 'error_message', 'attempts', 'result', 'result_truncated', 'started_at', 'finished_at' )
		);

		return Schema::object(
			array(
				'job'         => $this->job_schema(),
				'items'       => array(
					'type'  => 'array',
					'items' => $item,
				),
				'items_total' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'runner'      => Schema::object(
					array(
						'cron_disabled'              => array(
							'type'        => 'boolean',
							'description' => __( 'Whether DISABLE_WP_CRON is set, in which case jobs only move when something triggers cron externally.', 'super-abilities' ),
						),
						'alternate_cron'             => array(
							'type'        => 'boolean',
							'description' => __( 'Whether ALTERNATE_WP_CRON is set.', 'super-abilities' ),
						),
						'last_heartbeat_age_seconds' => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Seconds since our hourly cron heartbeat last ran, or null when it never has. A large value means cron is not running.', 'super-abilities' ),
						),
						'kicked'                     => array(
							'type'        => 'boolean',
							'description' => __( 'Whether this call re-armed a job that looked stuck.', 'super-abilities' ),
						),
					),
					array( 'cron_disabled', 'alternate_cron', 'last_heartbeat_age_seconds', 'kicked' )
				),
			),
			array( 'job', 'runner' )
		);
	}

	/**
	 * Reads the job.
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
				__( 'Only the user who started this job and administrators can read it.', 'super-abilities' ),
				403
			);
		}

		$this->note_object( 'job', $job->uuid() );

		$kicked = Queue::kick( $job );

		if ( $kicked ) {
			$refreshed = Queue::get( $uuid );
			$job       = $refreshed instanceof Job ? $refreshed : $job;
		}

		$output = array(
			'job'    => $job->to_array(),
			'runner' => array(
				'cron_disabled'              => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'alternate_cron'             => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				'last_heartbeat_age_seconds' => $this->heartbeat_age(),
				'kicked'                     => $kicked,
			),
		);

		if ( empty( $input['include_items'] ) ) {
			return $output;
		}

		$status = isset( $input['items_status'] ) ? (string) $input['items_status'] : '';
		$status = in_array( $status, Job::ITEM_STATUSES, true ) ? $status : null;

		$page = Queue::items(
			$job->id(),
			$status,
			isset( $input['page'] ) ? (int) $input['page'] : 1,
			isset( $input['per_page'] ) ? (int) $input['per_page'] : 20
		);

		// Item inputs are only ever shown to the owner and to administrators.
		$show_input = Queue::can_access( $job );
		$items      = array();

		foreach ( $page['items'] as $row ) {
			$items[] = $this->describe_item( $row, $show_input );
		}

		$output['items']       = $items;
		$output['items_total'] = (int) $page['total'];
		$output['page']        = (int) $page['page'];
		$output['per_page']    = (int) $page['per_page'];

		return $output;
	}

	/**
	 * Shapes one item row for output.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row        Item row.
	 * @param bool                 $show_input Whether the caller may see the queued input.
	 * @return array<string, mixed>
	 */
	protected function describe_item( array $row, $show_input ) {
		$item = array(
			'position'         => isset( $row['position'] ) ? (int) $row['position'] : 0,
			'status'           => isset( $row['status'] ) ? (string) $row['status'] : '',
			'error_code'       => isset( $row['error_code'] ) ? (string) $row['error_code'] : '',
			'error_message'    => isset( $row['error_message'] ) ? (string) $row['error_message'] : '',
			'attempts'         => isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
			'result'           => $this->decode( isset( $row['result'] ) ? $row['result'] : null ),
			'result_truncated' => ! empty( $row['result_truncated'] ),
			'started_at'       => Time::iso_from_mysql( isset( $row['started_at'] ) ? $row['started_at'] : '' ),
			'finished_at'      => Time::iso_from_mysql( isset( $row['finished_at'] ) ? $row['finished_at'] : '' ),
		);

		if ( $show_input ) {
			$item['input'] = $this->decode( isset( $row['input'] ) ? $row['input'] : null );
		}

		return $item;
	}

	/**
	 * Decodes a stored JSON column, tolerating a truncated payload.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw column value.
	 * @return mixed The decoded value, or null when there is nothing usable.
	 */
	protected function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	/**
	 * Seconds since the cron heartbeat last ran.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Null when the heartbeat has never run.
	 */
	protected function heartbeat_age() {
		$tick = (int) get_option( Plugin::HEARTBEAT_OPTION, 0 );

		if ( $tick < 1 ) {
			return null;
		}

		return max( 0, Time::now() - $tick );
	}

	/**
	 * The schema of the full job object.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function job_schema() {
		return Schema::object(
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
			array( 'uuid', 'ability', 'label', 'status', 'total_items', 'done_items', 'failed_items', 'progress_percent', 'created_at' )
		);
	}
}
