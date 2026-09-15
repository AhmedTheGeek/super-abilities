<?php
/**
 * Tests for the extensions pre-flight rules and package resolution.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Packages;
use SuperAbilities\Extensions\Preflight;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Plugin;

class ExtensionsPreflightTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Nothing in this file may reach the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
	}

	public function block_http() {
		return new WP_Error( 'http_request_failed', 'Outbound HTTP is blocked in tests.' );
	}

	private function disallow_file_mods( $context ) {
		add_filter(
			'file_mod_allowed',
			static function ( $allowed, $asked ) use ( $context ) {
				return $asked === $context ? false : $allowed;
			},
			10,
			2
		);
	}

	private function codes( array $findings ) {
		return wp_list_pluck( $findings, 'code' );
	}

	public function test_file_mod_contexts_match_core() {
		$this->assertSame( 'install_plugin', Preflight::file_mod_context( 'plugin', 'install' ) );
		$this->assertSame( 'update_plugin', Preflight::file_mod_context( 'plugin', 'update' ) );
		$this->assertSame( 'delete_plugin', Preflight::file_mod_context( 'plugin', 'delete' ) );
		$this->assertSame( 'install_theme', Preflight::file_mod_context( 'theme', 'install' ) );
		$this->assertSame( 'update_themes', Preflight::file_mod_context( 'theme', 'update' ) );
		$this->assertSame( 'delete_theme', Preflight::file_mod_context( 'theme', 'delete' ) );
		$this->assertSame( 'install_plugin', Preflight::file_mod_context( 'nonsense', 'nonsense' ) );
	}

	public function test_a_clean_site_passes() {
		$result = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'sa-not-installed',
				'action' => 'install',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['blockers'] );
		$this->assertSame( 'direct', $result['filesystem_method'] );
		$this->assertFalse( $result['installed'] );
		$this->assertFalse( $result['maintenance_mode'] );
		$this->assertSame( 'install', $result['action'] );
	}

	public function test_disallowed_file_mods_block_an_install() {
		$this->disallow_file_mods( 'install_plugin' );

		$result = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'sa-not-installed',
				'action' => 'install',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'file_mods_disallowed', $this->codes( $result['blockers'] ) );
	}

	public function test_disallowed_theme_install_does_not_block_a_plugin_install() {
		$this->disallow_file_mods( 'install_theme' );

		$plugin = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'sa-not-installed',
				'action' => 'install',
			)
		);
		$theme  = Preflight::check(
			array(
				'type'   => 'theme',
				'slug'   => 'sa-not-installed',
				'action' => 'install',
			)
		);

		$this->assertTrue( $plugin['ok'] );
		$this->assertFalse( $theme['ok'] );
	}

	public function test_updating_something_that_is_not_installed_is_blocked() {
		$result = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'sa-not-installed/sa-not-installed.php',
				'action' => 'update',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'not_installed', $this->codes( $result['blockers'] ) );
	}

	public function test_installing_over_something_installed_only_warns() {
		$result = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'hello.php',
				'action' => 'install',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['installed'] );
		$this->assertContains( 'already_installed', $this->codes( $result['warnings'] ) );
	}

	public function test_version_requirements_block_and_tested_warns() {
		$result = Preflight::check(
			array(
				'type'    => 'plugin',
				'slug'    => 'sa-not-installed',
				'action'  => 'install',
				'package' => array(
					'requires_wp'  => '99.0',
					'requires_php' => '99.0',
					'tested'       => '1.0',
					'size_bytes'   => 1024,
				),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'requires_wp', $this->codes( $result['blockers'] ) );
		$this->assertContains( 'requires_php', $this->codes( $result['blockers'] ) );
		$this->assertContains( 'untested_wp', $this->codes( $result['warnings'] ) );
		$this->assertSame( 3072, $result['required_bytes'], 'Three times the package size is reserved.' );
	}

	public function test_an_enormous_package_runs_out_of_disk_space() {
		$result = Preflight::check(
			array(
				'type'    => 'plugin',
				'slug'    => 'sa-not-installed',
				'action'  => 'install',
				'package' => array( 'size_bytes' => PHP_INT_MAX / 4 ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'insufficient_disk_space', $this->codes( $result['blockers'] ) );
	}

	public function test_an_active_plugin_update_is_warned_about() {
		$result = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'hello.php',
				'action' => 'update',
			)
		);

		$this->assertTrue( $result['installed'] );
		$this->assertNotContains( 'active', $this->codes( $result['warnings'] ), 'hello.php is not active in the test site.' );

		activate_plugin( 'hello.php' );

		$active = Preflight::check(
			array(
				'type'   => 'plugin',
				'slug'   => 'hello.php',
				'action' => 'update',
			)
		);

		deactivate_plugins( array( 'hello.php' ), true );

		$this->assertContains( 'active', $this->codes( $active['warnings'] ) );
	}

	public function test_zip_urls_are_refused_until_an_administrator_allows_them() {
		$options = Plugin::instance()->options();
		$options->set( 'extensions_allow_zip_url', false );

		$refused = Packages::resolve(
			array(
				'type'    => 'plugin',
				'zip_url' => 'https://example.org/package.zip',
			)
		);

		$this->assertWPError( $refused );
		$this->assertSame( 'super_abilities_forbidden', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );

		$options->set( 'extensions_allow_zip_url', true );
		$options->set( 'extensions_zip_hosts', array( 'example.org' ) );

		$allowed = Packages::resolve(
			array(
				'type'    => 'plugin',
				'zip_url' => 'https://example.org/package.zip',
				'probe'   => false,
			)
		);

		$this->assertIsArray( $allowed );
		$this->assertSame( 'zip_url', $allowed['source'] );
		$this->assertSame( 'example.org', $allowed['host'] );
		$this->assertSame( 'package', $allowed['slug'] );

		$blocked = Packages::resolve(
			array(
				'type'    => 'plugin',
				'zip_url' => 'https://not-example.org/package.zip',
				'probe'   => false,
			)
		);

		$this->assertWPError( $blocked, 'A host outside the allowlist is refused.' );
	}

	public function test_zip_urls_must_be_https_and_never_local() {
		$options = Plugin::instance()->options();
		$options->set( 'extensions_allow_zip_url', true );
		$options->set( 'extensions_zip_hosts', array() );

		foreach ( array( 'http://example.org/p.zip', 'file:///tmp/p.zip', '/tmp/p.zip', 'ftp://example.org/p.zip' ) as $url ) {
			$this->assertWPError(
				Packages::resolve(
					array(
						'type'    => 'plugin',
						'zip_url' => $url,
						'probe'   => false,
					)
				),
				$url . ' must be refused.'
			);
		}
	}

	public function test_a_missing_source_is_invalid_input() {
		$result = Packages::resolve( array( 'type' => 'plugin' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
	}

	public function test_wporg_slugs_are_normalized() {
		$this->assertSame( 'akismet', Packages::normalize_wporg_slug( 'akismet/akismet.php' ) );
		$this->assertSame( 'hello-dolly', Packages::normalize_wporg_slug( 'Hello-Dolly' ) );
		$this->assertSame( 'hello', Packages::normalize_wporg_slug( 'hello.php' ) );
		$this->assertSame( 'etc', Packages::normalize_wporg_slug( '../../etc/passwd' ), 'Traversal is stripped, not preserved.' );
		$this->assertSame( '', Packages::normalize_wporg_slug( '///' ) );
	}

	public function test_target_resolves_plugins_by_file_and_by_folder() {
		// Folder plugins are not guaranteed to exist in every test environment (wp-env ships
		// without Akismet), so create a throwaway one instead of relying on a bundled plugin.
		$dir  = WP_PLUGIN_DIR . '/sa-folder-fixture';
		$file = 'sa-folder-fixture/sa-folder-fixture.php';

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for a test fixture.
		}

		file_put_contents( WP_PLUGIN_DIR . '/' . $file, "<?php\n/**\n * Plugin Name: SA Folder Fixture\n * Version: 1.0.0\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for a test fixture.
		wp_cache_delete( 'plugins', 'plugins' );

		try {
			$this->assertSame( 'hello.php', Target::plugin_file( 'hello.php' ) );
			$this->assertSame( 'hello.php', Target::plugin_file( 'hello' ) );
			$this->assertSame( $file, Target::plugin_file( 'sa-folder-fixture' ) );
			$this->assertSame( $file, Target::plugin_file( $file ) );
			$this->assertSame( $file, Target::plugin_file( '/' . $file ), 'A leading slash is tolerated.' );
			$this->assertSame( '', Target::plugin_file( 'sa-nope' ) );
			$this->assertSame( '', Target::plugin_file( '' ) );
		} finally {
			unlink( WP_PLUGIN_DIR . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for cleaning up a test fixture.
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Plain PHP is correct for cleaning up a test fixture.
			wp_cache_delete( 'plugins', 'plugins' );
		}
	}

	public function test_target_derives_the_wporg_slug_from_a_plugin_file() {
		$this->assertSame( 'akismet', Target::plugin_slug( 'akismet/akismet.php' ) );
		$this->assertSame( 'hello', Target::plugin_slug( 'hello.php' ) );
		$this->assertSame( '', Target::plugin_slug( '' ) );
	}

	public function test_target_describes_an_installed_plugin() {
		$described = Target::describe( 'plugin', 'hello.php' );

		$this->assertIsArray( $described );
		$this->assertSame( 'plugin', $described['type'] );
		$this->assertSame( 'hello.php', $described['slug'] );
		$this->assertNotSame( '', $described['name'] );
		$this->assertFalse( $described['is_mu'] );
		$this->assertNull( Target::describe( 'plugin', 'sa-nope' ) );
	}

	public function test_target_describes_the_active_theme() {
		$described = Target::describe( 'theme', get_stylesheet() );

		$this->assertIsArray( $described );
		$this->assertSame( 'theme', $described['type'] );
		$this->assertTrue( $described['active'] );
		$this->assertNull( Target::describe( 'theme', 'sa-no-such-theme' ) );
	}

	public function test_fingerprints_change_with_the_version_and_the_active_state() {
		$base = array(
			'type'    => 'plugin',
			'slug'    => 'a/a.php',
			'version' => '1.0',
			'active'  => false,
		);

		$same     = Target::fingerprint( $base );
		$upgraded = Target::fingerprint( array_merge( $base, array( 'version' => '1.1' ) ) );
		$active   = Target::fingerprint( array_merge( $base, array( 'active' => true ) ) );

		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $same );
		$this->assertSame( $same, Target::fingerprint( $base ) );
		$this->assertNotSame( $same, $upgraded );
		$this->assertNotSame( $same, $active );
		$this->assertNotSame( $same, Target::fingerprint( null ) );
	}

	public function test_this_plugin_is_protected_and_the_list_is_filterable() {
		$this->assertContains( Guard::self_basename(), Guard::protected_extensions() );
		$this->assertTrue( Guard::is_protected( 'plugin', Guard::self_basename() ) );
		$this->assertFalse( Guard::is_protected( 'plugin', 'hello.php' ) );

		$filter = static function ( $entries ) {
			$entries[] = 'hello.php';

			return $entries;
		};

		add_filter( 'super_abilities_protected_extensions', $filter );

		$this->assertTrue( Guard::is_protected( 'plugin', 'hello.php' ) );

		remove_filter( 'super_abilities_protected_extensions', $filter );
	}

	public function test_capability_matrix_matches_the_type() {
		$this->assertSame( 'activate_plugins', Guard::cap_for( 'list', 'plugin' ) );
		$this->assertSame( 'switch_themes', Guard::cap_for( 'list', 'theme' ) );
		$this->assertSame( 'install_plugins', Guard::cap_for( 'install', 'plugin' ) );
		$this->assertSame( 'install_themes', Guard::cap_for( 'install', 'theme' ) );
		$this->assertSame( 'update_plugins', Guard::cap_for( 'update', 'plugin' ) );
		$this->assertSame( 'update_themes', Guard::cap_for( 'update', 'theme' ) );
		$this->assertSame( 'delete_plugins', Guard::cap_for( 'delete', 'plugin' ) );
		$this->assertSame( 'delete_themes', Guard::cap_for( 'delete', 'theme' ) );
		$this->assertSame( 'manage_options', Guard::cap_for( 'nonsense', 'plugin' ) );
	}

	public function test_require_file_mods_refuses_when_the_site_forbids_them() {
		$this->assertNull( Guard::require_file_mods( 'install', 'plugin' ) );

		$this->disallow_file_mods( 'install_plugin' );

		$denied = Guard::require_file_mods( 'install', 'plugin' );

		$this->assertWPError( $denied );
		$this->assertSame( 'super_abilities_forbidden', $denied->get_error_code() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertSame( 'install_plugin', $denied->get_error_data()['context'] );
	}
}
