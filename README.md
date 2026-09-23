# Zoer Connect

Zoer Connect transfers selected WordPress resources through HTTPS. Version 0.3.5 adds resumable block-based file publication up to 2 GiB per file. Version 0.3.4 introduced explicit shared-hosting replacement, preserves unrelated serialized plugin state, and reuses matching completed file uploads with fresh verification. SparkLab was successfully migrated from its managed local source to `https://sparklab.unbc.ca/`; all seven pages, representative file hashes and retained administrator access were verified. Qualification and live evidence is recorded in the parent repository’s `output/ui-audit/2026-09-08-zoer-connect-032/`. WP Migrate was used as a workflow reference; this implementation is independent.

See the [operating guide and implementation lessons](https://github.com/ahzs645/zoer/blob/main/docs/zoer-connect-operations.md) for first-site setup, recovery, WP Migrate comparisons and remaining work.

## Transfer workflow

The Zoer connection dialog supports remote Pull with pause/resume/cancel, and Push from a managed local DDEV source. The local source exports its database in a background CLI worker on the existing DDEV server, using one InnoDB consistent snapshot. It does not depend on a hosting HTTP request staying open. Verified, immutable artifacts are downloaded to private Zoer storage and uploaded in authenticated blocks.

The destination imports through `/wp-json/zoer-connect/v1/imports`. Each request advances a durable private journal. SQL is parsed as data rather than executed. Destination tables are staged and verified before cutover. Selected files retain verified backups. A must-use bootstrap blocks ordinary WordPress requests during activation and serves authenticated recovery before regular plugins or themes execute. Finish reopens the site. Rollback checks every affected table and file before restoring anything; it tolerates regenerated transient caches and refuses substantive later edits.

Destination home/siteurl, administrator accounts, roles, connector identity and selected environment settings are retained. Source authors map to the destination connection's administrator. Theme/plugin activation settings are preserved when their resources are not selected. Every nonidentity source table must have a matching existing destination schema; unsupported tables are rejected rather than silently omitted.

## Destination setup

1. Install the plugin, pair its connector key with Zoer and enable Push.
2. In Tools → Zoer Connect, select **Shared-hosting migration**, acknowledge replacement, then enable it. This installs the site-level MU protection and binds the setup to this site and protection code. It does not inspect shared PHP processes or require a hosting restart.
3. In Zoer, test the connection, choose the local source and resources, prepare an export, confirm the destination address and replacement, then import.
4. Finish to reopen the site; inspect pages and administrator access. Use the same connection to recover interruptions. Rollback retains conflict checks and refuses detected later edits.

This is replacement, not a concurrent-edit merge. Earlier requests and external writers are not guaranteed to stop; avoid editing during migration. Requests reaching the site's MU protection pause during application/recovery. Original tables are retained by table renames and selected files have backups. Detected content changes can stop activation or prevent rollback. Coherent private journal storage and working filesystem/database locks remain necessary; distributed multi-host coordination is not provided.

Advanced verified-worker setup remains available for isolated environments that can prove all PHP workers adopted the fence. It retains Linux `/proc` inventory checks and writer declarations. Shared-hosting mode never records those declarations as established facts.

Changing fence code invalidates new-import readiness. Existing recovery remains available. Do not delete the connector, earliest MU bootstrap or private journals while a transfer is active.

## Supported boundary

- Single-site WordPress 6.5+, PHP 8.1+, private writable storage outside every public document root.
- Existing matching InnoDB tables with primary keys; foreign keys and triggers are rejected. Identity tables remain at the destination.
- Local exports: 2 GiB total, 30-minute worker deadline, immutable block checksums and restart/cancel handling. A failed worker starts a fresh transaction rather than appending an incomplete snapshot.
- Destination SQL: at most 2 GiB; row batches at most 4 MiB. With a block-capable Zoer backend, individual selected files and existing destination originals support up to 2 GiB. Upload, verification, backup and restoration process 256 KiB blocks with durable progress. Older clients/jobs retain the legacy 32 MiB path. The managed-local export total remains 2 GiB; this is not an unlimited-size migration.
- Importable files: selected themes, plugins and media. Core, wp-config, MU plugins, connector files, executable uploads, symlinks and unsafe paths are excluded.
- Advanced verified-worker setup requires readable Linux `/proc` process information and standard, single-threaded PHP-FPM, CGI or LSAPI workers. It checks every PHP process under the same operating-system account; a long-running CLI job or another site’s idle worker may delay readiness until it exits or adopts protection. Renamed/custom PHP interpreters, embedded or multithreaded runtimes, hidden process inventories and forked/unfenced background writers are unsupported. This is a compatibility check, not a guarantee for every shared host.
- Shared-hosting mode in 0.3.9 permits regular `advanced-cache.php` and `object-cache.php` drop-ins. They execute before MU protection and can serve stale pages; exclude connector API routes from page caching and purge page/CDN caches after migration. Database, sunrise and maintenance drop-ins, symlinked drop-ins and nonstandard content locations remain unsupported. Advanced verified-worker mode still rejects all early drop-ins. External SQL writers are outside the protection guarantee.
- Hosted remote Pull still uses a bounded single-request database snapshot (256 MiB / up to 40 seconds), failing closed on interruption. Large local-to-hosted Push uses the independent DDEV worker instead.
- Revoking/rotating a key invalidates its generation. Existing jobs cannot be rebound automatically to a new key. Keep the original authorized connection available for recovery.
- Literal replacements preserve unrelated serialized bytes without deserialization. Serialized objects or references requiring URL changes remain unsupported; regex replacement of serialized objects is refused.
- Matching terminal uploads can supply cached files under the same destination/key generation. Reused files are hashed before activation; database chunks are uploaded again.
- Private backup journals are retained. This is replacement, not merging concurrent edits or preserving arbitrary source user identities.

## Authentication and API

Connection keys contain 256 random bits, are shown once, and are stored only as hashes in WordPress. Zoer stores its copy encrypted as a host-only secret. Requests use `X-Zoer-Connection`, never URL credentials. HTTPS, current administrator ownership and per-direction permission are checked. Public import/recovery uses native connector keys; application passwords remain supported for legacy staging endpoints.

- `/status`: canonical destination, version, readiness, permissions and capabilities.
- `/exports`: remote export create/status/step/chunks/cancel.
- `/imports`: idempotent create; `/{id}` status; `/{id}/chunks`, `/step`, `/finish`, `/rollback`.
- `/jobs`: legacy private file staging. Its old `/publish` route remains unimplemented; imports use the dedicated protocol.

Import requests are bounded to 2 MiB JSON; decoded upload blocks are 256 KiB. Job state, source/destination binding, sequence IDs and checksums determine retries. Clients never supply executable SQL or private filesystem paths.

## Verification and packaging

Run `make test` for PHP lint and artifact-free suites. Real MariaDB/WordPress checks live under `tests/integration/`; run these only against an explicitly authorized disposable destination. Integration evidence is recorded under the parent Zoer repository's `output/ui-audit/`.

After editing `WriteFence.php` or `RequestDrain.php`, run `python3 scripts/seal-runtime.py` before testing. Compiled source fingerprints prevent stale cached PHP code from certifying a new request-protection generation.

The source controller seals a whole-file SHA-256 and every block hash from the same verified bytes. The destination verifies each block against that authenticated manifest; it does not serialize a PHP SHA context across requests. Original files use recorded block digests for backup/conflict/restore verification. Existing legacy journals continue through their original recovery implementation.

Only after integration qualification, run `make test-package` and `make build`. Packaging is deterministic and includes runtime PHP, readme and licence only, with a SHA-256 sidecar in `dist/`. A local build is not a live plugin update or website publication.

## GitHub releases

The source repository is private at `ahzs645/zoer-connect`. Branch pushes and pull requests run the PHP 8.1–8.3 suites. Packaging additionally requires a version-specific qualification receipt in `releases/`, matching every packaged runtime file and the deterministic ZIP checksum. A runtime change therefore needs fresh integration qualification before packaging succeeds; a receipt is a recorded maintainer assertion, not an automated integration test.

To release: update the plugin header, API/admin version and readme stable tag together; run the PHP and authorized disposable WordPress integration checks; build and verify the package; record its file hashes/checksum and results in `releases/VERSION.json` and `releases/VERSION.md`. Commit the scoped plugin changes and push an annotated `vVERSION` tag. The workflow rejects mismatched tags and publishes the ZIP plus checksum only after all checks pass. It also pushes the qualified ZIP and checksum manifest into the separate public `ahzs645/zoer-connect-releases` repository using a write deploy key scoped to that repository. Failed runs publish no new update feed; fix the failure before retrying. Do not move an already published release tag or change a published version's ZIP.

Install 0.3.11 once through WordPress's Upload Plugin screen. Its Update URI then checks the public `latest.json` feed during ordinary WordPress plugin update checks, and WordPress can install later qualified versions through Plugins → Updates. The plugin verifies the downloaded ZIP against the versioned release checksum before WordPress installs it. Automatic updates remain off unless a WordPress administrator enables them. The public repository contains distributable ZIPs, not the private source or connection keys. Installing a plugin update does not push website content; never update the connector during an active transfer.

## Storage troubleshooting (0.3.6)

Tools → Zoer Connect now reports a safe storage diagnostic code, the current path and PHP's filesystem restrictions (paths are visible to WordPress administrators only). Native status responses expose the code and a safe description. For first-time setup, an administrator can validate and configure a private folder outside the public roots without editing wp-config.php. The existing ZOER_CONNECT_STORAGE_DIR constant remains authoritative. The form refuses to move storage that contains transfers or has import protection installed; existing recovery data must remain at its original location. A host that does not permit any writable private folder still needs administrator configuration. No public uploads-folder fallback is used.


## Paged exports (0.3.7)

Updated Zoer clients can negotiate pagedExport and use exports/paged. Traversal and immutable file preparation checkpoint after at most 250 entries or four seconds per request (one file copy/hash can extend that soft budget). Manifests return 500 entries per page. Downloads pack up to 32 chunks and 1 MiB into one response. Limits are 100,000 files, 4 GiB total, 32 MiB per source file and a separate database snapshot bounded to 256 MiB/40 seconds. Active requests renew the one-hour idle expiry within a 24-hour maximum lifetime. Earlier clients keep their existing export flow and limits. No full-site external clone is claimed by protocol qualification alone.

Exclude the connector REST namespace from any full-page hosting cache. Aram's Breeze cache required an explicit Never Cache URL entry even though connector responses set no-store. Confirm unauthenticated status returns 401 and a connection test reflects current plugin version and storage readiness.


### Selective Push in Zoer 0.3.8

The matching Zoer interface can compare a prepared local export and select individual new/changed files by path. The plugin requires Push permission and checks destination fingerprints before publication. Database replacement is separately selected and does not merge records. Destination-only files are retained. Comparison currently excludes protected paths and destination files above 32 MiB; existing full-export chunked file publication remains available. See `releases/0.3.8.md` for peer-only qualification.

Version 0.3.9 reads native recovery credentials directly from the database, bypassing persistent option caches. Imports affecting database, themes or plugins flush the WordPress object cache before releasing request protection; failures keep recovery available for retry. It does not regenerate root `.htaccess` or change page passwords for a file-only update.

Version 0.3.10 also supports hosts with PHP `link()` disabled: setup uses a flushed atomic rename under the private control lock, and transfers upload artifacts normally rather than requiring hard-link reuse.
