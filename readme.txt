=== Zoer Connect ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticated WordPress transfers and recovery for Zoer.

== Description ==
Connect WordPress to Zoer using a scoped connector key over HTTPS. Pull selected resources or push a verified export from a managed local DDEV source. Imports preserve destination URLs, administrator accounts and connector identity, stage matching database tables, and retain file/table backups for verified rollback.

Imports require explicit destination setup in WordPress. Shared-hosting migration installs site-level MU request protection and records consent to replace selected content, without inspecting or restarting shared PHP workers. It keeps original tables and selected files for recovery. Concurrent live edits are not merged and may be overwritten; avoid editing during migration. Earlier requests and external writers are not guaranteed to be stopped. Detected conflicts can halt activation or prevent automatic rollback. Advanced verified-worker mode retains the stricter Linux worker checks.

WordPress requests reaching the protection pause during application and recovery; authenticated recovery remains available before regular plugins/themes load. The site needs coherent private journal storage and functioning filesystem/database locks. This does not provide distributed multi-host coordination.

Supported: matching existing InnoDB schemas with primary keys, selected themes/plugins/media, SQL up to 2 GiB and individual files and originals up to 2 GiB with block-capable Zoer (older clients retain the 32 MiB path). Core, MU plugins, connector files, configuration, unsafe paths, foreign keys/triggers, early drop-ins, multisite and distributed multi-host storage are unsupported. Destination user identities remain intact. Rollback refuses substantive edits made after completion.

Large local exports run in a DDEV CLI worker independently of HTTP time limits. Hosted remote database Pull remains bounded to a single request and fails closed if interrupted. Retain the original connection key for recovering an active job; automatic key-generation rebinding is unavailable.

== Installation ==
Upload and activate the plugin. Open Tools > Zoer Connect to generate connection info and configure direction permissions. Complete the separate import setup before enabling destination publication. Private storage must be outside the public document root. Deactivation/uninstall preserve private journals; do not remove the connector or MU bootstrap during an active transfer.

== Changelog ==

= 0.3.5 =
Resumable block verification, backup, activation and restore for individual files up to 2 GiB. Atomic renames, conflict preflight, permissions and old-job recovery are retained. Requires a block-capable Zoer backend.

= 0.3.4 =
Preserve unrelated serialized plugin state and reuse matching terminal uploads with fresh verification.

= 0.3.3 =
Accept legitimate WordPress font commas and Unicode image filenames while retaining traversal, control-character, executable-upload and symlink protections.

= 0.3.2 =
Explicit shared-hosting replacement mode without process inventory requirements. Original tables/files and interruption recovery are retained. Bounded file-operation batches reduce maintenance time. Concurrent edits are not merged.

= 0.3.1 =
WordPress-only request adoption checks replace the hosting restart requirement. Earlier unprotected processes keep imports disabled until they finish or enter protection. No processes are restarted or signalled. Requires readable Linux process information and standard single-threaded PHP-FPM, CGI or LSAPI workers.
= 0.3.0 =
Selected remote Pull, resumable authenticated imports, background DDEV export integration, destination identity preservation, request fencing, staged activation and verified rollback.

= 0.2.0 =
Built-in connection info, one-time key display, reset/revoke, and enforced staging permission.

= 0.1.0 =
Initial authenticated staging API and reproducible ZIP build.
