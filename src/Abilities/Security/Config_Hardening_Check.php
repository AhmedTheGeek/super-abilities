<?php
/**
 * Configuration hardening ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Security;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Security\Probes;
use SuperAbilities\Support\Capabilities;
use SuperAbilities\Support\Schema;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews the constants, options and endpoints that decide how hard this site is to attack.
 *
 * Every check returns one of four statuses: `pass` when the site is hardened, `warn`
 * when it is worth changing, `fail` when it leaks something or is actively unsafe, and
 * `info` when the answer depends on how the site is run and there is nothing to fix.
 *
 * @since 0.1.0
 */
class Config_Hardening_Check extends Abstract_Ability {

	/**
	 * The eight constants that must hold unique secrets.
	 *
	 * @since 0.1.0
	 * @var array<int, string>
	 */
	const SALT_CONSTANTS = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	/**
	 * The placeholder shipped in `wp-config-sample.php`.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SALT_PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Minimum acceptable salt length.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const SALT_MIN_LENGTH = 32;

	/**
	 * PHP version below which we warn.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const MIN_PHP = '8.1';

	/**
	 * Ability slug.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'config-hardening-check';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function module() {
		return 'security';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Configuration hardening check', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reviews the hardening configuration of this site and returns one finding per check with a recommendation: the file editing and file modification constants, debug display and whether debug.log is publicly readable, the database table prefix, the eight security salts, HTTPS and FORCE_SSL_ADMIN, XML-RPC, author and REST user enumeration, open registration and its default role, an "admin" login, an exposed readme.html, automatic updates, the PHP version and pending core updates.', 'super-abilities' );
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
		return array( 'manage_options' );
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
		$check = Schema::object(
			array(
				'id'             => array( 'type' => 'string' ),
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'pass', 'warn', 'fail', 'info' ),
				),
				'detail'         => array( 'type' => 'string' ),
				'recommendation' => array( 'type' => 'string' ),
			),
			array( 'id', 'status', 'detail', 'recommendation' )
		);

		return Schema::object(
			array(
				'environment' => array( 'type' => 'string' ),
				'checks'      => array(
					'type'  => 'array',
					'items' => $check,
				),
				'summary'     => Schema::object(
					array(
						'pass' => array( 'type' => 'integer' ),
						'warn' => array( 'type' => 'integer' ),
						'fail' => array( 'type' => 'integer' ),
						'info' => array( 'type' => 'integer' ),
					),
					array( 'pass', 'warn', 'fail', 'info' )
				),
			),
			array( 'environment', 'checks', 'summary' )
		);
	}

	/**
	 * Runs every check.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$environment = (string) wp_get_environment_type();

		$checks = array(
			$this->check_disallow_file_edit(),
			$this->check_disallow_file_mods(),
			$this->check_debug_display( $environment ),
			$this->check_debug_log_public(),
			$this->check_table_prefix(),
			$this->check_salts(),
			$this->check_https(),
			$this->check_xmlrpc(),
			$this->check_author_enumeration(),
			$this->check_rest_user_enumeration(),
			$this->check_registration(),
			$this->check_admin_login(),
			$this->check_readme_exposed(),
			$this->check_auto_updates(),
			$this->check_application_passwords(),
			$this->check_php_version(),
			$this->check_wp_version(),
		);

		$summary = array(
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
			'info' => 0,
		);

		foreach ( $checks as $check ) {
			++$summary[ $check['status'] ];
		}

		return array(
			'environment' => $environment,
			'checks'      => $checks,
			'summary'     => $summary,
		);
	}

	/**
	 * Builds one finding.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id             Check id.
	 * @param string $status         One of `pass`, `warn`, `fail` or `info`.
	 * @param string $detail         What was found.
	 * @param string $recommendation Optional. What to do about it. Default empty.
	 * @return array<string, string>
	 */
	protected function finding( $id, $status, $detail, $recommendation = '' ) {
		return array(
			'id'             => (string) $id,
			'status'         => (string) $status,
			'detail'         => (string) $detail,
			'recommendation' => (string) $recommendation,
		);
	}

	/**
	 * Whether the theme and plugin file editors are disabled.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_disallow_file_edit() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return $this->finding(
				'disallow_file_edit',
				'pass',
				__( 'DISALLOW_FILE_EDIT is true, so the built in theme and plugin file editors are switched off.', 'super-abilities' )
			);
		}

		return $this->finding(
			'disallow_file_edit',
			'warn',
			__( 'The built in theme and plugin file editors are available, so anyone who reaches the dashboard as an administrator can execute code on the server.', 'super-abilities' ),
			__( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.', 'super-abilities' )
		);
	}

	/**
	 * Whether plugin and theme installs are blocked outright.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_disallow_file_mods() {
		// `wp_is_file_mod_allowed()` answers what DISALLOW_FILE_MODS plus any filter add up to.
		$disabled = ! Capabilities::file_mods_allowed( 'super_abilities_hardening_check' );

		return $this->finding(
			'disallow_file_mods',
			'info',
			$disabled
				? __( 'File modifications are blocked, by DISALLOW_FILE_MODS or by a filter, so plugins and themes cannot be installed, updated or deleted. This is the safest setting, and it also blocks automatic security updates.', 'super-abilities' )
				: __( 'File modifications are allowed, so plugins and themes can be installed and updated from the dashboard. This is the normal setting for a site that is not deployed from version control.', 'super-abilities' )
		);
	}

	/**
	 * Whether PHP errors are printed to visitors.
	 *
	 * @since 0.1.0
	 *
	 * @param string $environment Environment type.
	 * @return array<string, string>
	 */
	protected function check_debug_display( $environment ) {
		$debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display = ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY;

		if ( ! $debug || ! $display ) {
			return $this->finding(
				'debug_display',
				'pass',
				__( 'PHP errors are not printed into the page.', 'super-abilities' )
			);
		}

		if ( 'production' === $environment ) {
			return $this->finding(
				'debug_display',
				'fail',
				__( 'WP_DEBUG is on and errors are printed into the page on a production site, which shows visitors absolute paths, query fragments and plugin internals.', 'super-abilities' ),
				__( 'Set define( \'WP_DEBUG_DISPLAY\', false ); and, when you need the errors, define( \'WP_DEBUG_LOG\', true ); instead.', 'super-abilities' )
			);
		}

		return $this->finding(
			'debug_display',
			'info',
			sprintf(
				/* translators: %s: Environment type, e.g. "development". */
				__( 'WP_DEBUG is on and errors are printed into the page. That is expected on a "%s" site.', 'super-abilities' ),
				$environment
			)
		);
	}

	/**
	 * Whether the debug log can be downloaded by anyone.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_debug_log_public() {
		$logging = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		if ( ! $logging ) {
			return $this->finding(
				'debug_log_public',
				'info',
				__( 'WP_DEBUG_LOG is off, so WordPress is not writing a debug log.', 'super-abilities' )
			);
		}

		$probe = Probes::exposure( content_url( 'debug.log' ) );

		if ( $probe['exposed'] ) {
			return $this->finding(
				'debug_log_public',
				'fail',
				__( 'The debug log is readable over HTTP, which publishes absolute paths, queries and anything the code logged.', 'super-abilities' ),
				__( 'Move the log outside the web root with define( \'WP_DEBUG_LOG\', \'/path/outside/webroot/debug.log\' );, or deny access to it in the web server configuration.', 'super-abilities' )
			);
		}

		return $this->finding(
			'debug_log_public',
			'pass',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Logging is on and the log is not readable over HTTP (HTTP %d).', 'super-abilities' ),
				$probe['http_code']
			)
		);
	}

	/**
	 * Whether the database tables still use the default prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_table_prefix() {
		global $wpdb;

		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		if ( 'wp_' === $prefix ) {
			return $this->finding(
				'table_prefix',
				'warn',
				__( 'The database tables use the default "wp_" prefix, which makes blind SQL injection payloads in other plugins easier to write.', 'super-abilities' ),
				__( 'Changing the prefix on a live site is risky and only buys a little obscurity. Treat it as a nice to have during the next migration rather than an urgent fix.', 'super-abilities' )
			);
		}

		return $this->finding(
			'table_prefix',
			'pass',
			__( 'The database tables do not use the default prefix.', 'super-abilities' )
		);
	}

	/**
	 * Whether the eight security salts are unique and long enough.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_salts() {
		$problems = array();

		foreach ( self::SALT_CONSTANTS as $constant ) {
			if ( ! defined( $constant ) ) {
				$problems[] = $constant;
				continue;
			}

			$value = constant( $constant );

			if ( ! is_string( $value ) || strlen( $value ) < self::SALT_MIN_LENGTH ) {
				$problems[] = $constant;
				continue;
			}

			if ( false !== stripos( $value, self::SALT_PLACEHOLDER ) ) {
				$problems[] = $constant;
			}
		}

		if ( empty( $problems ) ) {
			return $this->finding(
				'salts',
				'pass',
				__( 'All eight authentication keys and salts are defined, long enough and not the sample placeholder.', 'super-abilities' )
			);
		}

		return $this->finding(
			'salts',
			'fail',
			sprintf(
				/* translators: %s: Comma separated list of constant names. */
				__( 'These authentication keys or salts are missing, too short, or still the sample placeholder: %s. Cookies and nonces on this site are guessable.', 'super-abilities' ),
				implode( ', ', $problems )
			),
			__( 'Generate a fresh set at https://api.wordpress.org/secret-key/1.1/salt/ and paste all eight lines into wp-config.php. Everyone will be logged out once.', 'super-abilities' )
		);
	}

	/**
	 * Whether the site and its dashboard are served over HTTPS.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_https() {
		$using_https = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : ( 0 === strpos( home_url(), 'https://' ) );
		$forced      = force_ssl_admin();

		if ( ! $using_https ) {
			return $this->finding(
				'https',
				'fail',
				__( 'The site URL and home URL are not HTTPS, so logins and cookies travel in the clear.', 'super-abilities' ),
				__( 'Install a certificate, then switch the site and home URLs to https and force the dashboard over TLS with define( \'FORCE_SSL_ADMIN\', true );.', 'super-abilities' )
			);
		}

		if ( ! $forced ) {
			return $this->finding(
				'https',
				'warn',
				__( 'The site is served over HTTPS but FORCE_SSL_ADMIN is not set, so a plain HTTP request to the dashboard is not redirected before the credentials are sent.', 'super-abilities' ),
				__( 'Add define( \'FORCE_SSL_ADMIN\', true ); to wp-config.php.', 'super-abilities' )
			);
		}

		return $this->finding(
			'https',
			'pass',
			__( 'The site is served over HTTPS and FORCE_SSL_ADMIN is set.', 'super-abilities' )
		);
	}

	/**
	 * Whether XML-RPC is still answering.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_xmlrpc() {
		/** This filter is documented in wp-includes/class-wp-xmlrpc-server.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading core's own filter is the only way to know whether XML-RPC answers.
		if ( apply_filters( 'xmlrpc_enabled', true ) ) {
			return $this->finding(
				'xmlrpc',
				'warn',
				__( 'XML-RPC is enabled. Its system.multicall method lets an attacker try many passwords in a single request, and it is a common amplification target.', 'super-abilities' ),
				__( 'Unless the Jetpack app or a remote publishing client needs it, block xmlrpc.php in the web server or return false from the xmlrpc_enabled filter.', 'super-abilities' )
			);
		}

		return $this->finding(
			'xmlrpc',
			'pass',
			__( 'XML-RPC is disabled.', 'super-abilities' )
		);
	}

	/**
	 * Whether `/?author=1` redirects to an author archive and leaks a login.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_author_enumeration() {
		$probe = Probes::redirect_target( home_url( '/?author=1' ) );

		if ( '' !== $probe['error'] ) {
			return $this->finding(
				'author_enumeration',
				'info',
				sprintf(
					/* translators: %s: Error message. */
					__( 'The author archive probe could not be completed: %s', 'super-abilities' ),
					$probe['error']
				)
			);
		}

		$redirects = in_array( $probe['code'], array( 301, 302, 307, 308 ), true );

		if ( $redirects && false !== strpos( $probe['location'], '/author/' ) ) {
			return $this->finding(
				'author_enumeration',
				'warn',
				__( 'A request for /?author=1 redirects to the author archive, so anyone can read the login names of your authors from the URL and then attack the login form with real user names.', 'super-abilities' ),
				__( 'Redirect or 404 author archives you do not use, and make sure the login name and the display name of every privileged user differ.', 'super-abilities' )
			);
		}

		return $this->finding(
			'author_enumeration',
			'pass',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'A request for /?author=1 does not redirect to an author archive (HTTP %d).', 'super-abilities' ),
				$probe['code']
			)
		);
	}

	/**
	 * Whether the REST users endpoint answers anonymous callers.
	 *
	 * Dispatched in process with the current user temporarily set to nobody, which is
	 * exactly what an anonymous request sees without making an HTTP request.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_rest_user_enumeration() {
		$previous = get_current_user_id();

		try {
			wp_set_current_user( 0 );

			$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
			$request->set_param( 'per_page', 1 );

			$response = rest_do_request( $request );
			$status   = (int) $response->get_status();
			$data     = $response->get_data();
		} finally {
			wp_set_current_user( $previous );
		}

		if ( 200 === $status && is_array( $data ) && ! empty( $data ) ) {
			return $this->finding(
				'rest_user_enumeration',
				'warn',
				__( 'An anonymous request to /wp-json/wp/v2/users returns users, so the login name of every author is public. WordPress does this by default for authors with published posts.', 'super-abilities' ),
				__( 'If the site does not need public author data, filter rest_endpoints to remove the collection route, or make sure no privileged user is also a post author.', 'super-abilities' )
			);
		}

		return $this->finding(
			'rest_user_enumeration',
			'pass',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'An anonymous request to /wp-json/wp/v2/users returns no users (HTTP %d).', 'super-abilities' ),
				$status
			)
		);
	}

	/**
	 * Whether anyone may register, and as what.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_registration() {
		if ( ! get_option( 'users_can_register' ) ) {
			return $this->finding(
				'registration',
				'pass',
				__( 'Open registration is off.', 'super-abilities' )
			);
		}

		$role = (string) get_option( 'default_role' );

		if ( 'subscriber' !== $role ) {
			return $this->finding(
				'registration',
				'fail',
				sprintf(
					/* translators: %s: Role slug. */
					__( 'Anyone may register and new accounts get the "%s" role rather than subscriber, so a stranger can obtain more than read access.', 'super-abilities' ),
					$role
				),
				__( 'Set the default role back to subscriber under Settings, General, or switch open registration off.', 'super-abilities' )
			);
		}

		return $this->finding(
			'registration',
			'info',
			__( 'Anyone may register, and new accounts get the subscriber role. That is the intended setup for a membership or comment gated site.', 'super-abilities' )
		);
	}

	/**
	 * Whether a user named `admin` exists.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_admin_login() {
		if ( username_exists( 'admin' ) ) {
			return $this->finding(
				'admin_login_exists',
				'warn',
				__( 'A user with the login "admin" exists. It is the first login every credential stuffing bot tries.', 'super-abilities' ),
				__( 'Create a new administrator with an unguessable login, move the content over, and delete or demote the "admin" account.', 'super-abilities' )
			);
		}

		return $this->finding(
			'admin_login_exists',
			'pass',
			__( 'No user is called "admin".', 'super-abilities' )
		);
	}

	/**
	 * Whether `readme.html` is still served.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_readme_exposed() {
		$probe = Probes::exposure( home_url( 'readme.html' ) );

		if ( $probe['exposed'] ) {
			return $this->finding(
				'readme_exposed',
				'warn',
				__( 'readme.html is readable over HTTP and names the WordPress version, which tells a scanner exactly which exploits to try.', 'super-abilities' ),
				__( 'Delete readme.html, or deny access to it in the web server configuration. It comes back with every core update, so automate it.', 'super-abilities' )
			);
		}

		return $this->finding(
			'readme_exposed',
			'pass',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'readme.html is not readable over HTTP (HTTP %d).', 'super-abilities' ),
				$probe['http_code']
			)
		);
	}

	/**
	 * Whether automatic updates can run.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_auto_updates() {
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			return $this->finding(
				'auto_updates',
				'warn',
				__( 'AUTOMATIC_UPDATER_DISABLED is true, so no automatic update of any kind can run, including a core security release.', 'super-abilities' ),
				__( 'Remove the constant, or make sure a deployment pipeline applies security releases within a day.', 'super-abilities' )
			);
		}

		$core_enabled = $this->core_auto_updates_enabled();

		return $this->finding(
			'auto_updates',
			$core_enabled ? 'pass' : 'warn',
			$core_enabled
				? __( 'Automatic core updates are enabled.', 'super-abilities' )
				: __( 'Automatic core updates are switched off, so security releases wait for a human.', 'super-abilities' ),
			$core_enabled ? '' : __( 'Re-enable automatic updates for core, or apply security releases manually within a day of publication.', 'super-abilities' )
		);
	}

	/**
	 * Whether the automatic updater is allowed to run at all.
	 *
	 * `wp_is_auto_update_enabled_for_type()` only answers for `plugin` and `theme`, so
	 * the updater itself is asked about core.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	protected function core_auto_updates_enabled() {
		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		$updater = new \WP_Automatic_Updater();

		return ! $updater->is_disabled();
	}

	/**
	 * Whether application passwords can be issued.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_application_passwords() {
		$available = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();

		return $this->finding(
			'application_passwords_available',
			'info',
			$available
				? __( 'Application passwords are available, which is how agents and other clients authenticate against this site. Audit them with the admin-users-audit ability.', 'super-abilities' )
				: __( 'Application passwords are not available on this site, so REST clients must authenticate some other way.', 'super-abilities' )
		);
	}

	/**
	 * Whether PHP is recent enough to receive security fixes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_php_version() {
		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			return $this->finding(
				'php_version',
				'warn',
				sprintf(
					/* translators: 1: Current PHP version. 2: Minimum recommended PHP version. */
					__( 'This site runs PHP %1$s, which is older than the recommended %2$s and may no longer receive security fixes.', 'super-abilities' ),
					PHP_VERSION,
					self::MIN_PHP
				),
				__( 'Ask the host to move the site to a supported PHP version, after checking the plugins and theme for compatibility.', 'super-abilities' )
			);
		}

		return $this->finding(
			'php_version',
			'pass',
			sprintf(
				/* translators: %s: Current PHP version. */
				__( 'This site runs PHP %s.', 'super-abilities' ),
				PHP_VERSION
			)
		);
	}

	/**
	 * Whether a core update is pending.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function check_wp_version() {
		$current = (string) get_bloginfo( 'version' );

		if ( false === get_site_transient( 'update_core' ) ) {
			return $this->finding(
				'wp_version_current',
				'info',
				sprintf(
					/* translators: %s: WordPress version. */
					__( 'This site runs WordPress %s. Core has not checked for updates yet, so whether a newer release exists is unknown; the updates-overview ability can refresh that.', 'super-abilities' ),
					$current
				)
			);
		}

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_core_updates();
		$newest  = '';

		if ( is_array( $updates ) ) {
			foreach ( $updates as $update ) {
				if ( ! is_object( $update ) || ! isset( $update->response ) || 'upgrade' !== $update->response ) {
					continue;
				}

				$version = isset( $update->current ) ? (string) $update->current : '';

				if ( '' !== $version && version_compare( $version, $current, '>' ) && version_compare( $version, $newest, '>' ) ) {
					$newest = $version;
				}
			}
		}

		if ( '' !== $newest ) {
			return $this->finding(
				'wp_version_current',
				'warn',
				sprintf(
					/* translators: 1: Installed WordPress version. 2: Available WordPress version. */
					__( 'This site runs WordPress %1$s while %2$s is available. Core releases usually carry security fixes.', 'super-abilities' ),
					$current,
					$newest
				),
				__( 'Apply the core update, after a backup if the gap spans a major release.', 'super-abilities' )
			);
		}

		return $this->finding(
			'wp_version_current',
			'pass',
			sprintf(
				/* translators: %s: WordPress version. */
				__( 'This site runs WordPress %s, which is the newest release core knows about.', 'super-abilities' ),
				$current
			)
		);
	}
}
