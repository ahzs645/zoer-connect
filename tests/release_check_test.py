"""Ensure development packaging never bypasses stable release qualification."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class ReleaseCheckTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for directory in ("scripts", "includes", "releases", "dist"):
            (self.root / directory).mkdir()
        shutil.copyfile(Path(__file__).resolve().parents[1] / "scripts/check-release.py", self.root / "scripts/check-release.py")
        (self.root / "zoer-connect.php").write_text("Version: 0.4.0\n")
        (self.root / "readme.txt").write_text("Stable tag: 0.4.0\n")
        (self.root / "LICENSE").write_text("test license\n")
        (self.root / "includes/Plugin.php").write_text("'version' => '0.4.0'\n<p>Version 0.4.0 — test\n")
        (self.root / "releases/0.4.0.md").write_text("Unqualified test notes\n")
        self.archive = self.root / "dist/zoer-connect-0.4.0.zip"
        self.archive.write_bytes(b"test package bytes")
        self.digest = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        self.archive.with_suffix(".zip.sha256").write_text(f"{self.digest}  {self.archive.name}\n")

    def check(self, ref, *args, success=True):
        result = subprocess.run(["python3", str(self.root / "scripts/check-release.py"), *args], env={**os.environ, "GITHUB_REF": ref}, capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)

    def receipt(self):
        files = [self.root / name for name in ("zoer-connect.php", "readme.txt", "LICENSE", "includes/Plugin.php")]
        receipt = {"version": "0.4.0", "qualified": True, "files": {p.relative_to(self.root).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in files}, "zipSha256": self.digest}
        (self.root / "releases/0.4.0.json").write_text(json.dumps(receipt))

    def test_unqualified_development_and_pull_requests(self):
        for ref in ("refs/heads/main", "refs/heads/feature-transfer", "refs/pull/1/merge"):
            self.check(ref, "--development")
            self.check(ref, "--development", "--package")

    def test_development_checksum_must_match(self):
        self.archive.write_bytes(b"changed package")
        self.check("refs/heads/main", "--development", "--package", success=False)

    def test_development_cannot_validate_any_tag(self):
        for ref in ("refs/tags/v0.4.0", "refs/tags/v0.4.0-rc.1"):
            self.check(ref, "--development", success=False)

    def test_stable_requires_receipt(self):
        self.check("refs/tags/v0.4.0", success=False)
        self.receipt()
        self.check("refs/tags/v0.4.0", "--package")

    def test_existing_receipt_must_match_development_source(self):
        self.receipt()
        self.check("refs/heads/main", "--development", "--package")
        (self.root / "LICENSE").write_text("changed license")
        self.check("refs/heads/main", "--development", success=False)

    def test_prerelease_tag_validation(self):
        for suffix in ("alpha.1", "beta.2", "rc.1"):
            self.check(f"refs/tags/v0.4.0-{suffix}", "--prerelease")
        for ref in ("refs/tags/v0.4.0", "refs/tags/v0.3.14-rc.1", "refs/tags/v0.4.0-rc.0", "refs/heads/main"):
            self.check(ref, "--prerelease", success=False)
        self.check("refs/tags/v0.4.0-rc.1", "--prerelease", "--development", success=False)


if __name__ == "__main__":
    unittest.main()
