# Disposable WordPress integration checks — 2026-09-08

Created via the real Zoer lifecycle API: `ddev-zoer-connect-security-test`.
Preview: https://zoer-connect-security-test.wp.k8s.ahmad.sh
WordPress 7.1, PHP 8.4.24. Plugin ZIP installed and activated through WP-CLI. SparkLab was not changed.

`probe.py` runs on the DDEV host and is deliberately pinned to this test container. It creates a transient administrator application password and removes it in finally, plus a temporary subscriber that is deleted. Do not generalize its destination to production. It uses the DDEV router over loopback HTTPS with a Host header; certificate verification is disabled solely for this local self-signed fixture. Therefore this test does NOT validate certificate trust, hostname verification or the public proxy transport. The test never prints credentials.

Observed public-proxy blocker: ddev-bridge/src/http-proxy.ts uses an allowlist that excludes Authorization. Authenticated calls through the managed wp.k8s domain returned 401. Direct DDEV HTTPS calls authenticated successfully. Do not weaken plugin authentication to work around this; the proxy needs a separately reviewed credential-forwarding contract and HTTPS handling.

Passed actual HTTP checks:
- Anonymous, invalid-password and subscriber application-password requests rejected.
- Administrator authentication and private staging readiness.
- Wrong destination and traversal rejected before staging creation.
- Job discovery after create.
- Identical chunk retry accepted; conflicting retry rejected.
- Incomplete verification rejected; resume offsets and final hash verification succeed.
- Verified staging immutable; unsupported publish returns 501.
- Staging leaves live files unchanged; cancellation removes the job.

`files.php` runs using `wp eval-file` in the disposable installation, checks its home URL, and refuses to reuse an existing fixture. The installed plugin engine backed up a theme fixture, activated new content, resumed in a new object and restored the original content. Fixture files and backup files were then removed. This checks engine behavior inside WordPress, not a remote publish endpoint.

Not yet verified / required before production:
- Full-site database migration and restore (not implemented).
- Coordinated database/files maintenance and cutover, including connector/auth preservation.
- Process termination at each journal boundary; power-loss durability; file mode preservation.
- Cross-process races, concurrent external edits, quotas and disk exhaustion during writes.
- Large bundle verification under PHP time/memory limits (verification is currently unbounded).
- Scoped connector credentials; native application passwords inherit broad account capabilities.
- Malicious archive/symlink input at future extraction boundary (no archive extraction exists now).
- Backup confidentiality across actual hosting aliases and private-directory permissions.
- Public proxy authorization forwarding and TLS trust.
- Browser/mobile setup, saved profiles, source exporter and full push/pull UX.

These are targeted integration tests, not a comprehensive security audit. Keep the public publish endpoint disabled until the remaining implementation and tests pass.

## New reference and database primitive follow-up

Compared the supplied WP Migrate 2.7.11 source with 2.7.7. Observed changes include primary-key column validation, prepared post-type filtering, download capability and nonce checks, basename/realpath containment, and removal of absolute paths from download errors. These observations are source comparisons, not a claim that either package received a complete security review.

Added internal `TableStage` for one explicitly selected existing InnoDB table: exact identifier/column validation, refusal of identity tables, trigger/foreign-key rejection, transactional row batches with an idempotency ledger, count verification, atomic pairwise table rename retaining the old table, and repeatable restore. Tested on uniquely named fixture tables in the fresh WordPress database and deleted only those fixtures afterward. No real WordPress content table was replaced.

Limits: this is not yet the full database importer. It clones the destination schema, does not perform schema reconciliation or serialization-safe replacements, and requires the caller to quiesce application writes. GET_LOCK only serializes this engine's operations; it does not stop WordPress writes. Verification currently checks counts, not a complete canonical content digest. Multi-table cutover, application settings/identity preservation, backup export, interrupted-DDL recovery testing and integrated file/database rollback remain required. The primitive has no public endpoint.

Rebuilt and reinstalled the ZIP on the disposable site. A direct test-file copy had created inconsistent ownership and initially blocked plugin update; corrected the ownership of that one file to match the plugin directory, then reinstalled successfully. Re-ran all HTTP, installed-file-engine and database-fixture checks successfully. This is also a reminder that production preflight must check ownership/permissions.

## Recovery and identity follow-up

Added internal RecoveryCoordinator and SettingsPreservation. On the explicitly disposable WordPress site, settings.php staged and briefly switched the actual options table, preserved its original destination URL and connector activation, verified user records were unchanged, then restored the original options. Authentication/staging HTTP checks passed afterward. Users/usermeta remain excluded from transfer; author/user-ID reconciliation is not implemented.

recovery.php used uniquely named table and theme fixtures with the installed file and database engines. An injected exception after actual database cutover simulated a lost response; a new coordinator instance restored the original table and file in reverse order. Only fixture tables/files were removed. This is exception/restart-instance testing, not OS kill or power-loss testing.

The coordinator is internal and requires externally quiesced writes. It does not provide a maintenance-mode bypass, public authenticated recovery channel, multi-table atomic cutover or a Zoer UI. Public publication remains disabled. No live SparkLab mutation, Hostinger SSH activation, proxy authorization widening or production deployment occurred.
