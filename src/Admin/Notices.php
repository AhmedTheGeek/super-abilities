<?php
/**
 * Admin notices.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The handful of notices this plugin prints.
 *
 * @since 0.1.0
 */
class Notices {

	/**
	 * Warns that the Abilities API is missing, so the plugin cannot do anything.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function missing_abilities_api() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: Plugin name, 2: Required WordPress version, 3: Current WordPress version. */
			__( '%1$s needs the WordPress Abilities API, which was added in WordPress %2$s. This site runs WordPress %3$s, so no abilities were registered.', 'super-abilities' ),
			__( 'Super Abilities', 'super-abilities' ),
			'6.9',
			get_bloginfo( 'version' )
		);

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
