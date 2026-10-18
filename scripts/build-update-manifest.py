#!/usr/bin/env python3
"""Release-asset metadata for WordPress: exact qualified packages, or (--prerelease) the stamped ZIP of an rc tag."""
import hashlib
import json
import os
from pathlib import Path
import re
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
    encoded = json.dumps({'version': full, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}, indent=2) + '\n'
else:
    subprocess.run([sys.executable, str(root / 'scripts/check-release.py'), '--package'], check=True)
    receipt = json.loads((root / 'releases' / f'{version}.json').read_text())
    encoded = json.dumps({'version': version, 'sha256': receipt['zipSha256']}, indent=2) + '\n'
if target.exists() and target.read_text() != encoded:
    raise RuntimeError('Remove stale development update.json before publishing a different release')
target.write_text(encoded)
print(target)
