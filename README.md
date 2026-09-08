# Zoer Connect

An independent WordPress plugin repository. Builds an installable `zoer-connect-0.1.0.zip` without a Node toolchain or Composer dependencies. Original implementation; WP Migrate was inspected as a workflow reference, not copied.

## Status: staging preview, not a publisher

Implemented: native WordPress application-password authentication over HTTPS, administrator capability checks, exact destination matching, one bounded private staging job, resumable chunks, identical retry handling, SHA-256 verification, cancellation, and a Tools → Zoer Connect setup page.

**Not implemented:** database transfer, backups, file activation, rollback, a Zoer UI connector, automatic pairing, multisite, or live publication. `POST /publish` explicitly returns 501. Do not replace the current Hostinger publisher with this version. A verified staging result means only that the supplied bytes match the manifest, not that a website is published or healthy.

## Build and test

Requirements: Python 3 for ZIP builds; PHP 8.1+ for checks.

```sh
make test build
```

Build only: `python3 scripts/build.py`. Artifacts are in `dist/`, including a SHA-256 sidecar. The ZIP has one `zoer-connect/` root and only runtime PHP, WordPress readme, and licence files. Repeated builds produce identical bytes. GitHub Actions checks PHP 8.1–8.3 and uploads ZIP artifacts; no GitHub remote or release is configured yet.

## Install and connect

1. Upload the ZIP in WordPress → Plugins → Add New → Upload Plugin and activate it.
2. Open Tools → Zoer Connect. Use HTTPS and a dedicated WordPress application password on an administrator account. WordPress handles revocation from the account profile.
3. An API client sends HTTP Basic authentication to `https://YOUR-SITE/wp-json/zoer-connect/v1/status`. Do not place credentials in URLs, logs or source files. Cookie login alone is rejected by this API.
4. Confirm `stagingReady: true` and the returned destination before creating a job.

Application passwords inherit the user's WordPress permissions, including other REST endpoints. They are not scoped connector credentials. The future Zoer integration must store them in the host secret store and never expose them to browsers or generic computer environments.

Storage defaults to `.zoer-connect` beside WordPress's root. It must resolve outside both ABSPATH and the server's DOCUMENT_ROOT; otherwise staging fails closed. An administrator may set `ZOER_CONNECT_STORAGE_DIR` to a private writable directory in wp-config.php. Do not change DOCUMENT_ROOT to bypass the check. PHP cannot infer other web-server aliases: the operator must ensure the chosen directory is not exposed by another vhost or alias. No database dumps or PHP files are staged under public_html.

Deactivation and uninstall preserve staging files deliberately. Cancel the job before uninstalling to remove its data. The authenticated POST /expire operation removes staging jobs older than 24 hours, excluding any job with a publication journal. It is not automatically scheduled yet. One active job reserves up to 2 GiB; no concurrent second bundle is accepted.

## API v1

All routes require HTTPS and an administrator application password. Requests are JSON, at most 512 KiB. Responses must not be cached.

- `GET /status`: version, target, storage readiness and explicit capabilities.
- `GET /jobs`: discover jobs after a lost create response.
- `POST /expire`: remove staging jobs older than 24 hours, preserving publication journals.
- `POST /jobs`: create a job from the manifest below; retain its returned `id`. An uncertain create must not be automatically repeated.
- `GET /jobs/{id}`: state and one current byte offset per file. Resume from those offsets.
- `POST /jobs/{id}/chunks`: `{ "index": 0, "offset": 0, "data": "BASE64" }`, decoded chunks ≤256 KiB. Retrying the exact same bytes is idempotent. Gaps and conflicting retries are rejected.
- `POST /jobs/{id}/verify`: checks every length and SHA-256, then freezes writes with state `staged`.
- `DELETE /jobs/{id}`: remove private staged data and release the reservation.
- `POST /jobs/{id}/publish`: 501, never mutates the live site.

Example manifest (hash is SHA-256 of `abc`):

```json
{
  "version": 1,
  "target": "https://example.org",
  "files": [{
    "path": "wp-content/themes/demo/style.css",
    "bytes": 3,
    "sha256": "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
  }]
}
```

Only themes, plugins and uploads paths are supported. Paths are conservative ASCII; dot segments/files, traversal, duplicate/colliding paths, the connector itself and executable uploads are rejected. Artifacts are stored under numeric private names; no archive extraction or SQL execution is available. The source must enumerate a selected bundle, not blindly send a full DDEV archive. Core, wp-config.php, host-specific mu-plugins and server configuration are outside the current contract.

## Next publication milestones

1. Add job discovery, bounded verification steps, expiration, scoped pairing credentials and the Zoer connector/client UI.
2. Destination backup with a verified restore path, durable receipt outside migrated tables, and exclusive publication lock.
3. Stage database tables under a temporary prefix using a defined data format; preserve destination credentials, connector identity and chosen live user accounts. Apply serialization-safe URL/path transforms at export.
4. Activate files and tables with a recoverable journal and maintenance window. A database rename cannot make a combined filesystem/database change atomic; recovery must cover interruption between both phases.
5. Verify home, researcher counts, assets, permalinks and admin login before reporting published. Test rollback and interrupted writes on a disposable WordPress destination before any live installation.

This repository has its own `.git` history under Zoer's `wordpress-plugins/` directory. It is a local nested repository, not yet a Git submodule with a remote URL. After choosing a remote, push this repository and register it as a proper submodule in Zoer; do not create a gitlink pointing at an unpublished commit.

## Development: file publication engine

`includes/FilePublication.php` now implements selected-file backup, verified activation, a persistent per-file journal, process-resume behavior, and rollback. It rejects symlink destinations and refuses to overwrite content edited since planning or publication. It is not exposed through REST and is not a complete site publisher. It does not coordinate a maintenance window, database cutover, modes/ownership restoration or full-site health checks. Backup hashes are verified; crash/power-loss durability and real WordPress integration still need testing. No files are deleted merely because they are absent from the manifest.

Unit tests exercise backup-before-activation, resuming with a new instance, rollback, post-publication edit protection, and staging job discovery/expiry. Tests ran in the existing DDEV PHP image in an isolated network-disabled container with a temporary filesystem; no live WordPress files or database were changed.

## Development: database staging primitive

`includes/TableStage.php` now provides experimental staging for an existing, explicitly selected InnoDB table, transactional retry tracking, count verification, retained original table at activation, and restore. It is internal only and cannot migrate a whole WordPress site. Options and user tables are deliberately rejected until identity preservation is implemented. Source and destination schemas must already match. See the integration test report for tested behavior and unresolved safeguards.
