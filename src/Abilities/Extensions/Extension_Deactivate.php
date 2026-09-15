<?php
/**
 * Plugin deactivation ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Extensions\Upgrades;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivates an installed plugin.
 *
 * There is no theme equivalent: a theme is replaced by activating another one, so a
 * theme request is answered with `unsupported`.
 *
 * @since 0.2.0
 */
class Extension_Deactivate extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-deactivate';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Deactivate a plugin', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deactivates an installed plugin, leaving its files and its data alone. Themes cannot be deactivated: a site always has one active theme, so switch to another theme instead. Plugins on the protected list, which always includes Super Abilities itself, are refused unless force is true, because an agent that deactivates this plugin loses the ability to put it back. Calling it again for a plugin that is already inactive changes nothing.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write_idempotent();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'activate_plugins' );
	}

	/**
	 * Requires the network capability when the caller asks for a network deactivation.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	public function permission( array $input ) {
		$denied = Guard::require_cap( 'activate', Target::TYPE_PLUGIN );

		if ( null !== $denied ) {
			return $denied;
		}

		if ( empty( $input['network_wide'] ) ) {
			return true;
		}

		if ( ! is_multisite() ) {
			return $this->error( 'unsupported', __( 'network_wide only means something on a multisite network.', 'super-abilities' ), 501 );
		}

		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			return $this->error(
				'forbidden',
				__( 'Deactivating a plugin network wide requires the "manage_network_plugins" capability.', 'super-abilities' ),
				403,
				array( 'required_capability' => 'manage_network_plugins' )
			);
		}

		return true;
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
				'type'         => self::type_property(),
				'slug'         => self::slug_property( __( 'Plugin file, such as akismet/akismet.php.', 'super-abilities' ) ),
				'network_wide' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Deactivate the network wide activation. Multisite only, and requires manage_network_plugins.', 'super-abilities' ),
				),
				'force'        => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Deactivate even a protected plugin, including Super Abilities itself. Default false.', 'super-abilities' ),
				),
			),
			array( 'slug' )
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
		return Schema::object(
			array(
				'type'             => array( 'type' => 'string' ),
				'slug'             => array( 'type' => 'string' ),
				'active'           => array( 'type' => 'boolean' ),
				'already_inactive' => array( 'type' => 'boolean' ),
				'network_wide'     => array( 'type' => 'boolean' ),
				'fingerprint'      => Schema::fingerprint( __( 'Fingerprint of the plugin after the call.', 'super-abilities' ) ),
			),
			array( 'type', 'slug', 'active', 'already_inactive', 'network_wide', 'fingerprint' )
		);
	}

	/**
	 * Deactivates the plugin.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		if ( Target::TYPE_THEME === $this->input_type( $input ) ) {
			return $this->error(
				'unsupported',
				__( 'A theme cannot be deactivated. Every site has exactly one active theme, so activate a different theme instead.', 'super-abilities' ),
				501
			);
		}

		$force   = ! empty( $input['force'] );
		$network = ! empty( $input['network_wide'] ) && is_multisite();

		Upgrades::bootstrap();

		$described = $this->installed( Target::TYPE_PLUGIN, $this->input_slug( $input ) );

		if ( is_wp_error( $described ) ) {
			return $described;
		}

		$slug = (string) $described['slug'];

		if ( ! $force && Guard::is_protected( Target::TYPE_PLUGIN, $slug ) ) {
			return $this->error(
				'protected_extension',
				sprintf(
					/* translators: %s: Plugin file. */
					__( 'The plugin "%s" is on the protected list and was left active. Pass force to override, and be aware that deactivating Super Abilities removes every ability on this site, including the one that would put it back.', 'super-abilities' ),
					$slug
				),
				403,
				array(
					'slug'      => $slug,
					'protected' => true,
				)
			);
		}

		if ( empty( $described['active'] ) && empty( $described['network_active'] ) ) {
			return array(
				'type'             => Target::TYPE_PLUGIN,
				'slug'             => $slug,
				'active'           => false,
				'already_inactive' => true,
				'network_wide'     => false,
				'fingerprint'      => Target::fingerprint( $described ),
			);
		}

		$this->note_object( Target::TYPE_PLUGIN, $slug );

		deactivate_plugins( array( $slug ), false, $network ? true : null );

		$after = Target::describe( Target::TYPE_PLUGIN, $slug );

		return array(
			'type'             => Target::TYPE_PLUGIN,
			'slug'             => $slug,
			'active'           => null !== $after && ! empty( $after['active'] ),
			'already_inactive' => false,
			'network_wide'     => $network,
			'fingerprint'      => Target::fingerprint( $after ),
		);
	}
}
