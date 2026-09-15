<?php
/**
 * Redirects module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Redirects\Redirect_Create;
use SuperAbilities\Abilities\Redirects\Redirect_Delete;
use SuperAbilities\Abilities\Redirects\Redirect_Read;
use SuperAbilities\Abilities\Redirects\Redirect_Test;
use SuperAbilities\Abilities\Redirects\Redirect_Update;
use SuperAbilities\Abilities\Redirects\Redirects_Import;
use SuperAbilities\Abilities\Redirects\Redirects_List;
use SuperAbilities\Abilities\Redirects\Redirects_Stats;
use SuperAbilities\Abstract_Module;
use SuperAbilities\Redirects\Runtime;
use SuperAbilities\Redirects\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Simple source to target redirects, stored in their own table and served on
 * `template_redirect` before the canonical redirect runs.
 *
 * The module owns `{prefix}sa_redirects` and every write goes through the same guards:
 * paths WordPress needs are refused, targets are validated against `wp_http_validate_url()`
 * and kept on this host unless an administrator opts out, and loops are detected across
 * the whole rule set before a rule is stored.
 *
 * @since 0.2.0
 */
class Redirects_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id() {
		return 'redirects';
	}

	/**
	 * Module label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Redirects', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Manage simple redirects with loop and reserved-path guards. Rules live in their own table and are served on template_redirect, before the WordPress canonical redirect, with 301, 302, 307, 308 or a 410 Gone. Every write is guarded: the paths WordPress needs to keep working, such as wp-admin, the REST route, feeds and wp-content, can never be a source; the permalink of an existing published post is refused unless the caller overrides it; targets must be relative or pass wp_http_validate_url() and stay on this host unless the caller passes allow_external; and the whole rule set is walked before a rule is stored, so a redirect that would loop is rejected and one that would chain returns a warning. Because a bad rule can make a page unreachable for every visitor, the module is off by default.', 'super-abilities' );
	}

	/**
	 * Whether the module is enabled on a fresh install.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return false;
	}

	/**
	 * Risk level.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function risk() {
		return 'high';
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
			Redirects_List::class,
			Redirect_Read::class,
			Redirect_Create::class,
			Redirect_Update::class,
			Redirect_Delete::class,
			Redirect_Test::class,
			Redirects_Import::class,
			Redirects_Stats::class,
		);
	}

	/**
	 * Registers the table with the shared schema, however it is created.
	 *
	 * Runs whether or not the module is enabled, so that switching the module on never
	 * has to wait for a plugin upgrade.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function install() {
		add_filter( 'super_abilities_table_schema', array( $this, 'table_schema' ) );
		add_action( 'super_abilities_upgraded', array( $this, 'on_upgraded' ) );
	}

	/**
	 * Appends the redirects table to the statements `dbDelta()` receives.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $queries `CREATE TABLE` statements.
	 * @return array<int, string>
	 */
	public function table_schema( $queries ) {
		$queries   = is_array( $queries ) ? $queries : array();
		$queries[] = Store::schema();

		return $queries;
	}

	/**
	 * Records the table version after the plugin schema was created or upgraded.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function on_upgraded() {
		Store::install_table();
	}

	/**
	 * Creates the table if it is missing and wires the front end handler.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function boot() {
		Store::maybe_install();

		$runtime = new Runtime();
		$runtime->init();
	}
}
