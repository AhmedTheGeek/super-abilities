<?php
/**
 * Tests for the access module guard rails.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Access\Guard;
use SuperAbilities\Support\Fingerprint;

class AccessGuardTest extends WP_UnitTestCase {

	/**
	 * Roles this test added and must take away again.
	 *
	 * @var array<int, string>
	 */
	private $added_roles = array();

	public function tear_down() {
		foreach ( $this->added_roles as $role ) {
			remove_role( $role );
		}

		$this->added_roles = array();

		parent::tear_down();

		// The transaction rollback restores the option; the in-memory object needs telling.
		wp_roles()->for_site();
	}

	private function make_role( $slug, $caps ) {
		add_role( $slug, $slug, $caps );

		$this->added_roles[] = $slug;

		return $slug;
	}

	public function test_administrator_is_protected_and_the_list_is_filterable() {
		$this->assertSame( array( 'administrator' ), Guard::protected_roles() );
		$this->assertTrue( Guard::is_protected_role( 'administrator' ) );
		$this->assertFalse( Guard::is_protected_role( 'editor' ) );

		$filter = static function ( $roles ) {
			$roles[] = 'Editor';

			return $roles;
		};

		add_filter( 'super_abilities_protected_roles', $filter );

		$this->assertTrue( Guard::is_protected_role( 'editor' ), 'Slugs from the filter are sanitized.' );

		remove_filter( 'super_abilities_protected_roles', $filter );

		$this->assertFalse( Guard::is_protected_role( 'editor' ) );
	}

	public function test_forbidden_capabilities_cover_the_code_execution_caps() {
		foreach ( array( 'edit_plugins', 'edit_themes', 'edit_files', 'unfiltered_html', 'unfiltered_upload', 'manage_network', 'setup_network', 'delete_site' ) as $cap ) {
			$this->assertTrue( Guard::is_forbidden_capability( $cap ), $cap . ' must never be grantable.' );
			$this->assertTrue( Guard::is_dangerous_capability( $cap ), $cap . ' is also dangerous.' );
		}

		$this->assertFalse( Guard::is_forbidden_capability( 'edit_posts' ) );
		$this->assertFalse( Guard::is_dangerous_capability( 'edit_posts' ) );
		$this->assertTrue( Guard::is_dangerous_capability( 'manage_options' ) );
	}

	public function test_forbidden_capability_list_is_filterable() {
		$filter = static function ( $caps ) {
			$caps[] = 'sa_extra_forbidden';

			return $caps;
		};

		add_filter( 'super_abilities_forbidden_capabilities', $filter );

		$this->assertTrue( Guard::is_forbidden_capability( 'sa_extra_forbidden' ) );
		$this->assertSame( array( 'sa_extra_forbidden' ), Guard::forbidden_in( array( 'edit_posts', 'sa_extra_forbidden' ) ) );

		remove_filter( 'super_abilities_forbidden_capabilities', $filter );

		$this->assertFalse( Guard::is_forbidden_capability( 'sa_extra_forbidden' ) );
	}

	public function test_definition_capabilities_follow_the_installation_type() {
		$expected = is_multisite() ? array( 'manage_options', 'manage_network_users' ) : array( 'manage_options' );

		$this->assertSame( $expected, Guard::definition_capabilities() );
	}

	public function test_role_capabilities_are_sorted_and_split_into_granted_and_denied() {
		$slug = $this->make_role(
			'sa_guard_role',
			array(
				'read'       => true,
				'edit_posts' => true,
				'zzz_cap'    => false,
			)
		);

		$this->assertTrue( Guard::role_exists( $slug ) );
		$this->assertSame( array( 'edit_posts', 'read' ), Guard::granted_capabilities( $slug ) );
		$this->assertSame( array( 'zzz_cap' ), Guard::denied_capabilities( $slug ) );
		$this->assertSame(
			array(
				'edit_posts' => true,
				'read'       => true,
				'zzz_cap'    => false,
			),
			Guard::role_capabilities( $slug )
		);
	}

	public function test_role_fingerprint_is_stable_and_changes_with_the_caps() {
		$slug  = $this->make_role( 'sa_fp_role', array( 'read' => true ) );
		$first = Guard::role_fingerprint( $slug );

		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $first );
		$this->assertSame( $first, Guard::role_fingerprint( $slug ), 'Reading twice gives the same fingerprint.' );
		$this->assertSame( Fingerprint::of_array( array( 'read' => true ) ), $first );

		wp_roles()->get_role( $slug )->add_cap( 'edit_posts' );

		$this->assertNotSame( $first, Guard::role_fingerprint( $slug ) );
	}

	public function test_roles_fingerprint_ignores_order_and_duplicates() {
		$this->assertSame(
			Guard::roles_fingerprint( array( 'editor', 'subscriber' ) ),
			Guard::roles_fingerprint( array( 'subscriber', 'editor', 'subscriber' ) )
		);

		$this->assertNotSame(
			Guard::roles_fingerprint( array( 'editor' ) ),
			Guard::roles_fingerprint( array( 'editor', 'subscriber' ) )
		);
	}

	public function test_normalize_roles_and_sanitize_capabilities_clean_their_input() {
		$this->assertSame( array( 'editor', 'subscriber' ), Guard::normalize_roles( array( 'Subscriber', 'editor', '', 'editor', array( 'nope' ) ) ) );
		$this->assertSame( array( 'edit_posts', 'read' ), Guard::sanitize_capabilities( 'read, edit_posts, read' ) );
		$this->assertSame( array( 'edit_posts', 'read' ), Guard::sanitize_capabilities( array( 'read', 'Edit_Posts' ) ) );
		$this->assertSame( array(), Guard::sanitize_capabilities( null ) );
	}

	public function test_meta_capabilities_needing_an_object_are_recognized() {
		$this->assertTrue( Guard::requires_object( 'edit_post' ) );
		$this->assertTrue( Guard::requires_object( 'edit_user' ) );
		$this->assertFalse( Guard::requires_object( 'edit_posts' ) );
		$this->assertFalse( Guard::requires_object( 'manage_options' ) );
	}

	public function test_check_grantable_refuses_forbidden_caps_before_escalation() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$error = Guard::check_grantable( array( 'edit_posts', 'edit_plugins' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_forbidden_capability', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( array( 'edit_plugins' ), $error->get_error_data()['capabilities'] );

		$this->assertNull( Guard::check_grantable( array( 'edit_posts', 'manage_options' ) ) );
	}

	public function test_check_grantable_refuses_capabilities_the_caller_lacks() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$error = Guard::check_grantable( array( 'edit_posts', 'manage_options' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_capability_escalation', $error->get_error_code() );
		$this->assertSame( array( 'manage_options' ), $error->get_error_data()['capabilities'] );
		$this->assertNull( Guard::check_grantable( array( 'edit_posts' ) ) );
	}

	/**
	 * Core maps `manage_links` and `unfiltered_upload` to do_not_allow for everybody
	 * unless the site is configured for them, so asking current_user_can() alone would
	 * make even an administrator unable to assign the editor role.
	 */
	public function test_caller_has_reads_the_capability_map_not_just_current_user_can() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( current_user_can( 'manage_links' ), 'The link manager is off by default.' );
		$this->assertContains( 'manage_links', Guard::granted_capabilities( 'editor' ) );
		$this->assertTrue( Guard::caller_has( 'manage_links' ), 'It is still part of the administrator account.' );
		$this->assertNull( Guard::check_assignable( array( 'editor', 'administrator' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( Guard::caller_has( 'manage_links' ) );
		$this->assertFalse( Guard::caller_has( 'edit_post' ), 'A meta capability is never assumed.' );
		$this->assertFalse( Guard::caller_has( '' ) );
	}

	public function test_check_assignable_refuses_a_role_stronger_than_the_caller() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$error = Guard::check_assignable( array( 'administrator' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_capability_escalation', $error->get_error_code() );
		$this->assertSame( 'administrator', $error->get_error_data()['role'] );
		$this->assertNull( Guard::check_assignable( array( 'subscriber' ) ) );
	}

	public function test_administrator_loss_guard_refuses_self_demotion_unless_forced() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin );

		$error = Guard::check_administrator_loss( $admin, array( 'administrator' ), array( 'editor' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_self_demotion', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );

		$this->assertNull( Guard::check_administrator_loss( $admin, array( 'administrator' ), array( 'editor' ), true ) );
		$this->assertNull( Guard::check_administrator_loss( $admin, array( 'administrator' ), array( 'administrator', 'editor' ) ) );
	}

	public function test_administrator_loss_guard_refuses_demoting_the_only_administrator() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin );

		foreach ( get_users( array( 'role' => 'administrator' ) ) as $other ) {
			if ( (int) $other->ID !== (int) $admin ) {
				$other->set_role( 'subscriber' );
			}
		}

		$this->assertSame( 1, Guard::administrator_count() );

		$error = Guard::check_administrator_loss( $admin, array( 'administrator' ), array( 'editor' ), true );

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_last_administrator', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	public function test_describe_role_reports_counts_protection_and_danger() {
		$slug = $this->make_role(
			'sa_described',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);

		self::factory()->user->create( array( 'role' => $slug ) );

		$described = Guard::describe_role( $slug );

		$this->assertSame( $slug, $described['slug'] );
		$this->assertSame( array( 'manage_options', 'read' ), $described['capabilities'] );
		$this->assertSame( 2, $described['capability_count'] );
		$this->assertSame( array( 'manage_options' ), $described['dangerous'] );
		$this->assertSame( 1, $described['users'] );
		$this->assertFalse( $described['protected'] );
		$this->assertFalse( $described['is_default'] );
		$this->assertSame( Guard::role_fingerprint( $slug ), $described['fingerprint'] );

		$admin = Guard::describe_role( 'administrator' );

		$this->assertTrue( $admin['protected'] );
		$this->assertContains( 'manage_options', $admin['capabilities'] );
	}

	public function test_resolve_user_accepts_an_id_a_login_or_an_email() {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'sa_guard_user',
				'user_email' => 'sa-guard-user@example.com',
			)
		);

		$this->assertSame( $user_id, Guard::resolve_user( array( 'user_id' => $user_id ) )->ID );
		$this->assertSame( $user_id, Guard::resolve_user( array( 'login' => 'sa_guard_user' ) )->ID );
		$this->assertSame( $user_id, Guard::resolve_user( array( 'email' => 'sa-guard-user@example.com' ) )->ID );
		$this->assertNull( Guard::resolve_user( array( 'login' => 'sa_nobody_at_all' ) ) );
		$this->assertNull( Guard::resolve_user( array() ) );
	}
}
