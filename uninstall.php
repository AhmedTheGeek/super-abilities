<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted, never on deactivation. Nothing is removed unless
 * the `delete_data_on_uninstall` setting is on.
 *
 * @package SuperAbilities
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every trace of the plugin from the current site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function super_abilities_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'super_abilities_settings', array() );

	if ( ! is_array( $settings ) || ! array_key_exists( 'delete_data_on_uninstall', $settings ) ) {
		// Never stored, so fall back to the shipped default, which is to delete.
		$delete = true;
	} else {
		$delete = (bool) $settings['delete_data_on_uninstall'];
	}

	if ( ! $delete ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'sa_audit_log',
		$wpdb->prefix . 'sa_jobs',
		$wpdb->prefix . 'sa_job_items',
		$wpdb->prefix . 'sa_redirects',
	);

	foreach ( $tables as $table ) {
		// Table names cannot be prepared, and they are built from $wpdb->prefix plus a
		// hard-coded suffix, so there is nothing user supplied in this statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}

	$options = array(
		'super_abilities_settings',
		'super_abilities_db_version',
		'super_abilities_last_cron_tick',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Our transients all share one prefix; delete the option rows behind them.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_super_abilities_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_super_abilities_' ) . '%'
		)
	);

	foreach ( (array) $transients as $option_name ) {
		$option_name = (string) $option_name;

		if ( 0 === strpos( $option_name, '_transient_timeout_' ) ) {
			delete_option( $option_name );
			continue;
		}

		delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
	}

	$hooks = array(
		'super_abilities_audit_prune',
		'super_abilities_jobs_prune',
		'super_abilities_heartbeat',
		'super_abilities_run_job',
	);

	foreach ( $hooks as $hook ) {
		wp_unschedule_hook( $hook );
	}
}

if ( is_multisite() ) {
	$super_abilities_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $super_abilities_site_ids as $super_abilities_site_id ) {
		switch_to_blog( (int) $super_abilities_site_id );
		super_abilities_uninstall_site();
		restore_current_blog();
	}

	unset( $super_abilities_site_ids, $super_abilities_site_id );
} else {
	super_abilities_uninstall_site();
}
