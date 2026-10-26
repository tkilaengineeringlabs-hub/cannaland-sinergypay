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
for path in sorted([*(root / 'assets').rglob('*.js'), *(root / 'modules').rglob('*.js')]):
    subprocess.run(['node', '--check', str(path)], check=True)
subprocess.run(['git', 'diff', '--check'], cwd=root, check=True)
for path in [root / 'cannaland-payments.php', *(root / 'includes').glob('*.php'), *(root / 'assets').rglob('*.js'), *(root / 'modules').rglob('*.php'), *(root / 'modules').rglob('*.js')]:
    content = path.read_text()
    assert not re.search(r'dapp|dapp-codes|/payments', content, re.I), f'Excluded runtime dependency: {path}'
    assert not re.search(r'cannaland[-_]sinergypay|Cannaland\\SinergyPay|CANNALAND_SINERGYPAY|_csp_|csp_', content), f'Legacy local identity: {path}'
main = (root / 'cannaland-payments.php').read_text()
for header in ['Plugin Name: Cannaland Payments', 'Description: Pasarela de pagos integrada para Cannaland.', 'Version: 0.3.0', 'Text Domain: cannaland-payments']:
    assert header in main, header
print('JS syntax, git diff --check, runtime dependency and neutral branding scans passed')
