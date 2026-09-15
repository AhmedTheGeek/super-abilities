<?php
/**
 * Plugin and theme install ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Packages;
use SuperAbilities\Extensions\Preflight;
use SuperAbilities\Extensions\Silent_Skin;
use SuperAbilities\Extensions\Smoke_Test;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Extensions\Upgrades;
use SuperAbilities\Support\Redactor;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Installs a plugin or theme from WordPress.org or from an allowed ZIP URL.
 *
 * The pre-flight check runs first and any blocker aborts the call before a single byte
 * is downloaded. Activation is opt-in, needs its own capability, and is undone when the
 * smoke test afterwards finds the site returning 500.
 *
 * @since 0.2.0
 */
class Extension_Install extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-install';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Install a plugin or theme', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Installs a plugin or theme from WordPress.org by slug, or from a ZIP URL when an administrator allowed ZIP sources and the host is on the allowlist. The pre-flight check runs first and any blocker aborts the call before anything is downloaded. Activation is off by default and needs activate_plugins or switch_themes on top of install_plugins or install_themes; when it is requested, the site is smoke tested afterwards and the activation is undone if the site starts returning HTTP 500. Pass dry_run true to get the pre-flight result and nothing else. Never overwrites an existing plugin or theme: use extension-update for that.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'install_plugins' );
	}

	/**
	 * Checks the type capability, the activation capability and the network rules.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	public function permission( array $input ) {
		$allowed = $this->guard( 'install', $input );

		if ( true !== $allowed ) {
			return $allowed;
		}

		return $this->guard_activation( $input );
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
				'type'             => self::type_property(),
				'slug'             => self::slug_property( __( 'WordPress.org slug of the plugin or theme to install.', 'super-abilities' ) ),
				'zip_url'          => array(
					'type'        => 'string',
					'format'      => 'uri',
					'maxLength'   => 2048,
					'description' => __( 'HTTPS URL of a ZIP package, instead of a slug. Only accepted when an administrator enabled ZIP URLs and the host is on the allowlist.', 'super-abilities' ),
				),
				'activate'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Activate the plugin, or switch to the theme, once it is installed. Default false.', 'super-abilities' ),
				),
				'network_activate' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Activate the plugin for the whole network. Multisite only, and requires manage_network_plugins.', 'super-abilities' ),
				),
				'run_smoke_test'   => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Request the home page and the admin heartbeat afterwards to confirm the site still answers. Default true.', 'super-abilities' ),
				),
				'dry_run'          => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Run the pre-flight check and stop, changing nothing. Default false.', 'super-abilities' ),
				),
			),
			array( 'type' )
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
				'installed'              => array( 'type' => 'boolean' ),
				'dry_run'                => array( 'type' => 'boolean' ),
				'type'                   => array( 'type' => 'string' ),
				'slug'                   => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Plugin file or theme stylesheet of what was installed.', 'super-abilities' ),
				),
				'destination_name'       => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Folder name the package was unpacked into.', 'super-abilities' ),
				),
				'version'                => array( 'type' => 'string' ),
				'activated'              => array( 'type' => 'boolean' ),
				'network_activated'      => array( 'type' => 'boolean' ),
				'rolled_back_activation' => array(
					'type'        => 'boolean',
					'description' => __( 'True when the smoke test failed and the activation was undone. The files stay installed.', 'super-abilities' ),
				),
				'activation_error'       => array( 'type' => array( 'string', 'null' ) ),
				'smoke'                  => self::smoke_schema(),
				'preflight'              => self::preflight_schema(),
				'package'                => self::package_schema(),
				'messages'               => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Upgrader feedback, with absolute paths and secrets redacted.', 'super-abilities' ),
				),
				'duration_ms'            => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'installed', 'dry_run', 'type', 'slug', 'destination_name', 'version', 'activated', 'network_activated', 'rolled_back_activation', 'activation_error', 'smoke', 'preflight', 'package', 'messages', 'duration_ms' )
		);
	}

	/**
	 * Installs the package.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ) {
		$started = microtime( true );

		$type     = $this->input_type( $input );
		$slug     = $this->input_slug( $input );
		$zip_url  = isset( $input['zip_url'] ) ? trim( (string) $input['zip_url'] ) : '';
		$dry_run  = ! empty( $input['dry_run'] );
		$activate = ! empty( $input['activate'] ) || ! empty( $input['network_activate'] );
		$network  = ! empty( $input['network_activate'] ) && is_multisite();
		$smoke_on = ! isset( $input['run_smoke_test'] ) || ! empty( $input['run_smoke_test'] );

		if ( '' === $slug && '' === $zip_url ) {
			return $this->error( 'invalid_input', __( 'Pass either a slug or a zip_url.', 'super-abilities' ), 400 );
		}

		if ( '' !== $slug && '' !== $zip_url ) {
			return $this->error( 'invalid_input', __( 'Pass either a slug or a zip_url, not both.', 'super-abilities' ), 400 );
		}

		Upgrades::bootstrap();

		$package = Packages::resolve(
			array(
				'type'    => $type,
				'slug'    => $slug,
				'zip_url' => $zip_url,
			)
		);

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$preflight = Preflight::check(
			array(
				'type'    => $type,
				'slug'    => '' !== $slug ? $slug : (string) $package['slug'],
				'action'  => 'install',
				'package' => $package,
			)
		);

		$result = array(
			'installed'              => false,
			'dry_run'                => $dry_run,
			'type'                   => $type,
			'slug'                   => null,
			'destination_name'       => null,
			'version'                => (string) $package['version'],
			'activated'              => false,
			'network_activated'      => false,
			'rolled_back_activation' => false,
			'activation_error'       => null,
			'smoke'                  => Smoke_Test::skipped(),
			'preflight'              => $preflight,
			'package'                => Packages::public_info( $package ),
			'messages'               => array(),
			'duration_ms'            => 0,
		);

		if ( $dry_run ) {
			$result['duration_ms'] = $this->elapsed( $started );

			return $result;
		}

		if ( ! $preflight['ok'] ) {
			return $this->error(
				'preflight_failed',
				__( 'The pre-flight check found blockers, so nothing was installed.', 'super-abilities' ),
				409,
				array(
					'blockers'  => $preflight['blockers'],
					'preflight' => $preflight,
				)
			);
		}

		$this->note_object( $type, '' !== $slug ? $slug : (string) $package['slug'] );

		$skin     = new Silent_Skin();
		$upgrader = Target::TYPE_THEME === $type ? new \Theme_Upgrader( $skin ) : new \Plugin_Upgrader( $skin );
		$outcome  = $upgrader->install( (string) $package['download_url'] );

		$result['messages'] = $skin->messages();

		if ( is_wp_error( $outcome ) ) {
			return $this->error(
				'install_failed',
				Redactor::text( $outcome->get_error_message() ),
				500,
				array(
					'messages' => $result['messages'],
					'errors'   => $skin->errors(),
				)
			);
		}

		if ( true !== $outcome ) {
			return $this->error(
				'install_failed',
				__( 'The upgrader did not install the package. Its messages explain why.', 'super-abilities' ),
				500,
				array(
					'messages' => $result['messages'],
					'errors'   => $skin->errors(),
				)
			);
		}

		$destination = is_array( $upgrader->result ) && ! empty( $upgrader->result['destination_name'] )
			? (string) $upgrader->result['destination_name']
			: '';

		$installed_slug = $this->installed_slug( $type, $destination, $upgrader );

		$result['installed']        = true;
		$result['destination_name'] = '' === $destination ? null : $destination;
		$result['slug']             = '' === $installed_slug ? null : $installed_slug;

		$described = '' === $installed_slug ? null : Target::describe( $type, $installed_slug );

		if ( null !== $described ) {
			$result['version'] = (string) $described['version'];
			$this->note_object( $type, $installed_slug );
		}

		$previous_stylesheet = get_stylesheet();

		if ( $activate && '' !== $installed_slug ) {
			$activation = $this->activate( $type, $installed_slug, $network );

			$result['activated']         = (bool) $activation['activated'];
			$result['network_activated'] = (bool) $activation['network'];
			$result['activation_error']  = $activation['error'];
		}

		if ( $smoke_on ) {
			$result['smoke'] = Smoke_Test::run();

			if ( ! $result['smoke']['passed'] && $result['activated'] ) {
				$this->undo_activation( $type, $installed_slug, $result['network_activated'], $previous_stylesheet );

				$result['activated']              = false;
				$result['network_activated']      = false;
				$result['rolled_back_activation'] = true;
			}
		}

		$result['duration_ms'] = $this->elapsed( $started );

		return $result;
	}

	/**
	 * Requires the extra capabilities an activation needs.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	protected function guard_activation( array $input ) {
		$type    = $this->input_type( $input );
		$network = ! empty( $input['network_activate'] );

		if ( empty( $input['activate'] ) && ! $network ) {
			return true;
		}

		$cap = Target::TYPE_THEME === $type ? 'switch_themes' : 'activate_plugins';

		if ( ! current_user_can( $cap ) ) {
			return $this->error(
				'forbidden',
				sprintf(
					/* translators: %s: Capability name. */
					__( 'Activating it additionally requires the "%s" capability.', 'super-abilities' ),
					$cap
				),
				403,
				array( 'required_capability' => $cap )
			);
		}

		if ( $network ) {
			if ( ! is_multisite() ) {
				return $this->error(
					'unsupported',
					__( 'network_activate only means something on a multisite network.', 'super-abilities' ),
					501
				);
			}

			if ( Target::TYPE_THEME === $type ) {
				return $this->error(
					'unsupported',
					__( 'Themes are enabled for a network, not activated. Use the network admin for that.', 'super-abilities' ),
					501
				);
			}

			if ( ! current_user_can( 'manage_network_plugins' ) ) {
				return $this->error(
					'forbidden',
					__( 'Activating a plugin network wide requires the "manage_network_plugins" capability.', 'super-abilities' ),
					403,
					array( 'required_capability' => 'manage_network_plugins' )
				);
			}
		}

		return true;
	}

	/**
	 * Resolves what the upgrader actually put on disk.
	 *
	 * @since 0.2.0
	 *
	 * @param string                           $type        Extension type.
	 * @param string                           $destination Destination folder name.
	 * @param \Plugin_Upgrader|\Theme_Upgrader $upgrader    The upgrader that ran.
	 * @return string Plugin file or theme stylesheet, or an empty string.
	 */
	protected function installed_slug( $type, $destination, $upgrader ) {
		if ( Target::TYPE_THEME === $type ) {
			return $destination;
		}

		if ( $upgrader instanceof \Plugin_Upgrader ) {
			$file = $upgrader->plugin_info();

			if ( is_string( $file ) && '' !== $file ) {
				return $file;
			}
		}

		return '' === $destination ? '' : Target::plugin_file( $destination );
	}

	/**
	 * Activates the freshly installed extension.
	 *
	 * `activate_plugin()` loads the plugin in a sandbox, so a plugin that fatals comes
	 * back as a `WP_Error` instead of taking the request down.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type    Extension type.
	 * @param string $slug    Plugin file or theme stylesheet.
	 * @param bool   $network Whether to activate network wide.
	 * @return array{activated: bool, network: bool, error: string|null}
	 */
	protected function activate( $type, $slug, $network ) {
		if ( Target::TYPE_THEME === $type ) {
			switch_theme( $slug );

			return array(
				'activated' => get_stylesheet() === $slug,
				'network'   => false,
				'error'     => null,
			);
		}

		try {
			$activated = activate_plugin( $slug, '', (bool) $network, false );
		} catch ( \Throwable $throwable ) {
			return array(
				'activated' => false,
				'network'   => false,
				'error'     => Redactor::text( $throwable->getMessage() ),
			);
		}

		if ( is_wp_error( $activated ) ) {
			return array(
				'activated' => false,
				'network'   => false,
				'error'     => Redactor::text( $activated->get_error_message() ),
			);
		}

		return array(
			'activated' => true,
			'network'   => (bool) $network,
			'error'     => null,
		);
	}

	/**
	 * Undoes an activation the smoke test disowned.
	 *
	 * A plugin is deactivated again; a theme switch is reversed by switching back to
	 * the stylesheet that was active before the call.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type     Extension type.
	 * @param string $slug     Plugin file or theme stylesheet.
	 * @param bool   $network  Whether the plugin was activated network wide.
	 * @param string $previous Stylesheet that was active before the call.
	 * @return void
	 */
	protected function undo_activation( $type, $slug, $network, $previous ) {
		if ( '' === (string) $slug ) {
			return;
		}

		if ( Target::TYPE_THEME === $type ) {
			if ( '' !== (string) $previous && (string) $previous !== (string) $slug ) {
				switch_theme( (string) $previous );
			}

			return;
		}

		deactivate_plugins( array( $slug ), true, (bool) $network );
	}

	/**
	 * Milliseconds since a `microtime( true )` mark.
	 *
	 * @since 0.2.0
	 *
	 * @param float $started Start time.
	 * @return int
	 */
	protected function elapsed( $started ) {
		return (int) round( ( microtime( true ) - (float) $started ) * 1000 );
	}
}
