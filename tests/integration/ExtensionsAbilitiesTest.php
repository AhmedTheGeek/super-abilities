<?php
/**
 * Tests for the extensions module: install, activate, update, roll back and delete.
 *
 * Everything here is offline. `pre_http_request` answers the WordPress.org API, the
 * package download and the smoke test loopbacks, and the package itself is a ZIP built
 * in the temp directory. Files installed into WP_PLUGIN_DIR and restore points written
 * into the uploads folder are removed in tear_down().
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Extensions\Extension_Activate;
use SuperAbilities\Abilities\Extensions\Extension_Deactivate;
use SuperAbilities\Abilities\Extensions\Extension_Delete;
use SuperAbilities\Abilities\Extensions\Extension_Install;
use SuperAbilities\Abilities\Extensions\Extension_Preflight;
use SuperAbilities\Abilities\Extensions\Extension_Rollback;
use SuperAbilities\Abilities\Extensions\Extension_Update;
use SuperAbilities\Abilities\Extensions\Extensions_List;
use SuperAbilities\Abilities\Extensions\Restore_Point_Delete;
use SuperAbilities\Abilities\Extensions\Restore_Points_List;
use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Modules\Extensions_Module;
use SuperAbilities\Plugin;

class ExtensionsAbilitiesTest extends WP_UnitTestCase {

	const SLUG = 'sa-test-plugin';

	const FILE = 'sa-test-plugin/sa-test-plugin.php';

	const DOWNLOAD = 'https://example.org/sa-packages/sa-test-plugin.zip';

	/**
	 * Absolute path of the ZIP the HTTP stub serves.
	 */
	private $zip = '';

	/**
	 * Scratch directory for the ZIPs.
	 */
	private $tmp_dir = '';

	/**
	 * Status code the smoke test loopbacks answer with.
	 */
	private $smoke_code = 200;

	/**
	 * Body the stubbed WordPress.org API answers with.
	 */
	private $api = array();

	/**
	 * Ability names this test registered with core and must unregister again.
	 */
	private $registered = array();

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$options = Plugin::instance()->options();
		$options->set(
			array(
				'modules'                  => array_merge( (array) $options->get( 'modules' ), array( 'extensions' => true ) ),
				'extensions_allow_zip_url' => false,
				'extensions_zip_hosts'     => array(),
			)
		);

		$this->tmp_dir = get_temp_dir() . 'sa-extensions-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->tmp_dir );

		$this->api        = $this->api_body( '1.0.0' );
		$this->smoke_code = 200;

		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
	}

	public function tear_down() {
		$this->remove_test_plugin();

		foreach ( $this->registered as $name ) {
			wp_unregister_ability( $name );
		}

		$this->registered = array();

		Restore_Point::delete_all();

		if ( '' !== $this->tmp_dir ) {
			$this->remove_dir( $this->tmp_dir );
		}

		parent::tear_down();
	}

	/**
	 * Answers every outbound request this test may make.
	 */
	public function http( $preempt, $args, $url ) {
		$url = (string) $url;

		if ( false !== strpos( $url, self::DOWNLOAD ) ) {
			$size = '' === $this->zip || ! file_exists( $this->zip ) ? 0 : (int) filesize( $this->zip );

			if ( isset( $args['method'] ) && 'HEAD' === strtoupper( (string) $args['method'] ) ) {
				return $this->response( 200, '', array( 'content-length' => (string) $size ) );
			}

			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) && '' !== $this->zip ) {
				copy( $this->zip, (string) $args['filename'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for a test fixture in the temp directory.
			}

			return $this->response(
				200,
				'',
				array(
					'content-type'   => 'application/zip',
					'content-length' => (string) $size,
				)
			);
		}

		if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
			return $this->response(
				200,
				(string) wp_json_encode(
					array(
						'plugins'      => array(),
						'translations' => array(),
						'no_update'    => array(),
					)
				)
			);
		}

		if ( false !== strpos( $url, 'api.wordpress.org/themes/update-check' ) ) {
			return $this->response(
				200,
				(string) wp_json_encode(
					array(
						'themes'       => array(),
						'translations' => array(),
						'no_update'    => array(),
					)
				)
			);
		}

		if ( false !== strpos( $url, 'api.wordpress.org' ) ) {
			return $this->response( 200, (string) wp_json_encode( $this->api ) );
		}

		if ( 0 === strpos( $url, home_url() ) || 0 === strpos( $url, admin_url() ) ) {
			return $this->response( $this->smoke_code );
		}

		return new WP_Error( 'http_request_failed', 'Outbound HTTP is blocked in tests: ' . $url );
	}

	private function response( $code, $body = '', array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => (int) $code,
				'message' => get_status_header_desc( (int) $code ),
			),
			'cookies'  => array(),
		);
	}

	private function api_body( $version ) {
		return array(
			'name'          => 'SA Test Plugin',
			'slug'          => self::SLUG,
			'version'       => (string) $version,
			'requires'      => '6.9',
			'requires_php'  => '8.0',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => gmdate( 'Y-m-d H:i:s' ),
			'download_link' => self::DOWNLOAD,
		);
	}

	private function plugin_source( $version ) {
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: SA Test Plugin\n"
			. " * Description: Built by the Super Abilities test suite. Does nothing.\n"
			. ' * Version: ' . $version . "\n"
			. " * Author: Super Abilities Tests\n"
			. " * Requires at least: 6.9\n"
			. " * Requires PHP: 8.0\n"
			. " */\n";
	}

	/**
	 * Builds the package the HTTP stub will serve and points the API at that version.
	 */
	private function use_package( $version ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is required to build the test package.' );
		}

		$path = $this->tmp_dir . '/' . self::SLUG . '-' . $version . '.zip';
		$zip  = new ZipArchive();

		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( self::SLUG . '/' . self::SLUG . '.php', $this->plugin_source( $version ) );
		$zip->addFromString( self::SLUG . '/readme.txt', "=== SA Test Plugin ===\nStable tag: " . $version . "\n" );
		$zip->close();

		$this->zip = $path;
		$this->api = $this->api_body( $version );

		return $path;
	}

	/**
	 * Pretends WordPress.org offers an update for the installed test plugin.
	 */
	private function offer_update( $new_version ) {
		$transient               = new stdClass();
		$transient->last_checked = time();
		$transient->checked      = array();
		$transient->no_update    = array();
		$transient->response     = array(
			self::FILE => (object) array(
				'slug'         => self::SLUG,
				'plugin'       => self::FILE,
				'new_version'  => (string) $new_version,
				'package'      => self::DOWNLOAD,
				'url'          => 'https://example.org/sa-test-plugin',
				'requires'     => '6.9',
				'requires_php' => '8.0',
				'tested'       => get_bloginfo( 'version' ),
			),
		);

		foreach ( get_plugins() as $file => $data ) {
			$transient->checked[ (string) $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}

		add_filter(
			'pre_site_transient_update_plugins',
			static function () use ( $transient ) {
				return $transient;
			}
		);
	}

	private function install_test_plugin() {
		$this->use_package( '1.0.0' );

		$result = $this->run_ability( new Extension_Install(), array( 'slug' => self::SLUG ) );

		$this->assertTrue( $result['installed'], 'The fixture plugin must install before the test can continue.' );
		$this->assertSame( self::FILE, $result['slug'] );

		return $result;
	}

	private function remove_test_plugin() {
		$dir = WP_PLUGIN_DIR . '/' . self::SLUG;

		if ( is_dir( $dir ) ) {
			deactivate_plugins( array( self::FILE ), true );
			$this->remove_dir( $dir );
			wp_clean_plugins_cache( false );
		}
	}

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for cleaning up a test fixture.
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for cleaning up a test fixture.
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for cleaning up a test fixture.
	}

	/**
	 * Runs an ability and asserts the result validates against its output schema.
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

	private function row_for( array $result, $slug ) {
		foreach ( $result['items'] as $item ) {
			if ( $item['slug'] === $slug ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Registers the module's abilities with core when the registry was built without them.
	 *
	 * `wp_register_ability()` and `wp_register_ability_category()` only work while their
	 * init actions are running, and the registry is a per-request singleton: when another
	 * test file touched it first, this module was still switched off and its abilities
	 * never got registered. The registry objects themselves accept a late registration,
	 * which is what this uses; whatever it adds is unregistered again in tear_down().
	 */
	private function ensure_registered() {
		$module = new Extensions_Module( Plugin::instance() );

		// Touching the registry fires wp_abilities_api_init, which runs our Registrar.
		wp_get_abilities();

		$categories = WP_Ability_Categories_Registry::get_instance();

		if ( null !== $categories && ! wp_has_ability_category( 'super-abilities-extensions' ) ) {
			$categories->register( 'super-abilities-extensions', $module->category_args() );
		}

		$registry = WP_Abilities_Registry::get_instance();

		foreach ( $module->abilities() as $class_name ) {
			$ability = new $class_name();

			if ( null !== $registry && ! wp_has_ability( $ability->name() ) ) {
				$registry->register( $ability->name(), $ability->to_args() );

				$this->registered[] = $ability->name();
			}
		}

		return $module;
	}

	public function test_module_shape() {
		$module = new Extensions_Module( Plugin::instance() );

		$this->assertSame( 'extensions', $module->id() );
		$this->assertSame( 'Extensions', $module->label() );
		$this->assertSame( 'high', $module->risk() );
		$this->assertFalse( $module->default_enabled() );
		$this->assertCount( 10, $module->abilities() );
	}

	public function test_every_ability_registers_with_core_and_declares_itself_honestly() {
		$module = $this->ensure_registered();

		$expected = array(
			'super-abilities/extensions-list'      => array( 'readonly' => true ),
			'super-abilities/extension-preflight'  => array( 'readonly' => true ),
			'super-abilities/extension-install'    => array( 'readonly' => false ),
			'super-abilities/extension-update'     => array( 'readonly' => false ),
			'super-abilities/extension-rollback'   => array( 'destructive' => true ),
			'super-abilities/extension-activate'   => array( 'readonly' => false ),
			'super-abilities/extension-deactivate' => array( 'readonly' => false ),
			'super-abilities/extension-delete'     => array( 'destructive' => true ),
			'super-abilities/restore-points-list'  => array( 'readonly' => true ),
			'super-abilities/restore-point-delete' => array( 'destructive' => true ),
		);

		$names = array();

		foreach ( $module->abilities() as $class_name ) {
			$ability = new $class_name();
			$names[] = $ability->name();

			$this->assertTrue( wp_has_ability( $ability->name() ), $ability->name() . ' is not registered.' );
			$this->assertSame( 'super-abilities-extensions', $ability->category() );
			$this->assertSame( 'extensions', $ability->module() );
			$this->assertSame( '0.2.0', $ability->since() );
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $ability->slug() );
			$this->assertNotEmpty( $ability->capability(), $ability->name() . ' declares no capability.' );
			$this->assertNotEmpty( $ability->input_schema() );
			$this->assertNotEmpty( $ability->output_schema() );
			$this->assertNotSame( '', $ability->summary() );

			$annotations = $ability->annotations();

			foreach ( $expected[ $ability->name() ] as $key => $value ) {
				$this->assertSame( $value, $annotations[ $key ], $ability->name() . ' annotation ' . $key );
			}
		}

		$this->assertSame( array_keys( $expected ), $names );
	}

	public function test_extensions_list_sees_the_installed_fixtures() {
		$list = $this->run_ability( new Extensions_List(), array() );

		$this->assertGreaterThan( 0, $list['total'] );
		$this->assertGreaterThan( 0, $list['totals']['plugins'] );
		$this->assertGreaterThan( 0, $list['totals']['themes'] );
		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $list['fingerprint'] );

		$hello = $this->row_for( $list, 'hello.php' );

		$this->assertIsArray( $hello, 'The single file hello.php plugin is listed.' );
		$this->assertSame( 'plugin', $hello['type'] );
		$this->assertSame( 'hello', $hello['wporg_slug'] );
		$this->assertFalse( $hello['is_mu'] );
		$this->assertNull( $hello['restore_point_id'] );

		$theme = $this->row_for( $list, get_stylesheet() );

		$this->assertIsArray( $theme );
		$this->assertSame( 'theme', $theme['type'] );
		$this->assertTrue( $theme['active'] );
	}

	public function test_extensions_list_sees_this_plugin_active_when_it_lives_in_the_plugins_directory() {
		$self = Target::plugin_file( Guard::self_basename() );

		if ( '' === $self ) {
			$this->markTestSkipped( 'Super Abilities is loaded from outside wp-content/plugins in this environment, so get_plugins() cannot see it.' );
		}

		activate_plugin( $self );

		$row = $this->row_for( $this->run_ability( new Extensions_List(), array( 'type' => 'plugin' ) ), $self );

		$this->assertIsArray( $row, 'Super Abilities lists itself.' );
		$this->assertTrue( $row['active'] );
		$this->assertFalse( $row['is_mu'] );
	}

	public function test_extensions_list_filters_by_type_status_and_search() {
		$plugins = $this->run_ability( new Extensions_List(), array( 'type' => 'plugin' ) );
		$themes  = $this->run_ability( new Extensions_List(), array( 'type' => 'theme' ) );

		$this->assertSame( array( 'plugin' ), array_values( array_unique( wp_list_pluck( $plugins['items'], 'type' ) ) ) );
		$this->assertSame( array( 'theme' ), array_values( array_unique( wp_list_pluck( $themes['items'], 'type' ) ) ) );

		$active = $this->run_ability(
			new Extensions_List(),
			array(
				'type'   => 'all',
				'status' => 'active',
			)
		);

		foreach ( $active['items'] as $item ) {
			$this->assertTrue( $item['active'] );
		}

		$searched = $this->run_ability(
			new Extensions_List(),
			array(
				'type'   => 'plugin',
				'search' => 'hello',
			)
		);

		$this->assertNotEmpty( $searched['items'] );
		$this->assertNotNull( $this->row_for( $searched, 'hello.php' ) );

		$nothing = $this->run_ability(
			new Extensions_List(),
			array( 'search' => 'sa-definitely-not-installed-xyz' )
		);

		$this->assertSame( 0, $nothing['total'] );
		$this->assertGreaterThan( 0, $nothing['totals']['plugins'], 'Totals count the whole site, not the filtered page.' );
	}

	public function test_preflight_reports_an_installable_package() {
		$this->use_package( '1.0.0' );

		$result = $this->run_ability(
			new Extension_Preflight(),
			array(
				'type' => 'plugin',
				'slug' => self::SLUG,
			)
		);

		$this->assertTrue( $result['preflight']['ok'] );
		$this->assertSame( array(), $result['preflight']['blockers'] );
		$this->assertSame( 'direct', $result['preflight']['filesystem_method'] );
		$this->assertNull( $result['package_error'] );
		$this->assertSame( 'wordpress.org', $result['package']['source'] );
		$this->assertSame( '1.0.0', $result['package']['version'] );
		$this->assertSame( 'example.org', $result['package']['host'] );
		$this->assertGreaterThan( 0, $result['package']['size_bytes'] );
		$this->assertArrayNotHasKey( 'download_url', $result['package'], 'The download URL never leaves the site.' );
	}

	public function test_preflight_blocks_when_file_modifications_are_disabled() {
		$this->use_package( '1.0.0' );

		add_filter(
			'file_mod_allowed',
			static function ( $allowed, $context ) {
				return 'install_plugin' === $context ? false : $allowed;
			},
			10,
			2
		);

		$result = $this->run_ability(
			new Extension_Preflight(),
			array(
				'type' => 'plugin',
				'slug' => self::SLUG,
			)
		);

		$this->assertFalse( $result['preflight']['ok'] );
		$this->assertContains( 'file_mods_disallowed', wp_list_pluck( $result['preflight']['blockers'], 'code' ) );
	}

	public function test_preflight_reports_an_unknown_slug_as_a_package_error() {
		$this->api = array();

		$result = $this->run_ability(
			new Extension_Preflight(),
			array(
				'type' => 'plugin',
				'slug' => 'sa-no-such-plugin',
			)
		);

		$this->assertIsString( $result['package_error'] );
		$this->assertSame( '', $result['package']['version'] );
	}

	public function test_preflight_needs_a_slug_or_a_zip_url() {
		$result = ( new Extension_Preflight() )->execute( array( 'type' => 'plugin' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_install_dry_run_changes_nothing() {
		$this->use_package( '1.0.0' );

		$result = $this->run_ability(
			new Extension_Install(),
			array(
				'slug'    => self::SLUG,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['installed'] );
		$this->assertNull( $result['slug'] );
		$this->assertTrue( $result['preflight']['ok'] );
		$this->assertFalse( is_dir( WP_PLUGIN_DIR . '/' . self::SLUG ) );
	}

	public function test_install_aborts_on_a_preflight_blocker() {
		$this->use_package( '1.0.0' );

		add_filter(
			'file_mod_allowed',
			static function ( $allowed, $context ) {
				return 'install_plugin' === $context ? false : $allowed;
			},
			10,
			2
		);

		$result = ( new Extension_Install() )->execute( array( 'slug' => self::SLUG ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_preflight_failed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertFalse( is_dir( WP_PLUGIN_DIR . '/' . self::SLUG ) );
	}

	public function test_install_needs_exactly_one_source() {
		$neither = ( new Extension_Install() )->execute( array( 'type' => 'plugin' ) );

		$this->assertWPError( $neither );
		$this->assertSame( 'super_abilities_invalid_input', $neither->get_error_code() );

		$both = ( new Extension_Install() )->execute(
			array(
				'slug'    => self::SLUG,
				'zip_url' => self::DOWNLOAD,
			)
		);

		$this->assertWPError( $both );
		$this->assertSame( 'super_abilities_invalid_input', $both->get_error_code() );
	}

	public function test_install_from_a_zip_url_needs_the_setting_and_the_host() {
		$this->use_package( '1.0.0' );

		$refused = ( new Extension_Install() )->execute(
			array(
				'type'    => 'plugin',
				'zip_url' => self::DOWNLOAD,
			)
		);

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_forbidden', $refused->get_error_code() );

		Plugin::instance()->options()->set(
			array(
				'extensions_allow_zip_url' => true,
				'extensions_zip_hosts'     => array( 'example.org' ),
			)
		);

		$installed = $this->run_ability(
			new Extension_Install(),
			array(
				'type'           => 'plugin',
				'zip_url'        => self::DOWNLOAD,
				'run_smoke_test' => false,
			)
		);

		$this->assertTrue( $installed['installed'] );
		$this->assertSame( self::SLUG, $installed['destination_name'] );
		$this->assertSame( self::FILE, $installed['slug'] );
		$this->assertSame( 'zip_url', $installed['package']['source'] );
		$this->assertSame( 'example.org', $installed['package']['host'] );
	}

	public function test_install_activate_deactivate_and_delete_cycle() {
		$installed = $this->install_test_plugin();

		$this->assertSame( '1.0.0', $installed['version'] );
		$this->assertFalse( $installed['activated'], 'Activation is off by default.' );
		$this->assertTrue( $installed['smoke']['passed'] );
		$this->assertTrue( $installed['smoke']['ran'] );
		$this->assertCount( 2, $installed['smoke']['probes'] );
		$this->assertGreaterThanOrEqual( 0, $installed['duration_ms'] );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::FILE );

		$listed = $this->row_for( $this->run_ability( new Extensions_List(), array( 'type' => 'plugin' ) ), self::FILE );

		$this->assertIsArray( $listed );
		$this->assertSame( 'SA Test Plugin', $listed['name'] );
		$this->assertSame( '1.0.0', $listed['version'] );
		$this->assertSame( 'Super Abilities Tests', $listed['author'] );
		$this->assertSame( '6.9', $listed['requires_wp'] );
		$this->assertSame( '8.0', $listed['requires_php'] );
		$this->assertFalse( $listed['active'] );

		$activated = $this->run_ability(
			new Extension_Activate(),
			array(
				'type' => 'plugin',
				'slug' => self::SLUG,
			)
		);

		$this->assertTrue( $activated['active'] );
		$this->assertFalse( $activated['already_active'] );
		$this->assertFalse( $activated['rolled_back'] );
		$this->assertSame( self::FILE, $activated['slug'] );
		$this->assertTrue( is_plugin_active( self::FILE ) );

		$again = $this->run_ability(
			new Extension_Activate(),
			array(
				'type' => 'plugin',
				'slug' => self::FILE,
			)
		);

		$this->assertTrue( $again['active'] );
		$this->assertTrue( $again['already_active'], 'Activating twice is idempotent.' );

		$blocked = ( new Extension_Delete() )->execute(
			array(
				'type' => 'plugin',
				'slug' => self::FILE,
			)
		);

		$this->assertWPError( $blocked, 'An active plugin cannot be deleted.' );
		$this->assertSame( 'super_abilities_still_active', $blocked->get_error_code() );
		$this->assertSame( 409, $blocked->get_error_data()['status'] );

		$deactivated = $this->run_ability(
			new Extension_Deactivate(),
			array( 'slug' => self::FILE )
		);

		$this->assertFalse( $deactivated['active'] );
		$this->assertFalse( $deactivated['already_inactive'] );
		$this->assertFalse( is_plugin_active( self::FILE ) );

		$idempotent = $this->run_ability( new Extension_Deactivate(), array( 'slug' => self::FILE ) );

		$this->assertTrue( $idempotent['already_inactive'] );

		$dry_run = $this->run_ability(
			new Extension_Delete(),
			array(
				'type'    => 'plugin',
				'slug'    => self::FILE,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $dry_run['dry_run'] );
		$this->assertFalse( $dry_run['deleted'] );
		$this->assertNull( $dry_run['restore_point_id'] );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::FILE );

		$deleted = $this->run_ability(
			new Extension_Delete(),
			array(
				'type'             => 'plugin',
				'slug'             => self::FILE,
				'expected_version' => '1.0.0',
			)
		);

		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( '1.0.0', $deleted['version'] );
		$this->assertIsString( $deleted['restore_point_id'] );
		$this->assertFalse( is_dir( WP_PLUGIN_DIR . '/' . self::SLUG ) );

		// The restore point taken before the delete can put the plugin back.
		$restored = $this->run_ability(
			new Extension_Rollback(),
			array(
				'restore_point_id' => $deleted['restore_point_id'],
				'run_smoke_test'   => false,
			)
		);

		$this->assertTrue( $restored['restored'] );
		$this->assertSame( '1.0.0', $restored['restored_version'] );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::FILE );
	}

	public function test_delete_refuses_a_version_mismatch() {
		$this->install_test_plugin();

		$result = ( new Extension_Delete() )->execute(
			array(
				'type'             => 'plugin',
				'slug'             => self::FILE,
				'expected_version' => '9.9.9',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_version_mismatch', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( '1.0.0', $result->get_error_data()['installed_version'] );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::FILE );
	}

	public function test_delete_refuses_a_stale_fingerprint() {
		$this->install_test_plugin();

		$result = ( new Extension_Delete() )->execute(
			array(
				'type'                 => 'plugin',
				'slug'                 => self::FILE,
				'expected_fingerprint' => 'fp1:00000000000000000000',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_stale_fingerprint', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $result->get_error_data()['current_fingerprint'] );
	}

	public function test_delete_accepts_the_fingerprint_the_list_reported() {
		$this->install_test_plugin();

		$row = $this->row_for( $this->run_ability( new Extensions_List(), array( 'type' => 'plugin' ) ), self::FILE );

		$result = $this->run_ability(
			new Extension_Delete(),
			array(
				'type'                 => 'plugin',
				'slug'                 => self::FILE,
				'expected_fingerprint' => $row['fingerprint'],
			)
		);

		$this->assertTrue( $result['deleted'] );
	}

	public function test_deactivate_refuses_a_protected_plugin_unless_forced() {
		$this->install_test_plugin();

		activate_plugin( self::FILE );

		$filter = static function ( $entries ) {
			$entries[] = ExtensionsAbilitiesTest::FILE;

			return $entries;
		};

		add_filter( 'super_abilities_protected_extensions', $filter );

		$refused = ( new Extension_Deactivate() )->execute( array( 'slug' => self::FILE ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_protected_extension', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertTrue( $refused->get_error_data()['protected'] );
		$this->assertTrue( is_plugin_active( self::FILE ) );

		$forced = $this->run_ability(
			new Extension_Deactivate(),
			array(
				'slug'  => self::FILE,
				'force' => true,
			)
		);

		$this->assertFalse( $forced['active'] );
		$this->assertFalse( is_plugin_active( self::FILE ) );

		remove_filter( 'super_abilities_protected_extensions', $filter );
	}

	public function test_deactivate_refuses_this_plugin_without_force() {
		$self = Target::plugin_file( Guard::self_basename() );

		if ( '' === $self ) {
			$this->markTestSkipped( 'Super Abilities is loaded from outside wp-content/plugins in this environment.' );
		}

		$this->assertTrue( Guard::is_protected( 'plugin', $self ) );

		$refused = ( new Extension_Deactivate() )->execute( array( 'slug' => $self ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_protected_extension', $refused->get_error_code() );
	}

	public function test_deactivate_is_unsupported_for_themes() {
		$result = ( new Extension_Deactivate() )->execute(
			array(
				'type' => 'theme',
				'slug' => get_stylesheet(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_unsupported', $result->get_error_code() );
		$this->assertSame( 501, $result->get_error_data()['status'] );
	}

	public function test_activating_something_that_is_not_installed_is_a_404() {
		$result = ( new Extension_Activate() )->execute(
			array(
				'type' => 'plugin',
				'slug' => 'sa-no-such-plugin',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_a_traversing_slug_is_invalid_input() {
		$result = ( new Extension_Activate() )->execute(
			array(
				'type' => 'plugin',
				'slug' => '../../wp-config.php',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
	}

	public function test_activation_is_undone_when_the_smoke_test_fails() {
		$this->install_test_plugin();

		$this->smoke_code = 500;

		$result = $this->run_ability(
			new Extension_Activate(),
			array(
				'type' => 'plugin',
				'slug' => self::FILE,
			)
		);

		$this->assertFalse( $result['active'] );
		$this->assertTrue( $result['rolled_back'] );
		$this->assertFalse( $result['smoke']['passed'] );
		$this->assertSame( 'failed', $result['smoke']['probes'][0]['status'] );
		$this->assertFalse( is_plugin_active( self::FILE ) );
	}

	public function test_install_with_activation_rolls_the_activation_back_on_a_failed_smoke_test() {
		$this->use_package( '1.0.0' );

		$this->smoke_code = 500;

		$result = $this->run_ability(
			new Extension_Install(),
			array(
				'slug'     => self::SLUG,
				'activate' => true,
			)
		);

		$this->assertTrue( $result['installed'], 'The files stay installed.' );
		$this->assertTrue( $result['rolled_back_activation'] );
		$this->assertFalse( $result['activated'] );
		$this->assertFalse( is_plugin_active( self::FILE ) );
	}

	public function test_update_takes_a_restore_point_and_reports_both_versions() {
		$this->install_test_plugin();

		$this->use_package( '1.1.0' );
		$this->offer_update( '1.1.0' );

		$listed = $this->row_for( $this->run_ability( new Extensions_List(), array( 'type' => 'plugin' ) ), self::FILE );

		$this->assertTrue( $listed['update_available'] );
		$this->assertSame( '1.1.0', $listed['new_version'] );

		$result = $this->run_ability(
			new Extension_Update(),
			array(
				'type' => 'plugin',
				'slug' => self::FILE,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertFalse( $result['rolled_back'] );
		$this->assertNull( $result['rollback_reason'] );
		$this->assertSame( '1.0.0', $result['old_version'] );
		$this->assertSame( '1.1.0', $result['new_version'] );
		$this->assertIsString( $result['restore_point_id'] );
		$this->assertTrue( $result['smoke']['passed'] );
		$this->assertSame( '1.1.0', (string) get_plugin_data( WP_PLUGIN_DIR . '/' . self::FILE, false, false )['Version'] );

		$points = $this->run_ability( new Restore_Points_List(), array() );

		$this->assertSame( 1, $points['total'] );
		$this->assertSame( $result['restore_point_id'], $points['items'][0]['id'] );
		$this->assertSame( '1.0.0', $points['items'][0]['version'] );
		$this->assertSame( 'plugin', $points['items'][0]['type'] );
		$this->assertSame( self::FILE, $points['items'][0]['slug'] );
		$this->assertGreaterThan( 0, $points['items'][0]['size_bytes'] );
		$this->assertSame( 20, $points['max_kept'] );

		$rolled_back = $this->run_ability(
			new Extension_Rollback(),
			array(
				'type' => 'plugin',
				'slug' => self::FILE,
			)
		);

		$this->assertTrue( $rolled_back['restored'] );
		$this->assertSame( '1.1.0', $rolled_back['previous_version'] );
		$this->assertSame( '1.0.0', $rolled_back['restored_version'] );
		$this->assertSame( '1.0.0', (string) get_plugin_data( WP_PLUGIN_DIR . '/' . self::FILE, false, false )['Version'] );
	}

	public function test_a_failed_smoke_test_rolls_the_update_back_automatically() {
		$this->install_test_plugin();

		$this->use_package( '1.1.0' );
		$this->offer_update( '1.1.0' );

		$this->smoke_code = 500;

		$result = $this->run_ability(
			new Extension_Update(),
			array(
				'type' => 'plugin',
				'slug' => self::FILE,
			)
		);

		$this->assertTrue( $result['rolled_back'] );
		$this->assertFalse( $result['updated'] );
		$this->assertFalse( $result['smoke']['passed'] );
		$this->assertStringContainsString( 'smoke test failed', (string) $result['rollback_reason'] );
		$this->assertSame( '1.0.0', $result['new_version'], 'The old version is back in place.' );
		$this->assertSame( '1.0.0', (string) get_plugin_data( WP_PLUGIN_DIR . '/' . self::FILE, false, false )['Version'] );
	}

	public function test_update_dry_run_writes_no_restore_point() {
		$this->install_test_plugin();

		$this->use_package( '1.1.0' );
		$this->offer_update( '1.1.0' );

		$result = $this->run_ability(
			new Extension_Update(),
			array(
				'type'    => 'plugin',
				'slug'    => self::FILE,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['updated'] );
		$this->assertNull( $result['restore_point_id'] );
		$this->assertTrue( $result['preflight']['ok'] );
		$this->assertSame( array(), Restore_Point::all() );
	}

	public function test_update_refuses_a_stale_fingerprint() {
		$this->install_test_plugin();

		$result = ( new Extension_Update() )->execute(
			array(
				'type'                 => 'plugin',
				'slug'                 => self::FILE,
				'expected_fingerprint' => 'fp1:11111111111111111111',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_stale_fingerprint', $result->get_error_code() );
	}

	public function test_updating_something_that_is_not_installed_is_a_404() {
		$result = ( new Extension_Update() )->execute(
			array(
				'type' => 'plugin',
				'slug' => 'sa-no-such-plugin',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_not_found', $result->get_error_code() );
	}

	public function test_restore_points_can_be_listed_filtered_and_deleted() {
		$this->install_test_plugin();

		$created = Restore_Point::create( 'plugin', self::FILE );

		$this->assertIsArray( $created );
		$this->assertMatchesRegularExpression( '/^rp_[0-9a-f]{12}$/', $created['id'] );
		$this->assertTrue( $created['size_bytes'] > 0 );
		$this->assertDirectoryExists( Restore_Point::path_of( $created ) );
		$this->assertFileExists( Restore_Point::base_dir() . '/index.php' );
		$this->assertFileExists( Restore_Point::base_dir() . '/.htaccess' );
		$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( Restore_Point::base_dir() . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for reading a test fixture.

		$all = $this->run_ability( new Restore_Points_List(), array() );

		$this->assertSame( 1, $all['total'] );
		$this->assertSame( $created['id'], $all['items'][0]['id'] );

		$filtered = $this->run_ability(
			new Restore_Points_List(),
			array(
				'type' => 'theme',
				'slug' => '',
			)
		);

		$this->assertSame( 0, $filtered['total'], 'A theme filter excludes plugin restore points.' );

		$by_slug = $this->run_ability(
			new Restore_Points_List(),
			array( 'slug' => self::SLUG )
		);

		$this->assertSame( 1, $by_slug['total'], 'The folder name matches as well as the plugin file.' );

		$deleted = $this->run_ability( new Restore_Point_Delete(), array( 'id' => $created['id'] ) );

		$this->assertTrue( $deleted['deleted'] );
		$this->assertTrue( $deleted['existed'] );
		$this->assertSame( 0, $deleted['remaining'] );
		$this->assertDirectoryDoesNotExist( Restore_Point::path_of( $created ) );

		$again = $this->run_ability( new Restore_Point_Delete(), array( 'id' => $created['id'] ) );

		$this->assertFalse( $again['existed'], 'Deleting a restore point twice is not an error.' );
	}

	public function test_restore_point_delete_rejects_a_malformed_id() {
		$result = ( new Restore_Point_Delete() )->execute( array( 'id' => 'not-an-id' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
	}

	public function test_rollback_needs_a_restore_point() {
		$missing = ( new Extension_Rollback() )->execute( array( 'restore_point_id' => 'rp_000000000000' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 'super_abilities_not_found', $missing->get_error_code() );

		$nothing = ( new Extension_Rollback() )->execute( array( 'type' => 'plugin' ) );

		$this->assertWPError( $nothing );
		$this->assertSame( 'super_abilities_invalid_input', $nothing->get_error_code() );
	}

	public function test_rollback_dry_run_changes_nothing() {
		$this->install_test_plugin();

		$created = Restore_Point::create( 'plugin', self::FILE );

		$result = $this->run_ability(
			new Extension_Rollback(),
			array(
				'restore_point_id' => $created['id'],
				'dry_run'          => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['restored'] );
		$this->assertSame( $created['id'], $result['restore_point']['id'] );
		$this->assertSame( '1.0.0', $result['previous_version'] );
	}

	public function test_rollback_reactivates_what_was_active_at_snapshot_time() {
		$this->install_test_plugin();

		activate_plugin( self::FILE );

		$created = Restore_Point::create( 'plugin', self::FILE );

		$this->assertTrue( $created['was_active'] );

		deactivate_plugins( array( self::FILE ), true );

		$result = $this->run_ability(
			new Extension_Rollback(),
			array( 'restore_point_id' => $created['id'] )
		);

		$this->assertTrue( $result['restored'] );
		$this->assertTrue( $result['reactivated'] );
		$this->assertNull( $result['activation_error'] );
		$this->assertTrue( is_plugin_active( self::FILE ) );
	}

	public function test_a_subscriber_is_denied_every_ability() {
		$module = new Extensions_Module( Plugin::instance() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( $module->abilities() as $class_name ) {
			$ability = new $class_name();
			$denied  = $ability->check_permission(
				array(
					'type' => 'plugin',
					'slug' => self::FILE,
					'id'   => 'rp_000000000000',
				)
			);

			$this->assertWPError( $denied, $ability->name() . ' must refuse a subscriber.' );
			$this->assertSame( 403, $denied->get_error_data()['status'] );
		}
	}

	public function test_an_anonymous_caller_is_denied_every_ability() {
		$module = new Extensions_Module( Plugin::instance() );

		wp_set_current_user( 0 );

		foreach ( $module->abilities() as $class_name ) {
			$ability = new $class_name();
			$denied  = $ability->check_permission( array( 'type' => 'plugin' ) );

			$this->assertWPError( $denied, $ability->name() . ' must refuse an anonymous caller.' );
			$this->assertSame( 'super_abilities_forbidden', $denied->get_error_code() );
			$this->assertSame( 'not_logged_in', $denied->get_error_data()['reason'] );
		}
	}

	public function test_permission_requires_the_capability_that_matches_the_type() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		// An administrator who may install plugins but not themes.
		add_filter(
			'user_has_cap',
			static function ( $caps ) {
				$caps['install_themes'] = false;
				$caps['update_themes']  = false;

				return $caps;
			}
		);

		$install = new Extension_Install();

		$this->assertTrue( $install->check_permission( array( 'type' => 'plugin' ) ) );

		$denied = $install->check_permission( array( 'type' => 'theme' ) );

		$this->assertWPError( $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertSame( 'install_themes', $denied->get_error_data()['required_capability'] );
	}

	public function test_activation_needs_its_own_capability_on_top_of_install() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'user_has_cap',
			static function ( $caps ) {
				$caps['activate_plugins'] = false;

				return $caps;
			}
		);

		$install = new Extension_Install();

		$this->assertTrue( $install->check_permission( array( 'type' => 'plugin' ) ) );

		$denied = $install->check_permission(
			array(
				'type'     => 'plugin',
				'activate' => true,
			)
		);

		$this->assertWPError( $denied );
		$this->assertSame( 'activate_plugins', $denied->get_error_data()['required_capability'] );
	}

	public function test_writes_are_refused_when_the_site_forbids_file_modifications() {
		add_filter( 'file_mod_allowed', '__return_false' );

		foreach ( array( new Extension_Install(), new Extension_Update(), new Extension_Delete(), new Extension_Rollback() ) as $ability ) {
			$denied = $ability->check_permission(
				array(
					'type' => 'plugin',
					'slug' => self::FILE,
				)
			);

			$this->assertWPError( $denied, $ability->name() . ' must honor DISALLOW_FILE_MODS.' );
			$this->assertSame( 403, $denied->get_error_data()['status'] );
		}

		$this->assertTrue(
			( new Extensions_List() )->check_permission( array() ),
			'Reading the inventory is still allowed.'
		);
	}

	public function test_every_write_notes_the_object_it_touched() {
		$noted = array();

		add_action(
			'super_abilities_note_object',
			static function ( $name, $type, $id ) use ( &$noted ) {
				$noted[] = array( $name, $type, (string) $id );
			},
			10,
			3
		);

		$this->install_test_plugin();

		$types = array_values( array_unique( wp_list_pluck( $noted, 1 ) ) );

		$this->assertSame( array( 'plugin' ), $types );
		$this->assertContains( array( 'super-abilities/extension-install', 'plugin', self::FILE ), $noted );
	}
}
