#!/usr/bin/env python3
"""Release-asset metadata for WordPress; only exact qualified packages may publish."""
import json
from pathlib import Path
import re
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
subprocess.run([sys.executable, str(root / 'scripts/check-release.py'), '--package'], check=True)
version = re.search(r'Version: ([0-9]+\.[0-9]+\.[0-9]+)\n', (root / 'zoer-connect.php').read_text()).group(1)
receipt = json.loads((root / 'releases' / f'{version}.json').read_text())
target = root / 'dist/update.json'
encoded = json.dumps({'version': version, 'sha256': receipt['zipSha256']}, indent=2) + '\n'
if target.exists() and target.read_text() != encoded:
    raise RuntimeError('Remove stale development update.json before publishing a different release')
target.write_text(encoded)
print(target)
