<?php
/**
 * Tests for the Job value object.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Jobs\Job;

class JobTest extends WP_UnitTestCase {

	/**
	 * Builds a job row with sane defaults.
	 *
	 * @param array<string, mixed> $overrides Columns to override.
	 * @return Job
	 */
	protected function job( array $overrides = array() ) {
		$row = array_merge(
			array(
				'id'                => '12',
				'uuid'              => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
				'ability'           => 'super-abilities/integrity-check',
				'label'             => 'Nightly checksums',
				'user_id'           => '7',
				'app_password_uuid' => null,
				'status'            => 'queued',
				'cancel_requested'  => '0',
				'total_items'       => '4',
				'done_items'        => '0',
				'failed_items'      => '0',
				'options'           => '{"stop_on_error":false,"keep_results":true,"label":"Nightly checksums"}',
				'lock_token'        => null,
				'lock_expires_at'   => null,
				'last_error'        => null,
				'created_at'        => '2026-09-12 10:00:00',
				'started_at'        => null,
				'finished_at'       => null,
				'updated_at'        => '2026-09-12 10:00:05',
			),
			$overrides
		);

		return new Job( $row );
	}

	public function test_from_row_accepts_objects_and_rejects_junk() {
		$job = Job::from_row(
			(object) array(
				'id'   => 3,
				'uuid' => 'x',
			)
		);

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( 3, $job->id() );

		$this->assertNull( Job::from_row( null ) );
		$this->assertNull( Job::from_row( 'nope' ) );
		$this->assertNull( Job::from_row( array( 'uuid' => 'no-id' ) ) );
	}

	public function test_getters_are_typed() {
		$job = $this->job();

		$this->assertSame( 12, $job->id() );
		$this->assertSame( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $job->uuid() );
		$this->assertSame( 'super-abilities/integrity-check', $job->ability() );
		$this->assertSame( 7, $job->user_id() );
		$this->assertSame( 'queued', $job->status() );
		$this->assertSame( 4, $job->total_items() );
		$this->assertSame( 0, $job->done_items() );
		$this->assertFalse( $job->cancel_requested() );
		$this->assertSame( '', $job->app_password_uuid() );
		$this->assertSame( '', $job->last_error() );
		$this->assertFalse( $job->is_finished() );
	}

	public function test_options_apply_defaults() {
		$job = $this->job( array( 'options' => null ) );

		$this->assertFalse( $job->stop_on_error() );
		$this->assertTrue( $job->keep_results(), 'keep_results defaults to true.' );

		$job = $this->job( array( 'options' => '{"stop_on_error":true,"keep_results":false}' ) );

		$this->assertTrue( $job->stop_on_error() );
		$this->assertFalse( $job->keep_results() );
		$this->assertSame( '', $job->options()['label'] );
	}

	public function test_options_survive_broken_json() {
		$job = $this->job( array( 'options' => 'not json at all' ) );

		$this->assertSame( Job::DEFAULT_OPTIONS, $job->options() );
	}

	public function test_timestamps_are_parsed_as_utc() {
		$job = $this->job();

		$this->assertSame( strtotime( '2026-09-12 10:00:00 UTC' ), $job->created_at() );
		$this->assertNull( $job->started_at() );
		$this->assertNull( $job->finished_at() );
		$this->assertSame( strtotime( '2026-09-12 10:00:05 UTC' ), $job->touched_at() );
	}

	public function test_zero_datetimes_read_as_no_value() {
		$job = $this->job( array( 'created_at' => '0000-00-00 00:00:00' ) );

		$this->assertNull( $job->created_at() );
		$this->assertSame( '', $job->to_array()['created_at'] );
	}

	public function test_lock_is_only_held_until_it_expires() {
		$this->assertFalse( $this->job()->is_locked(), 'No token means no lock.' );

		$held = $this->job(
			array(
				'status'          => 'running',
				'lock_token'      => str_repeat( 'a', 32 ),
				'lock_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			)
		);

		$this->assertTrue( $held->is_locked() );

		$expired = $this->job(
			array(
				'status'          => 'running',
				'lock_token'      => str_repeat( 'a', 32 ),
				'lock_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);

		$this->assertFalse( $expired->is_locked() );
	}

	public function test_progress_percent_rounds_down_and_finished_jobs_are_complete() {
		$job = $this->job(
			array(
				'status'       => 'running',
				'total_items'  => '3',
				'done_items'   => '1',
				'failed_items' => '0',
			)
		);

		$this->assertSame( 33, $job->progress_percent() );

		$job = $this->job(
			array(
				'status'       => 'running',
				'total_items'  => '4',
				'done_items'   => '2',
				'failed_items' => '1',
			)
		);

		$this->assertSame( 75, $job->progress_percent() );

		$job = $this->job(
			array(
				'status'      => 'cancelled',
				'total_items' => '10',
				'done_items'  => '1',
			)
		);

		$this->assertSame( 100, $job->progress_percent(), 'A finished job is always reported as 100 per cent.' );

		$this->assertSame( 0, $this->job( array( 'total_items' => '0' ) )->progress_percent() );
	}

	public function test_finished_statuses() {
		foreach ( array( 'completed', 'partial', 'failed', 'cancelled' ) as $status ) {
			$this->assertTrue( $this->job( array( 'status' => $status ) )->is_finished(), $status );
		}

		foreach ( array( 'queued', 'running' ) as $status ) {
			$this->assertFalse( $this->job( array( 'status' => $status ) )->is_finished(), $status );
		}
	}

	public function test_to_array_shape() {
		$array = $this->job( array( 'started_at' => '2026-09-12 10:00:10' ) )->to_array();

		$this->assertSame(
			array(
				'uuid',
				'ability',
				'label',
				'status',
				'cancel_requested',
				'user_id',
				'total_items',
				'done_items',
				'failed_items',
				'progress_percent',
				'stop_on_error',
				'keep_results',
				'last_error',
				'created_at',
				'started_at',
				'finished_at',
				'updated_at',
			),
			array_keys( $array )
		);

		$this->assertSame( '2026-09-12T10:00:00Z', $array['created_at'] );
		$this->assertSame( '2026-09-12T10:00:10Z', $array['started_at'] );
		$this->assertSame( '', $array['finished_at'] );
		$this->assertIsBool( $array['cancel_requested'] );
		$this->assertIsInt( $array['progress_percent'] );
	}

	public function test_receipt_and_summary_are_subsets() {
		$job = $this->job();

		$this->assertSame(
			array( 'uuid', 'ability', 'label', 'status', 'total_items', 'created_at' ),
			array_keys( $job->to_receipt() )
		);

		$this->assertSame(
			array(
				'uuid',
				'ability',
				'label',
				'status',
				'user_id',
				'total_items',
				'done_items',
				'failed_items',
				'progress_percent',
				'created_at',
				'finished_at',
			),
			array_keys( $job->to_summary() )
		);
	}
}
