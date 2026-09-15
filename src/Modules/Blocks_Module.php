<?php
/**
 * Block editing module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Blocks\Blocks_Find;
use SuperAbilities\Abilities\Blocks\Blocks_Insert;
use SuperAbilities\Abilities\Blocks\Blocks_Move;
use SuperAbilities\Abilities\Blocks\Blocks_Read;
use SuperAbilities\Abilities\Blocks\Blocks_Remove;
use SuperAbilities\Abilities\Blocks\Blocks_Render;
use SuperAbilities\Abilities\Blocks\Blocks_Replace_Text;
use SuperAbilities\Abilities\Blocks\Blocks_Update;
use SuperAbilities\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and edits the block tree of a post by path.
 *
 * Every ability in this module works on the block markup of `post_content` and
 * nothing else, which is what makes it builder agnostic: a page built with any
 * editor that stores core block markup can be read and edited here, and a post with
 * no blocks at all is a single `core/freeform` fragment that the same abilities can
 * still rewrite. Writes go through `wp_update_post()` so every one of them leaves a
 * revision behind, they take an optional fingerprint so a stale edit is refused
 * instead of overwriting somebody else's work, and they stop when another user has
 * the post open in an editor.
 *
 * @since 0.2.0
 */
class Blocks_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id() {
		return 'blocks';
	}

	/**
	 * Module label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Block editing', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Reads and edits the block tree of a post by path, builder agnostic, with a preview renderer. Post content is parsed into blocks and every block gets an address, the zero based index of each list it is reached through joined with dots, so an agent can read the structure, find the block it means, and then update, insert, move, remove or search and replace text in exactly that block without rewriting the whole post. Nothing here bypasses WordPress: the caller needs edit_posts plus edit_post on the post itself, every write goes through wp_update_post so a revision is created, and a write against a fingerprint that no longer matches is refused with a 409 rather than overwriting a change somebody else made in the meantime.', 'super-abilities' );
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
			Blocks_Read::class,
			Blocks_Find::class,
			Blocks_Update::class,
			Blocks_Insert::class,
			Blocks_Remove::class,
			Blocks_Move::class,
			Blocks_Render::class,
			Blocks_Replace_Text::class,
		);
	}
}
