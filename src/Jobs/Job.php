<?php
/**
 * Job value object.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Jobs;

use SuperAbilities\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * A single row of the jobs table, with typed accessors.
 *
 * Instances are immutable snapshots. Anything that changes a job goes through
 * {@see Queue} or {@see Runner} and then re-reads the row.
 *
 * @since 0.1.0
 */
class Job {

	/**
	 * Statuses a job can never leave.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const FINISHED_STATUSES = array( 'completed', 'partial', 'failed', 'cancelled' );

	/**
	 * Every status a job can have.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const STATUSES = array( 'queued', 'running', 'completed', 'partial', 'failed', 'cancelled' );

	/**
	 * Every status a job item can have.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const ITEM_STATUSES = array( 'pending', 'running', 'done', 'failed', 'skipped', 'cancelled' );

	/**
	 * Default job options.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	const DEFAULT_OPTIONS = array(
		'stop_on_error' => false,
		'keep_results'  => true,
		'label'         => '',
	);

	/**
	 * The raw database row.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	protected $row;

	/**
	 * Decoded `options` column.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>|null
	 */
	protected $options = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row A row of the jobs table.
	 */
	public function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * Builds a job from whatever `$wpdb` returned, or null when the row is unusable.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $row Database row as an object or an array.
	 * @return Job|null
	 */
	public static function from_row( $row ) {
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}

		if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
			return null;
		}

		$columns = array();

		foreach ( $row as $column => $value ) {
			$columns[ (string) $column ] = $value;
		}

		return new self( $columns );
	}

	/**
	 * The raw row, as read from the database.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function row() {
		return $this->row;
	}

	/**
	 * Primary key.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function id() {
		return $this->int( 'id' );
	}

	/**
	 * Public identifier handed to callers.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function uuid() {
		return $this->string( 'uuid' );
	}

	/**
	 * Fully namespaced name of the ability this job runs.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function ability() {
		return $this->string( 'ability' );
	}

	/**
	 * Caller supplied label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return $this->string( 'label' );
	}

	/**
	 * Id of the user the job runs as.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function user_id() {
		return $this->int( 'user_id' );
	}

	/**
	 * UUID of the application password the job was started with, when there was one.
	 *
	 * @since 0.1.0
	 *
	 * @return string Empty string when the job was not started with an application password.
	 */
	public function app_password_uuid() {
		return $this->string( 'app_password_uuid' );
	}

	/**
	 * Job status.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of {@see Job::STATUSES}.
	 */
	public function status() {
		return $this->string( 'status' );
	}

	/**
	 * Whether somebody asked for this job to stop.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function cancel_requested() {
		return 1 === $this->int( 'cancel_requested' );
	}

	/**
	 * Number of items the job was queued with.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function total_items() {
		return $this->int( 'total_items' );
	}

	/**
	 * Number of items that completed successfully.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function done_items() {
		return $this->int( 'done_items' );
	}

	/**
	 * Number of items that failed.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function failed_items() {
		return $this->int( 'failed_items' );
	}

	/**
	 * Decoded job options, with defaults applied.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function options() {
		if ( null !== $this->options ) {
			return $this->options;
		}

		$decoded = json_decode( $this->string( 'options' ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$this->options = array(
			'stop_on_error' => ! empty( $decoded['stop_on_error'] ),
			'keep_results'  => ! array_key_exists( 'keep_results', $decoded ) || ! empty( $decoded['keep_results'] ),
			'label'         => isset( $decoded['label'] ) ? (string) $decoded['label'] : '',
		);

		return $this->options;
	}

	/**
	 * Whether the job stops at the first failing item.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function stop_on_error() {
		$options = $this->options();

		return ! empty( $options['stop_on_error'] );
	}

	/**
	 * Whether item results are stored.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function keep_results() {
		$options = $this->options();

		return ! empty( $options['keep_results'] );
	}

	/**
	 * The token held by the worker that currently owns this job.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function lock_token() {
		return $this->string( 'lock_token' );
	}

	/**
	 * When the current lock expires, as a UTC MySQL datetime.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function lock_expires_at() {
		return $this->string( 'lock_expires_at' );
	}

	/**
	 * Whether a worker holds an unexpired lock on this job.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_locked() {
		if ( '' === $this->lock_token() ) {
			return false;
		}

		$expires = $this->timestamp( 'lock_expires_at' );

		return null !== $expires && $expires > Time::now();
	}

	/**
	 * Last error recorded against the job as a whole.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->string( 'last_error' );
	}

	/**
	 * When the job was queued, as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null
	 */
	public function created_at() {
		return $this->timestamp( 'created_at' );
	}

	/**
	 * When the first item started, as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null
	 */
	public function started_at() {
		return $this->timestamp( 'started_at' );
	}

	/**
	 * When the job reached a final status, as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null
	 */
	public function finished_at() {
		return $this->timestamp( 'finished_at' );
	}

	/**
	 * When the job row last changed, as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null
	 */
	public function updated_at() {
		return $this->timestamp( 'updated_at' );
	}

	/**
	 * The most recent sign of life, as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int Zero when neither timestamp could be parsed.
	 */
	public function touched_at() {
		$created = $this->created_at();
		$updated = $this->updated_at();

		return max( null === $created ? 0 : $created, null === $updated ? 0 : $updated );
	}

	/**
	 * Whether the job reached a final status.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_finished() {
		return in_array( $this->status(), self::FINISHED_STATUSES, true );
	}

	/**
	 * How far the job got, as a whole percentage.
	 *
	 * @since 0.1.0
	 *
	 * @return int A value between 0 and 100.
	 */
	public function progress_percent() {
		if ( $this->is_finished() ) {
			return 100;
		}

		$total = $this->total_items();

		if ( $total < 1 ) {
			return 0;
		}

		$processed = $this->done_items() + $this->failed_items();

		return max( 0, min( 100, (int) floor( ( $processed / $total ) * 100 ) ) );
	}

	/**
	 * The full job, ready for an ability output.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'uuid'             => $this->uuid(),
			'ability'          => $this->ability(),
			'label'            => $this->label(),
			'status'           => $this->status(),
			'cancel_requested' => $this->cancel_requested(),
			'user_id'          => $this->user_id(),
			'total_items'      => $this->total_items(),
			'done_items'       => $this->done_items(),
			'failed_items'     => $this->failed_items(),
			'progress_percent' => $this->progress_percent(),
			'stop_on_error'    => $this->stop_on_error(),
			'keep_results'     => $this->keep_results(),
			'last_error'       => $this->last_error(),
			'created_at'       => $this->iso( 'created_at' ),
			'started_at'       => $this->iso( 'started_at' ),
			'finished_at'      => $this->iso( 'finished_at' ),
			'updated_at'       => $this->iso( 'updated_at' ),
		);
	}

	/**
	 * The short form used by listings.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_summary() {
		return array(
			'uuid'             => $this->uuid(),
			'ability'          => $this->ability(),
			'label'            => $this->label(),
			'status'           => $this->status(),
			'user_id'          => $this->user_id(),
			'total_items'      => $this->total_items(),
			'done_items'       => $this->done_items(),
			'failed_items'     => $this->failed_items(),
			'progress_percent' => $this->progress_percent(),
			'created_at'       => $this->iso( 'created_at' ),
			'finished_at'      => $this->iso( 'finished_at' ),
		);
	}

	/**
	 * The form returned right after the job was queued.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_receipt() {
		return array(
			'uuid'        => $this->uuid(),
			'ability'     => $this->ability(),
			'label'       => $this->label(),
			'status'      => $this->status(),
			'total_items' => $this->total_items(),
			'created_at'  => $this->iso( 'created_at' ),
		);
	}

	/**
	 * Reads a column as a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Column name.
	 * @return string
	 */
	protected function string( $key ) {
		if ( ! isset( $this->row[ $key ] ) ) {
			return '';
		}

		$value = $this->row[ $key ];

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * Reads a column as an integer.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Column name.
	 * @return int
	 */
	protected function int( $key ) {
		return (int) $this->string( $key );
	}

	/**
	 * Parses a UTC MySQL datetime column into a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Column name.
	 * @return int|null Null when the column is empty or a zero datetime.
	 */
	protected function timestamp( $key ) {
		$value = $this->string( $key );

		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}

		return Time::parse( $value );
	}

	/**
	 * Formats a datetime column as ISO 8601 in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Column name.
	 * @return string Empty string when there is no value.
	 */
	protected function iso( $key ) {
		$timestamp = $this->timestamp( $key );

		return null === $timestamp ? '' : Time::iso( $timestamp );
	}
}
