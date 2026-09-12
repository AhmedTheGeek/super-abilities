<?php
/**
 * Cached Site Health counts plus the safe parts of the debug data.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use SuperAbilities\Support\Time;
use WP_Debug_Data;

defined( 'ABSPATH' ) || exit;

/**
 * An overview of the site: cached health counts and environment facts.
 *
 * No Site Health test runs here. Note that `WP_Debug_Data::debug_data()` itself
 * requests wordpress.org with a ten second timeout to fill in the
 * `dotorg_communication` field, so the call is not free. Use
 * `super-abilities/health-run` when fresh verdicts are needed.
 *
 * @since 0.1.0
 */
class Health_Summary extends Abstract_Ability {

	/**
	 * Transient the Site Health screen and its weekly cron job write their counts to.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const COUNTS_TRANSIENT = 'health-check-site-status-result';

	/**
	 * Debug data sections we expose. `wp-paths-sizes` is deliberately absent.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const SECTIONS = array( 'wp-core', 'wp-server', 'wp-database', 'wp-constants' );

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'health-summary';
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
		return __( 'Site Health summary', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Returns the last cached Site Health counts together with the core, server, database and constants sections of the WordPress debug data, with private fields dropped and secrets redacted. No Site Health test is executed, so this is the quick way to get your bearings, although collecting the debug data costs one outbound request to wordpress.org because core checks that connection while it builds the data. All counts being zero means no one has run the Site Health tests yet, so call super-abilities/health-run. WordPress does not record when it cached the counts, so they can be arbitrarily old. Directory and database sizes are left out because collecting them is slow.', 'super-abilities' );
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
		return Schema::object( array() );
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
				'counts'           => Schema::object(
					array(
						'good'        => array( 'type' => 'integer' ),
						'recommended' => array( 'type' => 'integer' ),
						'critical'    => array( 'type' => 'integer' ),
						'cached_at'   => array(
							'type'        => 'string',
							'description' => __( 'Upper bound for when the counts were stored, read from the transient expiry. Usually empty, because WordPress stores these counts without an expiry and without a timestamp: treat them as possibly stale and call super-abilities/health-run for fresh verdicts.', 'super-abilities' ),
						),
					),
					array( 'good', 'recommended', 'critical', 'cached_at' )
				),
				'environment'      => array(
					'type'        => 'string',
					'description' => __( 'Result of wp_get_environment_type(), for example "production".', 'super-abilities' ),
				),
				'development_mode' => array(
					'type'        => 'string',
					'description' => __( 'Result of wp_get_development_mode(). Empty when development mode is off.', 'super-abilities' ),
				),
				'sections'         => array(
					'type'        => 'object',
					'description' => __( 'Debug data sections keyed by id, each with a label and its public fields.', 'super-abilities' ),
				),
			),
			array( 'counts', 'environment', 'development_mode', 'sections' )
		);
	}

	/**
	 * Builds the summary.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		return array(
			'counts'           => $this->counts(),
			'environment'      => (string) wp_get_environment_type(),
			'development_mode' => (string) wp_get_development_mode(),
			'sections'         => $this->sections(),
		);
	}

	/**
	 * Reads the cached status counts.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function counts() {
		$counts = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
			'cached_at'   => '',
		);

		$cached = get_transient( self::COUNTS_TRANSIENT );

		if ( is_string( $cached ) && '' !== $cached ) {
			$decoded = json_decode( $cached, true );

			if ( is_array( $decoded ) ) {
				foreach ( array( 'good', 'recommended', 'critical' ) as $key ) {
					if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
						$counts[ $key ] = (int) $decoded[ $key ];
					}
				}
			}
		}

		/*
		 * Both writers of this transient (the Site Health screen over Ajax and the
		 * weekly `wp_site_health_scheduled_check` cron job) store it without an
		 * expiry and without a timestamp, so WordPress keeps no record of when the
		 * counts were produced. Should something ever store it with an expiry, that
		 * expiry is reported as an upper bound on the write time.
		 */
		$expires = get_option( '_transient_timeout_' . self::COUNTS_TRANSIENT );

		if ( is_numeric( $expires ) && (int) $expires > 0 ) {
			$counts['cached_at'] = Time::iso( (int) $expires );
		}

		return $counts;
	}

	/**
	 * Collects the allowed debug data sections, redacted.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function sections() {
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}

		Health_Run::load_site_health();

		$all = (array) WP_Debug_Data::debug_data();

		/**
		 * Filters which debug data sections `health-summary` exposes.
		 *
		 * Sections are redacted and stripped of their private fields afterwards.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string> $sections Section ids.
		 */
		$wanted = (array) apply_filters( 'super_abilities_health_summary_sections', self::SECTIONS );

		$selected = array();

		foreach ( $wanted as $section_id ) {
			$section_id = (string) $section_id;

			if ( 'wp-paths-sizes' === $section_id ) {
				continue;
			}

			if ( isset( $all[ $section_id ] ) && is_array( $all[ $section_id ] ) ) {
				$selected[ $section_id ] = $all[ $section_id ];
			}
		}

		return Redactor::redact_debug_data( $selected, true );
	}
}
