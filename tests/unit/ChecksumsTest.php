<?php
/**
 * Tests for the pure checksum comparison logic.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Security\Checksums;

class ChecksumsTest extends WP_UnitTestCase {

	/**
	 * A hasher backed by an in-memory map, so no filesystem is involved.
	 *
	 * @param array<string, string> $disk Map of relative path to hash.
	 * @return callable
	 */
	private function hasher( array $disk ) {
		return static function ( $file ) use ( $disk ) {
			return isset( $disk[ $file ] ) ? $disk[ $file ] : null;
		};
	}

	private function md5_of( $value ) {
		return md5( (string) $value );
	}

	public function test_identical_files_produce_no_findings() {
		$expected = array(
			'index.php'           => $this->md5_of( 'a' ),
			'wp-settings.php'     => $this->md5_of( 'b' ),
			'wp-includes/foo.php' => $this->md5_of( 'c' ),
		);

		$result = Checksums::compare( $expected, $this->hasher( $expected ) );

		$this->assertSame( array(), $result['modified'] );
		$this->assertSame( array(), $result['missing'] );
		$this->assertSame( 3, $result['checked'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_modified_and_missing_files_are_separated() {
		$expected = array(
			'index.php'       => $this->md5_of( 'a' ),
			'wp-settings.php' => $this->md5_of( 'b' ),
			'wp-login.php'    => $this->md5_of( 'c' ),
		);

		$disk = array(
			'index.php'       => $this->md5_of( 'a' ),
			'wp-settings.php' => $this->md5_of( 'tampered' ),
		);

		$result = Checksums::compare( $expected, $this->hasher( $disk ) );

		$this->assertSame( array( 'wp-settings.php' ), $result['modified'] );
		$this->assertSame( array( 'wp-login.php' ), $result['missing'] );
		$this->assertSame( 3, $result['checked'] );
	}

	public function test_hash_comparison_ignores_case() {
		$expected = array( 'index.php' => strtoupper( $this->md5_of( 'a' ) ) );
		$disk     = array( 'index.php' => $this->md5_of( 'a' ) );

		$result = Checksums::compare( $expected, $this->hasher( $disk ) );

		$this->assertSame( array(), $result['modified'] );
	}

	public function test_skip_prefixes_are_never_checked() {
		$expected = array(
			'index.php'                          => $this->md5_of( 'a' ),
			'wp-content/themes/twenty/style.css' => $this->md5_of( 'b' ),
			'wp-content/plugins/hello.php'       => $this->md5_of( 'c' ),
		);

		$result = Checksums::compare(
			$expected,
			$this->hasher( array( 'index.php' => $this->md5_of( 'a' ) ) ),
			array( 'skip_prefixes' => array( 'wp-content/' ) )
		);

		$this->assertSame( array(), $result['missing'] );
		$this->assertSame( 1, $result['checked'] );
		$this->assertSame( 2, $result['skipped'] );
	}

	public function test_plugin_manifest_hash_shapes_are_understood() {
		$hash = $this->md5_of( 'a' );

		$expected = array(
			'readme.txt'   => array(
				'md5'    => array( $hash ),
				'sha256' => array( 'ignored' ),
			),
			'plugin.php'   => array( 'md5' => $hash ),
			'list.php'     => array( 'other', $hash ),
			'sha-only.php' => array( 'sha256' => array( 'deadbeef' ) ),
		);

		$disk = array(
			'readme.txt'   => $hash,
			'plugin.php'   => $hash,
			'list.php'     => $hash,
			'sha-only.php' => $this->md5_of( 'anything at all' ),
		);

		$result = Checksums::compare( $expected, $this->hasher( $disk ) );

		// A file the manifest publishes no md5 for cannot be judged, so it stays silent.
		$this->assertSame( array(), $result['modified'] );
		$this->assertSame( array(), $result['missing'] );
	}

	public function test_findings_are_capped_and_marked_truncated() {
		$expected = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$expected[ 'file-' . $i . '.php' ] = $this->md5_of( $i );
		}

		$result = Checksums::compare( $expected, $this->hasher( array() ), array( 'max_entries' => 4 ) );

		$this->assertCount( 4, $result['missing'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_a_spent_time_budget_truncates_immediately() {
		$expected = array( 'index.php' => $this->md5_of( 'a' ) );

		$result = Checksums::compare(
			$expected,
			$this->hasher( $expected ),
			array( 'deadline' => microtime( true ) - 1 )
		);

		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 0, $result['checked'] );
	}

	public function test_unknown_files_are_the_ones_no_manifest_lists() {
		$expected = array(
			'wp-admin/index.php' => $this->md5_of( 'a' ),
		);

		$found = array(
			'wp-admin/index.php',
			'wp-admin/shell.php',
			'wp-admin/.quarantine/payload.php',
		);

		$result = Checksums::unknown( $found, $expected );

		$this->assertSame(
			array( 'wp-admin/.quarantine/payload.php', 'wp-admin/shell.php' ),
			$result['unknown']
		);
		$this->assertFalse( $result['truncated'] );
	}

	public function test_unknown_files_honour_the_cap_and_skip_prefixes() {
		$found = array( 'wp-admin/a.php', 'wp-admin/b.php', 'wp-content/c.php' );

		$capped = Checksums::unknown( $found, array(), array( 'max_entries' => 1 ) );

		$this->assertCount( 1, $capped['unknown'] );
		$this->assertTrue( $capped['truncated'] );

		$skipped = Checksums::unknown( $found, array(), array( 'skip_prefixes' => array( 'wp-content/' ) ) );

		$this->assertSame( array( 'wp-admin/a.php', 'wp-admin/b.php' ), $skipped['unknown'] );
	}

	public function test_hash_matches_rejects_an_empty_disk_hash() {
		$this->assertFalse( Checksums::hash_matches( $this->md5_of( 'a' ), '' ) );
		$this->assertTrue( Checksums::hash_matches( array(), 'anything' ) );
	}

	public function test_sanitize_slug_keeps_real_directory_names_and_strips_separators() {
		$this->assertSame( 'classic-editor', Checksums::sanitize_slug( ' classic-editor ' ) );

		// Plugin directories on disk may be mixed case; lowercasing would lose them.
		$this->assertSame( 'My_Plugin.v2', Checksums::sanitize_slug( 'My_Plugin.v2' ) );

		// No separator may survive, so a traversal attempt cannot leave the directory.
		$this->assertSame( 'etcpasswd', Checksums::sanitize_slug( '../../etc/passwd' ) );
		$this->assertSame( 'evil', Checksums::sanitize_slug( '/evil' ) );
		$this->assertSame( '', Checksums::sanitize_slug( '..' ) );
		$this->assertSame( '', Checksums::sanitize_slug( '' ) );
	}

	public function test_scan_walks_a_directory_and_reports_relative_paths() {
		$root = get_temp_dir() . 'sa-checksums-' . wp_generate_password( 8, false );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A fixture tree for the scanner, built and removed inside the test.
		wp_mkdir_p( $root . '/nested' );
		file_put_contents( $root . '/one.php', 'one' );
		file_put_contents( $root . '/.DS_Store', 'junk' );
		file_put_contents( $root . '/nested/two.php', 'two' );

		$result = Checksums::scan( $root, 'wp-admin/' );

		sort( $result['files'] );

		$this->assertSame( array( 'wp-admin/nested/two.php', 'wp-admin/one.php' ), $result['files'] );
		$this->assertFalse( $result['truncated'] );

		wp_delete_file( $root . '/nested/two.php' );
		wp_delete_file( $root . '/.DS_Store' );
		wp_delete_file( $root . '/one.php' );
		rmdir( $root . '/nested' );
		rmdir( $root );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	public function test_scan_of_a_missing_directory_is_empty() {
		$result = Checksums::scan( get_temp_dir() . 'sa-does-not-exist-' . wp_generate_password( 8, false ), '' );

		$this->assertSame( array(), $result['files'] );
		$this->assertFalse( $result['truncated'] );
	}
}
