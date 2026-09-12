<?php
/**
 * Lists background jobs.
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
 * Lists background jobs, newest first.
 *
 * Administrators see every job on the site; everybody else only sees their own.
 *
 * @since 0.1.0
 */
class Job_List extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'job-list';
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
		return __( 'List background jobs', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists background jobs, newest first, with their status and progress. Administrators see every job on the site; other users only see the jobs they started themselves. Use it to find the identifier of a job you lost track of, then read it with job-status.', 'super-abilities' );
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
	 * Input schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => Job::STATUSES,
					'description' => __( 'Only return jobs in this status.', 'super-abilities' ),
				),
				'ability'  => array(
					'type'        => 'string',
					'description' => __( 'Only return jobs that run this ability.', 'super-abilities' ),
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			)
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
		$summary = Schema::object(
			array(
				'uuid'             => array( 'type' => 'string' ),
				'ability'          => array( 'type' => 'string' ),
				'label'            => array( 'type' => 'string' ),
				'status'           => array(
					'type' => 'string',
					'enum' => Job::STATUSES,
				),
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
				'created_at'       => array( 'type' => 'string' ),
				'finished_at'      => array( 'type' => 'string' ),
			),
			array( 'uuid', 'ability', 'label', 'status', 'total_items', 'done_items', 'failed_items', 'progress_percent', 'created_at' )
		);

		return Schema::object(
			array(
				'total'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'items'    => array(
					'type'  => 'array',
					'items' => $summary,
				),
			),
			array( 'total', 'page', 'per_page', 'items' )
		);
	}

	/**
	 * Lists the jobs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$filters = array();

		if ( isset( $input['status'] ) && in_array( (string) $input['status'], Job::STATUSES, true ) ) {
			$filters['status'] = (string) $input['status'];
		}

		if ( isset( $input['ability'] ) && '' !== trim( (string) $input['ability'] ) ) {
			$filters['ability'] = trim( (string) $input['ability'] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$filters['user_id'] = get_current_user_id();
		}

		$page = Queue::list(
			$filters,
			isset( $input['page'] ) ? (int) $input['page'] : 1,
			isset( $input['per_page'] ) ? (int) $input['per_page'] : 20
		);

		$items = array();

		foreach ( $page['jobs'] as $job ) {
			$items[] = $job->to_summary();
		}

		return array(
			'total'    => (int) $page['total'],
			'page'     => (int) $page['page'],
			'per_page' => (int) $page['per_page'],
			'items'    => $items,
		);
	}
}
