# Super Abilities

Advanced, capability-gated abilities that let AI agents manage a WordPress site through
the core [Abilities API](https://developer.wordpress.org/plugins/abilities-api/).

Free, GPL-2.0-or-later, WordPress 6.9+, PHP 8.0+.

## What it is

WordPress 6.9 shipped the Abilities API and three read-only abilities. Every AI client
that talks to WordPress — the MCP Adapter, WPVibe, `wp ability run`, the core AI client —
can call abilities, but there are very few worth calling. Super Abilities fills that gap
with the operations an agent actually needs to run a site, grouped into modules you switch
on and off, each one gated by real WordPress capabilities and recorded in an audit log.

There is no bundled MCP server, no CLI commands and no approval queue. This plugin only
registers abilities; the transport is whatever the site already has.

## Modules

| Module | Default | Risk | What it covers |
| --- | --- | --- | --- |
| `audit` | on | low | Queryable trail of every ability call on the site, ours and other plugins', including denied and invalid attempts. |
| `jobs` | on | low | Queue any ability over many inputs and run it in the background under a time budget. |
| `health` | on | low | Site Health as abilities, parsed debug log, error triage correlated with recent changes, cron list/health/run/unschedule. |
| `security` | on | low | Core and plugin checksum verification, file permission audit, administrator audit, config hardening review, update overview. |
| `design` | on | medium | Read and write global styles, theme mods, block templates and patterns. |
| `blocks` | on | medium | Read and edit the block tree of a post by path, builder-agnostic, with a preview renderer. |
| `media` | on (planned v0.2) | low | Regenerate and convert images, find unused attachments, audit and set alt text, import from a URL. |
| `extensions` | **off** | high | Plugin and theme install, update and rollback with a pre-flight check, a restore point and a smoke test. |
| `access` | **off** | high | Roles and capabilities: explain, create, grant, revoke, assign. |
| `redirects` | **off** | high | Manage simple redirects with loop and reserved-path guards. |

`super-abilities/catalog` is always registered and lists every module, including the ones
that are off, so an agent can tell "this site cannot do that" from "an administrator
switched that off".

## Install

Download the ZIP from the [releases page](../../releases), upload it under
Plugins, Add New, Upload Plugin, and activate. Then visit **Settings, Super Abilities**
to choose your modules.

From source:

```sh
git clone https://github.com/ahmedhussein/super-abilities.git wp-content/plugins/super-abilities
cd wp-content/plugins/super-abilities
composer install
composer zip   # builds dist/super-abilities.zip
```

## Security model

- **Authentication is WordPress authentication.** Abilities run as the authenticated user.
  An agent using an application password for a subscriber can do what that subscriber can do.
- **Authorization is WordPress capabilities.** Every ability declares the capabilities it
  requires and all of them are checked with `current_user_can()` before anything runs.
  Object-level abilities check the object too (`edit_post`, `read_post`, `edit_user`).
- **Honest annotations.** Each ability declares `readonly`, `destructive` and `idempotent`,
  which is what the REST layer turns into a GET, POST or DELETE and what MCP clients show
  the user before they approve a call.
- **Everything is logged.** Successes, errors, denials and invalid input all land in
  `{prefix}sa_audit_log` with the ability name, transport, user, application password uuid,
  outcome, error code, duration and the *keys* of the input — never the values.
- **Writes are recoverable.** Post edits go through `wp_update_post()` so revisions are
  created, deletes prefer the trash, and installs and updates take a restore point first.
- **Stale writes are rejected.** Abilities with a stable base accept an optional
  `expected_fingerprint`; a mismatch returns 409 with the current fingerprint instead of
  overwriting someone else's change.
- **No secrets leave the site.** Database credentials, the eight salts, anything that looks
  like a key or token, and absolute paths are redacted from every output.
- **Outbound requests are guarded.** HTTPS only, `wp_http_validate_url()`,
  `reject_unsafe_urls`, a response size cap, a 15 second timeout and an optional host
  allowlist.

## How agents reach it

The abilities are registered with core, so every existing client works without extra setup.

**Core REST API** — list and run:

```sh
curl -u "user:application password" \
  "https://example.com/wp-json/wp-abilities/v1/abilities?category=super-abilities-health"

curl -u "user:application password" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"input":{"since":"-24 hours"}}' \
  "https://example.com/wp-json/wp-abilities/v1/abilities/super-abilities/error-triage/run"
```

Read-only abilities are exposed as GET and read their input from `input[...]` query
parameters; destructive and idempotent abilities are exposed as DELETE; everything else is
POST with a JSON body.

**MCP Adapter** — install the official [MCP Adapter](https://github.com/WordPress/mcp-adapter)
plugin and our abilities appear as MCP tools. Every ability sets `meta.mcp.public`.

**WPVibe** — `discover_abilities`, `get_ability_info` and `run_ability` see them
immediately on any connected site.

**WP-CLI** — `wp ability list --format=table` and
`wp ability run super-abilities/catalog`. Calls made this way are logged with the
`wp-cli` transport.

## Development

### Running the tests locally

The suites need a MySQL server and a WordPress core checkout. Write a `wp-tests-config.php`
(the wp-phpunit README documents the constants), point `WP_PHPUNIT__TESTS_CONFIG` at it and run:

```bash
composer install
WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php bin/test-local.sh
WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php bin/test-local.sh --testsuite unit
```

CI runs the same suites through wp-env on WordPress 6.9 and latest, PHP 8.0 and 8.3.


```sh
composer install
composer lint        # phpcs, WordPress-Extra + Docs, PHPCompatibilityWP 8.0-
composer lint:fix    # phpcbf
composer analyse     # phpstan level 6
composer test        # phpunit, needs a WordPress test suite
composer zip         # dist/super-abilities.zip
```

The test suite runs against [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```sh
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/super-abilities vendor/bin/phpunit
```

CI runs phpcs and phpstan on PHP 8.0 and 8.3, and the test suites on WordPress 6.9 and
latest against PHP 8.0 and 8.3.

### Layout

```
super-abilities.php          bootstrap: header, constants, autoloader, hooks
uninstall.php                drops tables, options and cron when asked to
src/Plugin.php               composition root
src/Options.php              the single autoloaded settings option
src/Install.php              dbDelta tables, cron scheduling, upgrades
src/Modules.php              ordered module registry
src/Registrar.php            registers categories and abilities with core
src/Abilities/               Abstract_Ability, Catalog_Ability, one folder per module
src/Modules/                 one *_Module class per module
src/Support/                 Fingerprint, Redactor, Http, Capabilities, Schema, Error, Time, Version
src/Admin/                   settings screen and notices
tests/                       unit and integration suites
```

Classes are autoloaded PSR-4 from `SuperAbilities\` to `src/`, with the file name matching
the class name exactly (`Abstract_Ability.php`).

### Writing an ability

```php
namespace SuperAbilities\Abilities\Health;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Support\Schema;

class Cron_List_Ability extends Abstract_Ability {

	public function slug() {
		return 'cron-list';
	}

	public function module() {
		return 'health';
	}

	public function label() {
		return __( 'List cron events', 'super-abilities' );
	}

	public function description() {
		return __( 'Lists every scheduled WP-Cron event with its next run time.', 'super-abilities' );
	}

	public function annotations() {
		return self::readonly();
	}

	public function capability() {
		return array( 'manage_options' );
	}

	public function input_schema() {
		return Schema::object( array( 'hook' => array( 'type' => 'string' ) ) );
	}

	public function output_schema() {
		return Schema::object( array( 'events' => array( 'type' => 'array' ) ), array( 'events' ) );
	}

	public function execute( array $input ) {
		return array( 'events' => array() );
	}
}
```

List the class in your module's `abilities()` and it is registered, categorised, gated and
logged for free.

### Hooks

Actions:

| Hook | Arguments | Fires |
| --- | --- | --- |
| `super_abilities_loaded` | `Plugin $plugin` | Once the plugin has wired its modules and abilities. |
| `super_abilities_permission_denied` | `string $name, array $input, string $code` | A caller was refused. `$code` is `not_logged_in`, `insufficient_capability`, `permission_denied` or the code of a `WP_Error` returned by `permission()`. |
| `super_abilities_ability_error` | `string $name, array $input, WP_Error $error` | An ability returned an error or threw. |
| `super_abilities_note_object` | `string $name, string $type, int\|string $id` | An ability reported the object it acted on. |
| `super_abilities_upgraded` | `int $to, int $from` | The schema was created or upgraded. |

Filters:

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `super_abilities_modules` | `array $modules, Plugin $plugin` | Add, remove or reorder modules. |
| `super_abilities_ability_enabled` | `bool $enabled, string $name, Abstract_Ability $ability` | Keep one ability out of the registry. |
| `super_abilities_ability_args` | `array $args, string $name, Abstract_Ability $ability` | Change the `wp_register_ability()` arguments. |
| `super_abilities_table_schema` | `array $queries` | Append `CREATE TABLE` statements for `dbDelta()`. |
| `super_abilities_sanitize_settings` | `array $clean, mixed $input` | Post-process sanitized settings. |
| `super_abilities_protected_cron_hooks` | `array $hooks` | Cron hooks `cron-unschedule` refuses without `force`. |
| `super_abilities_debug_log_path` | `string $path` | Where `debug-log-read` looks for the debug log. |
| `super_abilities_forbidden_block_markup` | `array $fragments` | Markup fragments `template-write` and `pattern-write` refuse. Default `<script`, `<?php`, `<?=`, `</script`. |
| `super_abilities_protected_theme_mods` | `array $keys` | Theme mods `theme-mods-write` never touches. Default empty. |
| `super_abilities_protected_extensions` | `array $basenames` | Plugins `extension-deactivate` and `extension-delete` refuse without `force`. Default this plugin. |
| `super_abilities_protected_roles` | `array $roles` | Roles that cannot be deleted or have capabilities revoked. Default `administrator`. |
| `super_abilities_forbidden_capabilities` | `array $caps` | Capabilities the `access` module never grants, such as `unfiltered_html` and `edit_plugins`. |
| `super_abilities_reserved_redirect_paths` | `array $prefixes` | Paths the `redirects` module refuses as a redirect source. |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
