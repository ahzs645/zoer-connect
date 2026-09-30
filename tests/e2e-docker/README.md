# Local Docker end-to-end test (two real WordPress sites)

Drives the Zoer Connect 0.4.0 protocol (API version 2) between two disposable WordPress sites on real Apache/PHP and MariaDB (or MySQL), over HTTPS, using the plugin straight from this working tree. It is the local substitute for cluster qualification. It is not a certificate-trust, public-proxy or hosting test.

```
./run.sh                         # fresh fixture -> all scenarios -> teardown (exit 0 = all PASS)
DB_IMAGE=mysql:8.0 ./run.sh      # same against MySQL 8.0
KEEP=1 ./run.sh                  # keep containers for inspection; ./teardown.sh later
./setup.sh && python3 driver.py 1 2   # run selected scenarios (3-9 depend on 2; 9 also compares with 3)
```

Last full result: 2026-09-29, WordPress 7.1.2, PHP 8.3.35, 225 checks and 0 failures on both MariaDB 11.4.13 and MySQL 8.0.46. Record new results in `releases/0.4.0.md`, not here; a pass is local evidence, not a qualification receipt.

Requirements: Docker (Compose v2), `python3` (standard library only), `openssl`, `curl`. Port `127.0.0.1:8443` (override with `ZC_HTTPS_PORT`).

The Zoer backend has a companion check that runs its production batched uploader against this fixture: start the fixture with `KEEP=1 ./run.sh 1` (or `./setup.sh`), run `bun scripts/zoer-connect-batch-interop.ts` from the Zoer repository's `backend/` (`ZC_E2E_DIR` overrides the fixture path when the repositories are not siblings), then `./teardown.sh`. It verifies TLS against the fixture's Caddy root CA rather than disabling it.

## Fixture

| | source | destination |
|---|---|---|
| URL | `https://source.test` | `https://dest.test` |
| WordPress root | `/srv/source/public` (site root, non-default path) | `/var/www/html` |
| Private storage (`ZOER_CONNECT_STORAGE_DIR`) | `/srv/source/zoer-private` | `/var/www/zoer-private` |
| Table prefix | `wp_` | `wpd_` |
| Admin | `srcadmin` | `destadmin` |

- `wordpress:php8.3-apache` (latest WordPress; WP-CLI copied from `wordpress:cli-php8.3`), `mariadb:11.4` by default (`DB_IMAGE`), `caddy:2-alpine` with `tls internal` terminating HTTPS for both hosts. WordPress sees HTTPS through `X-Forwarded-Proto` (the official `wp-config-docker.php` handles it), so `is_ssl()` is true only through the proxy; plain HTTP to Apache is refused by the plugin (scenario 1 checks this).
- The driver resolves `source.test`/`dest.test` to the published port inside its own process. **TLS verification is disabled in the driver for this local fixture only** (Caddy's throwaway internal CA); inside the Docker network the same names resolve to the proxy, so WordPress loopback requests work.
- The plugin is bind-mounted read-only from the repository (`zoer-connect.php`, `includes/`, `readme.txt` only).
- `setup.sh` installs both sites with WP-CLI and generates the connection keys by calling `ConnectionAdmin::render()` with a nonce-verified HTTPS administrator POST (`php/connection.php`), i.e. the admin form code path: key generation enables Push and prepares shared-hosting migration automatically; Pull is then enabled on the source through the form's permissions action.
- Seeded source content (`php/seed-source.php`): Gutenberg blocks with absolute, `http://` twin, protocol-relative and URL-encoded site URLs; an Elementor-style `_elementor_data` meta with JSON-escaped `https:\/\/source.test` URLs; a serialized option with URLs, a filesystem path and nested JSON; revisions; spam comments with meta; a second author `jdoe` and an unmatched author; `zc_book` and `zc_internal` custom post types (`fixtures/plugins/zc-cpt`); a transient; a media upload with sub-sizes; a child theme `zc-child` of Twenty Twenty-Five; a plugin that creates `{prefix}zc_custom` on activation (`fixtures/plugins/zc-custom-table`, source only); and a MyISAM table (diagnostics warning, must be excluded by the table filter).
- Destination (`php/seed-dest.php`): different posts/pages, `blog_public=0`, and a `jdoe` user with a different ID and email (author matching by login).
- Both front pages are rendered once during setup, as on a real site. Block themes create their fallback `wp_navigation` post and a `custom_css_post_id` theme mod on the first view; a never-rendered source would push without them, the destination would create them on first view after activation, and rollback would then (correctly, by design) refuse as a later edit.
- WP-Cron is not spawned by page views (`DISABLE_WP_CRON` in `WORDPRESS_CONFIG_EXTRA` on both sites); `setup.sh` runs the due events once with WP-CLI. Rationale: WordPress's timed jobs write real, non-ephemeral options. For example, the first admin visit (the driver's login checks in scenario 3) schedules `wp_update_comment_type_batch` one minute later, which adds `finished_updating_comment_type`. With spawned cron that write landed at a random point of a later scenario, and the plugin then behaved correctly but made the run fail intermittently: during live staging the activation check cancelled the import (`Destination changed since preparation.`), between finish and rollback the rollback refused (`Destination edited after publication`), and outside those windows the rollback was exact but the table no longer equalled the (older) baseline. Restore checks print the differing non-ephemeral rows. (A second, rarer scenario-6 failure, `Schema changed during export.` while snapshotting, was a plugin defect: a transient inserted by a page view moved `AUTO_INCREMENT`, which `SHOW CREATE TABLE` reports live even inside the export's consistent snapshot; `DatabaseExporter` now ignores it.) A standalone reproduction with deliberate front-page/`wp-cron.php` traffic and a cron job fired at random moments showed no lost or invented option writes and no other option difference after rollback or cancellation.
- Test passwords and keys are generated per run and written only to `.state/` (git-ignored, mode 0600); nothing secret is printed. `teardown.sh` deletes containers, volumes and `.state/`.

SQL probes (`php/sql.php`) use WordPress `SHORTINIT` via `docker compose exec`, so they bypass MU plugins and still work while the request fence returns 503.

## Scenarios (`driver.py`)

1. `/status` (0.4.0, apiVersion 2, all capability flags, permissions, 401/403/plain-HTTP refusals) and `/diagnostics` shape, tables/engines, post types, themes, plugins, MU fence and warnings (`non_innodb` on the source, `blog_private` on the destination).
2. Paged Pull with `database:{tables, postTypes, excludeRevisions, excludeSpam}` and `pluginsMode:selected`, `themesMode:active`; step/manifest/batch with per-chunk and per-file SHA-256; `database.sql` row counts per table compared with independent source queries; unfiltered pull refused because of the MyISAM table; unknown table suffix refused; `mediaSince`.
3. Push of that export to the destination with `replacements{automatic,variants,paths,custom}`, `authorMapping:match`, `createTables`, `fence:activation`, `review`, `purgeCaches` and `sourcePath`; chunk upload with `sha256` + `chunkSha256`; site stays 200 during staging/review; review stats/samples/authors; approve; 503 only after approval; finish. Then SQL and HTTP verification of every replacement form, valid serialization, custom rule, author/comment mapping, created table, retained identity, `active_plugins`/theme inference, files, permalinks, cache-purge marker, key validity and admin login.
4. Rollback after finish: refused safely after a later edit (site reopened, nothing changed), then succeeds once the edit is reverted; every table equals its pre-import content, created table and created directories removed, no broken themes; cleanup drops the import's `zoer_b_/zoer_s_/zoer_l_` tables and artifacts.
5. Legacy client (no `options`, no `sourcePath`, 32 MiB file path): a snapshot containing a table missing on the destination is refused before any fence; a core-table snapshot imports with the early fence (503 in every protected phase), authors = connection administrator, 0.3.14 replacement rules; rollback and cleanup.
6. `kind:'replace'` with literal and case-insensitive regex rules, review, approve, finish, rollback; a no-review options-only replace, cleanup, rollback refused after cleanup, reverse replace.
7. Late-fence abort: a live row edited during `review_required` makes the approved import end `cancelled` with a safe error; the site is live (200) and `write-fence.json` is absent.
8. Pause/resume (while uploading and mid-pipeline), `GET /imports`, idempotent/conflicting create, and safe error shapes (`{code:'zoer_import_failed', message, phase}`) for bad options, bad chunks, unknown ids and actions.
9. Batched Push (`POST /imports/{id}/batch`) of the scenario 3 export with the same options: `/status` batch capabilities/limits; index files at create; `view=upload` cursor; `database.sql` as one zlib-deflated octet-stream batch, one JSON-transport batch, one deflated JSON batch and one multipart batch (1 MiB each), then fixed 4 MiB `ZBT1` octet-stream batches from the server cursor; idempotent retry; 413 `zoer_import_body_limit` for an oversized multipart part and octet-stream batch; digest mismatch, oversized header, malformed JSON and wrong-phase refusals. The review summary and the published tables must equal the `/chunks` push of scenario 3; request counts for `/chunks` vs `/batch` are printed (`INFO request counts`). Then rollback and cleanup.

A final check requires the destination to be byte-for-byte back at its pre-test content (ignoring caches, cron, session and Quick Draft state) with no private tables left.

## Not covered

Certificate trust and hostname verification (the driver disables TLS verification), public proxies/CDNs and page caches, web application firewalls and real host body limits (scenario 9 only lowers PHP limits locally), real shared hosting (open_basedir, disabled functions, PHP-FPM worker pools), large (>256 MiB / multi-GiB) transfers and time limits, process kills at journal boundaries, the advanced verified-worker setup mode, the Zoer app/backend UI, multisite, and `lower_case_table_names` other than the image default.
