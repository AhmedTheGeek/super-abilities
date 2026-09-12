<?php
/**
 * Activation, deactivation and schema upgrades.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin tables, seeds options and manages our cron events.
 *
 * @since 0.1.0
 */
class Install {

	/**
	 * Option holding the installed schema version.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'super_abilities_db_version';

	/**
	 * Recurring cron hooks owned by this plugin, mapped to their recurrence.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const CRON_EVENTS = array(
		'super_abilities_audit_prune' => 'daily',
		'super_abilities_jobs_prune'  => 'daily',
		'super_abilities_heartbeat'   => 'hourly',
	);

	/**
	 * Every cron hook owned by this plugin, including single events.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const CRON_HOOKS = array(
		'super_abilities_audit_prune',
		'super_abilities_jobs_prune',
		'super_abilities_heartbeat',
		'super_abilities_run_job',
	);

	/**
	 * Short names of the tables this plugin owns.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const TABLES = array( 'audit_log', 'jobs', 'job_items', 'redirects' );

	/**
	 * Returns the prefixed name of one of our tables.
	 *
	 * Accepts either the short name (`audit_log`) or the already prefixed short
	 * name (`sa_audit_log`).
	 *
	 * @since 0.1.0
	 *
	 * @param string $short Short table name.
	 * @return string Fully prefixed table name.
	 */
	public static function table( $short ) {
		global $wpdb;

		$short = (string) $short;

		if ( 0 === strpos( $short, 'sa_' ) ) {
			$short = substr( $short, 3 );
		}

		return $wpdb->prefix . 'sa_' . $short;
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $network_wide Whether the plugin was activated network wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => 0,
					'update_site_meta_cache' => false,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}

	/**
	 * Installs the plugin on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate_site() {
		self::install_tables();
		self::seed_options();
		self::schedule_events();

		update_option( self::DB_VERSION_OPTION, SUPER_ABILITIES_DB_VERSION, true );
	}

	/**
	 * Installs the plugin on a newly created multisite site.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $new_site The new site object.
	 * @return void
	 */
	public static function on_new_site( $new_site ) {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( SUPER_ABILITIES_FILE ) ) ) {
			return;
		}

		$site_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? (int) $new_site->blog_id : 0;

		if ( $site_id < 1 ) {
			return;
		}

		switch_to_blog( $site_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => 0,
					'update_site_meta_cache' => false,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clear_scheduled_events();
				restore_current_blog();
			}

			return;
		}

		self::clear_scheduled_events();
	}

	/**
	 * Upgrades the schema when the stored version is behind the shipped one.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( (int) SUPER_ABILITIES_DB_VERSION === $installed ) {
			self::schedule_events();
			return;
		}

		self::install_tables();
		self::seed_options();
		self::schedule_events();

		update_option( self::DB_VERSION_OPTION, SUPER_ABILITIES_DB_VERSION, true );

		/**
		 * Fires after the plugin schema has been created or upgraded.
		 *
		 * @since 0.1.0
		 *
		 * @param int $to   Schema version that is now installed.
		 * @param int $from Schema version that was installed before.
		 */
		do_action( 'super_abilities_upgraded', (int) SUPER_ABILITIES_DB_VERSION, $installed );
	}

	/**
	 * Writes the default settings when none are stored yet.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function seed_options() {
		if ( false === get_option( Options::OPTION, false ) ) {
			add_option( Options::OPTION, Options::DEFAULTS, '', true );
		}
	}

	/**
	 * Creates or updates the plugin tables.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$audit_log = self::table( 'audit_log' );
		$jobs      = self::table( 'jobs' );
		$job_items = self::table( 'job_items' );

		$queries = array();

		$queries[] = "CREATE TABLE $audit_log (
	id bigint(20) unsigned NOT NULL auto_increment,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	request_id char(32) NOT NULL default '',
	ability varchar(191) NOT NULL default '',
	transport varchar(20) NOT NULL default '',
	client varchar(191) NOT NULL default '',
	user_id bigint(20) unsigned NOT NULL default 0,
	app_password_uuid char(36) default NULL,
	outcome varchar(20) NOT NULL default '',
	error_code varchar(100) NOT NULL default '',
	input_keys text,
	object_type varchar(50) NOT NULL default '',
	object_id varchar(191) NOT NULL default '',
	duration_ms int(10) unsigned NOT NULL default 0,
	ip varchar(45) NOT NULL default '',
	job_id bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	KEY ability_created (ability,created_at),
	KEY user_created (user_id,created_at),
	KEY outcome_created (outcome,created_at),
	KEY object (object_type,object_id),
	KEY request_id (request_id),
	KEY job_id (job_id)
) $charset_collate;";

		$queries[] = "CREATE TABLE $jobs (
	id bigint(20) unsigned NOT NULL auto_increment,
	uuid char(36) NOT NULL default '',
	ability varchar(191) NOT NULL default '',
	label varchar(191) NOT NULL default '',
	user_id bigint(20) unsigned NOT NULL default 0,
	app_password_uuid char(36) default NULL,
	status varchar(20) NOT NULL default 'queued',
	cancel_requested tinyint(1) NOT NULL default 0,
	total_items int(10) unsigned NOT NULL default 0,
	done_items int(10) unsigned NOT NULL default 0,
	failed_items int(10) unsigned NOT NULL default 0,
	options longtext,
	lock_token char(32) default NULL,
	lock_expires_at datetime default NULL,
	last_error text,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	started_at datetime default NULL,
	finished_at datetime default NULL,
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY uuid (uuid),
	KEY status_created (status,created_at),
	KEY user_created (user_id,created_at),
	KEY ability (ability),
	KEY lock_expires_at (lock_expires_at)
) $charset_collate;";

		$queries[] = "CREATE TABLE $job_items (
	id bigint(20) unsigned NOT NULL auto_increment,
	job_id bigint(20) unsigned NOT NULL default 0,
	position int(10) unsigned NOT NULL default 0,
	status varchar(20) NOT NULL default 'pending',
	input longtext,
	result longtext,
	result_truncated tinyint(1) NOT NULL default 0,
	error_code varchar(100) NOT NULL default '',
	error_message text,
	attempts smallint(5) unsigned NOT NULL default 0,
	started_at datetime default NULL,
	finished_at datetime default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY job_position (job_id,position),
	KEY job_status (job_id,status)
) $charset_collate;";

		/**
		 * Filters the `CREATE TABLE` statements passed to `dbDelta()`.
		 *
		 * Modules that own extra tables append their statements here.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string> $queries Table definitions.
		 */
		$queries = (array) apply_filters( 'super_abilities_table_schema', $queries );

		dbDelta( implode( "\n", $queries ) );
	}

	/**
	 * Schedules our recurring cron events when they are missing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function schedule_events() {
		foreach ( self::CRON_EVENTS as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + wp_rand( 60, 600 ), $recurrence, $hook );
			}
		}
	}

	/**
	 * Removes every cron event owned by this plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function clear_scheduled_events() {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
