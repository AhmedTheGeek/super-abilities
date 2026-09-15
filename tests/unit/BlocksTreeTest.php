<?php
/**
 * Tests for the block tree helper: paths, innerContent rebuilding and editing.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Blocks\Block_Tree;

class BlocksTreeTest extends WP_UnitTestCase {

	/**
	 * A group holding a paragraph and two columns, followed by a top level paragraph.
	 */
	const NESTED = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Right</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group --><!-- wp:paragraph {"align":"center"} --><p>Tail</p><!-- /wp:paragraph -->';

	private function paths( Block_Tree $tree ) {
		return wp_list_pluck( $tree->summary(), 'path' );
	}

	private function names( Block_Tree $tree ) {
		return wp_list_pluck( $tree->summary(), 'name' );
	}

	/**
	 * Asserts that serialized markup parses back into the same paths and names.
	 */
	private function assert_round_trips( Block_Tree $tree ) {
		$again = Block_Tree::parse( $tree->serialize() );

		$this->assertSame( $this->paths( $tree ), $this->paths( $again ), 'Paths changed after a serialize and parse round trip.' );
		$this->assertSame( $this->names( $tree ), $this->names( $again ), 'Names changed after a serialize and parse round trip.' );
		$this->assertSame( $tree->serialize(), $again->serialize(), 'Markup is not stable across a round trip.' );

		return $again;
	}

	public function test_path_validation() {
		$this->assertTrue( Block_Tree::is_path( '0' ) );
		$this->assertTrue( Block_Tree::is_path( '12' ) );
		$this->assertTrue( Block_Tree::is_path( '2.1.0' ) );

		foreach ( array( '', '.', '1.', '.1', '1..2', 'a', '1.a', '-1', '1,2', ' 1', '1 ' ) as $bad ) {
			$this->assertFalse( Block_Tree::is_path( $bad ), "Accepted the invalid path '{$bad}'." );
		}

		$this->assertFalse( Block_Tree::is_path( 1 ) );
		$this->assertFalse( Block_Tree::is_path( null ) );
	}

	public function test_parse_indexes_every_block_depth_first() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertSame(
			array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0', '1' ),
			$this->paths( $tree )
		);

		$this->assertSame(
			array( 'core/group', 'core/paragraph', 'core/columns', 'core/column', 'core/paragraph', 'core/column', 'core/paragraph', 'core/paragraph' ),
			$this->names( $tree )
		);

		$this->assertSame( 8, $tree->size() );
		$this->assertSame( self::NESTED, $tree->serialize() );
	}

	public function test_summary_reports_attrs_lengths_and_child_counts() {
		$tree  = Block_Tree::parse( self::NESTED );
		$flat  = $tree->summary();
		$by_id = array();

		foreach ( $flat as $entry ) {
			$by_id[ $entry['path'] ] = $entry;
		}

		$this->assertSame( 2, $by_id['0']['inner_blocks'] );
		$this->assertSame( 0, $by_id['0.0']['inner_blocks'] );
		$this->assertSame( array( 'align' => 'center' ), $by_id['1']['attrs'] );
		$this->assertSame( strlen( '<p>Hello</p>' ), $by_id['0.0']['inner_html_length'] );
		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $by_id['0']['fingerprint'] );

		// A subtree summary keeps the absolute paths and starts at its own root.
		$subtree = $tree->summary( '0.1' );

		$this->assertSame( array( '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0' ), wp_list_pluck( $subtree, 'path' ) );
	}

	public function test_get_walks_into_inner_blocks() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertSame( 'core/columns', $tree->get( '0.1' )['blockName'] );
		$this->assertSame( '<p>Right</p>', $tree->get( '0.1.1.0' )['innerHTML'] );
		$this->assertNull( $tree->get( '0.1.2' ) );
		$this->assertNull( $tree->get( '9' ) );
		$this->assertNull( $tree->get( 'nope' ) );
	}

	public function test_freeform_blocks_are_indexed_and_named() {
		$tree = Block_Tree::parse( '<p>Just classic HTML</p>' );

		$this->assertSame( array( '0' ), $this->paths( $tree ) );
		$this->assertSame( array( Block_Tree::FREEFORM ), $this->names( $tree ) );
		$this->assertNull( $tree->get( '0' )['blockName'] );

		// Serializing a freeform fragment adds no block delimiters.
		$this->assertSame( '<p>Just classic HTML</p>', $tree->serialize() );

		$mixed = Block_Tree::parse( "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->" );

		$this->assertSame(
			array( 'core/paragraph', Block_Tree::FREEFORM, 'core/paragraph' ),
			$this->names( $mixed )
		);
	}

	public function test_set_replaces_a_block_in_place() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertTrue(
			$tree->set(
				'0.0',
				Block_Tree::from_input(
					array(
						'name'       => 'core/heading',
						'attrs'      => array( 'level' => 3 ),
						'inner_html' => '<h3>Changed</h3>',
					)
				)
			)
		);

		$this->assertSame( 'core/heading', $tree->get( '0.0' )['blockName'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} --><h3>Changed</h3><!-- /wp:heading -->', $tree->serialize() );
		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.1', '0.1.1.0', '1' ), $this->paths( $tree ) );

		$this->assert_round_trips( $tree );
		$this->assertFalse( $tree->set( '5', array( 'blockName' => 'core/paragraph' ) ) );
	}

	public function test_insert_after_a_sibling_shifts_the_later_paths() {
		$tree = Block_Tree::parse( self::NESTED );

		$path = $tree->insert( '0.0', Block_Tree::from_input( array( 'name' => 'core/separator' ) ), 'after' );

		$this->assertSame( '0.1', $path );
		$this->assertSame( 'core/separator', $tree->get( '0.1' )['blockName'] );
		$this->assertSame( 'core/columns', $tree->get( '0.2' )['blockName'] );

		// The group's innerContent must now hold three placeholders inside its wrapper.
		$group = $tree->get( '0' );

		$this->assertSame(
			array( '<div class="wp-block-group">', null, null, null, '</div>' ),
			$group['innerContent']
		);

		$this->assert_round_trips( $tree );
	}

	public function test_insert_before_prepend_and_append() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertSame( '0', $tree->insert( '0', Block_Tree::from_input( array( 'name' => 'core/spacer' ) ), 'before' ) );
		$this->assertSame( 'core/spacer', $tree->get( '0' )['blockName'] );
		$this->assertSame( 'core/group', $tree->get( '1' )['blockName'] );

		$this->assertSame( '1.0', $tree->insert( '1', Block_Tree::from_input( array( 'name' => 'core/code' ) ), 'prepend' ) );
		$this->assertSame( 'core/code', $tree->get( '1.0' )['blockName'] );
		$this->assertSame( 'core/paragraph', $tree->get( '1.1' )['blockName'] );

		$this->assertSame( '1.3', $tree->insert( '1', Block_Tree::from_input( array( 'name' => 'core/quote' ) ), 'append' ) );
		$this->assertSame( 'core/quote', $tree->get( '1.3' )['blockName'] );

		$this->assert_round_trips( $tree );
	}

	public function test_insert_into_the_root_list() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertSame( array( '2' ), $tree->insert_many( '', array( Block_Tree::from_input( array( 'name' => 'core/separator' ) ) ), 'append' ) );
		$this->assertSame( array( '0' ), $tree->insert_many( null, array( Block_Tree::from_input( array( 'name' => 'core/spacer' ) ) ), 'prepend' ) );

		$this->assertSame( 'core/spacer', $tree->get( '0' )['blockName'] );
		$this->assertSame( 'core/separator', $tree->get( '3' )['blockName'] );

		$this->assert_round_trips( $tree );
	}

	public function test_insert_many_keeps_the_order_and_reports_every_path() {
		$tree = Block_Tree::parse( self::NESTED );

		$paths = $tree->insert_many(
			'0.1',
			Block_Tree::parse_fragment( "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->" ),
			'before'
		);

		$this->assertSame( array( '0.1', '0.2' ), $paths );
		$this->assertSame( '<p>One</p>', $tree->get( '0.1' )['innerHTML'] );
		$this->assertSame( '<p>Two</p>', $tree->get( '0.2' )['innerHTML'] );
		$this->assertSame( 'core/columns', $tree->get( '0.3' )['blockName'] );

		$this->assert_round_trips( $tree );
	}

	public function test_insert_into_an_empty_wrapper_keeps_the_wrapper() {
		$tree = Block_Tree::parse( '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->' );

		$this->assertSame(
			'0.0',
			$tree->insert(
				'0',
				Block_Tree::from_input(
					array(
						'name'       => 'core/paragraph',
						'inner_html' => '<p>In</p>',
					)
				),
				'append'
			)
		);

		$this->assertSame(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>In</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			$tree->serialize()
		);

		$this->assert_round_trips( $tree );
	}

	public function test_insert_reports_null_for_an_unknown_anchor() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertNull( $tree->insert( '7', Block_Tree::from_input( array( 'name' => 'core/spacer' ) ), 'after' ) );
		$this->assertNull( $tree->insert( '0.9', Block_Tree::from_input( array( 'name' => 'core/spacer' ) ), 'append' ) );
		$this->assertNull( $tree->insert( 'bad', Block_Tree::from_input( array( 'name' => 'core/spacer' ) ), 'after' ) );
	}

	public function test_remove_rebuilds_the_parent_placeholders() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertTrue( $tree->remove( '0.1.0' ) );

		$columns = $tree->get( '0.1' );

		$this->assertCount( 1, $columns['innerBlocks'] );
		$this->assertSame( array( '<div class="wp-block-columns">', null, '</div>' ), $columns['innerContent'] );
		$this->assertSame( '<p>Right</p>', $tree->get( '0.1.0.0' )['innerHTML'] );

		$this->assert_round_trips( $tree );
		$this->assertFalse( $tree->remove( '0.1.5' ) );
	}

	public function test_removing_the_last_child_leaves_a_usable_wrapper() {
		$tree = Block_Tree::parse( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Only</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		$this->assertTrue( $tree->remove( '0.0' ) );

		$group = $tree->get( '0' );

		$this->assertSame( array(), $group['innerBlocks'] );
		$this->assertSame( array( '<div class="wp-block-group"></div>' ), $group['innerContent'] );
		$this->assertSame( '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->', $tree->serialize() );
	}

	public function test_sort_deepest_first_puts_children_and_later_siblings_first() {
		$this->assertSame(
			array( '2.1.0', '2.1', '2', '1.5', '1', '0' ),
			Block_Tree::sort_deepest_first( array( '0', '1', '1.5', '2', '2.1', '2.1.0' ) )
		);

		// Duplicates and invalid paths are dropped.
		$this->assertSame( array( '1', '0' ), Block_Tree::sort_deepest_first( array( '0', '1', '0', 'x', '' ) ) );
	}

	public function test_remove_many_removes_deepest_first_and_stays_correct() {
		$tree = Block_Tree::parse( self::NESTED );

		$removed = $tree->remove_many( array( '0.0', '0.1.1', '1' ) );

		$this->assertSame( array( '1', '0.1.1', '0.0' ), $removed );
		$this->assertSame( array( '0', '0.0', '0.0.0', '0.0.0.0' ), $this->paths( $tree ) );
		$this->assertSame( 'core/columns', $tree->get( '0.0' )['blockName'] );
		$this->assertSame( '<p>Left</p>', $tree->get( '0.0.0.0' )['innerHTML'] );

		$this->assert_round_trips( $tree );
	}

	public function test_remove_many_tolerates_a_parent_and_its_child_together() {
		$tree = Block_Tree::parse( self::NESTED );

		$removed = $tree->remove_many( array( '0.1', '0.1.0' ) );

		$this->assertSame( array( '0.1.0', '0.1' ), $removed );
		$this->assertSame( array( '0', '0.0', '1' ), $this->paths( $tree ) );

		$this->assert_round_trips( $tree );
	}

	public function test_shift_after_removal() {
		$this->assertSame( '1', Block_Tree::shift_after_removal( '2', '0' ) );
		$this->assertSame( '0', Block_Tree::shift_after_removal( '0', '2' ) );
		$this->assertSame( '0.1', Block_Tree::shift_after_removal( '0.2', '0.0' ) );
		$this->assertSame( '0.2', Block_Tree::shift_after_removal( '0.2', '1.0' ) );
		$this->assertSame( '1.3.4', Block_Tree::shift_after_removal( '2.3.4', '1' ) );
		$this->assertSame( '0.1.0', Block_Tree::shift_after_removal( '0.1.0', '0.1.5' ) );
	}

	public function test_is_within_detects_a_nodes_own_subtree() {
		$this->assertTrue( Block_Tree::is_within( '0.1', '0.1' ) );
		$this->assertTrue( Block_Tree::is_within( '0.1.2', '0.1' ) );
		$this->assertTrue( Block_Tree::is_within( '0', '' ) );
		$this->assertFalse( Block_Tree::is_within( '0.10', '0.1' ) );
		$this->assertFalse( Block_Tree::is_within( '0.2', '0.1' ) );
		$this->assertFalse( Block_Tree::is_within( '1', '0' ) );
	}

	public function test_move_a_block_into_another_container() {
		$tree = Block_Tree::parse( self::NESTED );

		// Move the top level paragraph into the first column, as its last child.
		$path = $tree->move( '1', '0.1.0', 'append' );

		$this->assertSame( '0.1.0.1', $path );
		$this->assertSame( array( '0', '0.0', '0.1', '0.1.0', '0.1.0.0', '0.1.0.1', '0.1.1', '0.1.1.0' ), $this->paths( $tree ) );
		$this->assertSame( array( 'align' => 'center' ), $tree->get( '0.1.0.1' )['attrs'] );

		$this->assert_round_trips( $tree );
	}

	public function test_move_accounts_for_the_shift_the_removal_causes() {
		$tree = Block_Tree::parse( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>C</p><!-- /wp:paragraph -->' );

		// Moving A after C: once A is gone, C sits at 1, so the block lands at 2.
		$this->assertSame( '2', $tree->move( '0', '2', 'after' ) );
		$this->assertSame( '<p>B</p>', $tree->get( '0' )['innerHTML'] );
		$this->assertSame( '<p>C</p>', $tree->get( '1' )['innerHTML'] );
		$this->assertSame( '<p>A</p>', $tree->get( '2' )['innerHTML'] );
	}

	public function test_move_to_the_root_list() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertSame( '2', $tree->move( '0.1.0.0', '', 'append' ) );
		$this->assertSame( '<p>Left</p>', $tree->get( '2' )['innerHTML'] );
		$this->assertSame( array(), $tree->get( '0.1.0' )['innerBlocks'] );

		$this->assert_round_trips( $tree );
	}

	public function test_move_reports_null_for_unknown_paths() {
		$tree = Block_Tree::parse( self::NESTED );

		$this->assertNull( $tree->move( '9', '0', 'after' ) );
		$this->assertNull( $tree->move( '0', '9', 'after' ) );
		$this->assertSame( self::NESTED, $tree->serialize(), 'A failed move must leave the tree alone.' );
	}

	public function test_split_wrapper() {
		$this->assertSame( array( '<div class="a">', '</div>' ), Block_Tree::split_wrapper( '<div class="a"></div>' ) );
		$this->assertSame( array( '<div><span>x</span>', '</div>' ), Block_Tree::split_wrapper( '<div><span>x</span></div>' ) );
		$this->assertSame( array( '', '' ), Block_Tree::split_wrapper( '' ) );
		$this->assertSame( array( '<hr class="wp-block-separator"/>', '' ), Block_Tree::split_wrapper( '<hr class="wp-block-separator"/>' ) );
		$this->assertSame( array( 'plain text', '' ), Block_Tree::split_wrapper( 'plain text' ) );
	}

	public function test_from_input_builds_a_nested_tree_that_serializes() {
		$block = Block_Tree::from_input(
			array(
				'name'         => 'core/columns',
				'attrs'        => array( 'verticalAlignment' => 'center' ),
				'inner_html'   => '<div class="wp-block-columns"></div>',
				'inner_blocks' => array(
					array(
						'name'         => 'core/column',
						'inner_html'   => '<div class="wp-block-column"></div>',
						'inner_blocks' => array(
							array(
								'name'       => 'core/paragraph',
								'inner_html' => '<p>Deep</p>',
							),
						),
					),
					array(
						'name'       => 'core/column',
						'inner_html' => '<div class="wp-block-column"></div>',
					),
				),
			)
		);

		$this->assertSame( array( '<div class="wp-block-columns">', null, null, '</div>' ), $block['innerContent'] );

		$tree = new Block_Tree( array( $block ) );

		$this->assertSame(
			'<!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Deep</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column --></div><!-- /wp:columns -->',
			$tree->serialize()
		);

		$this->assertSame( array( '0', '0.0', '0.0.0', '0.1' ), $this->paths( $tree ) );
		$this->assert_round_trips( $tree );
	}

	public function test_from_input_treats_freeform_as_a_nameless_fragment() {
		$block = Block_Tree::from_input(
			array(
				'name'       => Block_Tree::FREEFORM,
				'inner_html' => '<p>Classic</p>',
			)
		);

		$this->assertNull( $block['blockName'] );
		$this->assertSame( '<p>Classic</p>', ( new Block_Tree( array( $block ) ) )->serialize() );
	}

	public function test_normalize_repairs_a_placeholder_count_that_does_not_match() {
		$block = Block_Tree::normalize(
			array(
				'blockName'    => 'core/group',
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => array( '<div class="wp-block-group"></div>' ),
				'innerBlocks'  => array(
					array(
						'blockName' => 'core/paragraph',
						'innerHTML' => '<p>Child</p>',
					),
				),
			)
		);

		$this->assertSame( array( '<div class="wp-block-group">', null, '</div>' ), $block['innerContent'] );
		$this->assertSame( '<div class="wp-block-group"></div>', $block['innerHTML'] );
		$this->assertSame(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Child</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			serialize_block( $block )
		);
	}

	public function test_rebuild_keeps_the_separator_between_children() {
		$pretty = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:group -->";
		$tree   = Block_Tree::parse( $pretty );

		$this->assertSame( $pretty, $tree->serialize() );

		$tree->insert(
			'0.1',
			Block_Tree::from_input(
				array(
					'name'       => 'core/paragraph',
					'inner_html' => "\n<p>C</p>\n",
				)
			),
			'after'
		);

		$group = $tree->get( '0' );
		$gaps  = array();

		foreach ( $group['innerContent'] as $chunk ) {
			if ( null !== $chunk ) {
				$gaps[] = $chunk;
			}
		}

		// The original gap between the two paragraphs is reused for the new one.
		$this->assertContains( "\n\n", $gaps );
		$this->assertSame( array( '0', '0.0', '0.1', '0.2' ), $this->paths( $tree ) );
		$this->assert_round_trips( $tree );
	}

	public function test_fingerprints_follow_the_content() {
		$tree  = Block_Tree::parse( self::NESTED );
		$first = $tree->fingerprint();

		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $first );
		$this->assertSame( $first, Block_Tree::parse( self::NESTED )->fingerprint() );
		$this->assertSame( $tree->fingerprint_of( '0.0' ), Block_Tree::parse( self::NESTED )->fingerprint_of( '0.0' ) );
		$this->assertNotSame( $tree->fingerprint_of( '0.0' ), $tree->fingerprint_of( '1' ) );
		$this->assertSame( '', $tree->fingerprint_of( '9' ) );

		$tree->remove( '1' );

		$this->assertNotSame( $first, $tree->fingerprint() );
	}

	public function test_nodes_trims_to_depth() {
		$tree = Block_Tree::parse( self::NESTED );

		$one = $tree->nodes( null, 1 );

		$this->assertCount( 2, $one );
		$this->assertSame( '0', $one[0]['path'] );
		$this->assertSame( array(), $one[0]['inner_blocks'] );
		$this->assertSame( 2, $one[0]['inner_blocks_count'] );
		$this->assertArrayHasKey( 'attrs', $one[0] );
		$this->assertArrayNotHasKey( 'inner_html', $one[0] );

		$two = $tree->nodes( null, 2 );

		$this->assertCount( 2, $two[0]['inner_blocks'] );
		$this->assertSame( array(), $two[0]['inner_blocks'][1]['inner_blocks'] );

		$deep = $tree->nodes( null, 0 );

		$this->assertSame( '0.1.0.0', $deep[0]['inner_blocks'][1]['inner_blocks'][0]['inner_blocks'][0]['path'] );

		$raw = $tree->nodes( '0.0', 0, true, false );

		$this->assertCount( 1, $raw );
		$this->assertSame( '0.0', $raw[0]['path'] );
		$this->assertSame( '<p>Hello</p>', $raw[0]['inner_html'] );
		$this->assertSame( array( '<p>Hello</p>' ), $raw[0]['inner_content'] );
		$this->assertArrayNotHasKey( 'attrs', $raw[0] );

		$this->assertNull( $tree->nodes( '4', 1 ) );
	}

	public function test_map_html_text_leaves_tags_alone() {
		$upper = static function ( $text ) {
			return strtoupper( $text );
		};

		$this->assertSame(
			'<p class="lead">HELLO <em>THERE</em></p>',
			Block_Tree::map_html_text( '<p class="lead">Hello <em>there</em></p>', $upper )
		);

		$this->assertSame( 'PLAIN', Block_Tree::map_html_text( 'plain', $upper ) );
		$this->assertSame( '<br/>', Block_Tree::map_html_text( '<br/>', $upper ) );
	}

	public function test_map_text_records_the_paths_it_changed() {
		$tree = Block_Tree::parse( self::NESTED );

		$changed = $tree->map_text(
			null,
			static function ( $text ) {
				return str_replace( 'Left', 'Port', $text );
			}
		);

		$this->assertSame( array( '0.1.0.0' ), $changed );
		$this->assertSame( '<p>Port</p>', $tree->get( '0.1.0.0' )['innerHTML'] );
		$this->assertStringContainsString( '<p>Port</p>', $tree->serialize() );

		$this->assert_round_trips( $tree );

		// A subtree that does not hold the text changes nothing.
		$other = Block_Tree::parse( self::NESTED );

		$this->assertSame(
			array(),
			$other->map_text(
				'0.1.1',
				static function ( $text ) {
					return str_replace( 'Left', 'Port', $text );
				}
			)
		);

		$this->assertNull(
			$other->map_text(
				'9',
				static function ( $text ) {
					return $text;
				}
			)
		);
	}

	public function test_map_text_never_touches_the_wrapper_of_a_parent() {
		$tree = Block_Tree::parse( self::NESTED );

		$tree->map_text(
			null,
			static function ( $text ) {
				return str_replace( 'div', 'span', $text );
			}
		);

		$this->assertSame( self::NESTED, $tree->serialize(), 'Tag names inside the markup must not be rewritten.' );
	}

	public function test_parse_fragment_drops_the_whitespace_between_blocks() {
		$blocks = Block_Tree::parse_fragment( "  <!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->\n" );

		$this->assertCount( 2, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertSame( 'core/paragraph', $blocks[1]['blockName'] );

		$this->assertSame( array(), Block_Tree::parse_fragment( "   \n " ) );

		// Real classic HTML survives.
		$classic = Block_Tree::parse_fragment( '<p>Classic</p>' );

		$this->assertCount( 1, $classic );
		$this->assertNull( $classic[0]['blockName'] );
	}

	public function test_normalize_position_falls_back_to_after() {
		$this->assertSame( 'before', Block_Tree::normalize_position( 'before' ) );
		$this->assertSame( 'append', Block_Tree::normalize_position( ' APPEND ' ) );
		$this->assertSame( 'after', Block_Tree::normalize_position( 'sideways' ) );
		$this->assertSame( 'after', Block_Tree::normalize_position( null ) );
	}
}
