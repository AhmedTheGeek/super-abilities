# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

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
