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
