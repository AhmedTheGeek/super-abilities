<?php
/**
 * Tests for the privileged users audit ability.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Security\Admin_Users_Audit;

class AdminUsersAuditTest extends WP_UnitTestCase {

	/**
	 * Id of the administrator whose login is the guessable `admin`.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Id of an editor, who must never appear in the report.
	 *
	 * @var int
	 */
	private $editor_id;

	public function set_up() {
		parent::set_up();

		// The test install already ships a user called `admin`, so it is reused rather
		// than created; creating it again would return a WP_Error.
		$existing = get_user_by( 'login', 'admin' );

		if ( $existing instanceof WP_User ) {
			$this->admin_id = (int) $existing->ID;

			wp_update_user(
				array(
					'ID'           => $this->admin_id,
					'role'         => 'administrator',
					'user_email'   => 'admin@example.org',
					'display_name' => 'admin',
				)
			);
		} else {
			$this->admin_id = self::factory()->user->create(
				array(
					'role'         => 'administrator',
					'user_login'   => 'admin',
					'user_email'   => 'admin@example.org',
					'display_name' => 'admin',
				)
			);
		}

		$this->assertIsInt( $this->admin_id );

		$this->editor_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'ed-the-editor',
				'user_email' => 'ed@example.org',
			)
		);

		wp_set_current_user( $this->admin_id );
	}

	private function run_audit() {
		$result = ( new Admin_Users_Audit() )->execute( array() );

		$this->assertIsArray( $result );

		return $result;
	}

	private function users_by_id( array $result ) {
		$by_id = array();

		foreach ( $result['users'] as $user ) {
			$by_id[ (int) $user['id'] ] = $user;
		}

		return $by_id;
	}

	public function test_only_privileged_roles_are_reported() {
		$result = $this->run_audit();
		$by_id  = $this->users_by_id( $result );

		$this->assertArrayHasKey( $this->admin_id, $by_id );
		$this->assertArrayNotHasKey( $this->editor_id, $by_id );
		$this->assertSame( count( $result['users'] ), $result['total_privileged'] );
		$this->assertContains( 'administrator', $result['roles_checked'] );
		$this->assertNotContains( 'editor', $result['roles_checked'] );
		$this->assertNotContains( 'subscriber', $result['roles_checked'] );
	}

	public function test_the_admin_login_is_flagged_as_weak() {
		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		$this->assertSame( 'admin', $user['login'] );
		$this->assertContains( 'weak_login', $user['flags'] );
		$this->assertContains( 'display_name_equals_login', $user['flags'] );
		$this->assertContains( 'administrator', $user['roles'] );
		$this->assertNotNull( $user['registered'] );
	}

	public function test_every_guessable_login_is_flagged() {
		$ids = array();

		foreach ( Admin_Users_Audit::WEAK_LOGINS as $login ) {
			if ( 'admin' === $login ) {
				$ids[ $login ] = $this->admin_id;
				continue;
			}

			$ids[ $login ] = self::factory()->user->create(
				array(
					'role'       => 'administrator',
					'user_login' => $login,
				)
			);
		}

		// A login that matches the site name is just as guessable as "admin".
		$site_login         = sanitize_title( get_bloginfo( 'name' ) );
		$ids[ $site_login ] = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => $site_login,
			)
		);

		$by_id = $this->users_by_id( $this->run_audit() );

		foreach ( $ids as $login => $id ) {
			$this->assertArrayHasKey( $id, $by_id, $login . ' should be reported' );
			$this->assertContains( 'weak_login', $by_id[ $id ]['flags'], $login . ' should be flagged as weak' );
		}
	}

	public function test_a_strong_login_is_not_flagged() {
		$strong_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'user_login'   => 'a7-maintainer',
				'display_name' => 'Site Maintainer',
			)
		);

		$user = $this->users_by_id( $this->run_audit() )[ $strong_id ];

		$this->assertNotContains( 'weak_login', $user['flags'] );
		$this->assertNotContains( 'display_name_equals_login', $user['flags'] );
	}

	public function test_a_stale_last_login_is_flagged() {
		update_user_meta( $this->admin_id, 'last_login', time() - ( 120 * DAY_IN_SECONDS ) );

		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		$this->assertNotNull( $user['last_login'] );
		$this->assertContains( 'no_recent_login', $user['flags'] );
	}

	public function test_a_recent_last_login_is_not_flagged() {
		update_user_meta( $this->admin_id, 'wp_last_login', gmdate( 'Y-m-d H:i:s' ) );

		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		$this->assertNotNull( $user['last_login'] );
		$this->assertNotContains( 'no_recent_login', $user['flags'] );
	}

	public function test_no_login_tracking_reports_a_null_last_login() {
		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		$this->assertNull( $user['last_login'] );
		$this->assertNotContains( 'no_recent_login', $user['flags'] );
	}

	public function test_summary_counts_admins_and_weak_logins() {
		$result = $this->run_audit();

		$weak   = 0;
		$admins = 0;

		foreach ( $result['users'] as $user ) {
			if ( in_array( 'weak_login', $user['flags'], true ) ) {
				++$weak;
			}

			if ( in_array( 'administrator', $user['roles'], true ) ) {
				++$admins;
			}
		}

		$this->assertSame( $weak, $result['summary']['weak_logins'] );
		$this->assertSame( $admins, $result['summary']['admins'] );
		$this->assertGreaterThanOrEqual( 1, $result['summary']['weak_logins'] );
		$this->assertGreaterThanOrEqual( 1, $result['summary']['admins'] );

		// The Two Factor plugin is not installed in the test suite, so 2FA is unknown.
		$this->assertNull( $result['summary']['without_2fa'] );
	}

	public function test_no_email_or_password_data_leaves_the_ability() {
		$admin  = get_userdata( $this->admin_id );
		$result = $this->run_audit();
		$user   = $this->users_by_id( $result )[ $this->admin_id ];

		$this->assertSame( 'example.org', $user['email_domain'] );
		$this->assertArrayNotHasKey( 'email', $user );
		$this->assertArrayNotHasKey( 'user_email', $user );
		$this->assertArrayNotHasKey( 'user_pass', $user );
		$this->assertArrayNotHasKey( 'password', $user );

		$encoded = wp_json_encode( $result );

		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'admin@example.org', $encoded );
		$this->assertStringNotContainsString( $admin->user_pass, $encoded );

		foreach ( array( 'user_pass', 'password', 'hash', 'uuid', 'user_activation_key' ) as $forbidden ) {
			$this->assertStringNotContainsString( '"' . $forbidden . '"', $encoded );
		}
	}

	public function test_application_passwords_report_counts_but_no_secrets() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			$this->markTestSkipped( 'Application passwords are not available.' );
		}

		// The test install is not served over HTTPS, where core switches the feature off.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$created = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$new = WP_Application_Passwords::create_new_application_password(
				$this->admin_id,
				array( 'name' => 'agent-' . $i )
			);

			$this->assertNotWPError( $new, is_wp_error( $new ) ? $new->get_error_message() : '' );

			$created[] = $new;
		}

		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );

		$this->assertSame( 4, $user['app_passwords']['count'] );
		$this->assertContains( 'many_app_passwords', $user['flags'] );
		$this->assertNull( $user['app_passwords']['last_used'] );

		$encoded = (string) wp_json_encode( $user['app_passwords'] );

		foreach ( $created as $new ) {
			$this->assertStringNotContainsString( $new[0], $encoded );
			$this->assertStringNotContainsString( $new[1]['uuid'], $encoded );
			$this->assertStringNotContainsString( $new[1]['password'], $encoded );
		}
	}

	public function test_a_recently_used_application_password_is_reported_as_a_date() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			$this->markTestSkipped( 'Application passwords are not available.' );
		}

		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$new = WP_Application_Passwords::create_new_application_password(
			$this->admin_id,
			array( 'name' => 'agent-used' )
		);

		$this->assertNotWPError( $new, is_wp_error( $new ) ? $new->get_error_message() : '' );

		// Core only writes `last_used` through its own usage recorder.
		$before   = time();
		$recorded = WP_Application_Passwords::record_application_password_usage( $this->admin_id, $new[1]['uuid'] );

		$this->assertNotWPError( $recorded, is_wp_error( $recorded ) ? $recorded->get_error_message() : '' );

		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );

		$this->assertSame( 1, $user['app_passwords']['count'] );
		$this->assertNotNull( $user['app_passwords']['last_used'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$user['app_passwords']['last_used']
		);
		$this->assertGreaterThanOrEqual( $before, strtotime( $user['app_passwords']['last_used'] ) );
		$this->assertNotContains( 'many_app_passwords', $user['flags'] );
	}

	public function test_the_ability_requires_both_capabilities() {
		$ability = new Admin_Users_Audit();

		$this->assertSame( array( 'list_users', 'manage_options' ), $ability->capability() );
		$this->assertTrue( $ability->annotations()['readonly'] );
		$this->assertSame( 'super-abilities/admin-users-audit', $ability->name() );
		$this->assertTrue( $ability->check_permission( array() ) );

		wp_set_current_user( $this->editor_id );

		$denied = $ability->check_permission( array() );

		$this->assertWPError( $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertSame( 'insufficient_capability', $denied->get_error_data()['reason'] );
	}
}
