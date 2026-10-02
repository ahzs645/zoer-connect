#!/usr/bin/env python3
"""Validate development/prerelease metadata; require qualification for stable tags."""
import hashlib
import json
import os
from pathlib import Path
import re
import sys

root = Path(__file__).resolve().parents[1]
version = re.search(r"Version: ([0-9]+\.[0-9]+\.[0-9]+)\n", (root / "zoer-connect.php").read_text()).group(1)
assert re.search(r"^Stable tag: " + re.escape(version) + r"$", (root / "readme.txt").read_text(), re.M), "Stable tag mismatch"
plugin = (root / "includes/Plugin.php").read_text()
assert "'version' => '" + version + "'" in plugin, "API version mismatch"
assert "<p>Version " + version + " —" in plugin, "Admin version mismatch"
ref = os.environ.get("GITHUB_REF", "")
assert (root / "releases" / (version + ".md")).is_file(), "Release notes required"
receipt_path = root / "releases" / (version + ".json")
if "--development" in sys.argv:
    assert "--prerelease" not in sys.argv, "Development and prerelease modes are exclusive"
    assert not ref or ref.startswith(("refs/heads/", "refs/pull/")), "Development mode cannot validate a release tag"
    if not receipt_path.exists():
        if "--package" in sys.argv:
            archive = root / "dist" / ("zoer-connect-" + version + ".zip")
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            assert archive.with_suffix(".zip.sha256").read_text() == digest + "  " + archive.name + "\n", "Development ZIP checksum mismatch"
        print("Unqualified development metadata" + (" and ZIP checksum" if "--package" in sys.argv else "") + ": " + version)
        sys.exit(0)
if "--prerelease" in sys.argv:
    assert re.fullmatch(r"refs/tags/v" + re.escape(version) + r"-(alpha|beta|rc)\.[1-9][0-9]*", ref), "Prerelease requires a matching alpha, beta or rc tag"
    assert "--package" not in sys.argv, "Prerelease packaging uses the reproducible package test, not qualification"
    print("Unqualified prerelease metadata: " + version)
    sys.exit(0)
if ref.startswith("refs/tags/"):
    assert ref == "refs/tags/v" + version, "Tag must match plugin version"
receipt = json.loads(receipt_path.read_text())
assert receipt["version"] == version and receipt["qualified"] is True
files = [root / "zoer-connect.php", root / "readme.txt", root / "LICENSE"] + [f for f in sorted((root / "includes").glob("*.php")) if f.name != "PeerImport.php"]
actual = {f.relative_to(root).as_posix(): hashlib.sha256(f.read_bytes()).hexdigest() for f in files}
assert actual == receipt["files"], "Runtime differs from qualified source; repeat integration qualification"
if "--package" in sys.argv:
    archive = root / "dist" / ("zoer-connect-" + version + ".zip")
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == receipt["zipSha256"], "ZIP differs from qualified package"
print("Qualified version, source" + (" and ZIP" if "--package" in sys.argv else "") + ": " + version)
