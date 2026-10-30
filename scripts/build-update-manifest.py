#!/usr/bin/env python3
"""Release-asset metadata for WordPress: exact qualified packages, or (--prerelease) the stamped ZIP of an rc tag.

When ZOER_CONNECT_UPDATE_SIGNING_KEY is set, update.json also carries an Ed25519 `signature`
(scripts/sign-update.php; PHP with sodium is required). If GitHubUpdater::PUBLIC_KEY ships a key,
signing is mandatory and the signing key must match it.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from zipfile import ZipFile

root = Path(__file__).resolve().parents[1]
version = re.search(r'Version: ([0-9]+\.[0-9]+\.[0-9]+)\n', (root / 'zoer-connect.php').read_text()).group(1)
target = root / 'dist/update.json'
if '--prerelease' in sys.argv:
    # Unqualified prerelease: describes the stamped ZIP built for this exact tag (release-candidate channel).
    match = re.fullmatch(r'refs/tags/v' + re.escape(version) + r'-((?:alpha|beta|rc)\.[1-9][0-9]*)', os.environ.get('GITHUB_REF', ''))
    assert match, 'Prerelease manifest requires a matching vX.Y.Z-rc.N tag'
    full = f'{version}-{match.group(1)}'
    archive = root / 'dist' / f'zoer-connect-{version}.zip'
    with ZipFile(archive) as z:
        assert re.search(r'^ \* Version: ' + re.escape(full) + r'$', z.read('zoer-connect/zoer-connect.php').decode(), re.M), 'ZIP header is not stamped with ' + full
    manifest = {'version': full, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}
else:
    subprocess.run([sys.executable, str(root / 'scripts/check-release.py'), '--package'], check=True)
    receipt = json.loads((root / 'releases' / f'{version}.json').read_text())
    manifest = {'version': version, 'sha256': receipt['zipSha256']}
updater = root / 'includes/GitHubUpdater.php'
shipped = re.search(r"public const PUBLIC_KEY = '([A-Za-z0-9+/=]*)';", updater.read_text()) if updater.exists() else None
shipped = shipped.group(1) if shipped else ''
if os.environ.get('ZOER_CONNECT_UPDATE_SIGNING_KEY'):
    signer = [shutil.which('php') or 'php', str(root / 'scripts/sign-update.php')]
    if shipped:
        public = subprocess.run([*signer, '--public-key'], check=True, capture_output=True, text=True).stdout.strip()
        assert public == shipped, 'ZOER_CONNECT_UPDATE_SIGNING_KEY does not match GitHubUpdater::PUBLIC_KEY'
    manifest['signature'] = subprocess.run([*signer, manifest['version'], manifest['sha256']], check=True, capture_output=True, text=True).stdout.strip()
elif shipped:
    raise SystemExit('GitHubUpdater::PUBLIC_KEY is set: provide ZOER_CONNECT_UPDATE_SIGNING_KEY so update.json is signed')
encoded = json.dumps(manifest, indent=2) + '\n'
if target.exists() and target.read_text() != encoded:
    raise RuntimeError('Remove stale development update.json before publishing a different release')
target.write_text(encoded)
print(target)
