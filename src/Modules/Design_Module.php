<?php
/**
 * Design module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

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
use SuperAbilities\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes what the site looks like: global styles, theme mods,
 * block templates and block patterns.
 *
 * Every write in this module stays in the database. Global styles go into the
 * `wp_global_styles` post, template overrides into `wp_template` and
 * `wp_template_part` posts, patterns into `wp_block` posts, theme mods into the
 * per-theme option the Customizer uses. No theme file is ever touched, which is
 * what makes the module recoverable: a template can be reset back to its theme
 * file and every post write leaves a revision behind.
 *
 * The risk is medium rather than low because a bad template or a bad global
 * styles document is visible to every visitor immediately.
 *
 * @since 0.2.0
 */
class Design_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id() {
		return 'design';
	}

	/**
	 * Module label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Design', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Read and write global styles, theme mods, block templates and patterns. Global styles are saved into the wp_global_styles post the site editor uses, never into a theme.json file on disk; customizing a block template creates the same database override the site editor creates and leaves the theme file in place, so template-reset can bring it back; user patterns are ordinary wp_block posts. Every write goes through wp_update_post so a revision exists, accepts a fingerprint so it cannot clobber someone else\'s change, and offers a dry run. Theme mods work on any theme; the template abilities need a block theme and return 501 on a classic one.', 'super-abilities' );
	}

	/**
	 * Whether the module is enabled on a fresh install.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * Risk level.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function risk() {
		return 'medium';
	}

	/**
	 * Abilities provided by this module.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function abilities() {
		return array(
			Global_Styles_Read::class,
			Global_Styles_Write::class,
			Theme_Mods_Read::class,
			Theme_Mods_Write::class,
			Templates_List::class,
			Template_Read::class,
			Template_Write::class,
			Template_Reset::class,
			Patterns_List::class,
			Pattern_Read::class,
			Pattern_Write::class,
			Pattern_Delete::class,
		);
	}
}
