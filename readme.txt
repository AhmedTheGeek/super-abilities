=== Super Abilities ===
Contributors: ahmedhussein
Tags: ai, abilities, mcp, agent, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced, capability-gated abilities that let AI agents manage a WordPress site through the core Abilities API.

== Description ==

Super Abilities registers advanced abilities with the WordPress Abilities API so AI agents can do real work on a site: run diagnostics, audit security, queue long jobs, edit block trees and global styles, install extensions with a restore point, and read a queryable trail of everything an agent did.

There is no bundled MCP server and no CLI commands. Anything that speaks the Abilities API reaches these abilities already: the core REST endpoints under `/wp-abilities/v1/`, the MCP Adapter plugin, WPVibe, and `wp ability run`.

Safety is built into each ability rather than bolted on:

* Every ability requires a logged-in user and checks real WordPress capabilities.
* Every attempt, including denied and invalid ones, is written to an audit log you can query as an ability.
* Abilities are grouped into modules you switch on and off. The risky ones ship off.
* Writes trash instead of deleting, create revisions, use allowlists, and can be pinned to a fingerprint so a stale edit is rejected with a 409 instead of clobbering someone else's work.
* Outbound requests are HTTPS-only, validated against SSRF, size-capped and time-limited.

== Installation ==

1. Upload the plugin to `wp-content/plugins/super-abilities` and activate it.
2. Visit Settings, Super Abilities to choose which modules are on.
3. Point your agent at the site. Call `super-abilities/catalog` first to see what is available.

== Frequently Asked Questions ==

= Does this give an AI agent unrestricted access to my site? =

No. Every ability runs as the authenticated WordPress user and is checked against that user's capabilities. An agent authenticated as a subscriber can do what a subscriber can do.

= Which WordPress version do I need? =

WordPress 6.9 or newer, because that is when the Abilities API landed. On WordPress 7.1 and newer the audit log can also see denied and invalid attempts made against other plugins' abilities.

= Where is the data stored? =

In custom tables prefixed `sa_` and one autoloaded option. Deleting the plugin removes them when the "Delete data" setting is on.

== Changelog ==

= 0.1.1 =
* Fix: audit-summary group counts were always empty (SQL argument order).
* Fix: cron-run no longer deletes a future recurring event when it is run early.
* Fix: jobs no longer trigger a core notice when a target ability is unregistered.
* Fix: integrity-check accepts plugin directories with uppercase letters or dots.

= 0.1.0 =
* Initial release.
