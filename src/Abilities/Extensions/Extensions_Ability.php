<?php
/**
 * Shared base for the extensions abilities.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Extensions\Guard;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the input fragments, output fragments and guards that every plugin and theme
 * ability in this module repeats.
 *
 * Capabilities are declared in two halves. `capability()` names the plugin side
 * capability, which is what nearly every caller needs and what MCP clients show before
 * they ask for approval; `permission()` then requires the capability that matches the
 * `type` the caller actually passed, so a theme operation really does need the theme
 * capability. Everything else an administrator can get wrong, `DISALLOW_FILE_MODS` and
 * the multisite network rules, is checked in `permission()` too.
 *
 * @since 0.2.0
 */
abstract class Extensions_Ability extends Abstract_Ability {

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'extensions';
	}

	/**
	 * Plugin version this ability first shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * The extension type an input asks for.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param string               $fallback Optional. Type to assume when none was passed. Default `plugin`.
	 * @return string
	 */
	protected function input_type( array $input, $fallback = Target::TYPE_PLUGIN ) {
		$type = isset( $input['type'] ) ? strtolower( trim( (string) $input['type'] ) ) : '';

		if ( '' === $type ) {
			$type = (string) $fallback;
		}

		return 'all' === $type ? 'all' : Target::normalize_type( $type );
	}

	/**
	 * The slug an input asks for.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @param string               $key   Optional. Input key. Default `slug`.
	 * @return string
	 */
	protected function input_slug( array $input, $key = 'slug' ) {
		$slug = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';

		return ltrim( str_replace( '\\', '/', $slug ), '/' );
	}

	/**
	 * Runs the capability, network and file modification guards for one operation.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $action One of `list`, `install`, `update`, `delete` or `activate`.
	 * @param array<string, mixed> $input  Validated input.
	 * @return true|WP_Error
	 */
	protected function guard( $action, array $input ) {
		$type = $this->input_type( $input, 'list' === $action ? 'all' : Target::TYPE_PLUGIN );

		$denied = Guard::require_cap( $action, $type );

		if ( null !== $denied ) {
			return $denied;
		}

		$denied = Guard::require_network( $action, $type );

		if ( null !== $denied ) {
			return $denied;
		}

		if ( in_array( (string) $action, array( 'install', 'update', 'delete' ), true ) ) {
			$denied = Guard::require_file_mods( $action, 'all' === $type ? Target::TYPE_PLUGIN : $type );

			if ( null !== $denied ) {
				return $denied;
			}
		}

		return true;
	}

	/**
	 * Looks up an installed extension or returns the 404.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Extension type.
	 * @param string $slug Plugin file, plugin folder name or theme stylesheet.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function installed( $type, $slug ) {
		$slug = trim( (string) $slug );

		if ( '' === $slug ) {
			return $this->error( 'invalid_input', __( 'A slug is required.', 'super-abilities' ), 400 );
		}

		if ( 0 !== validate_file( $slug ) ) {
			return $this->error( 'invalid_input', __( 'That slug is not a valid plugin file or theme stylesheet.', 'super-abilities' ), 400 );
		}

		$described = Target::describe( $type, $slug );

		if ( null === $described ) {
			return $this->error(
				'not_found',
				sprintf(
					/* translators: %s: Plugin file or theme stylesheet. */
					__( 'Nothing is installed under "%s". Call super-abilities/extensions-list to see what is.', 'super-abilities' ),
					$slug
				),
				404,
				array( 'slug' => $slug )
			);
		}

		if ( Target::TYPE_PLUGIN === Target::normalize_type( $type ) && Target::is_mu_or_dropin( $slug ) ) {
			return $this->error(
				'unsupported',
				__( 'Must-use plugins and drop-ins are installed by hand and cannot be managed through this ability.', 'super-abilities' ),
				501,
				array( 'slug' => $slug )
			);
		}

		return $described;
	}

	/**
	 * Input property describing the extension type.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $with_all Optional. Whether to offer the `all` value. Default false.
	 * @return array<string, mixed>
	 */
	protected static function type_property( $with_all = false ) {
		return array(
			'type'        => 'string',
			'enum'        => $with_all ? array( 'plugin', 'theme', 'all' ) : array( 'plugin', 'theme' ),
			'default'     => $with_all ? 'all' : 'plugin',
			'description' => $with_all
				? __( 'Which kind of extension to look at. Default all.', 'super-abilities' )
				: __( 'Whether the slug names a plugin or a theme. Default plugin.', 'super-abilities' ),
		);
	}

	/**
	 * Input property describing the slug.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description Field description.
	 * @return array<string, mixed>
	 */
	protected static function slug_property( $description ) {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 255,
			'description' => (string) $description,
		);
	}

	/**
	 * Output schema for a list of pre-flight findings.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description Field description.
	 * @return array<string, mixed>
	 */
	protected static function findings_schema( $description ) {
		return array(
			'type'        => 'array',
			'description' => (string) $description,
			'items'       => Schema::object(
				array(
					'code'    => array(
						'type'        => 'string',
						'description' => __( 'Stable machine readable finding code.', 'super-abilities' ),
					),
					'message' => array(
						'type'        => 'string',
						'description' => __( 'Human readable explanation of the finding.', 'super-abilities' ),
					),
				),
				array( 'code', 'message' )
			),
		);
	}

	/**
	 * Output schema for the pre-flight result.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected static function preflight_schema() {
		return Schema::object(
			array(
				'ok'                => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the operation can proceed: true when there are no blockers.', 'super-abilities' ),
				),
				'type'              => array( 'type' => 'string' ),
				'action'            => array( 'type' => 'string' ),
				'slug'              => array( 'type' => 'string' ),
				'installed'         => array( 'type' => 'boolean' ),
				'active'            => array( 'type' => 'boolean' ),
				'network_active'    => array( 'type' => 'boolean' ),
				'is_mu'             => array( 'type' => 'boolean' ),
				'filesystem_method' => array(
					'type'        => 'string',
					'description' => __( 'Transport WordPress would use to write files. Only "direct" works without credentials.', 'super-abilities' ),
				),
				'maintenance_mode'  => array( 'type' => 'boolean' ),
				'disk_free_bytes'   => array( 'type' => array( 'integer', 'null' ) ),
				'required_bytes'    => array( 'type' => 'integer' ),
				'installed_version' => array( 'type' => 'string' ),
				'blockers'          => self::findings_schema( __( 'Findings that abort the operation.', 'super-abilities' ) ),
				'warnings'          => self::findings_schema( __( 'Findings that are reported but do not abort the operation.', 'super-abilities' ) ),
			),
			array( 'ok', 'type', 'action', 'slug', 'installed', 'active', 'network_active', 'is_mu', 'filesystem_method', 'maintenance_mode', 'disk_free_bytes', 'required_bytes', 'installed_version', 'blockers', 'warnings' )
		);
	}

	/**
	 * Output schema for the resolved package description.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected static function package_schema() {
		return Schema::object(
			array(
				'source'       => array(
					'type'        => 'string',
					'description' => __( 'Either wordpress.org or zip_url.', 'super-abilities' ),
				),
				'slug'         => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'version'      => array( 'type' => 'string' ),
				'requires_wp'  => array( 'type' => array( 'string', 'null' ) ),
				'requires_php' => array( 'type' => array( 'string', 'null' ) ),
				'tested'       => array( 'type' => array( 'string', 'null' ) ),
				'last_updated' => array( 'type' => array( 'string', 'null' ) ),
				'host'         => array(
					'type'        => 'string',
					'description' => __( 'Host the package would be downloaded from. The full URL is never returned.', 'super-abilities' ),
				),
				'size_bytes'   => array(
					'type'        => 'integer',
					'description' => __( 'Package size in bytes, or zero when the host did not report one.', 'super-abilities' ),
				),
			),
			array( 'source', 'slug', 'name', 'version', 'requires_wp', 'requires_php', 'tested', 'last_updated', 'host', 'size_bytes' )
		);
	}

	/**
	 * Output schema for the smoke test result.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected static function smoke_schema() {
		return Schema::object(
			array(
				'passed'              => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the site still answers. True when the test was skipped.', 'super-abilities' ),
				),
				'ran'                 => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the probes were actually performed.', 'super-abilities' ),
				),
				'plugin_file_present' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this plugin\'s own main file is still on disk.', 'super-abilities' ),
				),
				'probes'              => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'name'      => array( 'type' => 'string' ),
							'url'       => array( 'type' => 'string' ),
							'status'    => array(
								'type'        => 'string',
								'enum'        => array( 'ok', 'failed', 'unverified' ),
								'description' => __( 'ok for a 2xx to 4xx answer, failed for a 5xx, unverified when the loopback could not be made.', 'super-abilities' ),
							),
							'http_code' => array( 'type' => 'integer' ),
							'message'   => array( 'type' => 'string' ),
						),
						array( 'name', 'url', 'status', 'http_code', 'message' )
					),
				),
			),
			array( 'passed', 'ran', 'plugin_file_present', 'probes' )
		);
	}

	/**
	 * Output schema for one restore point.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected static function restore_point_schema() {
		return Schema::object(
			array(
				'id'         => array( 'type' => 'string' ),
				'type'       => array( 'type' => 'string' ),
				'slug'       => array( 'type' => 'string' ),
				'name'       => array( 'type' => 'string' ),
				'version'    => array( 'type' => 'string' ),
				'created'    => Schema::iso_datetime( __( 'When the restore point was taken.', 'super-abilities' ) ),
				'size_bytes' => array( 'type' => 'integer' ),
				'was_active' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the extension was active when the restore point was taken.', 'super-abilities' ),
				),
			),
			array( 'id', 'type', 'slug', 'name', 'version', 'created', 'size_bytes', 'was_active' )
		);
	}

	/**
	 * Reduces a restore point to the fields the output schema describes.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $meta Restore point metadata.
	 * @return array<string, mixed>
	 */
	protected static function public_restore_point( array $meta ) {
		return array(
			'id'         => isset( $meta['id'] ) ? (string) $meta['id'] : '',
			'type'       => isset( $meta['type'] ) ? (string) $meta['type'] : '',
			'slug'       => isset( $meta['slug'] ) ? (string) $meta['slug'] : '',
			'name'       => isset( $meta['name'] ) ? (string) $meta['name'] : '',
			'version'    => isset( $meta['version'] ) ? (string) $meta['version'] : '',
			'created'    => isset( $meta['created'] ) ? (string) $meta['created'] : '',
			'size_bytes' => isset( $meta['size_bytes'] ) ? (int) $meta['size_bytes'] : 0,
			'was_active' => ! empty( $meta['was_active'] ),
		);
	}
}
