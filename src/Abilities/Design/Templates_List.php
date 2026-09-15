<?php
/**
 * Lists block templates and template parts.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Templates;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every template of one type, theme files and database overrides merged.
 *
 * @since 0.2.0
 */
class Templates_List extends Abstract_Ability {

	/**
	 * Default page size.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'templates-list';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'design';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'List block templates', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the block templates or template parts of the active theme, exactly as the site editor sees them: templates that exist only as a theme file, templates that only exist in the database, and theme templates an administrator has customized, whose source is then custom. Each item carries its id, slug, title, area for parts, whether a theme file backs it, when it was last saved, and a fingerprint of its content. Requires a block theme; on a classic theme this returns 501. Use template-read for the markup.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::readonly();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'edit_theme_options' );
	}

	/**
	 * Plugin version this ability shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'type'     => array(
					'type'        => 'string',
					'enum'        => Templates::TYPES,
					'default'     => 'wp_template',
					'description' => __( 'Which kind of template to list. Default wp_template.', 'super-abilities' ),
				),
				'source'   => array(
					'type'        => 'string',
					'enum'        => array( 'theme', 'custom', 'plugin' ),
					'description' => __( 'Only list templates from this source. Omit for all of them.', 'super-abilities' ),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Only list templates whose slug or title contains this text.', 'super-abilities' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page of results to return.', 'super-abilities' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => self::PER_PAGE,
					'description' => __( 'How many templates to return per page.', 'super-abilities' ),
				),
			)
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		$props = array(
			'type'      => array( 'type' => 'string' ),
			'theme'     => array( 'type' => 'string' ),
			'templates' => array(
				'type'  => 'array',
				'items' => Templates::item_schema( false ),
			),
		);

		return Schema::object(
			array_merge( $props, Schema::pagination() ),
			array( 'type', 'theme', 'templates', 'page', 'per_page', 'total', 'total_pages' )
		);
	}

	/**
	 * Lists the templates.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! Templates::supported() ) {
			return $this->error(
				'unsupported',
				__( 'Block templates need a block theme. The active theme is a classic theme.', 'super-abilities' ),
				501
			);
		}

		$type     = Templates::type( isset( $input['type'] ) ? $input['type'] : '' );
		$source   = isset( $input['source'] ) ? (string) $input['source'] : '';
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : self::PER_PAGE;

		$items = array();

		foreach ( Templates::all( $type ) as $template ) {
			if ( '' !== $source && $source !== (string) $template->source ) {
				continue;
			}

			if ( '' !== $search && ! self::matches( $template->slug, $template->title, $search ) ) {
				continue;
			}

			$items[] = Templates::to_array( $template, false );
		}

		$total       = count( $items );
		$total_pages = (int) ceil( $total / $per_page );

		return array(
			'type'        => $type,
			'theme'       => (string) get_stylesheet(),
			'templates'   => array_values( array_slice( $items, ( $page - 1 ) * $per_page, $per_page ) ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Whether a template matches a search term.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug   Template slug.
	 * @param string $title  Template title.
	 * @param string $search Search term.
	 * @return bool
	 */
	protected static function matches( $slug, $title, $search ) {
		$needle = strtolower( $search );

		return false !== strpos( strtolower( (string) $slug ), $needle )
			|| false !== strpos( strtolower( (string) $title ), $needle );
	}
}
