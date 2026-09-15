# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-09-15

### Added

- Design module (`design`, medium risk, on by default): `global-styles-read`, `global-styles-write`,
  `theme-mods-read`, `theme-mods-write`, `templates-list`, `template-read`, `template-write`,
  `template-reset`, `patterns-list`, `pattern-read`, `pattern-write`, `pattern-delete`. Template
  overrides and patterns are written through `wp_update_post()` so revisions exist; theme.json on
  disk is never touched; templates need a block theme and answer 501 otherwise.
- Blocks module (`blocks`, medium risk, on by default): `blocks-read`, `blocks-find`, `blocks-update`,
  `blocks-insert`, `blocks-remove`, `blocks-move`, `blocks-render`, `blocks-replace-text`. Blocks are
  addressed by dotted paths into the parsed tree (`2.1.0`); every write takes an optional
  `expected_fingerprint` and `dry_run`, honours post locks and refuses `<script`, `<?php` and
  `javascript:` content.
- Extensions module (`extensions`, high risk, off by default): `extensions-list`, `extension-preflight`,
  `extension-install`, `extension-update`, `extension-rollback`, `extension-activate`,
  `extension-deactivate`, `extension-delete`, `restore-points-list`, `restore-point-delete`. Updates
  and deletes take a restore point under `uploads/super-abilities/restore/` first, installs and
  activations run a loopback smoke test and undo themselves on a 5xx, and ZIP URLs are only
  accepted when the setting allows them and the host is on the allowlist.
- Access module (`access`, high risk, off by default): `roles-list`, `capability-explain`,
  `role-create`, `role-delete`, `capabilities-grant`, `capabilities-revoke`, `user-roles-read`,
  `user-roles-assign`, `access-audit`. A caller can only grant capabilities they hold, the
  `administrator` role is protected, self-demotion and removing the last administrator are refused,
  and a fixed list of capabilities such as `unfiltered_html` can never be granted.
- Redirects module (`redirects`, high risk, off by default): `redirects-list`, `redirect-read`,
  `redirect-create`, `redirect-update`, `redirect-delete`, `redirect-test`, `redirects-import`,
  `redirects-stats`, backed by a new `sa_redirects` table and served on `template_redirect`. Sources
  that hit reserved paths or existing content are refused, loops are detected across the rule set,
  and external targets need `allow_external`.
- Filters `super_abilities_forbidden_block_markup`, `super_abilities_protected_theme_mods`,
  `super_abilities_protected_extensions`, `super_abilities_protected_roles`,
  `super_abilities_forbidden_capabilities` and `super_abilities_reserved_redirect_paths`.
- Options `super_abilities_restore_points` and `super_abilities_redirects_table_version`; both, the
  `sa_redirects` table and the restore point directory are removed on uninstall.

## [0.1.1] - 2026-09-12

### Fixed

- `audit-summary` bound its query arguments in the wrong order, so `groups[]` was always empty.
- `cron-run` unscheduled the instance it had just rescheduled when a future recurring event
  was run early; it now unschedules first and reports `rescheduled` from observed state.
- Jobs guard `wp_get_ability()` with `wp_has_ability()` so an unregistered target no longer
  triggers a core incorrect-usage notice.
- `integrity-check` no longer lowercases plugin slugs, so directories with uppercase letters
  or dots can be verified.
- `Redactor` masks key/value pairs before literal secrets, and short DB identifiers are no
  longer treated as secrets.

### Added

- `super_abilities_debug_log_path` filter, `bin/test-local.sh`, and integration coverage that
  validates every health and security ability's output against its own schema.

## [0.1.0] - 2026-09-12

### Added

- Audit module: `super-abilities/audit-query` and `audit-summary`; every ability call on the
  site (ours and other plugins') is logged with outcome, duration, actor and object, never
  argument values. Version-aware listener for the 6.9 and 7.1 core hooks.
- Jobs module: `job-start`, `job-status`, `job-list`, `job-cancel`; run any ability over many
  inputs in the background under a time budget, as the calling user, with cancel and progress.
- Diagnostics module: `health-run`, `health-summary`, `debug-log-read`, `error-triage`,
  `cron-list`, `cron-health`, `cron-run`, `cron-unschedule`.
- Security audit module: `integrity-check`, `file-permissions-audit`, `admin-users-audit`,
  `config-hardening-check`, `updates-overview` (all read-only).
- Plugin foundation: bootstrap, PSR-4 autoloader, composition root, settings, module
  registry, ability registrar and install/upgrade routine.
- `Abilities\Abstract_Ability`, the base class every ability extends: namespaced name,
  category slug, `wp_register_ability()` argument builder, capability gate, exception
  boundary, fingerprint guard and annotation presets.
- `super-abilities/catalog`, a read-only ability that lists every module and ability on
  the site, including modules that are switched off.
- Support library: fingerprints, secret redaction, guarded HTTP, capability helpers,
  schema fragments, error codes, time parsing and version feature detection.
- Settings screen under Settings, Super Abilities with module toggles, retention and
  limit settings, and a status panel.
- Tables `sa_audit_log`, `sa_jobs` and `sa_job_items`, plus the `super_abilities_heartbeat`,
  `super_abilities_audit_prune` and `super_abilities_jobs_prune` cron events.
