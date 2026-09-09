#!/usr/bin/env python3
"""Require matching metadata and a reviewed qualification receipt before packaging."""
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
if ref.startswith("refs/tags/"):
    assert ref == "refs/tags/v" + version, "Tag must match plugin version"
receipt = json.loads((root / "releases" / (version + ".json")).read_text())
assert receipt["version"] == version and receipt["qualified"] is True
files = [root / "zoer-connect.php", root / "readme.txt", root / "LICENSE"] + [f for f in sorted((root / "includes").glob("*.php")) if f.name != "PeerImport.php"]
actual = {f.relative_to(root).as_posix(): hashlib.sha256(f.read_bytes()).hexdigest() for f in files}
assert actual == receipt["files"], "Runtime differs from qualified source; repeat integration qualification"
assert (root / "releases" / (version + ".md")).is_file(), "Release notes required"
if "--package" in sys.argv:
    archive = root / "dist" / ("zoer-connect-" + version + ".zip")
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == receipt["zipSha256"], "ZIP differs from qualified package"
print("Qualified version, source" + (" and ZIP" if "--package" in sys.argv else "") + ": " + version)
