<?php
/**
 * Plugin activation and theme switching ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Smoke_Test;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Extensions\Upgrades;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Activates an installed plugin, or switches to an installed theme.
 *
 * Idempotent: calling it for something that is already active reports `already_active`
 * and changes nothing.
 *
 * @since 0.2.0
 */
class Extension_Activate extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-activate';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Activate a plugin or theme', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Activates an installed plugin, or switches the site to an installed theme. WordPress loads a plugin in a sandbox while activating it, so a plugin that crashes comes back as activation_failed instead of taking the site down. The site is smoke tested afterwards and the activation is undone when the home page or the admin heartbeat starts returning HTTP 500. Calling it again for something that is already active changes nothing. Requires activate_plugins for plugins and switch_themes for themes.', 'super-abilities' );
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
	 * Requires the capability that matches the type, plus the network capability.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	public function permission( array $input ) {
		$type   = $this->input_type( $input );
		$denied = Guard::require_cap( 'activate', $type );

		if ( null !== $denied ) {
			return $denied;
		}

		if ( empty( $input['network_wide'] ) ) {
			return true;
		}

		if ( ! is_multisite() ) {
			return $this->error( 'unsupported', __( 'network_wide only means something on a multisite network.', 'super-abilities' ), 501 );
		}

		if ( Target::TYPE_THEME === $type ) {
			return $this->error( 'unsupported', __( 'Themes are enabled for a network rather than activated network wide.', 'super-abilities' ), 501 );
		}

		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			return $this->error(
				'forbidden',
				__( 'Activating a plugin network wide requires the "manage_network_plugins" capability.', 'super-abilities' ),
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
				'type'           => self::type_property(),
				'slug'           => self::slug_property( __( 'Plugin file, such as akismet/akismet.php, or theme stylesheet directory.', 'super-abilities' ) ),
				'network_wide'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Activate the plugin for every site in the network. Multisite only, and requires manage_network_plugins.', 'super-abilities' ),
				),
				'run_smoke_test' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Request the home page and the admin heartbeat afterwards, and undo the activation when they fail. Default true.', 'super-abilities' ),
				),
			),
			array( 'type', 'slug' )
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
				'type'           => array( 'type' => 'string' ),
				'slug'           => array( 'type' => 'string' ),
				'active'         => array( 'type' => 'boolean' ),
				'network_wide'   => array( 'type' => 'boolean' ),
				'already_active' => array( 'type' => 'boolean' ),
				'rolled_back'    => array(
					'type'        => 'boolean',
					'description' => __( 'True when the smoke test failed and the activation was undone.', 'super-abilities' ),
				),
				'smoke'          => self::smoke_schema(),
				'fingerprint'    => Schema::fingerprint( __( 'Fingerprint of the extension after the call.', 'super-abilities' ) ),
			),
			array( 'type', 'slug', 'active', 'network_wide', 'already_active', 'rolled_back', 'smoke', 'fingerprint' )
		);
	}

	/**
	 * Activates the extension.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$type     = $this->input_type( $input );
		$network  = ! empty( $input['network_wide'] ) && is_multisite();
		$smoke_on = ! isset( $input['run_smoke_test'] ) || ! empty( $input['run_smoke_test'] );

		Upgrades::bootstrap();

		$described = $this->installed( $type, $this->input_slug( $input ) );

		if ( is_wp_error( $described ) ) {
			return $described;
		}

		$slug = (string) $described['slug'];

		if ( ! empty( $described['active'] ) && ( ! $network || ! empty( $described['network_active'] ) ) ) {
			return array(
				'type'           => $type,
				'slug'           => $slug,
				'active'         => true,
				'network_wide'   => (bool) $described['network_active'],
				'already_active' => true,
				'rolled_back'    => false,
				'smoke'          => Smoke_Test::skipped(),
				'fingerprint'    => Target::fingerprint( $described ),
			);
		}

		$this->note_object( $type, $slug );

		$previous_stylesheet = get_stylesheet();

		if ( Target::TYPE_THEME === $type ) {
			switch_theme( $slug );

			if ( get_stylesheet() !== $slug ) {
				return $this->error(
					'activation_failed',
					__( 'WordPress did not switch to that theme. It may be broken or missing a parent theme.', 'super-abilities' ),
					500,
					array( 'slug' => $slug )
				);
			}
		} else {
			$activated = $this->activate_plugin_safely( $slug, $network );

			if ( is_wp_error( $activated ) ) {
				return $activated;
			}
		}

		$result = array(
			'type'           => $type,
			'slug'           => $slug,
			'active'         => true,
			'network_wide'   => $network,
			'already_active' => false,
			'rolled_back'    => false,
			'smoke'          => Smoke_Test::skipped(),
			'fingerprint'    => Target::fingerprint( Target::describe( $type, $slug ) ),
		);

		if ( $smoke_on ) {
			$result['smoke'] = Smoke_Test::run();

			if ( ! $result['smoke']['passed'] ) {
				if ( Target::TYPE_THEME === $type ) {
					if ( $previous_stylesheet !== $slug ) {
						switch_theme( $previous_stylesheet );
					}
				} else {
					deactivate_plugins( array( $slug ), true, $network );
				}

				$result['active']       = false;
				$result['network_wide'] = false;
				$result['rolled_back']  = true;
				$result['fingerprint']  = Target::fingerprint( Target::describe( $type, $slug ) );
			}
		}

		return $result;
	}

	/**
	 * Activates a plugin and turns a fatal error into a `WP_Error`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug    Plugin file.
	 * @param bool   $network Whether to activate network wide.
	 * @return true|WP_Error
	 */
	protected function activate_plugin_safely( $slug, $network ) {
		try {
			$activated = activate_plugin( $slug, '', (bool) $network, false );
		} catch ( \Throwable $throwable ) {
			return $this->error(
				'activation_failed',
				Redactor::text( $throwable->getMessage() ),
				500,
				array( 'slug' => $slug )
			);
		}

		if ( is_wp_error( $activated ) ) {
			return $this->error(
				'activation_failed',
				Redactor::text( $activated->get_error_message() ),
				500,
				array(
					'slug'       => $slug,
					'error_code' => $activated->get_error_code(),
				)
			);
		}

		return true;
	}
}
