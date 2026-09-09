#!/usr/bin/env python3
"""Seal compiled worker-proof code. Re-run after editing either adoption class."""
from pathlib import Path
import hashlib,re
root=Path(__file__).resolve().parents[1]
for name in ['WriteFence','RequestDrain']:
 path=root/'includes'/f'{name}.php'
 source=path.read_text()
 pattern=r"private const SOURCE_HASH='[a-f0-9]{64}';"
 normalized,count=re.subn(pattern,"private const SOURCE_HASH='"+'0'*64+"';",source,count=1)
 assert count==1
 digest=hashlib.sha256(normalized.encode()).hexdigest()
 path.write_text(re.sub(pattern,"private const SOURCE_HASH='"+digest+"';",source,count=1))
 print(name+' source fingerprint sealed')
