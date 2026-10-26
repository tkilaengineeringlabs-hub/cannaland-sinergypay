#!/usr/bin/env python3
"""Build an allowlisted, single-root installable plugin ZIP. No secrets or development files."""
from pathlib import Path
import hashlib
import zipfile

root = Path(__file__).resolve().parents[1]
files = [root / 'cannaland-payments.php', root / 'README.md', root / 'readme.txt']
files += [root / 'docs/ARCHITECTURE.md', root / 'docs/OPERATIONS.md']
files += sorted((root / 'includes').glob('class-*.php'))
files += sorted((root / 'modules').rglob('*.php'))
files += sorted((root / 'modules').rglob('*.js'))
files += [root / 'assets/js/blocks.js', root / 'resources/keys/README.md']
files += sorted((root / 'resources/keys').glob('sinergy_public_*.pem'))
assert len(files) == 24, 'Review package manifest after adding runtime files'
out = root / 'dist/cannaland-payments.zip'
out.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(out, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in files:
        assert path.is_file() and not path.is_symlink()
        data = path.read_bytes()
        assert b'PRIVATE KEY' not in data, f'Private key marker in {path}'
        name = 'cannaland-payments/' + path.relative_to(root).as_posix()
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, data)
with zipfile.ZipFile(out) as archive:
    assert archive.testzip() is None
    names = archive.namelist()
    assert all(n.startswith('cannaland-payments/') for n in names)
    assert all(not set(Path(n).parts).intersection({'.git', '.github', 'tests', 'dist', 'scripts'}) for n in names)
    assert 'cannaland-payments/cannaland-payments.php' in names
print(f'{out}: {len(files)} files, {out.stat().st_size} bytes')
print('SHA-256:', hashlib.sha256(out.read_bytes()).hexdigest())
