#!/usr/bin/env python3
"""Copy a qualified ZIP into the public, read-only WordPress update feed."""
from pathlib import Path
import hashlib
import json
import re
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
public = Path(sys.argv[1]).resolve()
version = re.search(r"Version: ([0-9]+\.[0-9]+\.[0-9]+)", (root / "zoer-connect.php").read_text()).group(1)
archive = root / "dist" / f"zoer-connect-{version}.zip"
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
receipt = json.loads((root / "releases" / f"{version}.json").read_text())
assert receipt["qualified"] is True and receipt["zipSha256"] == digest
assert (public / ".git").is_dir(), "Public release checkout is required"

release = public / "releases" / f"v{version}"
release.mkdir(parents=True, exist_ok=True)
target = release / archive.name
if target.exists() and hashlib.sha256(target.read_bytes()).hexdigest() != digest:
    raise RuntimeError("Published version has different bytes")
if not target.exists():
    shutil.copyfile(archive, target)
manifest = {"version": version, "sha256": digest}
encoded = json.dumps(manifest, indent=2) + "\n"
for path in (release / "manifest.json", public / "latest.json"):
    if path.exists() and path != public / "latest.json" and path.read_text() != encoded:
        raise RuntimeError("Published version manifest changed")
    if path == public / "latest.json" and path.exists():
        current = json.loads(path.read_text())["version"]
        if tuple(map(int, current.split("."))) > tuple(map(int, version.split("."))):
            continue
    path.write_text(encoded)

subprocess.run(["git", "add", "latest.json", f"releases/v{version}"], cwd=public, check=True)
changed = subprocess.run(["git", "diff", "--cached", "--quiet"], cwd=public)
if changed.returncode == 0:
    print("Public release already matches", version)
else:
    subprocess.run(["git", "-c", "user.name=zoer-connect-release", "-c", "user.email=release@users.noreply.github.com", "commit", "-m", f"Publish Zoer Connect {version}"], cwd=public, check=True)
    subprocess.run(["git", "push", "origin", "HEAD:main"], cwd=public, check=True)
    print("Published public update", version, digest)
