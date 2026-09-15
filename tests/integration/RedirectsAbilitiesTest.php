<?php
/**
 * Tests for the redirect abilities, end to end against the real table.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Catalog_Ability;
use SuperAbilities\Abilities\Redirects\Redirect_Create;
use SuperAbilities\Abilities\Redirects\Redirect_Delete;
use SuperAbilities\Abilities\Redirects\Redirect_Read;
use SuperAbilities\Abilities\Redirects\Redirect_Test;
use SuperAbilities\Abilities\Redirects\Redirect_Update;
use SuperAbilities\Abilities\Redirects\Redirects_Import;
use SuperAbilities\Abilities\Redirects\Redirects_List;
use SuperAbilities\Abilities\Redirects\Redirects_Stats;
use SuperAbilities\Modules\Redirects_Module;
use SuperAbilities\Plugin;
use SuperAbilities\Redirects\Runtime;
use SuperAbilities\Redirects\Store;

class RedirectsAbilitiesTest extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();

		// The module owns its table and the plugin schema version does not change when a
		// module ships later, so create it the same way `boot()` does.
		Store::install_table();
	}

	public function set_up() {
		parent::set_up();

		if ( ! Store::table_exists() ) {
			Store::install_table();
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Store::flush_cache();
		Store::delete_all();
	}

	public function tear_down() {
		Store::delete_all();

		parent::tear_down();
	}

	/**
	 * Runs an ability and asserts the result validates against its output schema.
	 */
	private function run_ability( Abstract_Ability $ability, array $input = array() ) {
		$result = $ability->execute( $input );

		$this->assertNotWPError( $result, $ability->name() . ' returned an error.' );
		$this->assertIsArray( $result );

		$schema = $ability->output_schema();

		$this->assertNotEmpty( $schema, $ability->name() . ' declares no output schema.' );

		$valid = rest_validate_value_from_schema( $result, $schema, 'output' );

		if ( is_wp_error( $valid ) ) {
			$this->fail( $ability->name() . ' output does not match its schema: ' . $valid->get_error_message() );
		}

		return $result;
	}

	/**
	 * Creates a rule through the ability and returns the stored row.
	 */
	private function create( $source, $target, array $extra = array() ) {
		$result = $this->run_ability(
			new Redirect_Create(),
			array_merge(
				array(
					'source' => $source,
					'target' => $target,
				),
				$extra
			)
		);

		$this->assertTrue( $result['created'] );

		return $result['redirect'];
	}

	public function test_module_shape() {
		$module = new Redirects_Module( Plugin::instance() );

		$this->assertSame( 'redirects', $module->id() );
		$this->assertSame( 'Redirects', $module->label() );
		$this->assertSame( 'high', $module->risk() );
		$this->assertFalse( $module->default_enabled() );
		$this->assertCount( 8, $module->abilities() );

		$slugs = array();

		foreach ( $module->abilities() as $class_name ) {
			$ability = new $class_name();

			$this->assertInstanceOf( Abstract_Ability::class, $ability );
			$this->assertSame( 'redirects', $ability->module() );
			$this->assertSame( '0.2.0', $ability->since() );
			$this->assertSame( array( 'manage_options' ), $ability->capability() );
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $ability->slug() );

			$slugs[] = $ability->slug();
		}

		$this->assertSame(
			array(
				'redirects-list',
				'redirect-read',
				'redirect-create',
				'redirect-update',
				'redirect-delete',
				'redirect-test',
				'redirects-import',
				'redirects-stats',
			),
			$slugs
		);
	}

	public function test_table_exists_after_boot() {
		global $wpdb;

		delete_option( Store::TABLE_VERSION_OPTION );

		$module = new Redirects_Module( Plugin::instance() );
		$module->boot();

		$table = Store::table();

		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
		);
		$this->assertSame( Store::TABLE_VERSION, get_option( Store::TABLE_VERSION_OPTION ) );
		$this->assertNotFalse( has_action( 'template_redirect' ) );
	}

	public function test_install_registers_the_schema_hooks() {
		$module = new Redirects_Module( Plugin::instance() );

		remove_filter( 'super_abilities_table_schema', array( $module, 'table_schema' ) );
		remove_action( 'super_abilities_upgraded', array( $module, 'on_upgraded' ) );

		$module->install();

		$this->assertNotFalse( has_filter( 'super_abilities_table_schema', array( $module, 'table_schema' ) ) );
		$this->assertNotFalse( has_action( 'super_abilities_upgraded', array( $module, 'on_upgraded' ) ) );

		$queries = apply_filters( 'super_abilities_table_schema', array() );

		$this->assertNotEmpty( $queries );
		$this->assertStringContainsString( Store::table(), implode( "\n", $queries ) );

		remove_filter( 'super_abilities_table_schema', array( $module, 'table_schema' ) );
		remove_action( 'super_abilities_upgraded', array( $module, 'on_upgraded' ) );
	}

	public function test_catalog_lists_the_module_and_its_abilities() {
		$result = ( new Catalog_Ability() )->execute( array() );

		$this->assertIsArray( $result );

		$module = null;

		foreach ( $result['modules'] as $entry ) {
			if ( 'redirects' === $entry['id'] ) {
				$module = $entry;
			}
		}

		$this->assertNotNull( $module, 'The catalog does not list the redirects module.' );
		$this->assertFalse( $module['enabled'] );
		$this->assertSame( 'high', $module['risk'] );
		$this->assertCount( 8, $module['abilities'] );
		$this->assertContains( 'super-abilities/redirect-create', wp_list_pluck( $module['abilities'], 'name' ) );
	}

	public function test_table_schema_filter_appends_the_statement() {
		$module = new Redirects_Module( Plugin::instance() );

		$queries = $module->table_schema( array( 'CREATE TABLE a (id int);' ) );

		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( Store::table(), $queries[1] );
		$this->assertStringContainsString( 'UNIQUE KEY source_match', $queries[1] );
	}

	public function test_create_normalizes_and_stores() {
		$rule = $this->create( '/Old-Page/', '/new-page' );

		$this->assertSame( '/old-page', $rule['source'] );
		$this->assertSame( '/new-page', $rule['target'] );
		$this->assertSame( 301, $rule['status'] );
		$this->assertTrue( $rule['enabled'] );
		$this->assertFalse( $rule['match_query'] );
		$this->assertSame( 0, $rule['hits'] );
		$this->assertNull( $rule['last_hit'] );
		$this->assertSame( get_current_user_id(), $rule['created_by'] );
		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $rule['fingerprint'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rule['created'] );
	}

	public function test_create_notes_the_object_for_the_audit_log() {
		$noted = array();

		add_action(
			'super_abilities_note_object',
			static function ( $name, $type, $id ) use ( &$noted ) {
				$noted[] = array( $type, (int) $id );
			},
			10,
			3
		);

		$rule = $this->create( '/old', '/new' );

		$this->assertContains( array( 'redirect', (int) $rule['id'] ), $noted );
	}

	public function test_create_refuses_a_duplicate_source() {
		$rule = $this->create( '/old', '/new' );

		$error = ( new Redirect_Create() )->execute(
			array(
				'source' => '/old',
				'target' => '/other',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_already_exists', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( (int) $rule['id'], $error->get_error_data()['existing_id'] );
	}

	public function test_create_refuses_a_reserved_source() {
		$error = ( new Redirect_Create() )->execute(
			array(
				'source' => '/wp-admin/options.php',
				'target' => '/settings',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_reserved_path', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	public function test_create_refuses_a_source_that_is_published_content() {
		$this->set_permalink_structure( '/%postname%/' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'pricing',
				'post_status' => 'publish',
			)
		);

		$path = (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH );

		$error = ( new Redirect_Create() )->execute(
			array(
				'source' => $path,
				'target' => '/plans',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_source_is_content', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( $page_id, $error->get_error_data()['object_id'] );

		$created = $this->run_ability(
			new Redirect_Create(),
			array(
				'source'                 => $path,
				'target'                 => '/plans',
				'allow_existing_content' => true,
			)
		);

		$this->assertTrue( $created['created'] );
	}

	public function test_create_gates_external_targets() {
		$error = ( new Redirect_Create() )->execute(
			array(
				'source' => '/go',
				'target' => 'https://8.8.8.8/landing',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_external_target', $error->get_error_code() );

		$created = $this->run_ability(
			new Redirect_Create(),
			array(
				'source'         => '/go',
				'target'         => 'https://8.8.8.8/landing',
				'allow_external' => true,
			)
		);

		$this->assertTrue( $created['preview']['external'] );
	}

	public function test_create_dry_run_writes_nothing() {
		$result = $this->run_ability(
			new Redirect_Create(),
			array(
				'source'  => '/old',
				'target'  => '/new',
				'dry_run' => true,
			)
		);

		$this->assertFalse( $result['created'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( '/old', $result['preview']['source'] );
		$this->assertArrayNotHasKey( 'redirect', $result );
		$this->assertCount( 0, Store::all() );
	}

	public function test_create_reports_a_chain_as_a_warning() {
		$this->create( '/b', '/c' );

		$result = $this->run_ability(
			new Redirect_Create(),
			array(
				'source' => '/a',
				'target' => '/b',
			)
		);

		$this->assertSame( 2, $result['chain_length'] );
		$this->assertSame( '/c', $result['resolves_to'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_create_refuses_a_cycle_through_existing_rules() {
		$this->create( '/b', '/c' );
		$this->create( '/c', '/a' );

		$error = ( new Redirect_Create() )->execute(
			array(
				'source' => '/a',
				'target' => '/b',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_loop_detected', $error->get_error_code() );
	}

	public function test_create_410_needs_no_target() {
		$rule = $this->create( '/gone', '', array( 'status' => 410 ) );

		$this->assertSame( 410, $rule['status'] );
		$this->assertSame( '', $rule['target'] );
	}

	public function test_read_by_id_and_by_source() {
		$rule = $this->create( '/old', '/new', array( 'note' => 'Migration' ) );

		$by_id = $this->run_ability( new Redirect_Read(), array( 'id' => (int) $rule['id'] ) );

		$this->assertSame( '/old', $by_id['redirect']['source'] );
		$this->assertSame( 'Migration', $by_id['redirect']['note'] );
		$this->assertSame( 1, $by_id['chain_length'] );
		$this->assertSame( '/new', $by_id['resolves_to'] );
		$this->assertFalse( $by_id['loop'] );

		$by_source = $this->run_ability( new Redirect_Read(), array( 'source' => '/Old/' ) );

		$this->assertSame( (int) $rule['id'], (int) $by_source['redirect']['id'] );
	}

	public function test_read_reports_a_chain() {
		$this->create( '/a', '/b' );
		$this->create( '/b', '/c' );

		$result = $this->run_ability( new Redirect_Read(), array( 'source' => '/a' ) );

		$this->assertSame( 2, $result['chain_length'] );
		$this->assertSame( '/c', $result['resolves_to'] );
		$this->assertSame( '/a', $result['chain'][0]['source'] );
		$this->assertSame( '/b', $result['chain'][1]['source'] );
	}

	public function test_read_requires_an_identifier_and_404s() {
		$this->assertWPError( ( new Redirect_Read() )->execute( array() ) );

		$missing = ( new Redirect_Read() )->execute( array( 'id' => 99999 ) );

		$this->assertWPError( $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	public function test_list_filters_and_paginates() {
		$this->create( '/one', '/new-one' );
		$this->create( '/two', '/new-two', array( 'enabled' => false ) );
		$this->create( '/three', '', array( 'status' => 410 ) );

		$all = $this->run_ability( new Redirects_List() );

		$this->assertSame( 3, $all['total'] );
		$this->assertSame( 1, $all['total_pages'] );
		$this->assertCount( 3, $all['redirects'] );

		$enabled = $this->run_ability( new Redirects_List(), array( 'enabled' => true ) );

		$this->assertSame( 2, $enabled['total'] );

		$gone = $this->run_ability( new Redirects_List(), array( 'status' => 410 ) );

		$this->assertSame( 1, $gone['total'] );
		$this->assertSame( '/three', $gone['redirects'][0]['source'] );

		$search = $this->run_ability( new Redirects_List(), array( 'search' => 'new-two' ) );

		$this->assertSame( 1, $search['total'] );

		$paged = $this->run_ability(
			new Redirects_List(),
			array(
				'per_page' => 2,
				'orderby'  => 'source',
				'order'    => 'asc',
			)
		);

		$this->assertSame( 2, $paged['total_pages'] );
		$this->assertSame( '/one', $paged['redirects'][0]['source'] );
	}

	public function test_update_changes_fields_and_reports_them() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability(
			new Redirect_Update(),
			array(
				'id'     => (int) $rule['id'],
				'target' => '/newer',
				'status' => 302,
				'note'   => 'Second pass',
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( array( 'target', 'status', 'note' ), $result['changed'] );
		$this->assertSame( '/newer', $result['redirect']['target'] );
		$this->assertSame( 302, $result['redirect']['status'] );
		$this->assertNotSame( $rule['fingerprint'], $result['redirect']['fingerprint'] );
	}

	public function test_update_rejects_a_stale_fingerprint() {
		$rule = $this->create( '/old', '/new' );

		$this->run_ability(
			new Redirect_Update(),
			array(
				'id'     => (int) $rule['id'],
				'target' => '/newer',
			)
		);

		$error = ( new Redirect_Update() )->execute(
			array(
				'id'                   => (int) $rule['id'],
				'target'               => '/newest',
				'expected_fingerprint' => $rule['fingerprint'],
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_stale_fingerprint', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	public function test_update_accepts_a_current_fingerprint() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability(
			new Redirect_Update(),
			array(
				'id'                   => (int) $rule['id'],
				'enabled'              => false,
				'expected_fingerprint' => $rule['fingerprint'],
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertFalse( $result['redirect']['enabled'] );
	}

	public function test_update_is_idempotent() {
		$rule = $this->create( '/old', '/new' );

		$input = array(
			'id'     => (int) $rule['id'],
			'target' => '/newer',
		);

		$first  = $this->run_ability( new Redirect_Update(), $input );
		$second = $this->run_ability( new Redirect_Update(), $input );

		$this->assertSame( array( 'target' ), $first['changed'] );
		$this->assertSame( array(), $second['changed'] );
		$this->assertSame( '/newer', $second['redirect']['target'] );
	}

	public function test_update_to_410_clears_the_target() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability(
			new Redirect_Update(),
			array(
				'id'     => (int) $rule['id'],
				'status' => 410,
			)
		);

		$this->assertSame( '', $result['redirect']['target'] );
		$this->assertSame( 410, $result['redirect']['status'] );
	}

	public function test_update_dry_run_writes_nothing() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability(
			new Redirect_Update(),
			array(
				'id'      => (int) $rule['id'],
				'target'  => '/newer',
				'dry_run' => true,
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( array( 'target' ), $result['changed'] );
		$this->assertSame( '/new', Store::get( (int) $rule['id'] )['target'] );
	}

	public function test_update_refuses_a_source_owned_by_another_rule() {
		$this->create( '/one', '/new-one' );
		$two = $this->create( '/two', '/new-two' );

		$error = ( new Redirect_Update() )->execute(
			array(
				'id'     => (int) $two['id'],
				'source' => '/one',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_already_exists', $error->get_error_code() );
	}

	public function test_update_allows_writing_the_same_source_back() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability(
			new Redirect_Update(),
			array(
				'id'     => (int) $rule['id'],
				'source' => '/old',
				'note'   => 'Unchanged source',
			)
		);

		$this->assertTrue( $result['updated'] );
	}

	public function test_delete_removes_one_rule() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability( new Redirect_Delete(), array( 'id' => (int) $rule['id'] ) );

		$this->assertSame( 1, $result['deleted'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertNull( Store::get( (int) $rule['id'] ) );

		$again = $this->run_ability( new Redirect_Delete(), array( 'id' => (int) $rule['id'] ) );

		$this->assertSame( 0, $again['deleted'] );
		$this->assertSame( array( (int) $rule['id'] ), $again['missing'] );
	}

	public function test_delete_removes_a_batch_and_rejects_a_fingerprint_with_many_ids() {
		$one = $this->create( '/one', '/new-one' );
		$two = $this->create( '/two', '/new-two' );

		$error = ( new Redirect_Delete() )->execute(
			array(
				'ids'                  => array( (int) $one['id'], (int) $two['id'] ),
				'expected_fingerprint' => $one['fingerprint'],
			)
		);

		$this->assertWPError( $error );

		$result = $this->run_ability(
			new Redirect_Delete(),
			array( 'ids' => array( (int) $one['id'], (int) $two['id'] ) )
		);

		$this->assertSame( 2, $result['deleted'] );
		$this->assertCount( 0, Store::all() );
	}

	public function test_delete_rejects_a_stale_fingerprint() {
		$rule = $this->create( '/old', '/new' );

		$error = ( new Redirect_Delete() )->execute(
			array(
				'id'                   => (int) $rule['id'],
				'expected_fingerprint' => 'fp1:0000000000000000dead',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_stale_fingerprint', $error->get_error_code() );
		$this->assertNotNull( Store::get( (int) $rule['id'] ) );
	}

	public function test_delete_dry_run_writes_nothing() {
		$rule = $this->create( '/old', '/new' );

		$result = $this->run_ability(
			new Redirect_Delete(),
			array(
				'id'      => (int) $rule['id'],
				'dry_run' => true,
			)
		);

		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( array( (int) $rule['id'] ), $result['ids'] );
		$this->assertNotNull( Store::get( (int) $rule['id'] ) );
	}

	public function test_delete_needs_an_id() {
		$this->assertWPError( ( new Redirect_Delete() )->execute( array() ) );
	}

	public function test_test_ability_follows_a_chain_without_side_effects() {
		$first = $this->create( '/a', '/b' );
		$this->create( '/b', '/c' );

		$result = $this->run_ability( new Redirect_Test(), array( 'path' => '/A/' ) );

		$this->assertTrue( $result['matched'] );
		$this->assertTrue( $result['would_redirect'] );
		$this->assertSame( 301, $result['status'] );
		$this->assertSame( 2, $result['hops'] );
		$this->assertSame( '/c', $result['final_target'] );
		$this->assertFalse( $result['loop'] );
		$this->assertSame( (int) $first['id'], (int) $result['redirect']['id'] );
		$this->assertNull( $result['reserved'] );
		$this->assertFalse( $result['would_serve']['known'] );

		$this->assertSame( 0, Store::get( (int) $first['id'] )['hits'] );
	}

	public function test_test_ability_reports_no_match_and_what_would_be_served() {
		$this->set_permalink_structure( '/%postname%/' );

		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'hello-there',
				'post_status' => 'publish',
			)
		);

		$path = (string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );

		$result = $this->run_ability( new Redirect_Test(), array( 'path' => $path ) );

		$this->assertFalse( $result['matched'] );
		$this->assertFalse( $result['would_redirect'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertTrue( $result['would_serve']['known'] );
		$this->assertSame( $post_id, $result['would_serve']['id'] );
		$this->assertSame( 'post', $result['would_serve']['type'] );
		$this->assertSame( 'publish', $result['would_serve']['status'] );
	}

	public function test_test_ability_honours_match_query() {
		$this->create(
			'/old?ref=news',
			'/with-ref',
			array( 'match_query' => true )
		);

		$hit = $this->run_ability(
			new Redirect_Test(),
			array(
				'path'  => '/old',
				'query' => 'ref=news',
			)
		);

		$this->assertTrue( $hit['matched'] );
		$this->assertSame( '/with-ref', $hit['final_target'] );

		$miss = $this->run_ability( new Redirect_Test(), array( 'path' => '/old' ) );

		$this->assertFalse( $miss['matched'] );
	}

	public function test_test_ability_flags_a_reserved_path() {
		$result = $this->run_ability( new Redirect_Test(), array( 'path' => '/wp-admin/edit.php' ) );

		$this->assertSame( '/wp-admin', $result['reserved'] );
		$this->assertFalse( $result['matched'] );
	}

	public function test_import_is_all_or_nothing() {
		$result = $this->run_ability(
			new Redirects_Import(),
			array(
				'rules' => array(
					array(
						'source' => '/one',
						'target' => '/new-one',
					),
					array(
						'source' => '/wp-admin',
						'target' => '/new-two',
					),
					array(
						'source' => '/three',
						'target' => '/new-three',
					),
				),
			)
		);

		$this->assertFalse( $result['written'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 1, $result['errors'][0]['index'] );
		$this->assertSame( 'super_abilities_reserved_path', $result['errors'][0]['code'] );
		$this->assertCount( 0, Store::all() );
	}

	public function test_import_creates_every_rule() {
		$result = $this->run_ability(
			new Redirects_Import(),
			array(
				'rules' => array(
					array(
						'source' => '/one',
						'target' => '/new-one',
						'note'   => 'From the old CMS',
					),
					array(
						'source' => '/two',
						'target' => '/new-two',
						'status' => 302,
					),
					array(
						'source' => '/gone',
						'status' => 410,
					),
				),
			)
		);

		$this->assertTrue( $result['written'] );
		$this->assertSame( 3, $result['created'] );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertCount( 3, $result['ids'] );
		$this->assertCount( 3, Store::all() );
	}

	public function test_import_skips_or_updates_conflicts() {
		$existing = $this->create( '/one', '/new-one' );

		$skipped = $this->run_ability(
			new Redirects_Import(),
			array(
				'rules' => array(
					array(
						'source' => '/one',
						'target' => '/somewhere-else',
					),
				),
			)
		);

		$this->assertSame( 1, $skipped['skipped'] );
		$this->assertSame( 0, $skipped['created'] );
		$this->assertSame( '/new-one', Store::get( (int) $existing['id'] )['target'] );

		$updated = $this->run_ability(
			new Redirects_Import(),
			array(
				'on_conflict' => 'update',
				'rules'       => array(
					array(
						'source' => '/one',
						'target' => '/somewhere-else',
					),
				),
			)
		);

		$this->assertSame( 1, $updated['updated'] );
		$this->assertSame( '/somewhere-else', Store::get( (int) $existing['id'] )['target'] );
	}

	public function test_import_rejects_a_duplicate_inside_the_batch() {
		$result = $this->run_ability(
			new Redirects_Import(),
			array(
				'rules' => array(
					array(
						'source' => '/one',
						'target' => '/new-one',
					),
					array(
						'source' => '/One/',
						'target' => '/new-two',
					),
				),
			)
		);

		$this->assertFalse( $result['written'] );
		$this->assertSame( 'duplicate_in_batch', $result['errors'][0]['code'] );
		$this->assertCount( 0, Store::all() );
	}

	public function test_import_dry_run_writes_nothing() {
		$result = $this->run_ability(
			new Redirects_Import(),
			array(
				'dry_run' => true,
				'rules'   => array(
					array(
						'source' => '/one',
						'target' => '/new-one',
					),
				),
			)
		);

		$this->assertFalse( $result['written'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertCount( 0, Store::all() );
	}

	public function test_import_detects_a_loop_across_the_batch() {
		$result = $this->run_ability(
			new Redirects_Import(),
			array(
				'rules' => array(
					array(
						'source' => '/a',
						'target' => '/b',
					),
					array(
						'source' => '/b',
						'target' => '/a',
					),
				),
			)
		);

		$this->assertFalse( $result['written'] );
		$this->assertSame( 'super_abilities_loop_detected', $result['errors'][0]['code'] );
	}

	public function test_stats_summarises_the_table() {
		$busy = $this->create( '/busy', '/new-busy' );
		$this->create( '/quiet', '/new-quiet' );
		$this->create( '/gone', '', array( 'status' => 410 ) );
		$this->create( '/off', '/new-off', array( 'enabled' => false ) );

		Store::record_hit( (int) $busy['id'] );
		Store::record_hit( (int) $busy['id'] );

		$result = $this->run_ability( new Redirects_Stats() );

		$this->assertSame( 4, $result['totals']['rules'] );
		$this->assertSame( 3, $result['totals']['enabled'] );
		$this->assertSame( 1, $result['totals']['disabled'] );
		$this->assertSame( 3, $result['totals']['never_hit'] );
		$this->assertSame( 2, $result['totals']['total_hits'] );
		$this->assertSame( 4, $result['totals']['created_last_30_days'] );

		$by_status = wp_list_pluck( $result['by_status'], 'count', 'status' );

		$this->assertSame( 3, $by_status[301] );
		$this->assertSame( 1, $by_status[410] );

		$this->assertCount( 1, $result['top_hits'] );
		$this->assertSame( '/busy', $result['top_hits'][0]['source'] );
		$this->assertSame( 2, $result['top_hits'][0]['hits'] );
		$this->assertCount( 3, $result['never_hit'] );
	}

	public function test_runtime_decision_and_hit_counter() {
		$rule = $this->create( '/old', '/new' );

		$decision = Runtime::decide( '/old', '' );

		$this->assertIsArray( $decision );
		$this->assertSame( (int) $rule['id'], $decision['rule_id'] );
		$this->assertSame( '/new', $decision['target'] );
		$this->assertSame( 301, $decision['status'] );
		$this->assertTrue( $decision['safe'] );

		Store::record_hit( (int) $rule['id'] );

		$after = Store::get( (int) $rule['id'] );

		$this->assertSame( 1, $after['hits'] );
		$this->assertNotNull( $after['last_hit'] );
	}

	public function test_runtime_ignores_disabled_rules() {
		$rule = $this->create( '/old', '/new' );

		$this->run_ability(
			new Redirect_Update(),
			array(
				'id'      => (int) $rule['id'],
				'enabled' => false,
			)
		);

		$this->assertNull( Runtime::decide( '/old', '' ) );
	}

	public function test_a_subscriber_is_denied_every_ability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( $this->every_ability() as $ability ) {
			$denied = $ability->check_permission( array() );

			$this->assertWPError( $denied, $ability->name() . ' allowed a subscriber.' );
			$this->assertSame( 'super_abilities_forbidden', $denied->get_error_code() );
			$this->assertSame( 403, $denied->get_error_data()['status'] );
			$this->assertSame( 'insufficient_capability', $denied->get_error_data()['reason'] );
		}
	}

	public function test_an_anonymous_caller_is_denied_every_ability() {
		wp_set_current_user( 0 );

		foreach ( $this->every_ability() as $ability ) {
			$denied = $ability->check_permission( array() );

			$this->assertWPError( $denied, $ability->name() . ' allowed an anonymous caller.' );
			$this->assertSame( 'not_logged_in', $denied->get_error_data()['reason'] );
		}
	}

	public function test_an_administrator_is_allowed_every_ability() {
		foreach ( $this->every_ability() as $ability ) {
			$this->assertTrue( $ability->check_permission( array() ), $ability->name() . ' refused an administrator.' );
		}
	}

	public function test_annotations_are_honest() {
		$expected = array(
			'redirects-list'   => array( true, false, true ),
			'redirect-read'    => array( true, false, true ),
			'redirect-create'  => array( false, false, false ),
			'redirect-update'  => array( false, false, true ),
			'redirect-delete'  => array( false, true, true ),
			'redirect-test'    => array( true, false, true ),
			'redirects-import' => array( false, false, false ),
			'redirects-stats'  => array( true, false, true ),
		);

		foreach ( $this->every_ability() as $ability ) {
			$annotations = $ability->annotations();
			$want        = $expected[ $ability->slug() ];

			$this->assertSame( $want[0], $annotations['readonly'], $ability->slug() . ' readonly' );
			$this->assertSame( $want[1], $annotations['destructive'], $ability->slug() . ' destructive' );
			$this->assertSame( $want[2], $annotations['idempotent'], $ability->slug() . ' idempotent' );
		}
	}

	public function test_input_schemas_are_closed_and_described() {
		foreach ( $this->every_ability() as $ability ) {
			$schema = $ability->input_schema();

			$this->assertSame( 'object', $schema['type'] );
			$this->assertFalse( $schema['additionalProperties'], $ability->slug() . ' accepts extra properties.' );

			foreach ( $schema['properties'] as $name => $property ) {
				$this->assertArrayHasKey( 'description', $property, $ability->slug() . ' property ' . $name . ' has no description.' );
			}
		}
	}

	/**
	 * One instance of every ability in the module.
	 */
	private function every_ability() {
		return array(
			new Redirects_List(),
			new Redirect_Read(),
			new Redirect_Create(),
			new Redirect_Update(),
			new Redirect_Delete(),
			new Redirect_Test(),
			new Redirects_Import(),
			new Redirects_Stats(),
		);
	}
}
