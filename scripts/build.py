#!/usr/bin/env python3
"""Deterministic WordPress ZIP; no dependencies, secrets, Git, or test fixtures."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import re
root = Path(__file__).resolve().parents[1]
version = re.search(r'Version: ([0-9.]+)', (root / 'zoer-connect.php').read_text()).group(1)
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / f'zoer-connect-{version}.zip'
files = [root / 'zoer-connect.php', root / 'readme.txt', root / 'LICENSE'] + sorted((root / 'includes').glob('*.php'))
with ZipFile(archive, 'w', compression=ZIP_DEFLATED) as z:
    for file in files:
        if file.is_symlink():
            raise RuntimeError('Symlink in package')
        info = ZipInfo('zoer-connect/' + file.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        z.writestr(info, file.read_bytes())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n')
print(archive)
print(digest)
