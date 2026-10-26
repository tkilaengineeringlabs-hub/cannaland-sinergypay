#!/usr/bin/env python3
"""Local checks; no network or financial operations."""
from pathlib import Path
import re
import subprocess

root = Path(__file__).resolve().parents[1]
php = sorted(root.rglob('*.php'))
for path in php:
    subprocess.run(['php', '-l', str(path)], check=True, capture_output=True)
print(f'PHP lint: {len(php)} files passed')
for path in sorted((root / 'assets').rglob('*.js')):
    subprocess.run(['node', '--check', str(path)], check=True)
subprocess.run(['git', 'diff', '--check'], cwd=root, check=True)
for path in [root / 'cannaland-sinergypay.php', *(root / 'includes').glob('*.php'), *(root / 'assets').rglob('*.js')]:
    content = path.read_text()
    assert not re.search(r'dapp|dapp-codes|/payments', content, re.I), f'Excluded runtime dependency: {path}'
print('JS syntax, git diff --check and runtime dependency scan passed')
