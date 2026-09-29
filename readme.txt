=== Zoer Connect ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticated WordPress transfers and recovery for Zoer.

== Description ==
Connect WordPress to Zoer using a scoped connector key over HTTPS. Pull selected resources or push a verified export from a managed local DDEV source. Imports preserve destination URLs, administrator accounts and connector identity, stage matching database tables, and retain file/table backups for verified rollback.

Generating a new connection key enables Push and automatically prepares shared-hosting migration. Pull stays off until enabled. Shared-hosting migration installs site-level MU request protection without inspecting or restarting shared PHP workers. Publishing still requires confirmation of selected content in Zoer. Existing permissions, revocations and advanced setup are preserved. It keeps original tables and selected files for recovery. Concurrent live edits are not merged and may be overwritten; avoid editing during migration. Earlier requests and external writers are not guaranteed to be stopped. Detected conflicts can halt activation or prevent automatic rollback. Advanced verified-worker mode retains the stricter Linux worker checks.

WordPress requests reaching the protection pause during application and recovery; authenticated recovery remains available before regular plugins/themes load. The site needs coherent private journal storage and functioning filesystem/database locks. This does not provide distributed multi-host coordination.

Supported: matching existing InnoDB schemas with primary keys, selected themes/plugins/media, SQL up to 2 GiB and individual files and originals up to 2 GiB with block-capable Zoer (older clients retain the 32 MiB path). Core, MU plugins, connector files, configuration, unsafe paths, foreign keys/triggers, early drop-ins, multisite and distributed multi-host storage are unsupported. Destination user identities remain intact. Rollback refuses substantive edits made after completion.

Large local exports run in a DDEV CLI worker independently of HTTP time limits. Hosted remote database Pull remains bounded to a single request and fails closed if interrupted. Retain the original connection key for recovering an active job; automatic key-generation rebinding is unavailable.

== Installation ==
Upload and activate the plugin. Open Tools > Zoer Connect to generate connection info and configure direction permissions. New connection keys prepare shared-hosting migration automatically. If storage or hosting compatibility blocks preparation, resolve the administrator diagnostics and save Push permissions to retry; existing incomplete setups retain manual recovery controls. Private storage must be outside the public document root. Deactivation/uninstall preserve private journals; do not remove the connector or MU bootstrap during an active transfer.

== Changelog ==

= 0.3.14 =
* Enable Push for new keys and prepare shared-hosting migration during key generation. Preserve existing permission choices, revocation and advanced setup; keep per-transfer confirmation in Zoer.

= 0.3.14 =
Preserve the destination's search-engine visibility during database Push. After a completed Push or rollback, refresh permalink rules once on a normal WordPress request, without rewriting the host's .htaccess file.

= 0.3.12 =
Exclude Finder metadata and repository control files from new exports by default, so hosted Pulls no longer fail on files that cannot be imported. Existing export profiles retain their other selections and exclusions.

= 0.3.11 =
Accept equivalent MySQL integer display widths during database import. Discover qualified updates through the public GitHub release repository, with checksum verification before installation.

= 0.3.10 =
Support shared hosts that disable PHP hard links: atomically install flushed protection files under the setup lock and fall back to uploading artifacts.

= 0.3.9 =
Shared-hosting cache drop-in coexistence, database-backed recovery authentication, and retryable object-cache invalidation before reopening. Page caches must exclude connector API routes and be purged separately.

= 0.3.8 =
* Add authenticated destination file comparison for selective Push.
* Reject selected file imports when the destination changed after preview.
* Comparison excludes protected paths and destination files over 32 MiB; full-export chunked transfers remain available.

= 0.3.7 =
* Add bounded resumable file scanning, manifest pages and batched downloads for updated Zoer clients.
* Report allowlisted export blockers without exposing raw exceptions.
* Safe storage diagnostics and first-time private-folder setup in WordPress admin.
* Preserve the configured destination storage location and exclude it from exports.

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
