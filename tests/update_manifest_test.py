"""WordPress release metadata must certify the exact stable, qualified ZIP."""
import json
import os
from pathlib import Path
import base64
import hashlib
import re
import shutil
import subprocess
import unittest

import release_check_test


class UpdateManifestTest(unittest.TestCase):
    def setUp(self):
        self.fixture = release_check_test.ReleaseCheckTest()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.root = self.fixture.root
        source = Path(__file__).resolve().parents[1]
        for script in ('build-update-manifest.py', 'sign-update.php'):
            shutil.copyfile(source / 'scripts' / script, self.root / 'scripts' / script)

    def run_builder(self, ref='refs/tags/v0.4.0', **env):
        base = {k: v for k, v in os.environ.items() if k != 'ZOER_CONNECT_UPDATE_SIGNING_KEY'}
        return subprocess.run(['python3', str(self.root / 'scripts/build-update-manifest.py')],
                              env={**base, 'GITHUB_REF': ref, **env}, capture_output=True, text=True)

    def php(self, code, *args):
        return subprocess.run(['php', '-r', code, '--', *args], check=True, capture_output=True, text=True).stdout.strip()

    def test_exact_stable_metadata_and_idempotent_generation(self):
        self.fixture.receipt()
        for _ in range(2):
            result = self.run_builder()
            self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads((self.root / 'dist/update.json').read_text()),
                         {'version': '0.4.0', 'sha256': self.fixture.digest})

    def test_unqualified_and_prerelease_cannot_generate_update_metadata(self):
        self.assertNotEqual(self.run_builder().returncode, 0)
        self.fixture.receipt()
        self.assertNotEqual(self.run_builder('refs/tags/v0.4.0-rc.1').returncode, 0)
        self.assertFalse((self.root / 'dist/update.json').exists())

    def test_changed_package_and_runtime_cannot_generate_metadata(self):
        self.fixture.receipt()
        self.fixture.archive.write_bytes(b'altered ZIP')
        self.assertNotEqual(self.run_builder().returncode, 0)
        self.fixture.archive.write_bytes(b'test package bytes')
        (self.root / 'LICENSE').write_text('altered runtime')
        self.assertNotEqual(self.run_builder().returncode, 0)
        self.assertFalse((self.root / 'dist/update.json').exists())

    def test_stale_manifest_is_not_silently_overwritten(self):
        self.fixture.receipt()
        target = self.root / 'dist/update.json'
        target.write_text('{"version":"0.3.0"}')
        self.assertNotEqual(self.run_builder().returncode, 0)
        self.assertEqual(target.read_text(), '{"version":"0.3.0"}')

    @unittest.skipUnless(shutil.which('php'), 'PHP with sodium signs update manifests')
    def test_signing_key_adds_a_verifiable_signature(self):
        # An ephemeral key made for this test only; no key is stored in the repository.
        seed = self.php('echo base64_encode(random_bytes(32));')
        public = self.php('echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode($argv[1]))));', seed)
        self.fixture.receipt()
        result = self.run_builder(ZOER_CONNECT_UPDATE_SIGNING_KEY=seed)
        self.assertEqual(result.returncode, 0, result.stderr)
        manifest = json.loads((self.root / 'dist/update.json').read_text())
        self.assertEqual(set(manifest), {'version', 'sha256', 'signature'})
        self.assertEqual(len(base64.b64decode(manifest['signature'])), 64)
        message = f"zoer-connect-update-v1\n0.4.0\n{self.fixture.digest}\n"
        verified = self.php('echo sodium_crypto_sign_verify_detached(base64_decode($argv[1]), $argv[2], base64_decode($argv[3])) ? "ok" : "bad";',
                            manifest['signature'], message, public)
        self.assertEqual(verified, 'ok')
        # Deterministic, so re-running a release publishes byte-identical metadata.
        self.assertEqual(self.run_builder(ZOER_CONNECT_UPDATE_SIGNING_KEY=seed).returncode, 0)
        # The message format must match what the plugin verifies.
        updater = (Path(__file__).resolve().parents[1] / 'includes/GitHubUpdater.php').read_text()
        self.assertIn('SIGNED_MESSAGE = "zoer-connect-update-v1\\n%s\\n%s\\n";', updater)

    @unittest.skipUnless(shutil.which('php'), 'PHP with sodium signs update manifests')
    def test_shipped_public_key_requires_a_matching_signing_key(self):
        seed = self.php('echo base64_encode(random_bytes(32));')
        public = self.php('echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode($argv[1]))));', seed)
        other = self.php('echo base64_encode(random_bytes(32));')
        updater = self.root / 'includes/GitHubUpdater.php'
        updater.write_text(f"    public const PUBLIC_KEY = '{public}';\n")
        self.fixture.receipt()
        receipt_path = self.root / 'releases/0.4.0.json'
        receipt = json.loads(receipt_path.read_text())
        receipt['files']['includes/GitHubUpdater.php'] = hashlib.sha256(updater.read_bytes()).hexdigest()
        receipt_path.write_text(json.dumps(receipt))
        missing = self.run_builder()
        self.assertNotEqual(missing.returncode, 0)
        self.assertIn('provide ZOER_CONNECT_UPDATE_SIGNING_KEY', missing.stderr)
        mismatched = self.run_builder(ZOER_CONNECT_UPDATE_SIGNING_KEY=other)
        self.assertNotEqual(mismatched.returncode, 0)
        self.assertIn('does not match GitHubUpdater::PUBLIC_KEY', mismatched.stderr)
        self.assertFalse((self.root / 'dist/update.json').exists())
        result = self.run_builder(ZOER_CONNECT_UPDATE_SIGNING_KEY=seed)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_shipped_public_key_is_empty_until_provisioned(self):
        updater = (Path(__file__).resolve().parents[1] / 'includes/GitHubUpdater.php').read_text()
        self.assertTrue(re.search(r"public const PUBLIC_KEY = '[A-Za-z0-9+/=]*';", updater))


if __name__ == '__main__':
    unittest.main()
