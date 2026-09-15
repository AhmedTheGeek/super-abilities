<?php
/**
 * Tests for the roles and capabilities abilities.
 *
 * Every successful result is validated against the ability's own output schema,
 * because core validates ability output on every real call.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Access\Access_Audit;
use SuperAbilities\Abilities\Access\Capabilities_Grant;
use SuperAbilities\Abilities\Access\Capabilities_Revoke;
use SuperAbilities\Abilities\Access\Capability_Explain;
use SuperAbilities\Abilities\Access\Role_Create;
use SuperAbilities\Abilities\Access\Role_Delete;
use SuperAbilities\Abilities\Access\Roles_List;
use SuperAbilities\Abilities\Access\User_Roles_Assign;
use SuperAbilities\Abilities\Access\User_Roles_Read;
use SuperAbilities\Access\Guard;
use SuperAbilities\Modules\Access_Module;
use SuperAbilities\Plugin;

class AccessAbilitiesTest extends WP_UnitTestCase {

	const ROLE = 'sa_test_role';

	private $admin_id = 0;

	private $editor_id = 0;

	private $subscriber_id = 0;

	/**
	 * Roles created during a test, removed again in tear_down().
	 *
	 * @var array<int, string>
	 */
	private $custom_roles = array();

	public function set_up() {
		parent::set_up();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		wp_set_current_user( $this->admin_id );

		foreach ( array_merge( $this->custom_roles, array( self::ROLE ) ) as $role ) {
			remove_role( $role );
		}

		$this->custom_roles = array();

		parent::tear_down();

		/*
		 * The transaction rollback puts `wp_user_roles` back, but WP_Roles caches the
		 * role definitions in memory for the whole process, so it has to be reloaded or
		 * a role added here leaks into every later test.
		 */
		wp_roles()->for_site();
	}

	/**
	 * Remembers a role so that tear_down() removes it.
	 *
	 * @param string $slug Role slug.
	 * @return string
	 */
	private function track( $slug ) {
		$this->custom_roles[] = $slug;

		return $slug;
	}

	/**
	 * Runs an ability and asserts the result validates against its output schema.
	 *
	 * @param Abstract_Ability     $ability Ability instance.
	 * @param array<string, mixed> $input   Input to pass.
	 * @return array<string, mixed>
	 */
	private function run_ability( Abstract_Ability $ability, array $input = array() ) {
		$result = $ability->execute( $input );

		$this->assertNotWPError( $result, $ability->name() . ' returned an error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->assertIsArray( $result );

		return $this->assert_matches_schema( $ability, $result );
	}

	private function assert_matches_schema( Abstract_Ability $ability, array $result ) {
		$schema = $ability->output_schema();

		$this->assertNotEmpty( $schema, $ability->name() . ' declares no output schema.' );

		$valid = rest_validate_value_from_schema( $result, $schema, 'output' );

		if ( is_wp_error( $valid ) ) {
			$this->fail( $ability->name() . ' output does not match its schema: ' . $valid->get_error_message() );
		}

		$this->assertTrue( $valid );

		return $result;
	}

	/**
	 * Creates a role through the ability, so the guards are exercised.
	 *
	 * @param string             $slug Role slug.
	 * @param array<int, string> $caps Capabilities to grant.
	 * @return array<string, mixed>
	 */
	private function create_role( $slug, array $caps ) {
		$result = $this->run_ability(
			new Role_Create(),
			array(
				'role'         => $slug,
				'display_name' => 'SA ' . $slug,
				'capabilities' => $caps,
			)
		);

		$this->track( $slug );

		return $result;
	}

	private function only_administrator( $keep_id ) {
		foreach ( get_users( array( 'role' => 'administrator' ) ) as $user ) {
			if ( (int) $user->ID !== (int) $keep_id ) {
				$user->set_role( 'subscriber' );
			}
		}
	}

	public function test_module_declares_nine_abilities_and_is_off_by_default() {
		$module = new Access_Module( Plugin::instance() );

		$this->assertSame( 'access', $module->id() );
		$this->assertSame( 'Roles and capabilities', $module->label() );
		$this->assertFalse( $module->default_enabled() );
		$this->assertSame( 'high', $module->risk() );
		$this->assertFalse( Plugin::instance()->options()->is_module_enabled( 'access' ) );
		$this->assertCount( 9, $module->abilities() );

		$slugs = array();

		foreach ( $module->abilities() as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), $class_name . ' is missing.' );

			$ability = new $class_name();

			$this->assertInstanceOf( Abstract_Ability::class, $ability );
			$this->assertSame( 'access', $ability->module() );
			$this->assertSame( 'super-abilities-access', $ability->category() );
			$this->assertSame( '0.2.0', $ability->since() );
			$this->assertNotEmpty( $ability->capability() );
			$this->assertNotEmpty( $ability->input_schema() );
			$this->assertNotEmpty( $ability->output_schema() );
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $ability->slug() );

			$slugs[] = $ability->slug();
		}

		$this->assertSame(
			array(
				'roles-list',
				'capability-explain',
				'role-create',
				'role-delete',
				'capabilities-grant',
				'capabilities-revoke',
				'user-roles-read',
				'user-roles-assign',
				'access-audit',
			),
			$slugs
		);
	}

	public function test_module_is_registered_once_it_is_enabled() {
		$options = Plugin::instance()->options();
		$modules = (array) $options->get( 'modules' );

		$options->set( 'modules', array_merge( $modules, array( 'access' => true ) ) );

		$enabled = Plugin::instance()->modules()->enabled();

		$this->assertArrayHasKey( 'access', $enabled );
		$this->assertInstanceOf( Access_Module::class, $enabled['access'] );

		$options->set( 'modules', $modules );

		$this->assertArrayNotHasKey( 'access', Plugin::instance()->modules()->enabled() );
	}

	public function test_registration_arguments_are_well_formed() {
		$module = new Access_Module( Plugin::instance() );
		$args   = $module->category_args();

		$this->assertSame( 'Roles and capabilities', $args['label'] );
		$this->assertNotEmpty( $args['description'] );
		$this->assertSame( 'access', $args['meta']['super_abilities']['module'] );
		$this->assertSame( 'high', $args['meta']['super_abilities']['risk'] );

		$ability = new Capabilities_Grant();
		$args    = $ability->to_args();

		$this->assertSame( 'super-abilities/capabilities-grant', $ability->name() );
		$this->assertSame( 'super-abilities-access', $args['category'] );
		$this->assertSame( array( $ability, 'run' ), $args['execute_callback'] );
		$this->assertSame( array( $ability, 'check_permission' ), $args['permission_callback'] );
		$this->assertTrue( $args['meta']['mcp']['public'] );
		$this->assertSame( 'access', $args['meta']['super_abilities']['module'] );
		$this->assertSame( '0.2.0', $args['meta']['super_abilities']['since'] );
		$this->assertSame( Guard::definition_capabilities(), $args['meta']['super_abilities']['capability'] );
		$this->assertArrayHasKey( 'input_schema', $args );
		$this->assertArrayHasKey( 'output_schema', $args );
		$this->assertSame( 'Adds capabilities to a role and reports which ones were added and which the role already had.', $ability->summary() );
	}

	public function test_abilities_declare_honest_annotations() {
		$this->assertTrue( ( new Roles_List() )->annotations()['readonly'] );
		$this->assertTrue( ( new Capability_Explain() )->annotations()['readonly'] );
		$this->assertTrue( ( new User_Roles_Read() )->annotations()['readonly'] );
		$this->assertTrue( ( new Access_Audit() )->annotations()['readonly'] );

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			( new Role_Create() )->annotations()
		);

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			( new Role_Delete() )->annotations()
		);

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			( new Capabilities_Grant() )->annotations()
		);

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			( new Capabilities_Revoke() )->annotations()
		);

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			( new User_Roles_Assign() )->annotations()
		);
	}

	public function test_roles_list_reports_every_role_with_a_fingerprint() {
		$result = $this->run_ability( new Roles_List() );

		$this->assertSame( count( wp_roles()->roles ), $result['total'] );
		$this->assertSame( array( 'administrator' ), $result['protected_roles'] );
		$this->assertContains( 'edit_plugins', $result['forbidden_capabilities'] );
		$this->assertSame( is_multisite(), $result['multisite'] );

		$by_slug = array_column( $result['roles'], null, 'slug' );

		$this->assertArrayHasKey( 'administrator', $by_slug );
		$this->assertArrayHasKey( 'editor', $by_slug );
		$this->assertArrayHasKey( 'subscriber', $by_slug );

		$admin = $by_slug['administrator'];

		$this->assertTrue( $admin['protected'] );
		$this->assertContains( 'manage_options', $admin['capabilities'] );
		$this->assertContains( 'manage_options', $admin['dangerous'] );
		$this->assertSame( count( $admin['capabilities'] ), $admin['capability_count'] );
		$this->assertGreaterThanOrEqual( 1, $admin['users'] );
		$this->assertSame( Guard::role_fingerprint( 'administrator' ), $admin['fingerprint'] );

		$subscriber = $by_slug['subscriber'];

		$this->assertFalse( $subscriber['protected'] );
		$this->assertSame( array( 'level_0', 'read' ), $subscriber['capabilities'] );
		$this->assertSame( array(), $subscriber['dangerous'] );
		$this->assertTrue( $subscriber['is_default'], 'subscriber is the default role in the test suite.' );
	}

	public function test_roles_list_can_describe_one_role_and_404s_on_a_missing_one() {
		$result = $this->run_ability( new Roles_List(), array( 'role' => 'Editor' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'editor', $result['roles'][0]['slug'] );
		$this->assertSame( 'Editor', $result['roles'][0]['name'] );

		$missing = ( new Roles_List() )->execute( array( 'role' => 'no_such_role' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 'super_abilities_not_found', $missing->get_error_code() );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	public function test_roles_list_reports_denied_capabilities() {
		add_role(
			$this->track( self::ROLE ),
			'Denier',
			array(
				'read'       => true,
				'edit_posts' => false,
			)
		);

		$result = $this->run_ability( new Roles_List(), array( 'role' => self::ROLE ) );

		$this->assertSame( array( 'read' ), $result['roles'][0]['capabilities'] );
		$this->assertSame( array( 'edit_posts' ), $result['roles'][0]['denied_capabilities'] );
		$this->assertSame( 0, $result['roles'][0]['users'] );
	}

	public function test_create_grant_revoke_and_delete_with_reassignment() {
		$created = $this->create_role( self::ROLE, array( 'read', 'edit_posts' ) );

		$this->assertTrue( $created['created'] );
		$this->assertFalse( $created['dry_run'] );
		$this->assertSame( self::ROLE, $created['role']['slug'] );
		$this->assertSame( 'SA ' . self::ROLE, $created['role']['name'] );
		$this->assertSame( array( 'edit_posts', 'read' ), $created['role']['capabilities'] );
		$this->assertSame( 0, $created['role']['users'] );
		$this->assertTrue( Guard::role_exists( self::ROLE ) );

		$granted = $this->run_ability(
			new Capabilities_Grant(),
			array(
				'role'                 => self::ROLE,
				'capabilities'         => array( 'edit_posts', 'upload_files', 'manage_categories' ),
				'expected_fingerprint' => $created['role']['fingerprint'],
			)
		);

		$this->assertSame( array( 'manage_categories', 'upload_files' ), $granted['added'] );
		$this->assertSame( array( 'edit_posts' ), $granted['already_present'] );
		$this->assertSame( Guard::role_fingerprint( self::ROLE ), $granted['fingerprint'] );
		$this->assertContains( 'upload_files', Guard::granted_capabilities( self::ROLE ) );

		// Granting the same set again adds nothing.
		$again = $this->run_ability(
			new Capabilities_Grant(),
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'upload_files' ),
			)
		);

		$this->assertSame( array(), $again['added'] );
		$this->assertSame( array( 'upload_files' ), $again['already_present'] );
		$this->assertSame( $granted['fingerprint'], $again['fingerprint'] );

		$revoked = $this->run_ability(
			new Capabilities_Revoke(),
			array(
				'role'                 => self::ROLE,
				'capabilities'         => array( 'upload_files', 'level_10' ),
				'expected_fingerprint' => $again['fingerprint'],
			)
		);

		$this->assertSame( array( 'upload_files' ), $revoked['removed'] );
		$this->assertSame( array( 'level_10' ), $revoked['not_present'] );
		$this->assertNotContains( 'upload_files', Guard::granted_capabilities( self::ROLE ) );

		// Move the subscriber into the role, then delete it.
		$assigned = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( self::ROLE ),
			)
		);

		$this->assertSame( array( 'subscriber' ), $assigned['before'] );
		$this->assertSame( array( self::ROLE ), $assigned['after'] );

		$deleted = $this->run_ability(
			new Role_Delete(),
			array(
				'role'        => self::ROLE,
				'reassign_to' => 'subscriber',
			)
		);

		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 1, $deleted['users_reassigned'] );
		$this->assertSame( 'subscriber', $deleted['reassign_to'] );
		$this->assertFalse( Guard::role_exists( self::ROLE ) );
		$this->assertSame( array( 'subscriber' ), array_values( get_userdata( $this->subscriber_id )->roles ) );
	}

	public function test_role_create_refuses_a_slug_that_exists() {
		$result = ( new Role_Create() )->execute(
			array(
				'role'         => 'editor',
				'display_name' => 'Editor again',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_role_exists', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_role_create_clones_an_existing_role_without_its_forbidden_caps() {
		$this->assertContains( 'unfiltered_html', Guard::granted_capabilities( 'editor' ), 'The editor role really does grant it.' );

		$result = $this->run_ability(
			new Role_Create(),
			array(
				'role'         => self::ROLE,
				'display_name' => 'Cloned editor',
				'clone_from'   => 'editor',
				'capabilities' => array( 'manage_categories' ),
			)
		);

		$this->track( self::ROLE );

		$this->assertTrue( $result['created'] );
		$this->assertContains( 'edit_others_posts', $result['role']['capabilities'], 'Editor capabilities came across.' );
		$this->assertContains( 'manage_categories', $result['role']['capabilities'] );
		$this->assertNotContains( 'unfiltered_html', $result['role']['capabilities'], 'A forbidden capability is never cloned.' );
		$this->assertSame( array( 'unfiltered_html' ), $result['excluded_capabilities'] );

		// Asking for it directly is still an error rather than a silent omission.
		$named = ( new Role_Create() )->execute(
			array(
				'role'         => 'sa_named_forbidden',
				'display_name' => 'Named',
				'clone_from'   => 'editor',
				'capabilities' => array( 'unfiltered_html' ),
			)
		);

		$this->assertWPError( $named );
		$this->assertSame( 'super_abilities_forbidden_capability', $named->get_error_code() );
		$this->assertFalse( Guard::role_exists( 'sa_named_forbidden' ) );

		$missing = ( new Role_Create() )->execute(
			array(
				'role'         => 'sa_other_role',
				'display_name' => 'Nope',
				'clone_from'   => 'no_such_role',
			)
		);

		$this->assertWPError( $missing );
		$this->assertSame( 'super_abilities_not_found', $missing->get_error_code() );
	}

	public function test_role_create_dry_run_changes_nothing() {
		$result = $this->run_ability(
			new Role_Create(),
			array(
				'role'         => self::ROLE,
				'display_name' => 'Dry run role',
				'capabilities' => array( 'read', 'edit_posts' ),
				'dry_run'      => true,
			)
		);

		$this->assertFalse( $result['created'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( array( 'edit_posts', 'read' ), $result['role']['capabilities'] );
		$this->assertFalse( Guard::role_exists( self::ROLE ), 'A dry run must not add the role.' );
	}

	public function test_forbidden_capabilities_are_refused_even_for_an_administrator() {
		$this->create_role( self::ROLE, array( 'read' ) );

		$result = ( new Capabilities_Grant() )->execute(
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'edit_posts', 'edit_plugins' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_forbidden_capability', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array( 'edit_plugins' ), $result->get_error_data()['capabilities'] );
		$this->assertSame( array( 'read' ), Guard::granted_capabilities( self::ROLE ), 'Nothing was written.' );

		$created = ( new Role_Create() )->execute(
			array(
				'role'         => 'sa_forbidden_role',
				'display_name' => 'Forbidden',
				'capabilities' => array( 'unfiltered_html' ),
			)
		);

		$this->assertWPError( $created );
		$this->assertSame( 'super_abilities_forbidden_capability', $created->get_error_code() );
		$this->assertFalse( Guard::role_exists( 'sa_forbidden_role' ) );
	}

	public function test_an_editor_with_manage_options_cannot_grant_what_it_lacks() {
		// An administrator builds a role that can manage options but not install plugins.
		$this->create_role( self::ROLE, array( 'read', 'manage_options', 'list_users' ) );

		$editor = get_userdata( $this->editor_id );
		$editor->add_role( self::ROLE );

		wp_set_current_user( $this->editor_id );

		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'install_plugins' ) );

		$ability = new Capabilities_Grant();

		// The capability gate lets them through: the escalation guard is what stops them.
		$this->assertTrue( $ability->check_permission( array( 'role' => self::ROLE ) ) );

		$result = $ability->execute(
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'install_plugins' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_capability_escalation', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array( 'install_plugins' ), $result->get_error_data()['capabilities'] );
		$this->assertNotContains( 'install_plugins', Guard::granted_capabilities( self::ROLE ) );

		// A capability they do hold goes through.
		$allowed = $this->run_ability(
			$ability,
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'edit_posts' ),
			)
		);

		$this->assertSame( array( 'edit_posts' ), $allowed['added'] );
	}

	public function test_an_editor_cannot_assign_the_administrator_role() {
		$this->create_role( self::ROLE, array( 'read', 'manage_options', 'promote_users', 'list_users' ) );

		$editor = get_userdata( $this->editor_id );
		$editor->add_role( self::ROLE );

		wp_set_current_user( $this->editor_id );

		$result = ( new User_Roles_Assign() )->execute(
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'administrator' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_capability_escalation', $result->get_error_code() );
		$this->assertSame( 'administrator', $result->get_error_data()['role'] );
		$this->assertSame( array( 'subscriber' ), array_values( get_userdata( $this->subscriber_id )->roles ) );
	}

	public function test_protected_role_needs_force_to_gain_a_capability_and_never_loses_one() {
		$grant = new Capabilities_Grant();

		$refused = $grant->execute(
			array(
				'role'         => 'administrator',
				'capabilities' => array( 'read' ),
			)
		);

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_protected_role', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertTrue( $refused->get_error_data()['protected'] );

		$forced = $this->run_ability(
			$grant,
			array(
				'role'         => 'administrator',
				'capabilities' => array( 'read' ),
				'force'        => true,
			)
		);

		$this->assertSame( array(), $forced['added'] );
		$this->assertSame( array( 'read' ), $forced['already_present'] );

		$revoke = ( new Capabilities_Revoke() )->execute(
			array(
				'role'         => 'administrator',
				'capabilities' => array( 'manage_options' ),
			)
		);

		$this->assertWPError( $revoke );
		$this->assertSame( 'super_abilities_protected_role', $revoke->get_error_code() );
		$this->assertTrue( current_user_can( 'manage_options' ), 'The administrator role is intact.' );

		// Force does not help either.
		$forced_revoke = ( new Capabilities_Revoke() )->execute(
			array(
				'role'         => 'administrator',
				'capabilities' => array( 'manage_options' ),
				'force'        => true,
			)
		);

		$this->assertWPError( $forced_revoke );
		$this->assertSame( 'super_abilities_protected_role', $forced_revoke->get_error_code() );
	}

	public function test_protected_role_is_never_deleted() {
		$result = ( new Role_Delete() )->execute(
			array(
				'role'  => 'administrator',
				'force' => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_protected_role', $result->get_error_code() );
		$this->assertTrue( Guard::role_exists( 'administrator' ) );
	}

	public function test_protected_roles_filter_is_honoured_by_the_abilities() {
		$this->create_role( self::ROLE, array( 'read' ) );

		$filter = static function ( $roles ) {
			$roles[] = AccessAbilitiesTest::ROLE;

			return $roles;
		};

		add_filter( 'super_abilities_protected_roles', $filter );

		$result = ( new Role_Delete() )->execute( array( 'role' => self::ROLE ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_protected_role', $result->get_error_code() );

		remove_filter( 'super_abilities_protected_roles', $filter );

		$this->assertTrue( $this->run_ability( new Role_Delete(), array( 'role' => self::ROLE ) )['deleted'] );
	}

	public function test_default_role_is_only_deleted_with_force() {
		$this->create_role( self::ROLE, array( 'read' ) );

		$previous = get_option( 'default_role' );
		update_option( 'default_role', self::ROLE );

		$refused = ( new Role_Delete() )->execute( array( 'role' => self::ROLE ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_default_role', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );

		$forced = $this->run_ability(
			new Role_Delete(),
			array(
				'role'  => self::ROLE,
				'force' => true,
			)
		);

		$this->assertTrue( $forced['deleted'] );
		$this->assertNull( $forced['reassign_to'] );

		update_option( 'default_role', $previous );
	}

	public function test_role_delete_needs_a_reassignment_target_when_users_hold_the_role() {
		$this->create_role( self::ROLE, array( 'read' ) );

		get_userdata( $this->subscriber_id )->add_role( self::ROLE );

		$refused = ( new Role_Delete() )->execute( array( 'role' => self::ROLE ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_invalid_input', $refused->get_error_code() );
		$this->assertSame( 1, $refused->get_error_data()['users'] );
		$this->assertTrue( Guard::role_exists( self::ROLE ) );

		$same = ( new Role_Delete() )->execute(
			array(
				'role'        => self::ROLE,
				'reassign_to' => self::ROLE,
			)
		);

		$this->assertWPError( $same );
		$this->assertSame( 'super_abilities_invalid_input', $same->get_error_code() );

		$unknown = ( new Role_Delete() )->execute(
			array(
				'role'        => self::ROLE,
				'reassign_to' => 'no_such_role',
			)
		);

		$this->assertWPError( $unknown );
		$this->assertSame( 'super_abilities_not_found', $unknown->get_error_code() );

		// A user with a second role keeps it.
		$deleted = $this->run_ability(
			new Role_Delete(),
			array(
				'role'        => self::ROLE,
				'reassign_to' => 'author',
			)
		);

		$this->assertSame( 1, $deleted['users_reassigned'] );
		$this->assertSame( array( 'subscriber', 'author' ), array_values( get_userdata( $this->subscriber_id )->roles ) );
	}

	public function test_role_delete_dry_run_changes_nothing() {
		$this->create_role( self::ROLE, array( 'read' ) );

		get_userdata( $this->subscriber_id )->add_role( self::ROLE );

		$result = $this->run_ability(
			new Role_Delete(),
			array(
				'role'        => self::ROLE,
				'reassign_to' => 'author',
				'dry_run'     => true,
			)
		);

		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['users_reassigned'] );
		$this->assertTrue( Guard::role_exists( self::ROLE ) );
		$this->assertContains( self::ROLE, get_userdata( $this->subscriber_id )->roles );
	}

	public function test_grant_and_revoke_dry_runs_change_nothing() {
		$this->create_role( self::ROLE, array( 'read' ) );

		$grant = $this->run_ability(
			new Capabilities_Grant(),
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'edit_posts' ),
				'dry_run'      => true,
			)
		);

		$this->assertTrue( $grant['dry_run'] );
		$this->assertSame( array( 'edit_posts' ), $grant['added'] );
		$this->assertSame( array( 'read' ), Guard::granted_capabilities( self::ROLE ) );

		$revoke = $this->run_ability(
			new Capabilities_Revoke(),
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'read' ),
				'dry_run'      => true,
			)
		);

		$this->assertTrue( $revoke['dry_run'] );
		$this->assertSame( array( 'read' ), $revoke['removed'] );
		$this->assertNotEmpty( $revoke['warnings'], 'Losing read is worth a warning.' );
		$this->assertSame( array( 'read' ), Guard::granted_capabilities( self::ROLE ) );
	}

	public function test_a_stale_fingerprint_is_rejected_with_409() {
		$created     = $this->create_role( self::ROLE, array( 'read' ) );
		$fingerprint = $created['role']['fingerprint'];

		$this->run_ability(
			new Capabilities_Grant(),
			array(
				'role'         => self::ROLE,
				'capabilities' => array( 'edit_posts' ),
			)
		);

		foreach ( array( new Capabilities_Grant(), new Capabilities_Revoke(), new Role_Delete() ) as $ability ) {
			$result = $ability->execute(
				array(
					'role'                 => self::ROLE,
					'capabilities'         => array( 'upload_files' ),
					'expected_fingerprint' => $fingerprint,
				)
			);

			$this->assertWPError( $result, $ability->name() . ' must reject a stale fingerprint.' );
			$this->assertSame( 'super_abilities_stale_fingerprint', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( Guard::role_fingerprint( self::ROLE ), $result->get_error_data()['current_fingerprint'] );
		}

		$this->assertTrue( Guard::role_exists( self::ROLE ) );
		$this->assertContains( 'edit_posts', Guard::granted_capabilities( self::ROLE ) );
	}

	public function test_user_roles_read_by_id_login_and_email() {
		$result = $this->run_ability( new User_Roles_Read(), array( 'user_id' => $this->editor_id ) );

		$editor = get_userdata( $this->editor_id );

		$this->assertSame( $this->editor_id, $result['id'] );
		$this->assertSame( $editor->user_login, $result['login'] );
		$this->assertSame( array( 'editor' ), $result['roles'] );
		$this->assertSame( array( 'Editor' ), $result['role_names'] );
		$this->assertContains( 'edit_others_posts', $result['capabilities'] );
		$this->assertSame( count( $result['capabilities'] ), $result['capability_count'] );
		$this->assertSame( array(), $result['direct_capabilities'] );
		$this->assertSame( $editor->user_email, $result['email'], 'An administrator can see the address.' );
		$this->assertSame( Guard::roles_fingerprint( array( 'editor' ) ), $result['fingerprint'] );

		$by_login = $this->run_ability( new User_Roles_Read(), array( 'login' => $editor->user_login ) );
		$by_email = $this->run_ability( new User_Roles_Read(), array( 'email' => $editor->user_email ) );

		$this->assertSame( $result['id'], $by_login['id'] );
		$this->assertSame( $result['id'], $by_email['id'] );

		$missing = ( new User_Roles_Read() )->execute( array( 'login' => 'nobody_at_all' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 'super_abilities_not_found', $missing->get_error_code() );

		$empty = ( new User_Roles_Read() )->execute( array() );

		$this->assertWPError( $empty );
		$this->assertSame( 'super_abilities_invalid_input', $empty->get_error_code() );
	}

	public function test_user_roles_read_redacts_the_email_without_edit_users() {
		$this->create_role( self::ROLE, array( 'read', 'list_users' ) );

		$reader = self::factory()->user->create( array( 'role' => self::ROLE ) );

		wp_set_current_user( $reader );

		$this->assertTrue( current_user_can( 'list_users' ) );
		$this->assertFalse( current_user_can( 'edit_users' ) );

		$ability = new User_Roles_Read();

		$this->assertTrue( $ability->check_permission( array( 'user_id' => $this->editor_id ) ) );

		$result = $this->run_ability( $ability, array( 'user_id' => $this->editor_id ) );

		$this->assertNull( $result['email'] );
		$this->assertSame( array( 'editor' ), $result['roles'] );
	}

	public function test_user_roles_read_reports_capabilities_set_on_the_account() {
		$user = get_userdata( $this->subscriber_id );
		$user->add_cap( 'sa_direct_cap' );

		$result = $this->run_ability( new User_Roles_Read(), array( 'user_id' => $this->subscriber_id ) );

		$this->assertSame( array( 'sa_direct_cap' ), $result['direct_capabilities'] );
		$this->assertContains( 'sa_direct_cap', $result['capabilities'] );
		$this->assertNotContains( 'subscriber', $result['capabilities'], 'The role slug is not a capability.' );

		$user->remove_cap( 'sa_direct_cap' );
	}

	public function test_user_roles_assign_replaces_adds_and_removes() {
		$replaced = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'author' ),
			)
		);

		$this->assertSame( 'replace', $replaced['mode'] );
		$this->assertTrue( $replaced['changed'] );
		$this->assertSame( array( 'subscriber' ), $replaced['before'] );
		$this->assertSame( array( 'author' ), $replaced['after'] );
		$this->assertSame( array( 'author' ), $replaced['added'] );
		$this->assertSame( array( 'subscriber' ), $replaced['removed'] );
		$this->assertSame( array( 'author' ), array_values( get_userdata( $this->subscriber_id )->roles ) );

		$added = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id'              => $this->subscriber_id,
				'roles'                => array( 'contributor' ),
				'mode'                 => 'add',
				'expected_fingerprint' => $replaced['fingerprint'],
			)
		);

		$this->assertSame( array( 'author', 'contributor' ), $added['after'] );
		$this->assertSame( array( 'contributor' ), $added['added'] );
		$this->assertSame( array(), $added['removed'] );

		// Adding the same role again is a no-op.
		$again = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'contributor' ),
				'mode'    => 'add',
			)
		);

		$this->assertFalse( $again['changed'] );
		$this->assertSame( array( 'author', 'contributor' ), $again['after'] );

		$removed = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'author' ),
				'mode'    => 'remove',
			)
		);

		$this->assertSame( array( 'contributor' ), $removed['after'] );
		$this->assertSame( array( 'author' ), $removed['removed'] );
		$this->assertSame( array( 'contributor' ), array_values( get_userdata( $this->subscriber_id )->roles ) );
	}

	public function test_user_roles_assign_dry_run_changes_nothing() {
		$result = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'editor' ),
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( array( 'editor' ), $result['after'] );
		$this->assertSame( array( 'subscriber' ), array_values( get_userdata( $this->subscriber_id )->roles ) );
	}

	public function test_user_roles_assign_rejects_unknown_roles_and_missing_users() {
		$unknown = ( new User_Roles_Assign() )->execute(
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'editor', 'no_such_role' ),
			)
		);

		$this->assertWPError( $unknown );
		$this->assertSame( 'super_abilities_invalid_input', $unknown->get_error_code() );
		$this->assertSame( array( 'no_such_role' ), $unknown->get_error_data()['roles'] );
		$this->assertSame( array( 'subscriber' ), array_values( get_userdata( $this->subscriber_id )->roles ) );

		$missing = ( new User_Roles_Assign() )->execute(
			array(
				'user_id' => 999999,
				'roles'   => array( 'editor' ),
			)
		);

		$this->assertWPError( $missing );
		$this->assertSame( 'super_abilities_not_found', $missing->get_error_code() );

		$denied = ( new User_Roles_Assign() )->permission(
			array(
				'user_id' => 999999,
				'roles'   => array( 'editor' ),
			)
		);

		$this->assertWPError( $denied );
		$this->assertSame( 'super_abilities_not_found', $denied->get_error_code() );
	}

	public function test_self_demotion_is_refused_without_force() {
		self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = ( new User_Roles_Assign() )->execute(
			array(
				'user_id' => $this->admin_id,
				'roles'   => array( 'editor' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_self_demotion', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertContains( 'administrator', get_userdata( $this->admin_id )->roles );

		$forced = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->admin_id,
				'roles'   => array( 'editor' ),
				'force'   => true,
			)
		);

		$this->assertSame( array( 'editor' ), $forced['after'] );
		$this->assertSame( array( 'administrator' ), $forced['removed'] );
	}

	public function test_the_last_administrator_cannot_be_demoted_even_with_force() {
		$this->only_administrator( $this->admin_id );

		$this->assertSame( 1, Guard::administrator_count() );

		$result = ( new User_Roles_Assign() )->execute(
			array(
				'user_id' => $this->admin_id,
				'roles'   => array( 'editor' ),
				'force'   => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_last_administrator', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertContains( 'administrator', get_userdata( $this->admin_id )->roles );
	}

	public function test_assigning_the_administrator_role_warns_the_caller() {
		$result = $this->run_ability(
			new User_Roles_Assign(),
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'administrator' ),
			)
		);

		$this->assertSame( array( 'administrator' ), $result['after'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_capability_explain_describes_a_core_capability() {
		$result = $this->run_ability(
			new Capability_Explain(),
			array(
				'capability' => 'edit_posts',
				'user_id'    => $this->editor_id,
			)
		);

		$this->assertSame( 'edit_posts', $result['capability'] );
		$this->assertTrue( $result['known'] );
		$this->assertFalse( $result['forbidden'] );
		$this->assertFalse( $result['dangerous'] );
		$this->assertFalse( $result['requires_object'] );

		$roles = wp_list_pluck( $result['granted_by'], 'role' );

		$this->assertContains( 'editor', $roles );
		$this->assertContains( 'administrator', $roles );
		$this->assertNotContains( 'subscriber', $roles );
		$this->assertSame( array( 'edit_posts' ), $result['maps_to'] );

		$this->assertSame( $this->editor_id, $result['user']['id'] );
		$this->assertTrue( $result['user']['can'] );
		$this->assertSame( array( 'editor' ), $result['user']['via_roles'] );
		$this->assertFalse( $result['user']['granted_directly'] );
	}

	public function test_capability_explain_flags_dangerous_and_forbidden_capabilities() {
		$forbidden = $this->run_ability( new Capability_Explain(), array( 'capability' => 'edit_plugins' ) );

		$this->assertTrue( $forbidden['forbidden'] );
		$this->assertTrue( $forbidden['dangerous'] );
		$this->assertNull( $forbidden['user'] );
		$this->assertSame( array(), $forbidden['maps_to'] );

		$dangerous = $this->run_ability( new Capability_Explain(), array( 'capability' => 'manage_options' ) );

		$this->assertFalse( $dangerous['forbidden'] );
		$this->assertTrue( $dangerous['dangerous'] );
	}

	public function test_capability_explain_handles_a_garbage_capability() {
		$result = $this->run_ability(
			new Capability_Explain(),
			array(
				'capability' => 'flibbertigibbet',
				'user_id'    => $this->admin_id,
			)
		);

		$this->assertSame( 'flibbertigibbet', $result['capability'] );
		$this->assertFalse( $result['known'] );
		$this->assertSame( array(), $result['granted_by'] );
		$this->assertSame( array(), $result['denied_by'] );
		$this->assertSame( array( 'flibbertigibbet' ), $result['maps_to'] );
		$this->assertFalse( $result['user']['can'], 'Not even an administrator has a capability nobody defined.' );
		$this->assertSame( array(), $result['user']['via_roles'] );

		$empty = ( new Capability_Explain() )->execute( array( 'capability' => '!!!' ) );

		$this->assertWPError( $empty );
		$this->assertSame( 'super_abilities_invalid_input', $empty->get_error_code() );
	}

	public function test_capability_explain_reports_meta_capabilities_and_denials() {
		add_role(
			$this->track( self::ROLE ),
			'Denier',
			array(
				'read'       => true,
				'edit_posts' => false,
			)
		);

		$result = $this->run_ability(
			new Capability_Explain(),
			array(
				'capability' => 'edit_posts',
				'user_id'    => $this->editor_id,
			)
		);

		$this->assertContains( self::ROLE, $result['denied_by'] );

		$meta = $this->run_ability(
			new Capability_Explain(),
			array(
				'capability' => 'edit_post',
				'user_id'    => $this->editor_id,
			)
		);

		$this->assertTrue( $meta['requires_object'] );
		$this->assertNull( $meta['user']['can'] );
		$this->assertSame( array(), $meta['maps_to'] );
	}

	public function test_capability_explain_404s_on_a_missing_user() {
		$result = ( new Capability_Explain() )->execute(
			array(
				'capability' => 'edit_posts',
				'user_id'    => 999999,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_not_found', $result->get_error_code() );
	}

	public function test_access_audit_summarises_the_site() {
		$this->create_role( self::ROLE, array( 'read', 'manage_options' ) );

		get_userdata( $this->editor_id )->add_role( self::ROLE );

		$result = $this->run_ability( new Access_Audit() );

		$this->assertGreaterThanOrEqual( 1, $result['administrators']['count'] );
		$this->assertSame( count( $result['administrators']['users'] ), $result['administrators']['count'] );
		$this->assertSame( count( wp_roles()->roles ), $result['roles_total'] );
		$this->assertSame( is_multisite(), $result['multisite'] );
		$this->assertFalse( $result['truncated'] );

		$admin_logins = wp_list_pluck( $result['administrators']['users'], 'login' );

		$this->assertContains( get_userdata( $this->admin_id )->user_login, $admin_logins );

		$dangerous = wp_list_pluck( $result['roles_with_dangerous_caps'], 'capabilities', 'role' );

		$this->assertArrayHasKey( 'administrator', $dangerous );
		$this->assertArrayHasKey( self::ROLE, $dangerous );
		$this->assertSame( array( 'manage_options' ), $dangerous[ self::ROLE ] );

		$multi = wp_list_pluck( $result['users_with_multiple_roles'], 'roles', 'id' );

		$this->assertArrayHasKey( $this->editor_id, $multi );
		$this->assertSame( array( 'editor', self::ROLE ), $multi[ $this->editor_id ] );

		$this->assertTrue( $result['application_passwords']['checked'] );
		$this->assertIsInt( $result['application_passwords']['total'] );
		$this->assertNotEmpty( $result['recommendations'] );

		$encoded = wp_json_encode( $result );

		$this->assertStringNotContainsString( '@', $encoded, 'No email address appears in the audit.' );
	}

	public function test_access_audit_reports_a_role_granting_a_forbidden_capability() {
		// A role built outside this plugin can hold anything.
		add_role(
			$this->track( self::ROLE ),
			'Dangerous',
			array(
				'read'         => true,
				'edit_plugins' => true,
			)
		);

		$holder = self::factory()->user->create( array( 'role' => self::ROLE ) );

		$result = $this->run_ability( new Access_Audit() );

		$roles = wp_list_pluck( $result['roles_with_forbidden_caps'], 'capabilities', 'role' );

		$this->assertArrayHasKey( self::ROLE, $roles );
		$this->assertSame( array( 'edit_plugins' ), $roles[ self::ROLE ] );

		$users = wp_list_pluck( $result['users_with_forbidden_caps'], 'capabilities', 'id' );

		$this->assertArrayHasKey( $holder, $users );
		$this->assertSame( array( 'edit_plugins' ), $users[ $holder ] );

		$joined = implode( ' ', $result['recommendations'] );

		$this->assertStringContainsString( self::ROLE, $joined );
	}

	public function test_access_audit_hides_application_password_counts_without_edit_users() {
		$this->create_role( self::ROLE, array( 'read', 'list_users' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => self::ROLE ) ) );

		$result = $this->run_ability( new Access_Audit() );

		$this->assertFalse( $result['application_passwords']['checked'] );
		$this->assertNull( $result['application_passwords']['total'] );
		$this->assertNull( $result['application_passwords']['admins_with_passwords'] );
	}

	public function test_a_subscriber_is_denied_every_ability() {
		wp_set_current_user( $this->subscriber_id );

		foreach ( ( new Access_Module( Plugin::instance() ) )->abilities() as $class_name ) {
			$ability = new $class_name();

			$result = $ability->check_permission( array( 'user_id' => $this->subscriber_id ) );

			$this->assertWPError( $result, $ability->name() . ' must refuse a subscriber.' );
			$this->assertSame( 'super_abilities_forbidden', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( 'insufficient_capability', $result->get_error_data()['reason'] );
		}
	}

	public function test_an_anonymous_caller_is_denied_every_ability() {
		wp_set_current_user( 0 );

		foreach ( ( new Access_Module( Plugin::instance() ) )->abilities() as $class_name ) {
			$ability = new $class_name();

			$result = $ability->check_permission( array() );

			$this->assertWPError( $result, $ability->name() . ' must refuse an anonymous caller.' );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( 'not_logged_in', $result->get_error_data()['reason'] );
		}
	}

	public function test_an_editor_cannot_edit_another_users_roles() {
		wp_set_current_user( $this->editor_id );

		$result = ( new User_Roles_Assign() )->check_permission(
			array(
				'user_id' => $this->subscriber_id,
				'roles'   => array( 'author' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'promote_users', $result->get_error_data()['required_capability'] );
	}

	public function test_every_read_ability_output_matches_its_schema() {
		$this->create_role( self::ROLE, array( 'read', 'edit_posts' ) );

		$this->run_ability( new Roles_List() );
		$this->run_ability( new Roles_List(), array( 'role' => self::ROLE ) );
		$this->run_ability( new Capability_Explain(), array( 'capability' => 'read' ) );
		$this->run_ability( new User_Roles_Read(), array( 'user_id' => $this->admin_id ) );
		$this->run_ability( new Access_Audit() );
	}

	public function test_schema_validation_can_actually_fail() {
		$ability = new Roles_List();
		$result  = $ability->execute( array() );

		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->output_schema(), 'output' ) );

		$wrong          = $result;
		$wrong['total'] = array( 'nope' );

		$this->assertWPError( rest_validate_value_from_schema( $wrong, $ability->output_schema(), 'output' ) );

		$extra             = $result;
		$extra['surprise'] = true;

		$this->assertWPError( rest_validate_value_from_schema( $extra, $ability->output_schema(), 'output' ) );
	}
}
