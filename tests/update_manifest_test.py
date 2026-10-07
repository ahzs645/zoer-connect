"""WordPress release metadata must certify the exact stable, qualified ZIP."""
import json
import os
from pathlib import Path
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
        shutil.copyfile(Path(__file__).resolve().parents[1] / 'scripts/build-update-manifest.py', self.root / 'scripts/build-update-manifest.py')

    def run_builder(self, ref='refs/tags/v0.4.0'):
        return subprocess.run(['python3', str(self.root / 'scripts/build-update-manifest.py')],
                              env={**os.environ, 'GITHUB_REF': ref}, capture_output=True, text=True)

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


if __name__ == '__main__':
    unittest.main()
