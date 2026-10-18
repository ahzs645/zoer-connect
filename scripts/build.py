#!/usr/bin/env python3
"""Deterministic WordPress ZIP; no dependencies, secrets, Git, or test fixtures."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import os
import re
root = Path(__file__).resolve().parents[1]
version = re.search(r'Version: ([0-9.]+)', (root / 'zoer-connect.php').read_text()).group(1)
# A prerelease ZIP says "Version: X.Y.Z-rc.N" in its header (the source stays X.Y.Z), so WordPress
# orders rc.1 < rc.2 < X.Y.Z. The archive keeps the stable file name.
prerelease = os.environ.get('ZOER_CONNECT_PRERELEASE', '')
if prerelease and not re.fullmatch(r'(alpha|beta|rc)\.[1-9][0-9]*', prerelease):
    raise RuntimeError('ZOER_CONNECT_PRERELEASE must look like rc.1')
# Old compiled PHP must never certify newly changed fence code.
for name in ['WriteFence','RequestDrain']:
    source=(root/'includes'/f'{name}.php').read_text()
    pattern=r"private const SOURCE_HASH='([a-f0-9]{64})';"
    match=re.search(pattern,source)
    normalized=re.sub(pattern,"private const SOURCE_HASH='"+'0'*64+"';",source,count=1)
    if not match or hashlib.sha256(normalized.encode()).hexdigest()!=match.group(1):
        raise RuntimeError('Run scripts/seal-runtime.py after editing request protection')
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / f'zoer-connect-{version}.zip'
files = [root / 'zoer-connect.php', root / 'readme.txt', root / 'LICENSE'] + [p for p in sorted((root / 'includes').glob('*.php')) if p.name != 'PeerImport.php']
with ZipFile(archive, 'w', compression=ZIP_DEFLATED) as z:
    for file in files:
        if file.is_symlink():
            raise RuntimeError('Symlink in package')
        info = ZipInfo('zoer-connect/' + file.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        data = file.read_bytes()
        if prerelease and file.name == 'zoer-connect.php':
            data, count = re.subn(rb'^( \* Version: )' + re.escape(version.encode()) + rb'$', rb'\g<1>' + f'{version}-{prerelease}'.encode(), data, count=1, flags=re.M)
            if count != 1: raise RuntimeError('Plugin header version not found')
        z.writestr(info, data)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n')
print(archive)
print(digest)
