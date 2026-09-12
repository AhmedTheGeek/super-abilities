<?php
/**
 * Tests for the background job queue and runner.
 *
 * A fixture ability is registered on `wp_abilities_api_init` and records every call
 * together with the user it ran as, which is how these tests prove that a job
 * executes as its owner and re-checks permissions per item.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Install;
use SuperAbilities\Jobs\Job;
use SuperAbilities\Jobs\Queue;
use SuperAbilities\Jobs\Runner;
use SuperAbilities\Plugin;

class JobsRunnerTest extends WP_UnitTestCase {

	const IDEMPOTENT     = 'sa-test/record';
	const NON_IDEMPOTENT = 'sa-test/record-once';

	/**
	 * Every call the fixture ability received, in order.
	 *
	 * @var array<int, array<string, int>>
	 */
	public static $calls = array();

	/**
	 * Microseconds the fixture ability sleeps per call.
	 *
	 * @var int
	 */
	public static $sleep = 0;

	/**
	 * Item position that asks for its own job to be cancelled while it runs.
	 *
	 * @var int|null
	 */
	public static $cancel_at = null;

	/**
	 * Job the fixture ability is currently running under.
	 *
	 * @var int
	 */
	public static $job_id = 0;

	public function set_up() {
		parent::set_up();

		self::$calls     = array();
		self::$sleep     = 0;
		self::$cancel_at = null;
		self::$job_id    = 0;

		// spawn_cron() fires a loopback request; keep the suite off the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );

		$this->register_fixture_abilities();
	}

	public function tear_down() {
		foreach ( array( self::IDEMPOTENT, self::NON_IDEMPOTENT ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		Plugin::instance()->options()->flush();

		parent::tear_down();
	}

	/**
	 * Short-circuits every outgoing HTTP request.
	 *
	 * @param mixed  $preempt Preempted return value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return WP_Error
	 */
	public function block_http( $preempt, $args, $url ) {
		return new WP_Error( 'sa_test_http_blocked', 'HTTP requests are blocked in tests: ' . $url );
	}

	/**
	 * Registers the two fixture abilities, whatever state the registry is in.
	 *
	 * Abilities may only be registered inside `wp_abilities_api_init`, and the
	 * registry fires that action exactly once per process. The first test in the
	 * process therefore goes through the real path; later tests would make core and
	 * our own registrar register everything a second time, so they talk to the
	 * registry directly instead.
	 */
	protected function register_fixture_abilities() {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		if ( ! did_action( 'wp_abilities_api_init' ) ) {
			wp_get_abilities();

			return;
		}

		$this->register_abilities();
	}

	/**
	 * Registers one fixture ability through whichever door is open.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Registration arguments.
	 */
	protected function register_one( $name, array $args ) {
		if ( doing_action( 'wp_abilities_api_init' ) ) {
			wp_register_ability( $name, $args );

			return;
		}

		WP_Abilities_Registry::get_instance()->register( $name, $args );
	}

	/**
	 * The `wp_abilities_api_init` callback.
	 */
	public function register_abilities() {
		$shared = array(
			'label'               => 'Recording fixture',
			'description'         => 'Records the calls a job makes.',
			// Borrowing a category our own registrar already registered.
			'category'            => 'super-abilities-jobs',
			'execute_callback'    => array( __CLASS__, 'record' ),
			'permission_callback' => array( __CLASS__, 'may_run' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'n'    => array( 'type' => 'integer' ),
					'fail' => array( 'type' => 'boolean' ),
					'big'  => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
		);

		$this->register_one(
			self::IDEMPOTENT,
			array_merge(
				$shared,
				array(
					'meta' => array(
						'annotations' => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			)
		);

		$this->register_one(
			self::NON_IDEMPOTENT,
			array_merge(
				$shared,
				array(
					'meta' => array(
						'annotations' => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			)
		);
	}

	/**
	 * Permission callback of the fixture abilities.
	 *
	 * @param mixed $input Validated input.
	 * @return bool
	 */
	public static function may_run( $input = null ) {
		unset( $input );

		return current_user_can( 'edit_posts' );
	}

	/**
	 * Execute callback of the fixture abilities.
	 *
	 * @param mixed $input Validated input.
	 * @return array|WP_Error
	 */
	public static function record( $input = null ) {
		$input    = is_array( $input ) ? $input : array();
		$position = isset( $input['n'] ) ? (int) $input['n'] : -1;

		self::$calls[] = array(
			'n'    => $position,
			'user' => get_current_user_id(),
		);

		if ( null !== self::$cancel_at && self::$cancel_at === $position && self::$job_id > 0 ) {
			$job = Queue::find( self::$job_id );

			if ( $job instanceof Job ) {
				Queue::request_cancel( $job );
			}
		}

		if ( self::$sleep > 0 ) {
			usleep( self::$sleep );
		}

		if ( ! empty( $input['fail'] ) ) {
			return new WP_Error( 'sa_test_item_failed', 'This item was told to fail.' );
		}

		if ( ! empty( $input['big'] ) ) {
			return array(
				'n'       => $position,
				'user'    => get_current_user_id(),
				'padding' => str_repeat( 'x', 70000 ),
			);
		}

		return array(
			'n'    => $position,
			'user' => get_current_user_id(),
		);
	}

	/**
	 * Creates an editor and makes them the current user.
	 *
	 * @return int
	 */
	protected function become_editor() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Queues a job of `$count` items on the given ability.
	 *
	 * @param int                  $count   Number of items.
	 * @param array<string, mixed> $options Job options.
	 * @param string               $ability Ability name.
	 * @return Job
	 */
	protected function queue( $count, array $options = array(), $ability = self::IDEMPOTENT ) {
		$inputs = array();

		for ( $n = 0; $n < $count; $n++ ) {
			$inputs[] = array( 'n' => $n );
		}

		$job = Queue::enqueue( $ability, $inputs, $options, get_current_user_id(), null );

		$this->assertInstanceOf( Job::class, $job, is_wp_error( $job ) ? $job->get_error_message() : '' );

		self::$job_id = $job->id();

		return $job;
	}

	/**
	 * Item statuses of a job, ordered by position.
	 *
	 * @param int $job_id Job id.
	 * @return array<int, string>
	 */
	protected function item_statuses( $job_id ) {
		global $wpdb;

		$table = Install::table( 'job_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( $wpdb->prepare( "SELECT status FROM {$table} WHERE job_id = %d ORDER BY position ASC", $job_id ) );
	}

	/**
	 * Item rows of a job, ordered by position.
	 *
	 * @param int $job_id Job id.
	 * @return array<int, array<string, mixed>>
	 */
	protected function item_rows( $job_id ) {
		global $wpdb;

		$table = Install::table( 'job_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY position ASC", $job_id ), ARRAY_A );
	}

	/**
	 * Forces an item into a given status.
	 *
	 * @param int    $job_id   Job id.
	 * @param int    $position Item position.
	 * @param string $status   New status.
	 */
	protected function force_item_status( $job_id, $position, $status ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Install::table( 'job_items' ),
			array(
				'status'     => $status,
				'attempts'   => 1,
				'started_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ),
			),
			array(
				'job_id'   => $job_id,
				'position' => $position,
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);
	}

	public function test_the_jobs_module_registers_its_four_abilities() {
		wp_get_abilities();

		foreach ( array( 'job-start', 'job-status', 'job-list', 'job-cancel' ) as $slug ) {
			$this->assertTrue( wp_has_ability( 'super-abilities/' . $slug ), $slug );
		}

		$start = wp_get_ability( 'super-abilities/job-start' );

		$this->assertSame( 'super-abilities-jobs', $start->get_category() );
		$this->assertFalse( $start->get_meta()['annotations']['readonly'] );
		$this->assertFalse( $start->get_meta()['annotations']['idempotent'] );
		$this->assertSame( 'jobs', $start->get_meta()['super_abilities']['module'] );
	}

	public function test_enqueue_writes_a_queued_job_with_its_items() {
		$editor = $this->become_editor();

		$job = $this->queue( 3, array( 'label' => 'Three things' ) );

		$this->assertSame( 'queued', $job->status() );
		$this->assertSame( 3, $job->total_items() );
		$this->assertSame( $editor, $job->user_id() );
		$this->assertSame( 'Three things', $job->label() );
		$this->assertTrue( $job->keep_results() );
		$this->assertFalse( $job->stop_on_error() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $job->uuid() );

		$this->assertSame( array( 'pending', 'pending', 'pending' ), $this->item_statuses( $job->id() ) );

		$rows = $this->item_rows( $job->id() );

		$this->assertSame( array( 0, 1, 2 ), array_map( 'intval', wp_list_pluck( $rows, 'position' ) ) );
		$this->assertSame( array( 'n' => 1 ), json_decode( $rows[1]['input'], true ) );

		$this->assertNotFalse( wp_next_scheduled( Queue::RUN_HOOK, array( $job->id() ) ) );
	}

	public function test_runner_completes_a_three_item_job_as_its_owner() {
		$editor = $this->become_editor();
		$job    = $this->queue( 3 );

		// Nobody is logged in when cron fires; the job must still run as its owner.
		wp_set_current_user( 0 );

		Runner::run( $job->id() );

		$this->assertSame( 0, get_current_user_id(), 'The runner restores the previous user.' );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'completed', $finished->status() );
		$this->assertSame( 3, $finished->done_items() );
		$this->assertSame( 0, $finished->failed_items() );
		$this->assertSame( 100, $finished->progress_percent() );
		$this->assertNotNull( $finished->started_at() );
		$this->assertNotNull( $finished->finished_at() );
		$this->assertSame( '', $finished->lock_token(), 'The lock is released when the job finishes.' );
		$this->assertFalse( $finished->is_locked() );

		$this->assertSame( array( 'done', 'done', 'done' ), $this->item_statuses( $job->id() ) );

		$this->assertCount( 3, self::$calls );
		$this->assertSame( array( 0, 1, 2 ), wp_list_pluck( self::$calls, 'n' ), 'Items run in position order.' );

		foreach ( self::$calls as $call ) {
			$this->assertSame( $editor, $call['user'], 'Every item runs as the user who queued the job.' );
		}

		$rows = $this->item_rows( $job->id() );

		$this->assertSame(
			array(
				'n'    => 2,
				'user' => $editor,
			),
			json_decode( $rows[2]['result'], true )
		);
		$this->assertSame( 1, (int) $rows[2]['attempts'] );
		$this->assertSame( 0, (int) $rows[2]['result_truncated'] );
	}

	public function test_a_failing_item_makes_the_job_partial() {
		$this->become_editor();

		$job = Queue::enqueue(
			self::IDEMPOTENT,
			array(
				array( 'n' => 0 ),
				array(
					'n'    => 1,
					'fail' => true,
				),
				array( 'n' => 2 ),
			),
			array(),
			get_current_user_id(),
			null
		);

		Runner::run( $job->id() );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'partial', $finished->status() );
		$this->assertSame( 2, $finished->done_items() );
		$this->assertSame( 1, $finished->failed_items() );
		$this->assertSame( 'This item was told to fail.', $finished->last_error() );

		$this->assertSame( array( 'done', 'failed', 'done' ), $this->item_statuses( $job->id() ) );

		$rows = $this->item_rows( $job->id() );

		$this->assertSame( 'sa_test_item_failed', $rows[1]['error_code'] );
		$this->assertSame( 'This item was told to fail.', $rows[1]['error_message'] );
		$this->assertNull( $rows[1]['result'] );
		$this->assertCount( 3, self::$calls );
	}

	public function test_all_items_failing_makes_the_job_failed() {
		$this->become_editor();

		$job = Queue::enqueue(
			self::IDEMPOTENT,
			array(
				array(
					'n'    => 0,
					'fail' => true,
				),
				array(
					'n'    => 1,
					'fail' => true,
				),
			),
			array(),
			get_current_user_id(),
			null
		);

		Runner::run( $job->id() );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'failed', $finished->status() );
		$this->assertSame( 0, $finished->done_items() );
		$this->assertSame( 2, $finished->failed_items() );
	}

	public function test_stop_on_error_skips_the_remaining_items() {
		$this->become_editor();

		$job = Queue::enqueue(
			self::IDEMPOTENT,
			array(
				array( 'n' => 0 ),
				array(
					'n'    => 1,
					'fail' => true,
				),
				array( 'n' => 2 ),
			),
			array( 'stop_on_error' => true ),
			get_current_user_id(),
			null
		);

		$this->assertTrue( $job->stop_on_error() );

		Runner::run( $job->id() );

		$this->assertSame( array( 'done', 'failed', 'skipped' ), $this->item_statuses( $job->id() ) );
		$this->assertSame( 'partial', Queue::find( $job->id() )->status() );
		$this->assertCount( 2, self::$calls, 'The third item never runs.' );
	}

	public function test_keep_results_off_stores_no_result() {
		$this->become_editor();

		$job = $this->queue( 1, array( 'keep_results' => false ) );

		$this->assertFalse( $job->keep_results() );

		Runner::run( $job->id() );

		$rows = $this->item_rows( $job->id() );

		$this->assertSame( 'done', $rows[0]['status'] );
		$this->assertNull( $rows[0]['result'] );
		$this->assertSame( 0, (int) $rows[0]['result_truncated'] );
	}

	public function test_an_oversized_result_is_capped_and_flagged() {
		$this->become_editor();

		$job = Queue::enqueue(
			self::IDEMPOTENT,
			array(
				array(
					'n'   => 0,
					'big' => true,
				),
			),
			array(),
			get_current_user_id(),
			null
		);

		Runner::run( $job->id() );

		$rows = $this->item_rows( $job->id() );

		$this->assertSame( 'done', $rows[0]['status'] );
		$this->assertSame( 1, (int) $rows[0]['result_truncated'] );
		$this->assertSame( Runner::MAX_RESULT_BYTES, strlen( $rows[0]['result'] ) );
		$this->assertSame( 'completed', Queue::find( $job->id() )->status() );
	}

	public function test_cancelling_a_queued_job_cancels_its_items_immediately() {
		$this->become_editor();

		$job       = $this->queue( 3 );
		$cancelled = Queue::request_cancel( $job );

		$this->assertInstanceOf( Job::class, $cancelled );
		$this->assertSame( 'cancelled', $cancelled->status() );
		$this->assertTrue( $cancelled->cancel_requested() );
		$this->assertTrue( $cancelled->is_finished() );
		$this->assertNotNull( $cancelled->finished_at() );

		$this->assertSame( array( 'cancelled', 'cancelled', 'cancelled' ), $this->item_statuses( $job->id() ) );

		// A cron event that fires afterwards must not resurrect the job.
		Runner::run( $job->id() );

		$this->assertSame( array(), self::$calls );
		$this->assertSame( 'cancelled', Queue::find( $job->id() )->status() );
	}

	public function test_cancelling_a_running_job_stops_before_the_next_item() {
		$this->become_editor();

		$job = $this->queue( 3 );

		// The first item asks for its own job to be cancelled while it runs.
		self::$cancel_at = 0;

		Runner::run( $job->id() );

		$this->assertCount( 1, self::$calls, 'The item in flight finishes, the rest do not start.' );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'cancelled', $finished->status() );
		$this->assertTrue( $finished->cancel_requested() );
		$this->assertSame( 1, $finished->done_items() );
		$this->assertSame( '', $finished->lock_token() );

		$this->assertSame( array( 'done', 'cancelled', 'cancelled' ), $this->item_statuses( $job->id() ) );
	}

	public function test_cancel_is_idempotent_on_a_finished_job() {
		$this->become_editor();

		$job = $this->queue( 1 );

		Runner::run( $job->id() );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'completed', $finished->status() );

		$again = Queue::request_cancel( $finished );

		$this->assertSame( 'completed', $again->status() );
		$this->assertFalse( $again->cancel_requested() );
	}

	public function test_an_interrupted_item_of_a_non_idempotent_ability_is_failed() {
		$this->become_editor();

		$job = $this->queue( 2, array(), self::NON_IDEMPOTENT );

		// Pretend a worker died halfway through the first item.
		$this->force_item_status( $job->id(), 0, 'running' );

		Runner::run( $job->id() );

		$rows = $this->item_rows( $job->id() );

		$this->assertSame( 'failed', $rows[0]['status'] );
		$this->assertSame( 'interrupted', $rows[0]['error_code'] );
		$this->assertNotSame( '', (string) $rows[0]['error_message'] );
		$this->assertSame( 1, (int) $rows[0]['attempts'], 'The item is not retried.' );

		$this->assertSame( 'done', $rows[1]['status'] );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'partial', $finished->status() );
		$this->assertSame( 1, $finished->done_items() );
		$this->assertSame( 1, $finished->failed_items() );

		$this->assertSame( array( 1 ), wp_list_pluck( self::$calls, 'n' ), 'Only the untouched item runs.' );
	}

	public function test_an_interrupted_item_of_an_idempotent_ability_is_retried() {
		$this->become_editor();

		$job = $this->queue( 2, array(), self::IDEMPOTENT );

		$this->force_item_status( $job->id(), 0, 'running' );

		Runner::run( $job->id() );

		$rows = $this->item_rows( $job->id() );

		$this->assertSame( 'done', $rows[0]['status'] );
		$this->assertSame( 2, (int) $rows[0]['attempts'], 'The item is retried, so it has been attempted twice.' );

		$this->assertSame( 'completed', Queue::find( $job->id() )->status() );
		$this->assertSame( array( 0, 1 ), wp_list_pluck( self::$calls, 'n' ) );
	}

	public function test_the_lock_keeps_a_second_worker_out() {
		$this->become_editor();

		$job = $this->queue( 2 );

		global $wpdb;

		$table = Install::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'          => 'running',
				'lock_token'      => str_repeat( 'a', 32 ),
				'lock_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			),
			array( 'id' => $job->id() ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		Runner::run( $job->id() );

		$this->assertSame( array(), self::$calls, 'A locked job is left alone.' );
		$this->assertSame( array( 'pending', 'pending' ), $this->item_statuses( $job->id() ) );
	}

	public function test_a_job_whose_owner_is_gone_fails() {
		$this->become_editor();

		$job = $this->queue( 2 );

		global $wpdb;

		// Stand in for a user who was deleted after queueing the job.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Install::table( 'jobs' ),
			array( 'user_id' => 999999 ),
			array( 'id' => $job->id() ),
			array( '%d' ),
			array( '%d' )
		);

		wp_set_current_user( 0 );

		Runner::run( $job->id() );

		$finished = Queue::find( $job->id() );

		$this->assertSame( 'failed', $finished->status() );
		$this->assertNotSame( '', $finished->last_error() );
		$this->assertSame( array( 'failed', 'failed' ), $this->item_statuses( $job->id() ) );
		$this->assertSame( array(), self::$calls );
	}

	public function test_the_job_context_action_brackets_the_run() {
		$this->become_editor();

		$job    = $this->queue( 1 );
		$events = array();

		add_action(
			'super_abilities_job_context',
			static function ( $context ) use ( &$events ) {
				$events[] = array( 'start', $context );
			}
		);

		add_action(
			'super_abilities_job_context_end',
			static function () use ( &$events ) {
				$events[] = array( 'end', null );
			}
		);

		Runner::run( $job->id() );

		$this->assertCount( 2, $events );
		$this->assertSame( 'start', $events[0][0] );
		$this->assertSame( 'end', $events[1][0] );
		$this->assertSame( $job->id(), $events[0][1]['job_id'] );
		$this->assertSame( $job->uuid(), $events[0][1]['uuid'] );
		$this->assertSame( $job->user_id(), $events[0][1]['user_id'] );
		$this->assertArrayHasKey( 'app_password_uuid', $events[0][1] );
	}

	public function test_running_out_of_budget_reschedules_the_job() {
		$this->become_editor();

		Plugin::instance()->options()->set( 'jobs_time_budget', 1 );

		$job = $this->queue( 3 );

		// The first item alone spends the whole budget.
		self::$sleep = 1100000;

		Runner::run( $job->id() );

		$this->assertCount( 1, self::$calls );

		$job_after = Queue::find( $job->id() );

		$this->assertSame( 'running', $job_after->status(), 'The job is not finished, only paused.' );
		$this->assertSame( 1, $job_after->done_items() );
		$this->assertSame( '', $job_after->lock_token(), 'The lock is released so the next cron run can pick it up.' );
		$this->assertNull( $job_after->finished_at() );
		$this->assertSame( array( 'done', 'pending', 'pending' ), $this->item_statuses( $job->id() ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::RUN_HOOK, array( $job->id() ) ) );

		// The next run finishes the rest.
		self::$sleep = 0;

		Plugin::instance()->options()->set( 'jobs_time_budget', 20 );

		Runner::run( $job->id() );

		$this->assertSame( 'completed', Queue::find( $job->id() )->status() );
		$this->assertSame( array( 0, 1, 2 ), wp_list_pluck( self::$calls, 'n' ) );
	}

	public function test_enqueue_refuses_the_job_control_abilities() {
		$this->become_editor();

		$error = Queue::enqueue( 'super-abilities/job-start', array( array( 'ability' => 'x' ) ), array(), get_current_user_id(), null );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
	}

	public function test_enqueue_honours_the_denylist_filter() {
		$this->become_editor();

		add_filter(
			'super_abilities_jobs_denied_abilities',
			static function () {
				return array( JobsRunnerTest::IDEMPOTENT );
			}
		);

		$error = Queue::enqueue( self::IDEMPOTENT, array( array( 'n' => 0 ) ), array(), get_current_user_id(), null );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	public function test_enqueue_rejects_empty_and_oversized_jobs() {
		$this->become_editor();

		$empty = Queue::enqueue( self::IDEMPOTENT, array(), array(), get_current_user_id(), null );

		$this->assertWPError( $empty );
		$this->assertSame( 'super_abilities_invalid_input', $empty->get_error_code() );

		Plugin::instance()->options()->set( 'jobs_max_items', 2 );

		$too_many = Queue::enqueue(
			self::IDEMPOTENT,
			array( array( 'n' => 0 ), array( 'n' => 1 ), array( 'n' => 2 ) ),
			array(),
			get_current_user_id(),
			null
		);

		$this->assertWPError( $too_many );
		$this->assertSame( 2, $too_many->get_error_data()['max_items'] );
	}

	public function test_enqueue_rejects_an_unknown_ability() {
		$this->become_editor();

		$error = Queue::enqueue( 'sa-test/nope', array( array( 'n' => 0 ) ), array(), get_current_user_id(), null );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_not_found', $error->get_error_code() );
	}

	public function test_enqueue_refuses_items_the_caller_may_not_run() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$error = Queue::enqueue( self::IDEMPOTENT, array( array( 'n' => 0 ) ), array(), get_current_user_id(), null );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_forbidden', $error->get_error_code() );
		$this->assertSame( 0, $error->get_error_data()['item'] );
	}

	public function test_enqueue_refuses_an_item_that_does_not_match_the_input_schema() {
		$this->become_editor();

		$error = Queue::enqueue( self::IDEMPOTENT, array( array( 'nope' => true ) ), array(), get_current_user_id(), null );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
		$this->assertSame( 0, $error->get_error_data()['item'] );
	}

	public function test_list_and_prune() {
		$this->become_editor();

		$first  = $this->queue( 1 );
		$second = $this->queue( 1 );

		$page = Queue::list( array(), 1, 10 );

		$this->assertSame( 2, $page['total'] );
		$this->assertSame( $second->uuid(), $page['jobs'][0]->uuid(), 'Newest first.' );

		$queued = Queue::list( array( 'status' => 'queued' ), 1, 10 );

		$this->assertSame( 2, $queued['total'] );

		$this->assertSame( 0, Queue::list( array( 'status' => 'completed' ), 1, 10 )['total'] );
		$this->assertSame( 0, Queue::list( array( 'ability' => 'sa-test/other' ), 1, 10 )['total'] );

		Runner::run( $first->id() );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Install::table( 'jobs' ),
			array( 'finished_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $first->id() ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame( 1, Queue::prune( 30 ) );
		$this->assertNull( Queue::find( $first->id() ) );
		$this->assertSame( array(), $this->item_rows( $first->id() ) );
		$this->assertInstanceOf( Job::class, Queue::find( $second->id() ) );
	}

	public function test_kick_only_fires_for_a_stale_job() {
		$this->become_editor();

		$job = $this->queue( 1 );

		$this->assertFalse( Queue::kick( $job ), 'A job queued a moment ago is left alone.' );

		global $wpdb;

		$stale = gmdate( 'Y-m-d H:i:s', time() - 300 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Install::table( 'jobs' ),
			array(
				'created_at' => $stale,
				'updated_at' => $stale,
			),
			array( 'id' => $job->id() ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$stale_job = Queue::find( $job->id() );

		$this->assertTrue( Queue::kick( $stale_job ) );
		$this->assertCount( 1, Queue::stuck( 5 ) );

		Runner::run( $job->id() );

		$this->assertFalse( Queue::kick( Queue::find( $job->id() ) ), 'A finished job is never kicked.' );
	}

	public function test_can_access_is_owner_or_administrator() {
		$editor = $this->become_editor();
		$job    = $this->queue( 1 );

		$this->assertTrue( Queue::can_access( $job ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( Queue::can_access( $job ), 'Another editor cannot see it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( Queue::can_access( $job ), 'Administrators can see every job.' );

		wp_set_current_user( $editor );

		$this->assertTrue( Queue::can_access( $job ) );
	}
}
