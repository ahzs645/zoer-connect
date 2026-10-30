=== Zoer Connect ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticated WordPress transfers and recovery for Zoer.

== Description ==
Connect WordPress to Zoer using a scoped connector key over HTTPS. Pull selected resources or push a verified export from a managed local DDEV source. Imports preserve destination URLs, administrator accounts and connector identity, stage matching database tables, and retain file/table backups for verified rollback.

New connection keys start with Push, Pull and Code off; choosing Push when generating the key (or turning it on later) automatically prepares shared-hosting migration. Push stages and imports media uploads and the database. Writing plugin or theme files (PHP that WordPress runs) additionally needs the separate Code permission, which is off unless an administrator turns it on. Shared-hosting migration installs site-level MU request protection without inspecting or restarting shared PHP workers. Publishing still requires confirmation of selected content in Zoer. Existing permissions, revocations and advanced setup are preserved. It keeps original tables and selected files for recovery. Concurrent live edits are not merged and may be overwritten; avoid editing during migration. Earlier requests and external writers are not guaranteed to be stopped. Detected conflicts can halt activation or prevent automatic rollback. Advanced verified-worker mode retains the stricter Linux worker checks.

WordPress requests reaching the protection pause during application and recovery; authenticated recovery remains available before regular plugins/themes load. Exclude /wp-json/zoer-connect/ and equivalent rest_route URLs from every page cache/CDN before connecting, then purge existing entries. A no-store response alone cannot override a host configured to cache authenticated API responses. The site needs coherent private journal storage and functioning filesystem/database locks. This does not provide distributed multi-host coordination.

Supported: matching existing InnoDB schemas with primary keys, selected themes/plugins/media, SQL up to 4 GiB and individual files and originals up to 4 GiB with block-capable Zoer (older clients retain the 32 MiB path). Core, MU plugins, connector files, configuration, unsafe paths, foreign keys/triggers, early drop-ins, multisite and distributed multi-host storage are unsupported. WordPress may sit in a folder beneath the site address. Another installation's tables in the same database are never exported or replaced. Destination user identities remain intact. Rollback refuses substantive edits made after completion.

Pull can filter tables, post types, revisions, spam comments and transients, and select themes and plugins by mode or media by date. Imports can keep the site online while tables are staged, review changes before activation, apply custom find-and-replace rules, match authors and create missing tables from a validated schema. Updated Zoer clients upload many verified blocks per request. Read-only diagnostics report compatibility warnings before a transfer.

Large local exports run in a DDEV CLI worker independently of HTTP time limits. Updated Zoer servers use a configurable 16 GiB transfer quota (1 MiB–64 GiB), a 4 GiB per-artifact ceiling and repeated free-space checks. The local worker retains its 30-minute consistent-transaction deadline. Hosted read-only database Pull retains its 256 MiB/40-second snapshot. The optional source-pause mode exports larger InnoDB databases with typed table/row checkpoints and checksummed parts. It requires Push and Pull, request protection and explicit confirmation that earlier requests, scheduled jobs and external writers have stopped. It pauses WordPress while the database is prepared; cancel or successful sealing resumes it. An idle source pause expires after one hour and its old snapshot cannot resume. Native Tools > Zoer Connect configures the hosted quota; server and DDEV quotas use ZOER_WORDPRESS_TRANSFER_QUOTA_BYTES. Existing recovery data stays at its original location. Resetting the secret key is refused while an unfinished import depends on it. Jobs are never rebound automatically; after a revocation or reset, an administrator can continue one unfinished import with the current key from Activity > Transfers.

== Installation ==
Upload and activate the plugin. Open Tools > Zoer Connect to generate connection info and configure direction permissions. New connection keys prepare shared-hosting migration automatically. If storage or hosting compatibility blocks preparation, resolve the administrator diagnostics and save Push permissions to retry; existing incomplete setups retain manual recovery controls. Private storage must be outside the public document root. Deactivation/uninstall preserve private journals; do not remove the connector or MU bootstrap during an active transfer.

== Changelog ==

= 0.10.0 (development) =
Connection key scopes. New keys start with Push off, and Push no longer implies installing code: transfers that write wp-content/plugins or wp-content/themes, or replace the destination's plugin or theme selection, need the new Code permission (off by default, with a warning in Tools > Zoer Connect). Turning Code off stops an import that carries code from being applied; status, pause, finish, rollback and cleanup stay available. Keys created before 0.10.0 with Push keep code access so existing pairings keep working; the Permissions card flags them as legacy keys until an administrator saves a choice, and resetting such a key asks for Code again. GET /status reports permissions.code and permissions.legacyCode and the codeScope capability. Signed updates: release update.json files can carry an Ed25519 signature; when ZOER_CONNECT_UPDATE_PUBLIC_KEY (wp-config.php) or the plugin's built-in key is set, unsigned or badly signed updates are refused, and without a key administrators see a notice that updates are unsigned. Sites on the stable channel no longer accept a release candidate download. The import routes' REST fallback now authenticates in its permission callback.

= 0.9.1 =
Import setup replaces a request fence copied from another site. A site restored from a hosting backup, migrated or cloned from another Zoer Connect site arrives with that site's wp-content/mu-plugins/000-zoer-connect-fence.php, whose paths belong to the other installation; setup stopped with "Existing MU fence differs; inspect it before replacement". Setup now recognises an exact Zoer Connect fence written for other paths and installs this site's own. Any other file is still refused, and a fence for this folder with an earlier private storage folder is replaced only while that folder holds no paused transfer. Import readiness of upgraded sites is unchanged.

= 0.9.0 =
Includes the unreleased 0.8.1 retained-storage measurements and opt-in cleanup of old finished imports (off by default; the newest successful recovery point is always kept). Migration fixes: source-pause database exports import through Push and local copies; rows up to 16 MiB import, and a destination whose max_allowed_packet is too small is refused with that reason; resetting the secret key is refused while an unfinished import depends on it, and an administrator can continue an earlier key's import with the current key; another WordPress installation's tables in the same database are excluded from exports and refused by imports; find and replace keeps JSON valid and never changes binary columns; destinations with WordPress in a subdirectory are accepted; exports can include translations (wp-content/languages).

= 0.8.0 =
Adds an optional release-candidate update channel. In Tools → Zoer Connect → Settings → Updates, an administrator who can update plugins can also offer release candidates; the site then updates from the newest stable release or release candidate, still asking first and verifying each download against its published SHA-256. Off by default, so sites stay on stable releases. Release candidate builds carry their own version (for example 0.8.0-rc.1) so WordPress orders them before the final release.

= 0.7.0 =
Adds the authenticated, read-only GET /theme-overrides (push or pull permission). It lists a block theme's Site Editor copies, meaning saved templates, template parts and styles, which override the theme's files. It returns names and dates only. Zoer uses it to warn before a code-only theme push whose changes those copies would hide. Status reports the themeOverrides capability.

= 0.6.0 =
Includes the unreleased 0.5.4 changes. Each completed import or rollback commits a new opaque publication identifier. Public HTML carries it in a zoer-publication meta tag and an X-Zoer-Publication header, and singular pages carry a revision of their post, so Zoer can check what anonymous visitors actually receive after a transfer. The authenticated GET /publication lists the expected identifier, home page and recently changed pages; POST /cache/purge runs the known WordPress cache purges now. Cache purges leave a per-layer receipt: cache plugins report "requested" (their hooks give no confirmation), the object cache "flushed" or "failed"; hosting and CDN caches are outside WordPress and need the provider's purge.

= 0.5.4 =
The request-protection MU file no longer breaks copies of a site: at another path it does nothing, and after Zoer Connect's files are removed it does nothing unless a transfer is paused, which then answers 503 with reinstall guidance instead of a fatal error. Existing 0.5.x MU files keep working and are replaced when an administrator next opens wp-admin, never during a transfer. Destination setup must be confirmed again in Tools > Zoer Connect before the next import, because the request-fence code changed.

= 0.5.3 =
Optional compact import acknowledgements reduce repeated response bytes while retaining detailed status, exact destination binding, complete private journals, verification and guarded rollback. Clients negotiate compactImportResponses before requesting view=compact. Exports report the source siteurl as an original URL when WordPress runs in a subdirectory, and imports from a source with another table prefix no longer leave its roles row as an orphan option. Released without live hosting qualification.

= 0.5.2 =
Discover qualified updates and download checksum-verified ZIPs directly from the public Zoer Connect GitHub Releases. This is the final bridge update offered through the legacy releases feed; existing connector keys, settings and transfer journals are preserved.

= 0.5.1 =
Empty files now create verified snapshots in block exports, including when resuming an interrupted export.

= 0.5.0 =
* Configurable quotas, disk reserves and 4 GiB individual artifacts for updated Zoer clients.
* Resumable source-pause database export with typed keyset cursors, checksummed output parts, bounded SQL reads and safe cancellation/expiry.
* Bounded source file preparation, ordered block-root verification, publication, original-file backup and rollback. Earlier jobs keep their original recovery path.
* Native quota settings and authenticated status during paused transfers. Repeat request-protection setup after updating.

= 0.4.0 =
* API version 2 for WP Migrate-style transfers. `/status` reports `apiVersion` and per-feature capabilities; older Zoer clients keep the 0.3.14 behaviour, including its URL replacement rules and early request fence.
* Pull database filters: table subset, post type inclusion list, exclude revisions, spam comments and (by default) transients. Dependent meta, comments and term relationships follow excluded posts.
* Pull resource modes for themes and plugins (all, active, selected, all except) and a media "modified since" date. Export responses report the source path and exported tables.
* Read-only `/diagnostics` preflight for Push or Pull keys: WordPress, PHP and database facts, tables, post types, themes, plugins and drop-ins, with warnings for firewalls, page and object caches, table engines, foreign keys, triggers, mixed-case tables, search visibility and HTTPS.
* Import options: custom and variant URL/path replacements, review before activation, late fencing (the site stays online while tables are staged), table creation from strictly validated schemas, author matching, activation-setting control, pause/resume, backup cleanup, find-and-replace on the destination's own database, cache purging and safe error reporting. Imports can be listed.
* Batched Push upload: `POST /imports/{id}/batch` accepts many SHA-256-verified 256 KiB blocks per request as a framed binary body (optionally zlib-compressed), a multipart file part or JSON, with about 2 seconds of work per request, idempotent retries, an upload cursor (`?view=upload`) and a 413 response that reports the host's body limit. `/chunks` is unchanged.
* The replacement size limit (now 16 MiB) applies only to values a rule can change; unrelated large values pass untouched.
* With the late fence, a live change during staging cancels the import without leaving the site paused, and live cron and maintenance markers are carried into the swap.
* Table creation and destination find-and-replace never touch tables of another WordPress installation whose prefix extends this site's prefix.
* Destination find-and-replace snapshots only its selected tables and keeps other tools' options, such as WP Migrate settings.
* Mixed-case WordPress prefixes work on MariaDB servers that fold table names, including database Pull and destination find-and-replace. Foreign WordPress prefixes remain protected.
* Deflate is advertised only when both PHP inflation functions are available; restricted hosts retain uncompressed uploads. Diagnostics report error display, open_basedir and required function availability.
* Tools > Zoer Connect lists recent transfers with backup cleanup for finished imports, including imports created before a key rotation; export profiles are editable.
* Rolling back a file publication also removes the directories it created (when empty), so a rolled-back new theme or plugin no longer leaves a broken empty folder.
* Escape LIKE wildcards in transient exclusions when a database filter object is sent, so only real transient rows are skipped; `database: true` keeps the 0.3.14 query.
* Database snapshots (Pull and destination find-and-replace) no longer fail with "Schema changed during export." when a concurrent insert, such as a transient or cron lock, advances a table's AUTO_INCREMENT during the export.
* Push and file comparison accept ordinary file names containing `@ + ~ ! & ' = [ ] #`, such as Akismet's `akismet-refresh-logo@2x.png`, which previously failed every Push that included stock plugins with "Unsupported file path." Traversal, hidden segments, `%`, `\`, `:` and control characters remain rejected.

= 0.3.14 =
* Enable Push for new keys and prepare shared-hosting migration during key generation. Preserve existing permission choices, revocation and advanced setup; keep per-transfer confirmation in Zoer.

= 0.3.13 =
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
