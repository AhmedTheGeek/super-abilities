<?php
/**
 * Tests for the pure design helpers: markup validation, deep merge, id parsing.
 *
 * @package SuperAbilities
 */

use SuperAbilities\Design\Block_Markup;
use SuperAbilities\Design\Global_Styles;
use SuperAbilities\Design\Patterns;
use SuperAbilities\Design\Templates;

class DesignHelpersTest extends WP_UnitTestCase {

	public function test_check_accepts_well_formed_block_markup() {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";

		$summary = Block_Markup::check( $content );

		$this->assertIsArray( $summary );
		$this->assertSame( 2, $summary['block_count'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'core/group',
					'count' => 1,
				),
				array(
					'name'  => 'core/paragraph',
					'count' => 1,
				),
			),
			$summary['blocks']
		);
	}

	public function test_check_counts_repeated_blocks() {
		$content = "<!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>b</p>\n<!-- /wp:paragraph -->";

		$summary = Block_Markup::check( $content );

		$this->assertSame( 2, $summary['block_count'] );
		$this->assertCount( 1, $summary['blocks'] );
		$this->assertSame( 2, $summary['blocks'][0]['count'] );
	}

	public function test_check_accepts_empty_markup() {
		$summary = Block_Markup::check( '' );

		$this->assertIsArray( $summary );
		$this->assertSame( 0, $summary['block_count'] );
		$this->assertSame( array(), $summary['blocks'] );
	}

	public function test_check_rejects_script_tags() {
		$result = Block_Markup::check( "<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->" );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_unsafe_content', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_check_rejects_php_open_tags() {
		$result = Block_Markup::check( '<?php echo 1; ?>' );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_unsafe_content', $result->get_error_code() );
	}

	public function test_check_rejects_a_non_string() {
		$result = Block_Markup::check( array( 'nope' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'super_abilities_invalid_input', $result->get_error_code() );
	}

	public function test_forbidden_fragment_is_case_insensitive() {
		$this->assertSame( '<script', Block_Markup::forbidden_fragment( '<SCRIPT src="x">' ) );
		$this->assertSame( '', Block_Markup::forbidden_fragment( '<p>plain</p>' ) );
	}

	public function test_fingerprint_is_stable_and_content_sensitive() {
		$one = Block_Markup::fingerprint( '<p>a</p>' );

		$this->assertSame( $one, Block_Markup::fingerprint( '<p>a</p>' ) );
		$this->assertNotSame( $one, Block_Markup::fingerprint( '<p>b</p>' ) );
		$this->assertMatchesRegularExpression( '/^fp1:[0-9a-f]{20}$/', $one );
	}

	public function test_merge_folds_nested_objects() {
		$base = array(
			'color'   => array(
				'background' => '#fff',
				'text'       => '#000',
			),
			'spacing' => array( 'padding' => array( 'top' => '1rem' ) ),
		);

		$merged = Global_Styles::merge(
			$base,
			array(
				'color'   => array( 'text' => '#111' ),
				'spacing' => array( 'padding' => array( 'bottom' => '2rem' ) ),
			)
		);

		$this->assertSame( '#fff', $merged['color']['background'] );
		$this->assertSame( '#111', $merged['color']['text'] );
		$this->assertSame( '1rem', $merged['spacing']['padding']['top'] );
		$this->assertSame( '2rem', $merged['spacing']['padding']['bottom'] );
	}

	public function test_merge_treats_null_as_a_removal() {
		$merged = Global_Styles::merge(
			array(
				'color' => array(
					'background' => '#fff',
					'text'       => '#000',
				),
			),
			array( 'color' => array( 'text' => null ) )
		);

		$this->assertSame( array( 'background' => '#fff' ), $merged['color'] );
	}

	public function test_merge_replaces_lists_wholesale() {
		$merged = Global_Styles::merge(
			array( 'palette' => array( 'a', 'b', 'c' ) ),
			array( 'palette' => array( 'z' ) )
		);

		$this->assertSame( array( 'z' ), $merged['palette'] );
	}

	public function test_shape_always_returns_both_halves() {
		$shaped = Global_Styles::shape( array( 'styles' => array( 'color' => array() ) ) );

		$this->assertSame( array( 'settings', 'styles' ), array_keys( $shaped ) );
		$this->assertSame( array(), $shaped['settings'] );
	}

	public function test_fingerprint_of_global_styles_ignores_key_order() {
		$one = Global_Styles::fingerprint(
			array(
				'settings' => array(
					'a' => 1,
					'b' => 2,
				),
				'styles'   => array(),
			)
		);
		$two = Global_Styles::fingerprint(
			array(
				'styles'   => array(),
				'settings' => array(
					'b' => 2,
					'a' => 1,
				),
			)
		);

		$this->assertSame( $one, $two );
	}

	public function test_parse_id_splits_theme_and_slug() {
		$this->assertSame(
			array(
				'theme' => 'block-theme',
				'slug'  => 'index',
			),
			Templates::parse_id( 'block-theme//index' )
		);
	}

	public function test_parse_id_rejects_malformed_ids() {
		foreach ( array( '', 'index', 'block-theme//', '//index', 'block theme//index', array(), 'theme//in dex' ) as $bad ) {
			$this->assertNull( Templates::parse_id( $bad ), 'Accepted ' . var_export( $bad, true ) );
		}
	}

	public function test_type_falls_back_to_wp_template() {
		$this->assertSame( 'wp_template', Templates::type( 'nonsense' ) );
		$this->assertSame( 'wp_template_part', Templates::type( 'wp_template_part' ) );
	}

	public function test_pattern_source_is_normalized() {
		$this->assertSame( 'core', Patterns::source( 'core' ) );
		$this->assertSame( 'theme', Patterns::source( 'theme' ) );
		$this->assertSame( 'directory', Patterns::source( 'pattern-directory/featured' ) );
		$this->assertSame( 'unknown', Patterns::source( '' ) );
		$this->assertSame( 'unknown', Patterns::source( 'something-else' ) );
	}
}
