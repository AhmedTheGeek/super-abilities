<?php
/**
 * Pre-flight check ability.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Packages;
use SuperAbilities\Extensions\Preflight;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether an install or update would succeed, without changing anything.
 *
 * @since 0.2.0
 */
class Extension_Preflight extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extension-preflight';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Pre-flight a plugin or theme change', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Checks, without changing anything, whether installing or updating a plugin or theme can succeed on this site: whether file modifications are allowed, whether WordPress can write files directly, whether there is free disk space for the download and the copy, what WordPress.org says the package requires, whether the site is in maintenance mode, and whether the target is active, network active or a must-use plugin. Findings come back as blockers, which always abort the real operation, and warnings, which do not. Requires install_plugins for plugins and install_themes for themes.', 'super-abilities' );
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
		return array( 'install_plugins' );
	}

	/**
	 * Requires the capability that matches the requested type.
	 *
	 * The network and file modification guards are deliberately not applied here: this
	 * ability exists to report those conditions rather than to refuse because of them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|\WP_Error
	 */
	public function permission( array $input ) {
		$denied = Guard::require_cap( 'install', $this->input_type( $input ) );

		return null === $denied ? true : $denied;
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
				'type'    => self::type_property(),
				'slug'    => self::slug_property( __( 'WordPress.org slug for an install, or the installed plugin file or theme stylesheet for an update.', 'super-abilities' ) ),
				'action'  => array(
					'type'        => 'string',
					'enum'        => array( 'install', 'update' ),
					'default'     => 'install',
					'description' => __( 'Which operation to check. Default install.', 'super-abilities' ),
				),
				'zip_url' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'maxLength'   => 2048,
					'description' => __( 'HTTPS URL of a ZIP package to check instead of a WordPress.org slug. Only accepted when an administrator enabled ZIP URLs.', 'super-abilities' ),
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
				'preflight'     => self::preflight_schema(),
				'package'       => self::package_schema(),
				'package_error' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Why the package could not be resolved, when it could not be.', 'super-abilities' ),
				),
			),
			array( 'preflight', 'package', 'package_error' )
		);
	}

	/**
	 * Runs the checks.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$type    = $this->input_type( $input );
		$slug    = $this->input_slug( $input );
		$zip_url = isset( $input['zip_url'] ) ? trim( (string) $input['zip_url'] ) : '';
		$action  = isset( $input['action'] ) && 'update' === $input['action'] ? 'update' : 'install';

		if ( '' === $slug && '' === $zip_url ) {
			return $this->error( 'invalid_input', __( 'Pass a slug or a zip_url.', 'super-abilities' ), 400 );
		}

		if ( '' !== $slug && 0 !== validate_file( $slug ) ) {
			return $this->error( 'invalid_input', __( 'That slug is not a valid plugin file or theme stylesheet.', 'super-abilities' ), 400 );
		}

		$package       = array();
		$package_error = null;

		$resolved = Packages::resolve(
			array(
				'type'    => $type,
				'slug'    => $slug,
				'zip_url' => $zip_url,
			)
		);

		if ( is_wp_error( $resolved ) ) {
			$package_error = $resolved->get_error_message();
		} else {
			$package = $resolved;
		}

		$preflight = Preflight::check(
			array(
				'type'    => $type,
				'slug'    => $slug,
				'action'  => $action,
				'package' => $package,
			)
		);

		return array(
			'preflight'     => $preflight,
			'package'       => Packages::public_info( $package ),
			'package_error' => null === $package_error ? null : (string) $package_error,
		);
	}
}
