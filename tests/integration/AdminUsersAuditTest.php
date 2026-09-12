<?php
/**
 * Tests for the privileged users audit ability.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Security\Admin_Users_Audit;

class AdminUsersAuditTest extends WP_UnitTestCase {

	/**
	 * Id of the administrator whose login is literally `admin`.
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

		$this->admin_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'user_login'   => 'admin',
				'user_email'   => 'admin@example.org',
				'display_name' => 'admin',
			)
		);

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

		$this->assertGreaterThanOrEqual( 1, $result['summary']['admins'] );
		$this->assertSame( 1, $result['summary']['weak_logins'] );

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

		$created = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$new = WP_Application_Passwords::create_new_application_password(
				$this->admin_id,
				array( 'name' => 'agent-' . $i )
			);

			$this->assertNotWPError( $new );

			$created[] = $new;
		}

		$user = $this->users_by_id( $this->run_audit() )[ $this->admin_id ];

		$this->assertSame( 4, $user['app_passwords']['count'] );
		$this->assertContains( 'many_app_passwords', $user['flags'] );

		$encoded = wp_json_encode( $user['app_passwords'] );

		foreach ( $created as $new ) {
			$this->assertStringNotContainsString( $new[0], (string) $encoded );
			$this->assertStringNotContainsString( $new[1]['uuid'], (string) $encoded );
		}
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
	}
}
