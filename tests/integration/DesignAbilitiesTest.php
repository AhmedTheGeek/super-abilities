<?php
/**
 * Tests for the design abilities: global styles, theme mods, templates, patterns.
 *
 * The default theme of the WordPress test suite is a classic theme, so the block
 * template tests switch to the bundled `block-theme` fixture and skip themselves
 * when no block theme is installed.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Design\Global_Styles_Read;
use SuperAbilities\Abilities\Design\Global_Styles_Write;
use SuperAbilities\Abilities\Design\Pattern_Delete;
use SuperAbilities\Abilities\Design\Pattern_Read;
use SuperAbilities\Abilities\Design\Pattern_Write;
use SuperAbilities\Abilities\Design\Patterns_List;
use SuperAbilities\Abilities\Design\Template_Read;
use SuperAbilities\Abilities\Design\Template_Reset;
use SuperAbilities\Abilities\Design\Template_Write;
use SuperAbilities\Abilities\Design\Templates_List;
use SuperAbilities\Abilities\Design\Theme_Mods_Read;
use SuperAbilities\Abilities\Design\Theme_Mods_Write;
use SuperAbilities\Design\Patterns;
use SuperAbilities\Design\Templates;
use SuperAbilities\Modules\Design_Module;
use SuperAbilities\Plugin;

class DesignAbilitiesTest extends WP_UnitTestCase {

	const PATTERN_NAME = 'super-abilities-test/one';

	const PARAGRAPH = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->";

	/**
	 * Block themes to look for, best first.
	 */
	const BLOCK_THEMES = array( 'block-theme', 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree' );

	private $admin = 0;

	private $original_theme = '';

	public function set_up() {
		parent::set_up();

		$this->admin          = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->original_theme = get_stylesheet();

		wp_set_current_user( $this->admin );

		// Nothing in this file may reach the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );

		$this->reset_theme_json_caches();

		register_block_pattern(
			self::PATTERN_NAME,
			array(
				'title'      => 'SA Test Pattern',
				'content'    => self::PARAGRAPH,
				'categories' => array( 'text' ),
				'source'     => 'plugin',
			)
		);
	}

	public function tear_down() {
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( self::PATTERN_NAME ) ) {
			unregister_block_pattern( self::PATTERN_NAME );
		}

		if ( get_stylesheet() !== $this->original_theme ) {
			switch_theme( $this->original_theme );
		}

		$this->reset_theme_json_caches();

		parent::tear_down();
	}

	public function block_http( $preempt, $args, $url ) {
		return new WP_Error( 'http_request_failed', 'Outbound HTTP is blocked in tests.' );
	}

	private function reset_theme_json_caches() {
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}

		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
	}

	/**
	 * Switches to a bundled block theme, or skips the test when there is none.
	 *
	 * @return string The stylesheet slug now active.
	 */
	private function use_block_theme() {
		foreach ( self::BLOCK_THEMES as $slug ) {
			$theme = wp_get_theme( $slug );

			if ( ! $theme->exists() || ! $theme->is_block_theme() ) {
				continue;
			}

			switch_theme( $slug );
			$this->reset_theme_json_caches();

			$this->assertTrue( wp_is_block_theme(), 'Switching to ' . $slug . ' did not produce a block theme.' );

			return $slug;
		}

		$this->markTestSkipped( 'No block theme is installed in this WordPress checkout.' );
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

		$this->assertNotWPError( $result, $ability->name() . ' returned an error.' );
		$this->assertIsArray( $result );

		return $this->assert_matches_schema( $ability, $result );
	}

	private function assert_matches_schema( Abstract_Ability $ability, $result ) {
		$schema = $ability->output_schema();

		$this->assertNotEmpty( $schema, $ability->name() . ' declares no output schema.' );

		$valid = rest_validate_value_from_schema( $result, $schema, 'output' );

		if ( is_wp_error( $valid ) ) {
			$this->fail( $ability->name() . ' output does not match its schema: ' . $valid->get_error_message() );
		}

		$this->assertTrue( $valid );

		return $result;
	}

	private function assert_error( $result, $code, $status ) {
		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_' . $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	private function make_pattern( $title = 'Stored pattern', $content = self::PARAGRAPH ) {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => Patterns::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => $this->admin,
			)
		);
	}

	/*
	 * ---------------------------------------------------------------- module
	 */

	public function test_module_declares_twelve_abilities() {
		$module = new Design_Module( Plugin::instance() );

		$this->assertSame( 'design', $module->id() );
		$this->assertSame( 'Design', $module->label() );
		$this->assertTrue( $module->default_enabled() );
		$this->assertSame( 'medium', $module->risk() );
		$this->assertCount( 12, $module->abilities() );

		$slugs = array();

		foreach ( $module->abilities() as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), $class_name . ' is missing.' );

			$ability = new $class_name();

			$this->assertInstanceOf( Abstract_Ability::class, $ability );
			$this->assertSame( 'design', $ability->module() );
			$this->assertSame( 'super-abilities-design', $ability->category() );
			$this->assertSame( '0.2.0', $ability->since() );
			$this->assertNotEmpty( $ability->capability() );
			$this->assertNotEmpty( $ability->input_schema() );
			$this->assertNotEmpty( $ability->output_schema() );
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $ability->slug() );
			$this->assertNotSame( '', $ability->summary() );

			$slugs[] = $ability->slug();
		}

		$this->assertSame( $slugs, array_unique( $slugs ) );
		$this->assertContains( 'global-styles-read', $slugs );
		$this->assertContains( 'pattern-delete', $slugs );
	}

	public function test_design_abilities_are_registered() {
		wp_get_abilities();

		$this->assertTrue( Plugin::instance()->options()->is_module_enabled( 'design' ) );
		$this->assertTrue( wp_has_ability_category( 'super-abilities-design' ) );

		foreach ( ( new Design_Module( Plugin::instance() ) )->abilities() as $class_name ) {
			$ability = new $class_name();

			$this->assertTrue( wp_has_ability( $ability->name() ), $ability->name() . ' is not registered.' );
		}
	}

	/*
	 * --------------------------------------------------------- global styles
	 */

	public function test_global_styles_read_describes_the_active_theme() {
		$result = $this->run_ability( new Global_Styles_Read() );

		$this->assertSame( get_stylesheet(), $result['theme'] );
		$this->assertIsBool( $result['is_block_theme'] );
		$this->assertIsBool( $result['has_theme_json'] );
		$this->assertNull( $result['merged'] );
		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $result['fingerprint'] );
		$this->assertIsArray( $result['settings'] );
		$this->assertIsArray( $result['styles'] );
	}

	public function test_global_styles_can_be_written_on_a_classic_theme() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'The default test theme is unexpectedly a block theme.' );
		}

		$read = $this->run_ability( new Global_Styles_Read() );

		$this->assertFalse( $read['is_block_theme'] );

		if ( null === $read['post_id'] ) {
			// Older cores refuse to create the post for a theme with no theme.json.
			$this->assert_error(
				( new Global_Styles_Write() )->execute( array( 'styles' => array( 'color' => array( 'background' => '#fff' ) ) ) ),
				'unsupported',
				501
			);

			return;
		}

		// Core creates the wp_global_styles post on demand, even for a classic theme,
		// so the document can be read and written; nothing renders it.
		$write = $this->run_ability(
			new Global_Styles_Write(),
			array(
				'styles'               => array( 'color' => array( 'background' => '#fff' ) ),
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assertTrue( $write['changed'] );
		$this->assertSame( '#fff', $write['styles']['color']['background'] );
		$this->assertSame( $read['post_id'], $write['post_id'] );
		$this->assertSame( $write['fingerprint'], $this->run_ability( new Global_Styles_Read() )['fingerprint'] );
	}

	public function test_global_styles_write_stores_the_schema_version_core_expects() {
		$read = $this->run_ability( new Global_Styles_Read() );

		if ( null === $read['post_id'] ) {
			$this->markTestSkipped( 'This core does not store global styles for the active theme.' );
		}

		$this->assertGreaterThanOrEqual( 2, $read['version'] );

		$this->run_ability(
			new Global_Styles_Write(),
			array( 'styles' => array( 'color' => array( 'text' => '#010101' ) ) )
		);

		$stored = json_decode( get_post( $read['post_id'] )->post_content, true );

		$this->assertIsArray( $stored );
		$this->assertTrue( $stored['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( $read['version'], $stored['version'] );
		$this->assertSame( '#010101', $stored['styles']['color']['text'] );
	}

	public function test_global_styles_round_trip_on_a_block_theme() {
		$this->use_block_theme();

		$read = $this->run_ability( new Global_Styles_Read(), array( 'include_merged' => true ) );

		if ( ! $read['has_theme_json'] ) {
			$this->markTestSkipped( 'WordPress reports no theme.json support for the switched theme.' );
		}

		$this->assertTrue( $read['is_block_theme'] );
		$this->assertIsInt( $read['post_id'] );
		$this->assertIsArray( $read['merged'] );
		$this->assertArrayHasKey( 'settings', $read['merged'] );

		$write = $this->run_ability(
			new Global_Styles_Write(),
			array(
				'styles'               => array( 'color' => array( 'background' => '#123456' ) ),
				'settings'             => array( 'color' => array( 'custom' => false ) ),
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assertTrue( $write['changed'] );
		$this->assertSame( 'merge', $write['mode'] );
		$this->assertSame( '#123456', $write['styles']['color']['background'] );
		$this->assertFalse( $write['settings']['color']['custom'] );
		$this->assertIsArray( $write['dropped_paths'] );
		$this->assertNotContains( 'styles.color', $write['dropped_paths'] );
		$this->assertNotContains( 'styles.color.background', $write['dropped_paths'] );
		$this->assertNotContains( 'settings.color.custom', $write['dropped_paths'] );
		$this->assertSame( $read['post_id'], $write['post_id'] );

		$again = $this->run_ability( new Global_Styles_Read() );

		$this->assertSame( '#123456', $again['styles']['color']['background'] );
		$this->assertSame( $write['fingerprint'], $again['fingerprint'] );

		// Writing the same document again changes nothing.
		$repeat = $this->run_ability(
			new Global_Styles_Write(),
			array( 'styles' => array( 'color' => array( 'background' => '#123456' ) ) )
		);

		$this->assertFalse( $repeat['changed'] );
		$this->assertSame( $write['fingerprint'], $repeat['fingerprint'] );
	}

	public function test_global_styles_merge_keeps_siblings_and_null_removes() {
		$this->use_block_theme();

		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'WordPress reports no theme.json support for the switched theme.' );
		}

		$this->run_ability(
			new Global_Styles_Write(),
			array(
				'styles' => array(
					'color' => array(
						'background' => '#111111',
						'text'       => '#eeeeee',
					),
				),
			)
		);

		$merged = $this->run_ability(
			new Global_Styles_Write(),
			array( 'styles' => array( 'color' => array( 'text' => '#dddddd' ) ) )
		);

		$this->assertSame( '#111111', $merged['styles']['color']['background'] );
		$this->assertSame( '#dddddd', $merged['styles']['color']['text'] );

		$removed = $this->run_ability(
			new Global_Styles_Write(),
			array( 'styles' => array( 'color' => array( 'text' => null ) ) )
		);

		$this->assertArrayNotHasKey( 'text', $removed['styles']['color'] );
		$this->assertSame( '#111111', $removed['styles']['color']['background'] );
	}

	public function test_global_styles_replace_mode_drops_what_it_does_not_mention() {
		$this->use_block_theme();

		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'WordPress reports no theme.json support for the switched theme.' );
		}

		$this->run_ability(
			new Global_Styles_Write(),
			array(
				'styles' => array(
					'color' => array(
						'background' => '#111111',
						'text'       => '#eeeeee',
					),
				),
			)
		);

		$result = $this->run_ability(
			new Global_Styles_Write(),
			array(
				'styles' => array( 'color' => array( 'background' => '#222222' ) ),
				'mode'   => 'replace',
			)
		);

		$this->assertSame( array( 'background' => '#222222' ), $result['styles']['color'] );
	}

	public function test_global_styles_write_reports_paths_wordpress_ignores() {
		$this->use_block_theme();

		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'WordPress reports no theme.json support for the switched theme.' );
		}

		$result = $this->run_ability(
			new Global_Styles_Write(),
			array( 'styles' => array( 'colour' => array( 'backgrnd' => '#fff' ) ) )
		);

		$this->assertContains( 'styles.colour', $result['dropped_paths'] );
	}

	public function test_global_styles_write_dry_run_changes_nothing() {
		$this->use_block_theme();

		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'WordPress reports no theme.json support for the switched theme.' );
		}

		$before = $this->run_ability( new Global_Styles_Read() );

		$result = $this->run_ability(
			new Global_Styles_Write(),
			array(
				'styles'  => array( 'color' => array( 'background' => '#abcdef' ) ),
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertTrue( $result['changed'] );
		$this->assertNull( $result['revision_id'] );

		$after = $this->run_ability( new Global_Styles_Read() );

		$this->assertSame( $before['fingerprint'], $after['fingerprint'] );
	}

	public function test_global_styles_write_rejects_a_stale_fingerprint() {
		$this->use_block_theme();

		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'WordPress reports no theme.json support for the switched theme.' );
		}

		$this->run_ability(
			new Global_Styles_Write(),
			array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) )
		);

		$result = ( new Global_Styles_Write() )->execute(
			array(
				'styles'               => array( 'color' => array( 'background' => '#202020' ) ),
				'expected_fingerprint' => 'fp1:00000000000000000000',
			)
		);

		$this->assert_error( $result, 'stale_fingerprint', 409 );
		$this->assertArrayHasKey( 'current_fingerprint', $result->get_error_data() );
	}

	public function test_global_styles_write_needs_settings_or_styles() {
		$this->assert_error( ( new Global_Styles_Write() )->execute( array() ), 'invalid_input', 400 );
	}

	public function test_global_styles_write_rejects_non_object_halves() {
		$result = ( new Global_Styles_Write() )->execute( array( 'styles' => 'not an object' ) );

		$this->assert_error( $result, 'invalid_input', 400 );
	}

	/*
	 * ------------------------------------------------------------ theme mods
	 */

	public function test_theme_mods_read_returns_the_mods_of_the_active_theme() {
		set_theme_mod( 'sa_design_colour', '#ff0000' );
		set_theme_mod( 'sa_design_list', array( 'a', 'b' ) );

		$result = $this->run_ability( new Theme_Mods_Read() );

		$this->assertSame( get_stylesheet(), $result['theme'] );
		$this->assertSame( 'theme_mods_' . get_stylesheet(), $result['option'] );
		$this->assertSame( '#ff0000', $result['mods']['sa_design_colour'] );
		$this->assertSame( array( 'a', 'b' ), $result['mods']['sa_design_list'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertSame( array(), $result['dropped_keys'] );
	}

	public function test_theme_mods_read_filters_by_key_without_changing_the_fingerprint() {
		set_theme_mod( 'sa_design_one', 1 );
		set_theme_mod( 'sa_design_two', 2 );

		$all      = $this->run_ability( new Theme_Mods_Read() );
		$filtered = $this->run_ability( new Theme_Mods_Read(), array( 'keys' => 'sa_design_one' ) );

		$this->assertSame( array( 'sa_design_one' ), array_keys( $filtered['mods'] ) );
		$this->assertSame( $all['fingerprint'], $filtered['fingerprint'], 'The fingerprint covers every mod.' );
		$this->assertSame( $all['total'], $filtered['total'] );
	}

	public function test_theme_mods_read_drops_values_that_are_not_json_safe() {
		set_theme_mod( 'sa_design_object', new stdClass() );
		set_theme_mod( 'sa_design_ok', 'fine' );

		$result = $this->run_ability( new Theme_Mods_Read() );

		$this->assertContains( 'sa_design_object', $result['dropped_keys'] );
		$this->assertArrayNotHasKey( 'sa_design_object', $result['mods'] );
		$this->assertSame( 'fine', $result['mods']['sa_design_ok'] );
	}

	public function test_theme_mods_write_sets_changes_and_removes() {
		$read = $this->run_ability( new Theme_Mods_Read() );

		$result = $this->run_ability(
			new Theme_Mods_Write(),
			array(
				'mods'                 => array(
					'sa_design_colour' => '#00ff00',
					'sa_design_flag'   => true,
				),
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assertSame( array( 'sa_design_colour', 'sa_design_flag' ), $result['changed_keys'] );
		$this->assertSame( array(), $result['removed_keys'] );
		$this->assertSame( '#00ff00', get_theme_mod( 'sa_design_colour' ) );
		$this->assertTrue( get_theme_mod( 'sa_design_flag' ) );

		// Idempotent: the same write again changes nothing.
		$repeat = $this->run_ability(
			new Theme_Mods_Write(),
			array( 'mods' => array( 'sa_design_colour' => '#00ff00' ) )
		);

		$this->assertSame( array(), $repeat['changed_keys'] );
		$this->assertSame( array( 'sa_design_colour' ), $repeat['unchanged_keys'] );
		$this->assertSame( $result['fingerprint'], $repeat['fingerprint'] );

		$removed = $this->run_ability(
			new Theme_Mods_Write(),
			array( 'mods' => array( 'sa_design_colour' => null ) )
		);

		$this->assertSame( array( 'sa_design_colour' ), $removed['removed_keys'] );
		$this->assertFalse( get_theme_mod( 'sa_design_colour' ) );

		// Removing it again is a no-op.
		$twice = $this->run_ability(
			new Theme_Mods_Write(),
			array( 'mods' => array( 'sa_design_colour' => null ) )
		);

		$this->assertSame( array(), $twice['removed_keys'] );
		$this->assertSame( array( 'sa_design_colour' ), $twice['unchanged_keys'] );
	}

	public function test_theme_mods_write_dry_run_changes_nothing() {
		$result = $this->run_ability(
			new Theme_Mods_Write(),
			array(
				'mods'    => array( 'sa_design_dry' => 'value' ),
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( array( 'sa_design_dry' ), $result['changed_keys'] );
		$this->assertFalse( get_theme_mod( 'sa_design_dry' ) );
	}

	public function test_theme_mods_write_rejects_a_stale_fingerprint() {
		set_theme_mod( 'sa_design_base', 'one' );

		$read = $this->run_ability( new Theme_Mods_Read() );

		set_theme_mod( 'sa_design_base', 'two' );

		$result = ( new Theme_Mods_Write() )->execute(
			array(
				'mods'                 => array( 'sa_design_base' => 'three' ),
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assert_error( $result, 'stale_fingerprint', 409 );
		$this->assertSame( 'two', get_theme_mod( 'sa_design_base' ) );
	}

	public function test_theme_mods_write_rejects_values_that_are_not_json_safe() {
		$result = ( new Theme_Mods_Write() )->execute( array( 'mods' => array( 'sa_design_bad' => new stdClass() ) ) );

		$this->assert_error( $result, 'invalid_input', 400 );
	}

	public function test_theme_mods_write_honours_the_protected_mods_filter() {
		add_filter(
			'super_abilities_protected_theme_mods',
			static function () {
				return array( 'custom_logo' );
			}
		);

		$result = ( new Theme_Mods_Write() )->execute( array( 'mods' => array( 'custom_logo' => 42 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_protected_theme_mod', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( get_theme_mod( 'custom_logo' ) );

		// Everything else still writes.
		$ok = $this->run_ability( new Theme_Mods_Write(), array( 'mods' => array( 'sa_design_free' => 'yes' ) ) );

		$this->assertSame( array( 'sa_design_free' ), $ok['changed_keys'] );
	}

	public function test_forbidden_block_markup_is_filterable() {
		add_filter(
			'super_abilities_forbidden_block_markup',
			static function ( $fragments ) {
				$fragments[] = '<iframe';

				return $fragments;
			}
		);

		$result = ( new Pattern_Write() )->execute(
			array(
				'title'   => 'Framed',
				'content' => '<!-- wp:html --><iframe src="https://example.com"></iframe><!-- /wp:html -->',
			)
		);

		$this->assert_error( $result, 'unsafe_content', 400 );
		$this->assertSame( '<iframe', $result->get_error_data()['fragment'] );
	}

	public function test_theme_mods_write_needs_at_least_one_mod() {
		$this->assert_error( ( new Theme_Mods_Write() )->execute( array( 'mods' => array() ) ), 'invalid_input', 400 );
	}

	/*
	 * -------------------------------------------------------------- templates
	 */

	public function test_template_abilities_are_unsupported_on_a_classic_theme() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'The default test theme is unexpectedly a block theme.' );
		}

		$this->assert_error( ( new Templates_List() )->execute( array() ), 'unsupported', 501 );
		$this->assert_error( ( new Template_Read() )->execute( array( 'id' => 'default//index' ) ), 'unsupported', 501 );
		$this->assert_error(
			( new Template_Write() )->execute(
				array(
					'id'      => 'default//index',
					'content' => self::PARAGRAPH,
				)
			),
			'unsupported',
			501
		);
		$this->assert_error( ( new Template_Reset() )->execute( array( 'id' => 'default//index' ) ), 'unsupported', 501 );
	}

	public function test_templates_list_reports_the_theme_templates() {
		$theme  = $this->use_block_theme();
		$result = $this->run_ability( new Templates_List() );

		$this->assertSame( 'wp_template', $result['type'] );
		$this->assertSame( $theme, $result['theme'] );
		$this->assertNotEmpty( $result['templates'] );
		$this->assertSame( count( $result['templates'] ), min( $result['per_page'], $result['total'] ) );

		$slugs = wp_list_pluck( $result['templates'], 'slug' );

		$this->assertContains( 'index', $slugs );

		foreach ( $result['templates'] as $template ) {
			$this->assertMatchesRegularExpression( '#^[^/]+//.+$#', $template['id'] );
			$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $template['fingerprint'] );
			$this->assertArrayNotHasKey( 'content', $template );
		}
	}

	public function test_templates_list_filters_and_paginates() {
		$this->use_block_theme();

		$searched = $this->run_ability( new Templates_List(), array( 'search' => 'index' ) );

		$this->assertNotEmpty( $searched['templates'] );

		foreach ( $searched['templates'] as $template ) {
			$this->assertStringContainsStringIgnoringCase( 'index', $template['slug'] . ' ' . $template['title'] );
		}

		$paged = $this->run_ability(
			new Templates_List(),
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);

		$this->assertCount( 1, $paged['templates'] );
		$this->assertSame( 1, $paged['per_page'] );
		$this->assertSame( (int) ceil( $paged['total'] / 1 ), $paged['total_pages'] );

		$theme_only = $this->run_ability( new Templates_List(), array( 'source' => 'theme' ) );

		foreach ( $theme_only['templates'] as $template ) {
			$this->assertSame( 'theme', $template['source'] );
		}
	}

	public function test_templates_list_reads_template_parts() {
		$this->use_block_theme();

		$result = $this->run_ability( new Templates_List(), array( 'type' => 'wp_template_part' ) );

		$this->assertSame( 'wp_template_part', $result['type'] );

		foreach ( $result['templates'] as $part ) {
			$this->assertSame( 'wp_template_part', $part['type'] );
		}
	}

	public function test_template_read_returns_markup_and_a_block_summary() {
		$theme = $this->use_block_theme();

		$result = $this->run_ability( new Template_Read(), array( 'id' => $theme . '//index' ) );

		$this->assertSame( $theme . '//index', $result['id'] );
		$this->assertSame( 'index', $result['slug'] );
		$this->assertSame( 'theme', $result['source'] );
		$this->assertTrue( $result['has_theme_file'] );
		$this->assertNull( $result['post_id'] );
		$this->assertNotSame( '', $result['content'] );
		$this->assertGreaterThan( 0, $result['block_count'] );
		$this->assertNotEmpty( $result['blocks'] );
	}

	public function test_template_read_rejects_bad_ids_and_missing_templates() {
		$theme = $this->use_block_theme();

		$this->assert_error( ( new Template_Read() )->execute( array( 'id' => 'nonsense' ) ), 'invalid_input', 400 );
		$this->assert_error( ( new Template_Read() )->execute( array( 'id' => $theme . '//no-such-template' ) ), 'not_found', 404 );
	}

	public function test_template_write_creates_then_updates_the_override() {
		$theme = $this->use_block_theme();
		$id    = $theme . '//index';

		$read = $this->run_ability( new Template_Read(), array( 'id' => $id ) );

		$content = "<!-- wp:paragraph -->\n<p>Agent wrote this</p>\n<!-- /wp:paragraph -->";

		$created = $this->run_ability(
			new Template_Write(),
			array(
				'id'                   => $id,
				'content'              => $content,
				'title'                => 'Index, customized',
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assertTrue( $created['created'] );
		$this->assertTrue( $created['changed'] );
		$this->assertIsInt( $created['post_id'] );
		$this->assertSame( 'custom', $created['source'] );
		$this->assertSame( 1, $created['block_count'] );

		$after = $this->run_ability( new Template_Read(), array( 'id' => $id ) );

		$this->assertSame( $content, $after['content'] );
		$this->assertSame( 'custom', $after['source'] );
		$this->assertTrue( $after['has_theme_file'] );
		$this->assertSame( $created['post_id'], $after['post_id'] );
		$this->assertSame( 'Index, customized', $after['title'] );

		$updated = $this->run_ability(
			new Template_Write(),
			array(
				'id'                   => $id,
				'content'              => self::PARAGRAPH,
				'expected_fingerprint' => $after['fingerprint'],
			)
		);

		$this->assertFalse( $updated['created'] );
		$this->assertTrue( $updated['changed'] );
		$this->assertSame( $created['post_id'], $updated['post_id'] );

		// Idempotent: the same content again reports no change.
		$repeat = $this->run_ability(
			new Template_Write(),
			array(
				'id'      => $id,
				'content' => self::PARAGRAPH,
			)
		);

		$this->assertFalse( $repeat['changed'] );
		$this->assertSame( $updated['fingerprint'], $repeat['fingerprint'] );
	}

	public function test_template_write_dry_run_creates_nothing() {
		$theme = $this->use_block_theme();
		$id    = $theme . '//index';

		$result = $this->run_ability(
			new Template_Write(),
			array(
				'id'      => $id,
				'content' => self::PARAGRAPH,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertTrue( $result['created'] );
		$this->assertNull( $result['post_id'] );

		$after = $this->run_ability( new Template_Read(), array( 'id' => $id ) );

		$this->assertSame( 'theme', $after['source'] );
	}

	public function test_template_write_refuses_unsafe_and_malformed_markup() {
		$theme = $this->use_block_theme();
		$id    = $theme . '//index';

		$this->assert_error(
			( new Template_Write() )->execute(
				array(
					'id'      => $id,
					'content' => '<script>alert(1)</script>',
				)
			),
			'unsafe_content',
			400
		);

		$this->assert_error(
			( new Template_Write() )->execute(
				array(
					'id'      => $id,
					'content' => '<?php wp_die(); ?>',
				)
			),
			'unsafe_content',
			400
		);

		$this->assertSame( 'theme', $this->run_ability( new Template_Read(), array( 'id' => $id ) )['source'] );
	}

	public function test_template_write_rejects_a_stale_fingerprint() {
		$theme = $this->use_block_theme();
		$id    = $theme . '//index';

		$result = ( new Template_Write() )->execute(
			array(
				'id'                   => $id,
				'content'              => self::PARAGRAPH,
				'expected_fingerprint' => 'fp1:00000000000000000000',
			)
		);

		$this->assert_error( $result, 'stale_fingerprint', 409 );
		$this->assertSame( 'theme', $this->run_ability( new Template_Read(), array( 'id' => $id ) )['source'] );
	}

	public function test_template_reset_deletes_the_override_and_restores_the_theme_file() {
		$theme = $this->use_block_theme();
		$id    = $theme . '//index';

		$created = $this->run_ability(
			new Template_Write(),
			array(
				'id'      => $id,
				'content' => self::PARAGRAPH,
			)
		);

		$result = $this->run_ability( new Template_Reset(), array( 'id' => $id ) );

		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( $result['trashed'] );
		$this->assertTrue( $result['reverted_to_theme'] );
		$this->assertSame( 'theme', $result['source'] );
		$this->assertNull( get_post( $created['post_id'] ) );

		// Idempotent: resetting a theme template again does nothing.
		$again = $this->run_ability( new Template_Reset(), array( 'id' => $id ) );

		$this->assertFalse( $again['deleted'] );
		$this->assertFalse( $again['trashed'] );
		$this->assertTrue( $again['reverted_to_theme'] );
	}

	public function test_template_reset_trashes_a_template_with_no_theme_file() {
		$theme = $this->use_block_theme();
		$slug  = 'sa-design-only-in-db';

		$post_id = Templates::create_override(
			array(
				'type'    => 'wp_template',
				'theme'   => $theme,
				'slug'    => $slug,
				'title'   => 'Database only',
				'content' => self::PARAGRAPH,
			)
		);

		$this->assertIsInt( $post_id );

		$result = $this->run_ability( new Template_Reset(), array( 'id' => $theme . '//' . $slug ) );

		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( $result['trashed'] );
		$this->assertFalse( $result['reverted_to_theme'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	public function test_template_part_write_creates_an_override_with_an_area() {
		$theme = $this->use_block_theme();
		$parts = $this->run_ability( new Templates_List(), array( 'type' => 'wp_template_part' ) );

		if ( empty( $parts['templates'] ) ) {
			$this->markTestSkipped( 'The block theme ships no template parts.' );
		}

		$part = $parts['templates'][0];

		$result = $this->run_ability(
			new Template_Write(),
			array(
				'id'      => $part['id'],
				'type'    => 'wp_template_part',
				'content' => self::PARAGRAPH,
			)
		);

		$this->assertIsInt( $result['post_id'] );

		$after = $this->run_ability(
			new Template_Read(),
			array(
				'id'   => $part['id'],
				'type' => 'wp_template_part',
			)
		);

		$this->assertSame( 'custom', $after['source'] );
		$this->assertNotNull( $after['area'] );
		$this->assertSame( $theme, $after['theme'] );
	}

	/*
	 * --------------------------------------------------------------- patterns
	 */

	public function test_patterns_list_includes_registered_and_user_patterns() {
		$post_id = $this->make_pattern( 'SA User Pattern' );

		$result = $this->run_ability( new Patterns_List(), array( 'search' => 'SA ' ) );

		$this->assertGreaterThanOrEqual( 1, $result['total_registered'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_user'] );
		$this->assertSame( $result['total'], $result['total_registered'] + $result['total_user'] );

		$names = wp_list_pluck( $result['patterns'], 'name' );
		$ids   = wp_list_pluck( $result['patterns'], 'id' );

		$this->assertContains( self::PATTERN_NAME, $names );
		$this->assertContains( $post_id, $ids );

		foreach ( $result['patterns'] as $pattern ) {
			$this->assertContains( $pattern['source'], Patterns::SOURCES );
			$this->assertArrayNotHasKey( 'content', $pattern );
		}
	}

	public function test_patterns_list_filters_by_source() {
		$post_id = $this->make_pattern( 'SA Only User' );

		$result = $this->run_ability( new Patterns_List(), array( 'source' => 'user' ) );

		$this->assertSame( 0, $result['total_registered'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_user'] );
		$this->assertContains( $post_id, wp_list_pluck( $result['patterns'], 'id' ) );

		foreach ( $result['patterns'] as $pattern ) {
			$this->assertSame( 'user', $pattern['source'] );
			$this->assertSame( 'synced', $pattern['sync'] );
		}
	}

	public function test_pattern_read_by_registered_name() {
		$result = $this->run_ability( new Pattern_Read(), array( 'name' => self::PATTERN_NAME ) );

		$this->assertSame( self::PATTERN_NAME, $result['name'] );
		$this->assertNull( $result['id'] );
		$this->assertSame( 'plugin', $result['source'] );
		$this->assertSame( self::PARAGRAPH, $result['content'] );
		$this->assertSame( 1, $result['block_count'] );
		$this->assertSame( 'core/paragraph', $result['blocks'][0]['name'] );
		$this->assertTrue( $result['inserter'] );
	}

	public function test_pattern_read_by_post_id() {
		$post_id = $this->make_pattern( 'Readable pattern' );

		$result = $this->run_ability( new Pattern_Read(), array( 'id' => $post_id ) );

		$this->assertSame( $post_id, $result['id'] );
		$this->assertNull( $result['name'] );
		$this->assertSame( 'user', $result['source'] );
		$this->assertSame( 'synced', $result['sync'] );
		$this->assertSame( 'Readable pattern', $result['title'] );
		$this->assertNotSame( '', $result['modified'] );
	}

	public function test_pattern_read_validates_its_input() {
		$this->assert_error( ( new Pattern_Read() )->execute( array() ), 'invalid_input', 400 );
		$this->assert_error(
			( new Pattern_Read() )->execute(
				array(
					'name' => self::PATTERN_NAME,
					'id'   => 1,
				)
			),
			'invalid_input',
			400
		);
		$this->assert_error( ( new Pattern_Read() )->execute( array( 'name' => 'nope/nope' ) ), 'not_found', 404 );
		$this->assert_error( ( new Pattern_Read() )->execute( array( 'id' => 999999 ) ), 'not_found', 404 );
	}

	public function test_pattern_read_refuses_a_post_that_is_not_a_pattern() {
		$post_id = self::factory()->post->create();

		$this->assert_error( ( new Pattern_Read() )->execute( array( 'id' => $post_id ) ), 'not_found', 404 );
	}

	public function test_pattern_write_creates_updates_and_switches_sync() {
		$created = $this->run_ability(
			new Pattern_Write(),
			array(
				'title'   => 'Agent pattern',
				'content' => self::PARAGRAPH,
				'sync'    => 'unsynced',
			)
		);

		$this->assertTrue( $created['created'] );
		$this->assertIsInt( $created['id'] );
		$this->assertSame( 'unsynced', $created['sync'] );
		$this->assertSame( 1, $created['block_count'] );
		$this->assertSame( 'unsynced', get_post_meta( $created['id'], Patterns::SYNC_META, true ) );
		$this->assertSame( Patterns::POST_TYPE, get_post_type( $created['id'] ) );

		$read = $this->run_ability( new Pattern_Read(), array( 'id' => $created['id'] ) );

		$this->assertSame( $created['fingerprint'], $read['fingerprint'] );

		$updated = $this->run_ability(
			new Pattern_Write(),
			array(
				'id'                   => $created['id'],
				'title'                => 'Agent pattern, renamed',
				'content'              => "<!-- wp:heading -->\n<h2>Now a heading</h2>\n<!-- /wp:heading -->",
				'sync'                 => 'synced',
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assertFalse( $updated['created'] );
		$this->assertTrue( $updated['changed'] );
		$this->assertSame( 'synced', $updated['sync'] );
		$this->assertSame( 'Agent pattern, renamed', get_post( $created['id'] )->post_title );
		$this->assertSame( '', get_post_meta( $created['id'], Patterns::SYNC_META, true ), 'A synced pattern stores no meta.' );

		// Idempotent: the same update again reports no change.
		$repeat = $this->run_ability(
			new Pattern_Write(),
			array(
				'id'      => $created['id'],
				'content' => "<!-- wp:heading -->\n<h2>Now a heading</h2>\n<!-- /wp:heading -->",
			)
		);

		$this->assertFalse( $repeat['changed'] );
		$this->assertSame( $updated['fingerprint'], $repeat['fingerprint'] );
	}

	public function test_pattern_write_dry_run_creates_nothing() {
		$before = wp_count_posts( Patterns::POST_TYPE )->publish;

		$result = $this->run_ability(
			new Pattern_Write(),
			array(
				'title'   => 'Never stored',
				'content' => self::PARAGRAPH,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertTrue( $result['created'] );
		$this->assertNull( $result['id'] );
		$this->assertSame( $before, wp_count_posts( Patterns::POST_TYPE )->publish );
	}

	public function test_pattern_write_validates_its_input() {
		$this->assert_error( ( new Pattern_Write() )->execute( array( 'content' => self::PARAGRAPH ) ), 'invalid_input', 400 );
		$this->assert_error( ( new Pattern_Write() )->execute( array( 'title' => 'No content' ) ), 'invalid_input', 400 );
		$this->assert_error(
			( new Pattern_Write() )->execute(
				array(
					'title'   => 'Unsafe',
					'content' => '<script>alert(1)</script>',
				)
			),
			'unsafe_content',
			400
		);
		$this->assert_error( ( new Pattern_Write() )->execute( array( 'id' => 999999 ) ), 'not_found', 404 );
	}

	public function test_pattern_write_rejects_a_stale_fingerprint() {
		$post_id = $this->make_pattern( 'Guarded pattern' );

		$result = ( new Pattern_Write() )->execute(
			array(
				'id'                   => $post_id,
				'content'              => '<!-- wp:separator /-->',
				'expected_fingerprint' => 'fp1:00000000000000000000',
			)
		);

		$this->assert_error( $result, 'stale_fingerprint', 409 );
		$this->assertSame( self::PARAGRAPH, get_post( $post_id )->post_content );
	}

	public function test_pattern_delete_trashes_then_deletes() {
		$post_id = $this->make_pattern( 'Doomed pattern' );

		$trashed = $this->run_ability( new Pattern_Delete(), array( 'id' => $post_id ) );

		$this->assertTrue( $trashed['trashed'] );
		$this->assertFalse( $trashed['deleted'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );

		// Idempotent: trashing again reports the same state.
		$again = $this->run_ability( new Pattern_Delete(), array( 'id' => $post_id ) );

		$this->assertTrue( $again['trashed'] );
		$this->assertFalse( $again['deleted'] );

		$deleted = $this->run_ability(
			new Pattern_Delete(),
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);

		$this->assertTrue( $deleted['deleted'] );
		$this->assertFalse( $deleted['trashed'] );
		$this->assertNull( get_post( $post_id ) );
	}

	public function test_pattern_delete_refuses_a_post_that_is_not_a_pattern() {
		$post_id = self::factory()->post->create();

		$this->assert_error( ( new Pattern_Delete() )->execute( array( 'id' => $post_id ) ), 'not_found', 404 );
	}

	/*
	 * ------------------------------------------------------------ permissions
	 */

	public function test_every_ability_refuses_an_anonymous_caller() {
		wp_set_current_user( 0 );

		foreach ( ( new Design_Module( Plugin::instance() ) )->abilities() as $class_name ) {
			$ability = new $class_name();
			$result  = $ability->check_permission( array() );

			$this->assertWPError( $result, $ability->name() . ' allowed an anonymous caller.' );
			$this->assertSame( 'super_abilities_forbidden', $result->get_error_code() );
			$this->assertSame( 'not_logged_in', $result->get_error_data()['reason'] );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
	}

	public function test_a_subscriber_is_refused_every_ability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( ( new Design_Module( Plugin::instance() ) )->abilities() as $class_name ) {
			$ability = new $class_name();
			$result  = $ability->check_permission( array() );

			$this->assertWPError( $result, $ability->name() . ' allowed a subscriber.' );
			$this->assertSame( 'super_abilities_forbidden', $result->get_error_code() );
			$this->assertSame( 'insufficient_capability', $result->get_error_data()['reason'] );
		}
	}

	public function test_an_administrator_passes_every_capability_gate() {
		foreach ( ( new Design_Module( Plugin::instance() ) )->abilities() as $class_name ) {
			$ability = new $class_name();

			$this->assertTrue( $ability->check_permission( array() ), $ability->name() . ' refused an administrator.' );
		}
	}

	public function test_a_contributor_may_read_patterns_but_not_create_them() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertTrue( ( new Patterns_List() )->check_permission( array() ) );
		$this->assertTrue( ( new Pattern_Read() )->check_permission( array( 'name' => self::PATTERN_NAME ) ) );

		$result = ( new Pattern_Write() )->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_an_author_may_not_edit_or_delete_someone_elses_pattern() {
		$post_id = $this->make_pattern( 'Admin owned' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$write = ( new Pattern_Write() )->check_permission( array( 'id' => $post_id ) );

		$this->assertWPError( $write );
		$this->assertSame( 'super_abilities_forbidden', $write->get_error_code() );

		$delete = ( new Pattern_Delete() )->check_permission( array( 'id' => $post_id ) );

		$this->assertWPError( $delete );
		$this->assertSame( 'super_abilities_forbidden', $delete->get_error_code() );

		// The author may still create their own.
		$this->assertTrue( ( new Pattern_Write() )->check_permission( array() ) );
	}

	/**
	 * Guards the guard: proves the schema assertion above can actually fail.
	 */
	public function test_schema_validation_rejects_wrong_output() {
		$ability = new Theme_Mods_Read();
		$schema  = $ability->output_schema();
		$result  = $ability->execute( array() );

		$this->assertTrue( rest_validate_value_from_schema( $result, $schema, 'output' ) );

		$wrong          = $result;
		$wrong['total'] = array( 'nope' );

		$this->assertWPError( rest_validate_value_from_schema( $wrong, $schema, 'output' ) );

		$extra             = $result;
		$extra['surprise'] = true;

		$this->assertWPError( rest_validate_value_from_schema( $extra, $schema, 'output' ) );

		$missing = $result;
		unset( $missing['fingerprint'] );

		$this->assertWPError( rest_validate_value_from_schema( $missing, $schema, 'output' ) );
	}
}
