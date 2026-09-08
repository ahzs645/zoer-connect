=== Zoer Connect ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticated connection and private, verified file staging for Zoer.

== Description ==
Staging preview only. This version does not publish files, import databases, create backups or perform rollback. It never modifies live content. An administrator application password and HTTPS are required.

== Installation ==
Upload the plugin ZIP, activate, and open Tools > Zoer Connect. Configure private storage outside the web root if the default sibling directory is unavailable. Revoke the application password to disconnect. Cancel staged jobs before uninstalling; uninstall preserves data.

== Changelog ==
= 0.1.0 =
Initial authenticated staging API and reproducible ZIP build.
