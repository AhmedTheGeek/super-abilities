<?php
/**
 * Installed plugin and theme inventory.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Extensions;

use SuperAbilities\Extensions\Restore_Point;
use SuperAbilities\Extensions\Target;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every installed plugin and theme with the facts the other abilities in this
 * module need: the exact slug to address it by, its version, whether it is active, and
 * whether an update is waiting.
 *
 * @since 0.2.0
 */
class Extensions_List extends Extensions_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'extensions-list';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'List plugins and themes', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Lists the installed plugins and themes with the identifier every other extensions ability expects: the plugin file for a plugin (akismet/akismet.php) and the stylesheet directory for a theme. Each row reports the version, author, active and network active state, whether an update is waiting and which version it is, the WordPress and PHP versions it requires, whether automatic updates are on, and the id of the newest restore point for it. Must-use plugins are listed with is_mu true; they are installed by hand and cannot be managed by these abilities. Requires activate_plugins for plugins and switch_themes for themes.', 'super-abilities' );
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
		return array( 'activate_plugins' );
	}

	/**
	 * Requires the capability that matches the requested type.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|\WP_Error
	 */
	public function permission( array $input ) {
		return $this->guard( 'list', $input );
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
				'type'   => self::type_property( true ),
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive', 'update_available', 'all' ),
					'default'     => 'all',
					'description' => __( 'Only return rows in this state. Default all.', 'super-abilities' ),
				),
				'search' => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Case insensitive substring match against the name, slug and author.', 'super-abilities' ),
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
		$item = Schema::object(
			array(
				'type'             => array( 'type' => 'string' ),
				'slug'             => array(
					'type'        => 'string',
					'description' => __( 'Plugin file for a plugin, stylesheet directory for a theme.', 'super-abilities' ),
				),
				'wporg_slug'       => array(
					'type'        => 'string',
					'description' => __( 'The WordPress.org style slug, which is the folder name.', 'super-abilities' ),
				),
				'name'             => array( 'type' => 'string' ),
				'version'          => array( 'type' => 'string' ),
				'author'           => array( 'type' => 'string' ),
				'active'           => array( 'type' => 'boolean' ),
				'network_active'   => array( 'type' => 'boolean' ),
				'update_available' => array( 'type' => 'boolean' ),
				'new_version'      => array( 'type' => array( 'string', 'null' ) ),
				'requires_wp'      => array( 'type' => array( 'string', 'null' ) ),
				'requires_php'     => array( 'type' => array( 'string', 'null' ) ),
				'is_mu'            => array( 'type' => 'boolean' ),
				'auto_update'      => array( 'type' => 'boolean' ),
				'restore_point_id' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Id of the newest restore point for this extension, or null when there is none.', 'super-abilities' ),
				),
				'fingerprint'      => Schema::fingerprint( __( 'Pass this back as expected_fingerprint when updating or deleting this extension.', 'super-abilities' ) ),
			),
			array( 'type', 'slug', 'wporg_slug', 'name', 'version', 'author', 'active', 'network_active', 'update_available', 'new_version', 'requires_wp', 'requires_php', 'is_mu', 'auto_update', 'restore_point_id', 'fingerprint' )
		);

		return Schema::object(
			array(
				'items'       => array(
					'type'  => 'array',
					'items' => $item,
				),
				'total'       => array( 'type' => 'integer' ),
				'totals'      => Schema::object(
					array(
						'plugins'          => array( 'type' => 'integer' ),
						'themes'           => array( 'type' => 'integer' ),
						'active'           => array( 'type' => 'integer' ),
						'inactive'         => array( 'type' => 'integer' ),
						'update_available' => array( 'type' => 'integer' ),
						'must_use'         => array( 'type' => 'integer' ),
					),
					array( 'plugins', 'themes', 'active', 'inactive', 'update_available', 'must_use' )
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the whole inventory.', 'super-abilities' ) ),
			),
			array( 'items', 'total', 'totals', 'fingerprint' )
		);
	}

	/**
	 * Builds the inventory.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ) {
		$type   = $this->input_type( $input, 'all' );
		$status = isset( $input['status'] ) ? strtolower( trim( (string) $input['status'] ) ) : 'all';
		$search = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';

		if ( ! in_array( $status, array( 'active', 'inactive', 'update_available', 'all' ), true ) ) {
			$status = 'all';
		}

		$rows = array();

		if ( 'all' === $type || Target::TYPE_PLUGIN === $type ) {
			$rows = array_merge( $rows, $this->plugin_rows() );
		}

		if ( 'all' === $type || Target::TYPE_THEME === $type ) {
			$rows = array_merge( $rows, $this->theme_rows() );
		}

		$totals = array(
			'plugins'          => 0,
			'themes'           => 0,
			'active'           => 0,
			'inactive'         => 0,
			'update_available' => 0,
			'must_use'         => 0,
		);

		$items = array();

		foreach ( $rows as $row ) {
			if ( Target::TYPE_THEME === $row['type'] ) {
				++$totals['themes'];
			} else {
				++$totals['plugins'];
			}

			if ( $row['active'] ) {
				++$totals['active'];
			} else {
				++$totals['inactive'];
			}

			if ( $row['update_available'] ) {
				++$totals['update_available'];
			}

			if ( $row['is_mu'] ) {
				++$totals['must_use'];
			}

			if ( ! $this->matches_status( $row, $status ) ) {
				continue;
			}

			if ( '' !== $search && ! $this->matches_search( $row, $search ) ) {
				continue;
			}

			$items[] = $row;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				if ( $a['type'] === $b['type'] ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}

				return strcmp( (string) $a['type'], (string) $b['type'] );
			}
		);

		return array(
			'items'       => $items,
			'total'       => count( $items ),
			'totals'      => $totals,
			'fingerprint' => Fingerprint::of_array( wp_list_pluck( $rows, 'fingerprint', 'slug' ) ),
		);
	}

	/**
	 * Every installed plugin, including must-use plugins.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function plugin_rows() {
		$rows = array();

		foreach ( array_keys( Target::plugins() ) as $file ) {
			$described = Target::describe_plugin( (string) $file );

			if ( null === $described ) {
				continue;
			}

			$rows[] = $this->row( $described );
		}

		foreach ( Target::mu_plugins() as $file => $data ) {
			$rows[] = array(
				'type'             => Target::TYPE_PLUGIN,
				'slug'             => (string) $file,
				'wporg_slug'       => Target::plugin_slug( (string) $file ),
				'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : (string) $file,
				'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'author'           => isset( $data['Author'] ) ? (string) wp_strip_all_tags( (string) $data['Author'] ) : '',
				'active'           => true,
				'network_active'   => is_multisite(),
				'update_available' => false,
				'new_version'      => null,
				'requires_wp'      => Target::requirement( isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : null ),
				'requires_php'     => Target::requirement( isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : null ),
				'is_mu'            => true,
				'auto_update'      => false,
				'restore_point_id' => null,
				'fingerprint'      => Fingerprint::of_array(
					array(
						'type'    => Target::TYPE_PLUGIN,
						'slug'    => (string) $file,
						'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
						'active'  => true,
					)
				),
			);
		}

		return $rows;
	}

	/**
	 * Every installed theme.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function theme_rows() {
		$rows = array();

		foreach ( array_keys( wp_get_themes( array( 'errors' => null ) ) ) as $stylesheet ) {
			$described = Target::describe_theme( (string) $stylesheet );

			if ( null === $described ) {
				continue;
			}

			$rows[] = $this->row( $described );
		}

		return $rows;
	}

	/**
	 * Turns a described extension into an output row.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $described Result of `Target::describe()`.
	 * @return array<string, mixed>
	 */
	protected function row( array $described ) {
		$new_version   = Target::new_version( (string) $described['type'], (string) $described['slug'] );
		$restore_point = Restore_Point::newest_for( (string) $described['type'], (string) $described['slug'] );

		return array(
			'type'             => (string) $described['type'],
			'slug'             => (string) $described['slug'],
			'wporg_slug'       => (string) $described['wporg_slug'],
			'name'             => (string) $described['name'],
			'version'          => (string) $described['version'],
			'author'           => (string) $described['author'],
			'active'           => (bool) $described['active'],
			'network_active'   => (bool) $described['network_active'],
			'update_available' => '' !== $new_version,
			'new_version'      => '' === $new_version ? null : $new_version,
			'requires_wp'      => $described['requires_wp'],
			'requires_php'     => $described['requires_php'],
			'is_mu'            => false,
			'auto_update'      => (bool) $described['auto_update'],
			'restore_point_id' => null === $restore_point ? null : (string) $restore_point['id'],
			'fingerprint'      => Target::fingerprint( $described ),
		);
	}

	/**
	 * Whether a row survives the status filter.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row    Output row.
	 * @param string               $status Status filter.
	 * @return bool
	 */
	protected function matches_status( array $row, $status ) {
		if ( 'active' === $status ) {
			return (bool) $row['active'];
		}

		if ( 'inactive' === $status ) {
			return ! $row['active'];
		}

		if ( 'update_available' === $status ) {
			return (bool) $row['update_available'];
		}

		return true;
	}

	/**
	 * Whether a row survives the search filter.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row    Output row.
	 * @param string               $search Lowercased needle.
	 * @return bool
	 */
	protected function matches_search( array $row, $search ) {
		$haystack = strtolower( (string) $row['name'] . ' ' . (string) $row['slug'] . ' ' . (string) $row['author'] );

		return false !== strpos( $haystack, $search );
	}
}
