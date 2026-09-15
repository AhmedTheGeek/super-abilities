<?php
/**
 * Tests for the blocks module: every ability, its permissions and its output schema.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Abilities\Blocks\Blocks_Find;
use SuperAbilities\Abilities\Blocks\Blocks_Insert;
use SuperAbilities\Abilities\Blocks\Blocks_Move;
use SuperAbilities\Abilities\Blocks\Blocks_Read;
use SuperAbilities\Abilities\Blocks\Blocks_Remove;
use SuperAbilities\Abilities\Blocks\Blocks_Render;
use SuperAbilities\Abilities\Blocks\Blocks_Replace_Text;
use SuperAbilities\Abilities\Blocks\Blocks_Update;
use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Modules\Blocks_Module;
use SuperAbilities\Plugin;

class BlocksAbilitiesTest extends WP_UnitTestCase {

	/**
	 * A group holding a paragraph and two columns, followed by a top level paragraph.
	 */
	const NESTED = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Right</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group --><!-- wp:paragraph {"align":"center"} --><p>Tail</p><!-- /wp:paragraph -->';

	private $admin_id = 0;

	private $post_id = 0;

	public static $shortcode_runs = 0;

	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->admin_id );

		$this->post_id = self::factory()->post->create(
			array(
				'post_author'  => $this->admin_id,
				'post_status'  => 'publish',
				'post_content' => self::NESTED,
			)
		);

		self::$shortcode_runs = 0;
	}

	/**
	 * Runs an ability and asserts the result validates against its own output schema.
	 *
	 * @param Abstract_Ability     $ability Ability instance.
	 * @param array<string, mixed> $input   Input to pass.
	 * @return array<string, mixed>
	 */
	private function run_ability( Abstract_Ability $ability, array $input ) {
		$result = $ability->execute( $input );

		$this->assertNotWPError( $result, $ability->name() . ' returned an error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->assertIsArray( $result );

		$schema = $ability->output_schema();

		$this->assertNotEmpty( $schema, $ability->name() . ' declares no output schema.' );

		$valid = rest_validate_value_from_schema( $result, $schema, 'output' );

		if ( is_wp_error( $valid ) ) {
			$this->fail( $ability->name() . ' output does not match its schema: ' . $valid->get_error_message() );
		}

		$this->assertTrue( $valid );

		return $result;
	}

	private function content() {
		return get_post( $this->post_id )->post_content;
	}

	private function fingerprint() {
		return Block_Tree::parse( $this->content() )->fingerprint();
	}

	private function paths() {
		return wp_list_pluck( Block_Tree::parse( $this->content() )->summary(), 'path' );
	}

	private function names() {
		return wp_list_pluck( Block_Tree::parse( $this->content() )->summary(), 'name' );
	}

	private function revision_count() {
		return count( wp_get_post_revisions( $this->post_id, array( 'fields' => 'ids' ) ) );
	}

	private function all_abilities() {
		return array(
			new Blocks_Read(),
			new Blocks_Find(),
			new Blocks_Update(),
			new Blocks_Insert(),
			new Blocks_Remove(),
			new Blocks_Move(),
			new Blocks_Render(),
			new Blocks_Replace_Text(),
		);
	}

	public static function record_shortcode() {
		++self::$shortcode_runs;

		return 'SHORTCODE RAN';
	}

	/*
	 * Module and registration.
	 */

	public function test_module_shape() {
		$module = new Blocks_Module( Plugin::instance() );

		$this->assertSame( 'blocks', $module->id() );
		$this->assertSame( 'Block editing', $module->label() );
		$this->assertSame( 'medium', $module->risk() );
		$this->assertTrue( $module->default_enabled() );
		$this->assertCount( 8, $module->abilities() );

		foreach ( $module->abilities() as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), $class_name . ' does not exist.' );

			$ability = new $class_name();

			$this->assertInstanceOf( Abstract_Ability::class, $ability );
			$this->assertSame( 'blocks', $ability->module() );
			$this->assertSame( '0.2.0', $ability->since() );
			$this->assertSame( array( 'edit_posts' ), $ability->capability() );
			$this->assertMatchesRegularExpression( '/^blocks-[a-z-]+$/', $ability->slug() );
			$this->assertNotEmpty( $ability->input_schema() );
			$this->assertNotEmpty( $ability->output_schema() );
		}
	}

	public function test_annotations_match_what_each_ability_does() {
		$expected = array(
			'blocks-read'         => array( true, false, true ),
			'blocks-find'         => array( true, false, true ),
			'blocks-render'       => array( true, false, true ),
			'blocks-update'       => array( false, false, true ),
			'blocks-move'         => array( false, false, true ),
			'blocks-replace-text' => array( false, false, true ),
			'blocks-insert'       => array( false, false, false ),
			'blocks-remove'       => array( false, true, true ),
		);

		foreach ( $this->all_abilities() as $ability ) {
			$annotations = $ability->annotations();
			$want        = $expected[ $ability->slug() ];

			$this->assertSame( $want[0], $annotations['readonly'], $ability->slug() . ' readonly' );
			$this->assertSame( $want[1], $annotations['destructive'], $ability->slug() . ' destructive' );
			$this->assertSame( $want[2], $annotations['idempotent'], $ability->slug() . ' idempotent' );
		}
	}

	public function test_abilities_are_registered_while_the_module_is_enabled() {
		$this->assertTrue( Plugin::instance()->options()->is_module_enabled( 'blocks' ) );

		wp_get_abilities();

		$this->assertTrue( wp_has_ability_category( 'super-abilities-blocks' ) );

		foreach ( $this->all_abilities() as $ability ) {
			$this->assertTrue( wp_has_ability( $ability->name() ), $ability->name() . ' is not registered.' );

			$registered = wp_get_ability( $ability->name() );

			$this->assertNotNull( $registered );
			$this->assertSame( 'super-abilities-blocks', $registered->get_category() );
		}
	}

	/*
	 * blocks-read.
	 */

	public function test_read_reports_every_path_and_a_fingerprint() {
		$result = $this->run_ability( new Blocks_Read(), array( 'post_id' => $this->post_id ) );

		$this->assertSame( $this->post_id, $result['post_id'] );
		$this->assertSame( 'post', $result['post_type'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( '', $result['path'] );
		$this->assertSame( 3, $result['depth'] );
		$this->assertSame( $this->fingerprint(), $result['fingerprint'] );
		$this->assertSame( 8, $result['count'] );

		$this->assertSame(
			array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0', '1' ),
			wp_list_pluck( $result['flat'], 'path' )
		);

		$this->assertSame( 'core/group', $result['blocks'][0]['name'] );
		$this->assertSame( array( 'align' => 'center' ), $result['blocks'][1]['attrs'] );
		$this->assertArrayNotHasKey( 'inner_html', $result['blocks'][0] );

		// Depth 3 reaches the columns' children but not their paragraphs.
		$this->assertSame( '0.1.0', $result['blocks'][0]['inner_blocks'][1]['inner_blocks'][0]['path'] );
		$this->assertSame( array(), $result['blocks'][0]['inner_blocks'][1]['inner_blocks'][0]['inner_blocks'] );
		$this->assertSame( 1, $result['blocks'][0]['inner_blocks'][1]['inner_blocks'][0]['inner_blocks_count'] );
	}

	public function test_read_a_subtree_with_raw_markup_and_no_attrs() {
		$result = $this->run_ability(
			new Blocks_Read(),
			array(
				'post_id'       => $this->post_id,
				'path'          => '0.1',
				'depth'         => 0,
				'include_raw'   => true,
				'include_attrs' => false,
			)
		);

		$this->assertSame( '0.1', $result['path'] );
		$this->assertSame( 5, $result['count'] );
		$this->assertCount( 1, $result['blocks'] );
		$this->assertSame( 'core/columns', $result['blocks'][0]['name'] );
		$this->assertSame( '<div class="wp-block-columns"></div>', $result['blocks'][0]['inner_html'] );
		$this->assertSame( array( '<div class="wp-block-columns">', null, null, '</div>' ), $result['blocks'][0]['inner_content'] );
		$this->assertArrayNotHasKey( 'attrs', $result['blocks'][0] );
		$this->assertSame( '0.1.1.0', $result['flat'][4]['path'] );
	}

	public function test_read_an_unknown_path_is_a_404() {
		$error = ( new Blocks_Read() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '9',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_not_found', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
	}

	public function test_read_a_classic_post_reports_one_freeform_block() {
		$classic = self::factory()->post->create(
			array(
				'post_author'  => $this->admin_id,
				'post_content' => '<p>Written before blocks.</p>',
			)
		);

		$result = $this->run_ability(
			new Blocks_Read(),
			array(
				'post_id'     => $classic,
				'include_raw' => true,
			)
		);

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( '0', $result['blocks'][0]['path'] );
		$this->assertSame( Block_Tree::FREEFORM, $result['blocks'][0]['name'] );
		$this->assertSame( '<p>Written before blocks.</p>', $result['blocks'][0]['inner_html'] );
	}

	public function test_writes_work_on_a_classic_post() {
		$classic = self::factory()->post->create(
			array(
				'post_author'  => $this->admin_id,
				'post_content' => '<p>Written before blocks.</p>',
			)
		);

		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $classic,
				'search'  => 'before blocks',
				'replace' => 'with the classic editor',
			)
		);

		$this->assertSame( 1, $result['replacements'] );
		$this->assertSame( array( '0' ), $result['changed_paths'] );
		$this->assertSame( '<p>Written with the classic editor.</p>', get_post( $classic )->post_content );

		$updated = $this->run_ability(
			new Blocks_Update(),
			array(
				'post_id'    => $classic,
				'path'       => '0',
				'inner_html' => '<p>Rewritten.</p>',
			)
		);

		$this->assertTrue( $updated['changed'] );
		$this->assertSame( '<p>Rewritten.</p>', get_post( $classic )->post_content );
	}

	/*
	 * blocks-find.
	 */

	public function test_find_by_name_exactly_and_by_prefix() {
		$result = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => 'core/paragraph',
			)
		);

		$this->assertSame( 4, $result['count'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( array( '0.0', '0.1.0.0', '0.1.1.0', '1' ), wp_list_pluck( $result['matches'], 'path' ) );
		$this->assertSame( 'Hello', $result['matches'][0]['excerpt'] );

		$all = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => 'core/*',
			)
		);

		$this->assertSame( 8, $all['count'] );

		$every = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => '*',
			)
		);

		$this->assertSame( 8, $every['count'] );

		$none = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => 'acme/hero',
			)
		);

		$this->assertSame( 0, $none['count'] );
		$this->assertSame( array(), $none['matches'] );
	}

	public function test_find_by_attribute_and_by_text() {
		$by_attr = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => 'core/paragraph',
				'attr'    => 'align',
				'value'   => 'center',
			)
		);

		$this->assertSame( array( '1' ), wp_list_pluck( $by_attr['matches'], 'path' ) );
		$this->assertSame( array( 'align' => 'center' ), $by_attr['matches'][0]['attrs'] );

		$wrong_value = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => 'core/paragraph',
				'attr'    => 'align',
				'value'   => 'left',
			)
		);

		$this->assertSame( 0, $wrong_value['count'] );

		$by_text = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => '*',
				'text'    => 'right',
			)
		);

		// `innerHTML` is a block's own markup, so a parent does not match on the text of
		// its children: only the paragraph that actually holds the word does.
		$this->assertSame( array( '0.1.1.0' ), wp_list_pluck( $by_text['matches'], 'path' ) );
	}

	public function test_find_respects_the_limit() {
		$result = $this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => '*',
				'limit'   => 2,
			)
		);

		$this->assertSame( 2, $result['count'] );
		$this->assertTrue( $result['truncated'] );
	}

	/*
	 * blocks-update.
	 */

	public function test_update_merges_attributes_and_removes_them_with_null() {
		$before    = $this->revision_count();
		$before_fp = $this->fingerprint();

		$result = $this->run_ability(
			new Blocks_Update(),
			array(
				'post_id'              => $this->post_id,
				'path'                 => '1',
				'attrs'                => array(
					'align'     => null,
					'dropCap'   => true,
					'className' => 'lead',
				),
				'expected_fingerprint' => $before_fp,
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( $before_fp, $result['fingerprint_before'] );
		$this->assertSame( $this->fingerprint(), $result['fingerprint_after'] );
		$this->assertNotSame( $result['fingerprint_before'], $result['fingerprint_after'] );
		$this->assertSame( '1', $result['path'] );
		$this->assertSame( 'core/paragraph', $result['block']['name'] );

		$block = Block_Tree::parse( $this->content() )->get( '1' );

		$this->assertSame(
			array(
				'dropCap'   => true,
				'className' => 'lead',
			),
			$block['attrs']
		);

		// The rest of the tree is untouched and the markup still parses the same way.
		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0', '1' ), $this->paths() );

		// A revision exists for the edit.
		$this->assertGreaterThan( $before, $this->revision_count() );
		$this->assertGreaterThan( 0, $result['revision_id'] );
		$this->assertSame( 'revision', get_post( $result['revision_id'] )->post_type );
	}

	public function test_update_replaces_a_whole_block() {
		$result = $this->run_ability(
			new Blocks_Update(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0.1',
				'block'   => array(
					'name'         => 'core/quote',
					'attrs'        => array( 'className' => 'is-style-large' ),
					'inner_html'   => '<blockquote class="wp-block-quote"></blockquote>',
					'inner_blocks' => array(
						array(
							'name'       => 'core/paragraph',
							'inner_html' => '<p>Quoted</p>',
						),
					),
				),
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '1' ), $this->paths() );
		$this->assertSame( array( 'core/group', 'core/paragraph', 'core/quote', 'core/paragraph', 'core/paragraph' ), $this->names() );

		$this->assertStringContainsString(
			'<!-- wp:quote {"className":"is-style-large"} --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
			$this->content()
		);
	}

	public function test_update_rewrites_markup_and_keeps_the_children() {
		$result = $this->run_ability(
			new Blocks_Update(),
			array(
				'post_id'    => $this->post_id,
				'path'       => '0',
				'inner_html' => '<div class="wp-block-group alignwide"></div>',
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0', '1' ), $this->paths() );
		$this->assertStringContainsString( '<div class="wp-block-group alignwide">', $this->content() );
		$this->assertSame( '<p>Hello</p>', Block_Tree::parse( $this->content() )->get( '0.0' )['innerHTML'] );
	}

	public function test_update_needs_exactly_one_of_block_attrs_or_inner_html() {
		$neither = ( new Blocks_Update() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
			)
		);

		$this->assertWPError( $neither );
		$this->assertSame( 'super_abilities_invalid_input', $neither->get_error_code() );

		$both = ( new Blocks_Update() )->execute(
			array(
				'post_id'    => $this->post_id,
				'path'       => '1',
				'attrs'      => array( 'dropCap' => true ),
				'inner_html' => '<p>No</p>',
			)
		);

		$this->assertWPError( $both );
		$this->assertSame( 'super_abilities_invalid_input', $both->get_error_code() );
		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_update_refuses_scripts_php_and_script_urls() {
		$script = ( new Blocks_Update() )->execute(
			array(
				'post_id'    => $this->post_id,
				'path'       => '1',
				'inner_html' => '<p>Hi<script>alert(1)</script></p>',
			)
		);

		$this->assertWPError( $script );
		$this->assertSame( 'super_abilities_unsafe_content', $script->get_error_code() );
		$this->assertSame( 400, $script->get_error_data()['status'] );

		$php = ( new Blocks_Update() )->execute(
			array(
				'post_id'    => $this->post_id,
				'path'       => '1',
				'inner_html' => '<p><?php echo 1; ?></p>',
			)
		);

		$this->assertWPError( $php );
		$this->assertSame( 'super_abilities_unsafe_content', $php->get_error_code() );

		$url = ( new Blocks_Update() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
				'attrs'   => array( 'href' => 'javascript:alert(1)' ),
			)
		);

		$this->assertWPError( $url );
		$this->assertSame( 'super_abilities_unsafe_content', $url->get_error_code() );

		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_update_refuses_an_unknown_block_name_only_when_strict() {
		$strict = ( new Blocks_Update() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
				'block'   => array( 'name' => 'acme/not-registered' ),
				'strict'  => true,
			)
		);

		$this->assertWPError( $strict );
		$this->assertSame( 'super_abilities_unknown_block', $strict->get_error_code() );
		$this->assertSame( self::NESTED, $this->content() );

		$loose = $this->run_ability(
			new Blocks_Update(),
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
				'block'   => array( 'name' => 'acme/not-registered' ),
			)
		);

		$this->assertTrue( $loose['changed'] );
		$this->assertSame( 'acme/not-registered', $loose['block']['name'] );
	}

	public function test_a_stale_fingerprint_is_refused_with_409() {
		$stale = Block_Tree::parse( '<!-- wp:paragraph --><p>Something else</p><!-- /wp:paragraph -->' )->fingerprint();

		foreach (
			array(
				array(
					new Blocks_Update(),
					array(
						'path'  => '1',
						'attrs' => array( 'dropCap' => true ),
					),
				),
				array(
					new Blocks_Insert(),
					array(
						'path'  => '1',
						'block' => array( 'name' => 'core/separator' ),
					),
				),
				array( new Blocks_Remove(), array( 'path' => '1' ) ),
				array(
					new Blocks_Move(),
					array(
						'from'     => '1',
						'to'       => '0',
						'position' => 'before',
					),
				),
				array(
					new Blocks_Replace_Text(),
					array(
						'search'  => 'Hello',
						'replace' => 'Hi',
					),
				),
			) as $case
		) {
			list( $ability, $input ) = $case;

			$error = $ability->execute(
				array_merge(
					$input,
					array(
						'post_id'              => $this->post_id,
						'expected_fingerprint' => $stale,
					)
				)
			);

			$this->assertWPError( $error, $ability->slug() . ' accepted a stale fingerprint.' );
			$this->assertSame( 'super_abilities_stale_fingerprint', $error->get_error_code() );
			$this->assertSame( 409, $error->get_error_data()['status'] );
			$this->assertSame( $this->fingerprint(), $error->get_error_data()['current_fingerprint'] );
		}

		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_dry_run_changes_nothing() {
		$revisions = $this->revision_count();

		$cases = array(
			array(
				new Blocks_Update(),
				array(
					'path'  => '1',
					'attrs' => array( 'dropCap' => true ),
				),
			),
			array(
				new Blocks_Insert(),
				array(
					'path'  => '1',
					'block' => array( 'name' => 'core/separator' ),
				),
			),
			array( new Blocks_Remove(), array( 'paths' => array( '0.1.0', '1' ) ) ),
			array(
				new Blocks_Move(),
				array(
					'from'     => '1',
					'to'       => '0',
					'position' => 'prepend',
				),
			),
			array(
				new Blocks_Replace_Text(),
				array(
					'search'  => 'Hello',
					'replace' => 'Hi',
				),
			),
		);

		foreach ( $cases as $case ) {
			list( $ability, $input ) = $case;

			$result = $this->run_ability(
				$ability,
				array_merge(
					$input,
					array(
						'post_id' => $this->post_id,
						'dry_run' => true,
					)
				)
			);

			$this->assertTrue( $result['dry_run'], $ability->slug() . ' did not report a dry run.' );
			$this->assertTrue( $result['changed'], $ability->slug() . ' reported no change during a dry run.' );
			$this->assertSame( 0, $result['revision_id'], $ability->slug() . ' reported a revision for a dry run.' );
			$this->assertNotSame( $result['fingerprint_before'], $result['fingerprint_after'], $ability->slug() . ' reported the same fingerprint twice.' );
			$this->assertSame( self::NESTED, $this->content(), $ability->slug() . ' wrote during a dry run.' );
		}

		$this->assertSame( $revisions, $this->revision_count() );
	}

	public function test_an_unchanged_write_reports_no_change_and_no_revision() {
		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => 'nothing matches this',
				'replace' => 'x',
			)
		);

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 0, $result['replacements'] );
		$this->assertSame( 0, $result['revision_id'] );
		$this->assertSame( $result['fingerprint_before'], $result['fingerprint_after'] );
		$this->assertSame( self::NESTED, $this->content() );
	}

	/*
	 * blocks-insert.
	 */

	public function test_insert_a_block_after_an_anchor() {
		$result = $this->run_ability(
			new Blocks_Insert(),
			array(
				'post_id'  => $this->post_id,
				'path'     => '0.0',
				'position' => 'after',
				'block'    => array(
					'name'       => 'core/heading',
					'attrs'      => array( 'level' => 2 ),
					'inner_html' => '<h2>Inserted</h2>',
				),
			)
		);

		$this->assertSame( array( '0.1' ), $result['paths'] );
		$this->assertSame( 1, $result['inserted'] );
		$this->assertSame( 'after', $result['position'] );
		$this->assertSame( '0.0', $result['anchor'] );
		$this->assertSame( 'core/heading', $result['blocks'][0]['name'] );
		$this->assertGreaterThan( 0, $result['revision_id'] );

		$this->assertSame( array( '0', '0.0', '0.1', '0.2', '0.2.0', '0.2.0.0', '0.2.1', '0.2.1.0', '1' ), $this->paths() );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} --><h2>Inserted</h2><!-- /wp:heading -->', $this->content() );
	}

	public function test_insert_several_blocks_from_markup() {
		$result = $this->run_ability(
			new Blocks_Insert(),
			array(
				'post_id'  => $this->post_id,
				'path'     => '0.1.0',
				'position' => 'append',
				'markup'   => "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->",
			)
		);

		$this->assertSame( array( '0.1.0.1', '0.1.0.2' ), $result['paths'] );
		$this->assertSame( 2, $result['inserted'] );

		$tree = Block_Tree::parse( $this->content() );

		$this->assertSame( '<p>One</p>', $tree->get( '0.1.0.1' )['innerHTML'] );
		$this->assertSame( '<p>Two</p>', $tree->get( '0.1.0.2' )['innerHTML'] );
		$this->assertSame( '<p>Left</p>', $tree->get( '0.1.0.0' )['innerHTML'] );
	}

	public function test_insert_at_the_root_without_a_path() {
		$result = $this->run_ability(
			new Blocks_Insert(),
			array(
				'post_id'  => $this->post_id,
				'position' => 'prepend',
				'block'    => array( 'name' => 'core/separator' ),
			)
		);

		$this->assertSame( array( '0' ), $result['paths'] );
		$this->assertSame( '', $result['anchor'] );
		$this->assertSame( array( 'core/separator', 'core/group', 'core/paragraph', 'core/columns', 'core/column', 'core/paragraph', 'core/column', 'core/paragraph', 'core/paragraph' ), $this->names() );
	}

	public function test_insert_needs_exactly_one_of_block_or_markup() {
		foreach (
			array(
				array( 'post_id' => $this->post_id ),
				array(
					'post_id' => $this->post_id,
					'block'   => array( 'name' => 'core/separator' ),
					'markup'  => '<!-- wp:separator /-->',
				),
			) as $input
		) {
			$error = ( new Blocks_Insert() )->execute( $input );

			$this->assertWPError( $error );
			$this->assertSame( 'super_abilities_invalid_input', $error->get_error_code() );
		}

		$empty = ( new Blocks_Insert() )->execute(
			array(
				'post_id' => $this->post_id,
				'markup'  => "\n \n",
			)
		);

		$this->assertWPError( $empty );
		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_insert_refuses_unsafe_markup() {
		$error = ( new Blocks_Insert() )->execute(
			array(
				'post_id' => $this->post_id,
				'markup'  => '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_unsafe_content', $error->get_error_code() );
		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_insert_against_an_unknown_anchor_is_a_404() {
		$error = ( new Blocks_Insert() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '7',
				'block'   => array( 'name' => 'core/separator' ),
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_not_found', $error->get_error_code() );
	}

	/*
	 * blocks-remove.
	 */

	public function test_remove_one_block() {
		$result = $this->run_ability(
			new Blocks_Remove(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0.1.0',
			)
		);

		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( array( '0.1.0' ), $result['removed_paths'] );
		$this->assertSame( array(), $result['missing'] );
		$this->assertSame( 6, $result['count'] );
		$this->assertGreaterThan( 0, $result['revision_id'] );

		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '1' ), $this->paths() );
		$this->assertSame( '<p>Right</p>', Block_Tree::parse( $this->content() )->get( '0.1.0.0' )['innerHTML'] );
		$this->assertStringContainsString( '<div class="wp-block-columns">', $this->content() );
	}

	public function test_remove_several_blocks_deepest_first() {
		$result = $this->run_ability(
			new Blocks_Remove(),
			array(
				'post_id' => $this->post_id,
				'paths'   => array( '0.0', '0.1.1', '1' ),
			)
		);

		$this->assertSame( 3, $result['removed'] );
		$this->assertSame( array( '1', '0.1.1', '0.0' ), $result['removed_paths'] );
		$this->assertSame( array( '0', '0.0', '0.0.0', '0.0.0.0' ), $this->paths() );
		$this->assertSame( '<p>Left</p>', Block_Tree::parse( $this->content() )->get( '0.0.0.0' )['innerHTML'] );
	}

	public function test_remove_reports_paths_that_addressed_nothing() {
		$result = $this->run_ability(
			new Blocks_Remove(),
			array(
				'post_id' => $this->post_id,
				'paths'   => array( '1', '9' ),
			)
		);

		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( array( '9' ), $result['missing'] );
		$this->assertTrue( $result['changed'] );
	}

	public function test_remove_needs_a_path_and_rejects_a_malformed_one() {
		$none = ( new Blocks_Remove() )->execute( array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $none );
		$this->assertSame( 'super_abilities_invalid_input', $none->get_error_code() );

		$bad = ( new Blocks_Remove() )->execute(
			array(
				'post_id' => $this->post_id,
				'paths'   => array( '0', '1.' ),
			)
		);

		$this->assertWPError( $bad );
		$this->assertSame( 'super_abilities_invalid_input', $bad->get_error_code() );
		$this->assertSame( self::NESTED, $this->content() );
	}

	/*
	 * blocks-move.
	 */

	public function test_move_a_block_into_a_column() {
		$result = $this->run_ability(
			new Blocks_Move(),
			array(
				'post_id'  => $this->post_id,
				'from'     => '1',
				'to'       => '0.1.0',
				'position' => 'append',
			)
		);

		$this->assertSame( '0.1.0.1', $result['path'] );
		$this->assertSame( '1', $result['from'] );
		$this->assertSame( '0.1.0', $result['to'] );
		$this->assertSame( 'append', $result['position'] );
		$this->assertSame( 'core/paragraph', $result['block']['name'] );
		$this->assertSame( array( 'align' => 'center' ), $result['block']['attrs'] );

		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.0.1', '0.1.1', '0.1.1.0' ), $this->paths() );
		$this->assertSame( '<p>Tail</p>', Block_Tree::parse( $this->content() )->get( '0.1.0.1' )['innerHTML'] );
	}

	public function test_move_to_the_top_level() {
		$result = $this->run_ability(
			new Blocks_Move(),
			array(
				'post_id'  => $this->post_id,
				'from'     => '0.1.1.0',
				'position' => 'append',
			)
		);

		$this->assertSame( '2', $result['path'] );
		$this->assertSame( '', $result['to'] );
		$this->assertSame( '<p>Right</p>', Block_Tree::parse( $this->content() )->get( '2' )['innerHTML'] );
	}

	public function test_move_into_its_own_subtree_is_refused() {
		foreach ( array( '0.1', '0.1.0', '0.1.1.0' ) as $to ) {
			$error = ( new Blocks_Move() )->execute(
				array(
					'post_id'  => $this->post_id,
					'from'     => '0.1',
					'to'       => $to,
					'position' => 'append',
				)
			);

			$this->assertWPError( $error, "Allowed a move into the subtree at {$to}." );
			$this->assertSame( 'super_abilities_invalid_move', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		}

		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_move_from_or_to_an_unknown_path_is_a_404() {
		foreach (
			array(
				array(
					'from' => '9',
					'to'   => '0',
				),
				array(
					'from' => '0',
					'to'   => '9',
				),
			) as $input
		) {
			$error = ( new Blocks_Move() )->execute( array_merge( $input, array( 'post_id' => $this->post_id ) ) );

			$this->assertWPError( $error );
			$this->assertSame( 'super_abilities_not_found', $error->get_error_code() );
		}

		$this->assertSame( self::NESTED, $this->content() );
	}

	/*
	 * blocks-render.
	 */

	public function test_render_a_paragraph_as_html_and_as_text() {
		$html = $this->run_ability(
			new Blocks_Render(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0.0',
			)
		);

		$this->assertSame( 'html', $html['format'] );
		$this->assertSame( 'post', $html['source'] );
		$this->assertSame( '0.0', $html['path'] );
		// The paragraph block adds its own class in current WordPress, so match the text.
		$this->assertMatchesRegularExpression( '#<p[^>]*>Hello</p>#', $html['html'] );
		$this->assertSame( '', $html['text'] );
		$this->assertSame( strlen( $html['html'] ), $html['length'] );
		$this->assertFalse( $html['truncated'] );

		$text = $this->run_ability(
			new Blocks_Render(),
			array(
				'post_id' => $this->post_id,
				'format'  => 'text',
			)
		);

		$this->assertSame( 'text', $text['format'] );
		$this->assertSame( '', $text['html'] );
		$this->assertStringContainsString( 'Hello', $text['text'] );
		$this->assertStringContainsString( 'Tail', $text['text'] );
		$this->assertStringNotContainsString( '<p>', $text['text'] );
	}

	public function test_render_arbitrary_markup() {
		$result = $this->run_ability(
			new Blocks_Render(),
			array(
				'post_id' => $this->post_id,
				'markup'  => '<!-- wp:paragraph --><p>From markup</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( 'markup', $result['source'] );
		$this->assertSame( '', $result['path'] );
		$this->assertMatchesRegularExpression( '#<p[^>]*>From markup</p>#', $result['html'] );
		$this->assertStringNotContainsString( 'Hello', $result['html'] );
	}

	public function test_render_never_runs_shortcodes() {
		add_shortcode( 'sa_probe', array( __CLASS__, 'record_shortcode' ) );

		$result = $this->run_ability(
			new Blocks_Render(),
			array(
				'post_id' => $this->post_id,
				'markup'  => '<!-- wp:shortcode -->[sa_probe]<!-- /wp:shortcode -->',
			)
		);

		remove_shortcode( 'sa_probe' );

		$this->assertSame( 0, self::$shortcode_runs );
		$this->assertStringNotContainsString( 'SHORTCODE RAN', $result['html'] );
	}

	public function test_render_restores_the_global_post() {
		$other = self::factory()->post->create( array( 'post_author' => $this->admin_id ) );

		$GLOBALS['post'] = get_post( $other );

		$this->run_ability(
			new Blocks_Render(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0.0',
			)
		);

		$this->assertSame( $other, $GLOBALS['post']->ID );

		unset( $GLOBALS['post'] );
	}

	public function test_render_caps_its_output_and_says_so() {
		$long = self::factory()->post->create(
			array(
				'post_author'  => $this->admin_id,
				'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'long ', 60000 ) . '</p><!-- /wp:paragraph -->',
			)
		);

		$result = $this->run_ability( new Blocks_Render(), array( 'post_id' => $long ) );

		$this->assertTrue( $result['truncated'] );
		$this->assertSame( Blocks_Render::MAX_BYTES, $result['length'] );
		$this->assertSame( Blocks_Render::MAX_BYTES, strlen( $result['html'] ) );
	}

	public function test_render_an_unknown_path_is_a_404() {
		$error = ( new Blocks_Render() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '9',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_not_found', $error->get_error_code() );
	}

	/*
	 * blocks-replace-text.
	 */

	public function test_replace_text_across_the_whole_post() {
		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => 'hello',
				'replace' => 'Goodbye',
			)
		);

		$this->assertSame( 1, $result['replacements'] );
		$this->assertSame( array( '0.0' ), $result['changed_paths'] );
		$this->assertSame( '', $result['path'] );
		$this->assertTrue( $result['changed'] );
		$this->assertGreaterThan( 0, $result['revision_id'] );
		$this->assertStringContainsString( '<p>Goodbye</p>', $this->content() );
		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0', '1' ), $this->paths() );
	}

	public function test_replace_text_is_case_sensitive_on_request() {
		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id'        => $this->post_id,
				'search'         => 'hello',
				'replace'        => 'Goodbye',
				'case_sensitive' => true,
			)
		);

		$this->assertSame( 0, $result['replacements'] );
		$this->assertFalse( $result['changed'] );
	}

	public function test_replace_text_inside_one_subtree_only() {
		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0.1.1',
				'search'  => 'Right',
				'replace' => 'Starboard',
			)
		);

		$this->assertSame( 1, $result['replacements'] );
		$this->assertSame( array( '0.1.1.0' ), $result['changed_paths'] );
		$this->assertStringContainsString( '<p>Starboard</p>', $this->content() );

		$missed = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0.1.1',
				'search'  => 'Left',
				'replace' => 'Port',
			)
		);

		$this->assertSame( 0, $missed['replacements'] );
		$this->assertStringContainsString( '<p>Left</p>', $this->content() );
	}

	public function test_replace_text_never_touches_markup_or_attributes() {
		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => 'wp-block-group',
				'replace' => 'broken',
			)
		);

		$this->assertSame( 0, $result['replacements'] );
		$this->assertSame( self::NESTED, $this->content() );

		$attrs = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => 'center',
				'replace' => 'left',
			)
		);

		$this->assertSame( 0, $attrs['replacements'] );
		$this->assertSame( self::NESTED, $this->content() );

		$delimiters = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => 'wp:paragraph',
				'replace' => 'wp:nonsense',
			)
		);

		$this->assertSame( 0, $delimiters['replacements'] );
		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_replace_text_with_a_regular_expression() {
		$result = $this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => '(Le)(ft)',
				'replace' => '$2$1',
				'regex'   => true,
			)
		);

		$this->assertSame( 1, $result['replacements'] );
		$this->assertStringContainsString( '<p>ftLe</p>', $this->content() );
	}

	public function test_replace_text_refuses_a_broken_or_overlong_pattern() {
		$broken = ( new Blocks_Replace_Text() )->execute(
			array(
				'post_id' => $this->post_id,
				'search'  => '(unclosed',
				'replace' => 'x',
				'regex'   => true,
			)
		);

		$this->assertWPError( $broken );
		$this->assertSame( 'super_abilities_invalid_input', $broken->get_error_code() );

		$long = ( new Blocks_Replace_Text() )->execute(
			array(
				'post_id' => $this->post_id,
				'search'  => str_repeat( 'a', 201 ),
				'replace' => 'x',
				'regex'   => true,
			)
		);

		$this->assertWPError( $long );
		$this->assertSame( 'super_abilities_invalid_input', $long->get_error_code() );
		$this->assertSame( self::NESTED, $this->content() );
	}

	public function test_replace_text_refuses_an_unsafe_replacement() {
		$error = ( new Blocks_Replace_Text() )->execute(
			array(
				'post_id' => $this->post_id,
				'search'  => 'Hello',
				'replace' => '<script>alert(1)</script>',
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_unsafe_content', $error->get_error_code() );
		$this->assertSame( self::NESTED, $this->content() );
	}

	/*
	 * Locks, permissions and unknown posts.
	 */

	public function test_a_post_another_user_is_editing_is_locked() {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );

		update_post_meta( $this->post_id, '_edit_lock', time() . ':' . $other );

		$error = ( new Blocks_Update() )->execute(
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
				'attrs'   => array( 'dropCap' => true ),
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'super_abilities_locked', $error->get_error_code() );
		$this->assertSame( 423, $error->get_error_data()['status'] );
		$this->assertSame( $other, $error->get_error_data()['locked_by'] );
		$this->assertSame( self::NESTED, $this->content() );

		$forced = $this->run_ability(
			new Blocks_Update(),
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
				'attrs'   => array( 'dropCap' => true ),
				'force'   => true,
			)
		);

		$this->assertTrue( $forced['changed'] );
	}

	public function test_the_callers_own_lock_does_not_block_them() {
		update_post_meta( $this->post_id, '_edit_lock', time() . ':' . $this->admin_id );

		$result = $this->run_ability(
			new Blocks_Remove(),
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
			)
		);

		$this->assertSame( 1, $result['removed'] );
	}

	public function test_reads_are_never_blocked_by_a_lock() {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );

		update_post_meta( $this->post_id, '_edit_lock', time() . ':' . $other );

		$this->run_ability( new Blocks_Read(), array( 'post_id' => $this->post_id ) );
	}

	public function test_an_unknown_post_is_a_404_everywhere() {
		$missing = $this->post_id + 100000;

		foreach ( $this->all_abilities() as $ability ) {
			$error = $ability->execute(
				array(
					'post_id' => $missing,
					'path'    => '0',
					'from'    => '0',
					'search'  => 'a',
					'replace' => 'b',
					'name'    => '*',
					'block'   => array( 'name' => 'core/separator' ),
				)
			);

			$this->assertWPError( $error, $ability->slug() . ' accepted an unknown post.' );
			$this->assertSame( 'super_abilities_not_found', $error->get_error_code() );
			$this->assertSame( 404, $error->get_error_data()['status'] );

			// The permission callback refuses it too, before execute is ever reached.
			$denied = $ability->check_permission( array( 'post_id' => $missing ) );

			$this->assertWPError( $denied, $ability->slug() . ' let an unknown post through the permission check.' );
			$this->assertSame( 'super_abilities_not_found', $denied->get_error_code() );
		}
	}

	public function test_a_subscriber_is_denied_every_ability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$denials = array();

		add_action(
			'super_abilities_permission_denied',
			static function ( $name, $input, $code ) use ( &$denials ) {
				$denials[] = $code;
			},
			10,
			3
		);

		foreach ( $this->all_abilities() as $ability ) {
			$denied = $ability->check_permission( array( 'post_id' => $this->post_id ) );

			$this->assertWPError( $denied, $ability->slug() . ' allowed a subscriber.' );
			$this->assertSame( 'super_abilities_forbidden', $denied->get_error_code() );
			$this->assertSame( 403, $denied->get_error_data()['status'] );
			$this->assertSame( 'insufficient_capability', $denied->get_error_data()['reason'] );
			$this->assertSame( 'edit_posts', $denied->get_error_data()['required_capability'] );
		}

		$this->assertCount( 8, $denials );
		$this->assertSame( array( 'insufficient_capability' ), array_values( array_unique( $denials ) ) );
	}

	public function test_an_anonymous_caller_is_denied_every_ability() {
		wp_set_current_user( 0 );

		foreach ( $this->all_abilities() as $ability ) {
			$denied = $ability->check_permission( array( 'post_id' => $this->post_id ) );

			$this->assertWPError( $denied, $ability->slug() . ' allowed an anonymous caller.' );
			$this->assertSame( 'not_logged_in', $denied->get_error_data()['reason'] );
		}
	}

	public function test_a_contributor_may_read_but_not_edit_someone_elses_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertTrue( ( new Blocks_Read() )->check_permission( array( 'post_id' => $this->post_id ) ) );
		$this->assertTrue( ( new Blocks_Find() )->check_permission( array( 'post_id' => $this->post_id ) ) );
		$this->assertTrue( ( new Blocks_Render() )->check_permission( array( 'post_id' => $this->post_id ) ) );

		// The raw markup of a post they cannot edit is not theirs to read.
		$raw = ( new Blocks_Read() )->check_permission(
			array(
				'post_id'     => $this->post_id,
				'include_raw' => true,
			)
		);

		$this->assertWPError( $raw );
		$this->assertSame( 'super_abilities_forbidden', $raw->get_error_code() );

		foreach ( array( new Blocks_Update(), new Blocks_Insert(), new Blocks_Remove(), new Blocks_Move(), new Blocks_Replace_Text() ) as $ability ) {
			$denied = $ability->check_permission( array( 'post_id' => $this->post_id ) );

			$this->assertWPError( $denied, $ability->slug() . ' allowed a contributor to edit another author\'s post.' );
			$this->assertSame( 'super_abilities_forbidden', $denied->get_error_code() );
		}
	}

	public function test_a_private_post_is_hidden_from_a_contributor() {
		$private = self::factory()->post->create(
			array(
				'post_author'  => $this->admin_id,
				'post_status'  => 'private',
				'post_content' => self::NESTED,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$denied = ( new Blocks_Read() )->check_permission( array( 'post_id' => $private ) );

		$this->assertWPError( $denied );
		$this->assertSame( 'super_abilities_forbidden', $denied->get_error_code() );
	}

	public function test_every_write_notes_the_post_it_touched() {
		$noted = array();

		add_action(
			'super_abilities_note_object',
			static function ( $name, $type, $id ) use ( &$noted ) {
				$noted[] = array( $type, (int) $id );
			},
			10,
			3
		);

		$this->run_ability(
			new Blocks_Update(),
			array(
				'post_id' => $this->post_id,
				'path'    => '1',
				'attrs'   => array( 'dropCap' => true ),
			)
		);

		$this->assertContains( array( 'post', $this->post_id ), $noted );
	}

	public function test_a_sequence_of_edits_keeps_the_markup_parseable() {
		$read = $this->run_ability( new Blocks_Read(), array( 'post_id' => $this->post_id ) );

		$inserted = $this->run_ability(
			new Blocks_Insert(),
			array(
				'post_id'              => $this->post_id,
				'path'                 => '0.1',
				'position'             => 'prepend',
				'markup'               => '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>New column</p><!-- /wp:paragraph --></div><!-- /wp:column -->',
				'expected_fingerprint' => $read['fingerprint'],
			)
		);

		$this->assertSame( array( '0.1.0' ), $inserted['paths'] );

		$moved = $this->run_ability(
			new Blocks_Move(),
			array(
				'post_id'              => $this->post_id,
				'from'                 => '0.1.0',
				'to'                   => '0.1.2',
				'position'             => 'after',
				'expected_fingerprint' => $inserted['fingerprint_after'],
			)
		);

		$this->assertSame( '0.1.2', $moved['path'] );

		$removed = $this->run_ability(
			new Blocks_Remove(),
			array(
				'post_id'              => $this->post_id,
				'path'                 => '0.0',
				'expected_fingerprint' => $moved['fingerprint_after'],
			)
		);

		$this->assertSame( 1, $removed['removed'] );

		$final = $this->run_ability(
			new Blocks_Read(),
			array(
				'post_id' => $this->post_id,
				'depth'   => 0,
			)
		);

		$this->assertSame(
			array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.1', '0.0.1.0', '0.0.2', '0.0.2.0', '1' ),
			wp_list_pluck( $final['flat'], 'path' )
		);

		$this->assertSame( '<p>New column</p>', Block_Tree::parse( $this->content() )->get( '0.0.2.0' )['innerHTML'] );
		$this->assertSame( $removed['fingerprint_after'], $final['fingerprint'] );
	}

	public function test_every_ability_validates_against_its_own_output_schema() {
		$this->run_ability( new Blocks_Read(), array( 'post_id' => $this->post_id ) );
		$this->run_ability(
			new Blocks_Find(),
			array(
				'post_id' => $this->post_id,
				'name'    => '*',
			)
		);
		$this->run_ability(
			new Blocks_Render(),
			array(
				'post_id' => $this->post_id,
			)
		);
		$this->run_ability(
			new Blocks_Insert(),
			array(
				'post_id' => $this->post_id,
				'block'   => array( 'name' => 'core/separator' ),
			)
		);
		$this->run_ability(
			new Blocks_Update(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0',
				'attrs'   => array( 'className' => 'x' ),
			)
		);
		$this->run_ability(
			new Blocks_Move(),
			array(
				'post_id'  => $this->post_id,
				'from'     => '0',
				'to'       => '1',
				'position' => 'after',
			)
		);
		$this->run_ability(
			new Blocks_Replace_Text(),
			array(
				'post_id' => $this->post_id,
				'search'  => 'Hello',
				'replace' => 'Hi',
			)
		);
		$this->run_ability(
			new Blocks_Remove(),
			array(
				'post_id' => $this->post_id,
				'path'    => '0',
			)
		);
	}
}
