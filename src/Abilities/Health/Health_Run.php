<?php
/**
 * Runs the Site Health tests.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;
use WP_Site_Health;

defined( 'ABSPATH' ) || exit;

/**
 * Executes the Site Health checks server side and returns their verdicts.
 *
 * @since 0.1.0
 */
class Health_Run extends Abstract_Ability {

	/**
	 * Default wall clock budget for one call, in seconds.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const DEFAULT_BUDGET = 20;

	/**
	 * Statuses a Site Health test can report.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const STATUSES = array( 'good', 'recommended', 'critical' );

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'health-run';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'health';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Run Site Health tests', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs the WordPress Site Health tests on the server and returns each verdict with its status, badge and recommended action. Several tests make outbound HTTP requests and can take many seconds: loopback_requests, dotorg_communication, background_updates, https_status and rest_availability are better run in the background through super-abilities/job-start, or skipped with include_async set to false. Asynchronous tests that offer no server side variant are reported in skipped instead of tests.', 'super-abilities' );
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
		return array( 'view_site_health_checks' );
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
				'tests'         => Schema::csv_or_array_of_strings(
					__( 'Test slugs to run, as an array or a comma separated list, for example "php_version,sql_server". Defaults to every test.', 'super-abilities' )
				),
				'include_async' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether to run the asynchronous tests that offer a server side variant. These are the slow, network bound ones. Default true.', 'super-abilities' ),
				),
				'status'        => array(
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'description' => __( 'Only return tests that reported this status. The summary counts always cover every test that ran.', 'super-abilities' ),
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
		$test = Schema::object(
			array(
				'test'        => array(
					'type'        => 'string',
					'description' => __( 'Test identifier, ready to be passed back in the tests input.', 'super-abilities' ),
				),
				'label'       => array( 'type' => 'string' ),
				'status'      => array(
					'type' => 'string',
					'enum' => self::STATUSES,
				),
				'badge'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'actions'     => array( 'type' => 'string' ),
				'duration_ms' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'test', 'label', 'status', 'badge', 'description', 'actions', 'duration_ms' )
		);

		$skipped = Schema::object(
			array(
				'test'   => array( 'type' => 'string' ),
				'label'  => array( 'type' => 'string' ),
				'reason' => array( 'type' => 'string' ),
			),
			array( 'test', 'label', 'reason' )
		);

		return Schema::object(
			array(
				'ran'     => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many tests were executed.', 'super-abilities' ),
				),
				'skipped' => array(
					'type'  => 'array',
					'items' => $skipped,
				),
				'summary' => Schema::object(
					array(
						'good'        => array( 'type' => 'integer' ),
						'recommended' => array( 'type' => 'integer' ),
						'critical'    => array( 'type' => 'integer' ),
					),
					array( 'good', 'recommended', 'critical' )
				),
				'tests'   => array(
					'type'  => 'array',
					'items' => $test,
				),
			),
			array( 'ran', 'skipped', 'summary', 'tests' )
		);
	}

	/**
	 * Runs the tests.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		self::load_site_health();

		$wanted        = Schema::to_string_list( isset( $input['tests'] ) ? $input['tests'] : array() );
		$include_async = ! isset( $input['include_async'] ) || (bool) $input['include_async'];
		$status_filter = isset( $input['status'] ) && in_array( (string) $input['status'], self::STATUSES, true ) ? (string) $input['status'] : '';

		$health     = WP_Site_Health::get_instance();
		$registered = (array) $health->get_tests();

		/**
		 * Filters the wall clock budget for a single `health-run` call, in seconds.
		 *
		 * Once the budget is spent the remaining tests are reported as skipped.
		 *
		 * @since 0.1.0
		 *
		 * @param int $budget Budget in seconds.
		 */
		$budget = (int) apply_filters( 'super_abilities_health_run_budget', self::DEFAULT_BUDGET );
		$budget = $budget > 0 ? $budget : self::DEFAULT_BUDGET;

		$started = microtime( true );
		$results = array();
		$skipped = array();
		$summary = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);

		foreach ( array( 'direct', 'async' ) as $kind ) {
			$group = isset( $registered[ $kind ] ) && is_array( $registered[ $kind ] ) ? $registered[ $kind ] : array();

			foreach ( $group as $slug => $test ) {
				if ( ! is_array( $test ) ) {
					continue;
				}

				$slug  = self::test_slug( $slug, $test );
				$label = isset( $test['label'] ) ? (string) $test['label'] : $slug;

				if ( ! empty( $wanted ) && ! self::is_wanted( $slug, $test, $wanted ) ) {
					continue;
				}

				if ( 'async' === $kind && ! $include_async ) {
					$skipped[] = array(
						'test'   => $slug,
						'label'  => $label,
						'reason' => __( 'Asynchronous test, skipped because include_async is false.', 'super-abilities' ),
					);
					continue;
				}

				$callable = self::resolve_callable( $health, $kind, $test );

				if ( null === $callable ) {
					$skipped[] = array(
						'test'   => $slug,
						'label'  => $label,
						'reason' => __( 'This test only runs in the browser: it exposes no server side variant.', 'super-abilities' ),
					);
					continue;
				}

				if ( ( microtime( true ) - $started ) > $budget ) {
					$skipped[] = array(
						'test'   => $slug,
						'label'  => $label,
						'reason' => sprintf(
							/* translators: %d: Time budget in seconds. */
							__( 'Skipped after the %d second time budget for this call was spent. Run the remaining tests in a background job or pass a shorter tests list.', 'super-abilities' ),
							$budget
						),
					);
					continue;
				}

				$test_started = microtime( true );

				try {
					$result = call_user_func( $callable );
				} catch ( \Throwable $throwable ) {
					$skipped[] = array(
						'test'   => $slug,
						'label'  => $label,
						'reason' => sprintf(
							/* translators: %s: Error message. */
							__( 'The test threw an error: %s', 'super-abilities' ),
							$throwable->getMessage()
						),
					);
					continue;
				}

				$duration = (int) round( ( microtime( true ) - $test_started ) * 1000 );

				if ( ! is_array( $result ) || ! isset( $result['status'] ) || ! in_array( (string) $result['status'], self::STATUSES, true ) ) {
					$skipped[] = array(
						'test'   => $slug,
						'label'  => $label,
						'reason' => __( 'The test returned no usable result.', 'super-abilities' ),
					);
					continue;
				}

				$row = self::normalize_result( $slug, $label, $result, $duration );

				++$summary[ $row['status'] ];

				if ( '' === $status_filter || $status_filter === $row['status'] ) {
					$results[] = $row;
				}
			}
		}

		return array(
			'ran'     => array_sum( $summary ),
			'skipped' => $skipped,
			'summary' => $summary,
			'tests'   => $results,
		);
	}

	/**
	 * Loads the admin side classes the Site Health tests rely on.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function load_site_health() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! function_exists( 'got_url_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		if ( ! class_exists( 'WP_Site_Health_Auto_Updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health-auto-updates.php';
		}

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
	}

	/**
	 * The slug a test should be reported under.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string           $key  Array key from `get_tests()`.
	 * @param array<string, mixed> $test Test definition.
	 * @return string
	 */
	protected static function test_slug( $key, array $test ) {
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		return isset( $test['test'] ) && is_string( $test['test'] ) ? $test['test'] : '';
	}

	/**
	 * Whether a test was asked for.
	 *
	 * Matches the identifier from `get_tests()` and, for direct tests, the method name
	 * behind it: core registers `debug_enabled` with the test `is_in_debug_mode`.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $slug   Test identifier.
	 * @param array<string, mixed> $test   Test definition.
	 * @param array<int, string>   $wanted Identifiers the caller asked for.
	 * @return bool
	 */
	protected static function is_wanted( $slug, array $test, array $wanted ) {
		if ( in_array( $slug, $wanted, true ) ) {
			return true;
		}

		if ( ! isset( $test['test'] ) || ! is_string( $test['test'] ) ) {
			return false;
		}

		// Asynchronous tests store a REST URL here rather than a method name.
		if ( false !== strpos( $test['test'], '://' ) ) {
			return false;
		}

		return in_array( $test['test'], $wanted, true );
	}

	/**
	 * Resolves the callable that runs a test on the server.
	 *
	 * String test names map to `WP_Site_Health::get_test_{name}()`. Asynchronous tests
	 * only run when they declare an `async_direct_test` callable.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Site_Health       $health Site Health instance.
	 * @param string               $kind   Either `direct` or `async`.
	 * @param array<string, mixed> $test   Test definition.
	 * @return callable|null
	 */
	protected static function resolve_callable( WP_Site_Health $health, $kind, array $test ) {
		if ( 'async' === $kind ) {
			if ( isset( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
				return $test['async_direct_test'];
			}

			return null;
		}

		if ( ! isset( $test['test'] ) ) {
			return null;
		}

		if ( is_string( $test['test'] ) ) {
			$method = 'get_test_' . $test['test'];

			if ( method_exists( $health, $method ) ) {
				return array( $health, $method );
			}

			return is_callable( $test['test'] ) ? $test['test'] : null;
		}

		return is_callable( $test['test'] ) ? $test['test'] : null;
	}

	/**
	 * Flattens a Site Health result into plain strings.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $slug     Test slug.
	 * @param string               $label    Test label.
	 * @param array<string, mixed> $result   Raw result.
	 * @param int                  $duration Duration in milliseconds.
	 * @return array<string, mixed>
	 */
	protected static function normalize_result( $slug, $label, array $result, $duration ) {
		$badge = '';

		if ( isset( $result['badge'] ) && is_array( $result['badge'] ) && isset( $result['badge']['label'] ) ) {
			$badge = (string) $result['badge']['label'];
		} elseif ( isset( $result['badge'] ) && is_string( $result['badge'] ) ) {
			$badge = $result['badge'];
		}

		return array(
			'test'        => (string) $slug,
			'label'       => isset( $result['label'] ) && is_string( $result['label'] ) ? $result['label'] : (string) $label,
			'status'      => (string) $result['status'],
			'badge'       => $badge,
			'description' => isset( $result['description'] ) ? trim( wp_strip_all_tags( (string) $result['description'] ) ) : '',
			'actions'     => isset( $result['actions'] ) ? trim( wp_strip_all_tags( (string) $result['actions'] ) ) : '',
			'duration_ms' => (int) $duration,
		);
	}
}
