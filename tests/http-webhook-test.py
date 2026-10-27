#!/usr/bin/env python3
"""Real loopback HTTP against the registered handler, using explicit WordPress doubles."""
from pathlib import Path
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.request
import urllib.error

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cannaland-webhook-http-') as tmp:
    logfile = Path(tmp) / 'events.jsonl'
    with socket.socket() as port_socket:
        port_socket.bind(('127.0.0.1', 0))
        port = port_socket.getsockname()[1]
    env = dict(os.environ, CANNALAND_WEBHOOK_TEST_LOG=str(logfile))
    with (Path(tmp) / 'server.log').open('w+') as serverlog:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(root / 'tests/webhook-http-router.php')], cwd=root, env=env, stdout=serverlog, stderr=serverlog)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    if server.poll() is not None:
                        raise RuntimeError('Local PHP server failed to start')
                    time.sleep(.05)
            else:
                raise RuntimeError('Local PHP server did not start')
            url = f'http://127.0.0.1:{port}/wp-json/cannaland-payments/v1/payment-notification'
            cases = [
                ('unsigned safe probe', b'{"probe":"cannaland-payments-reachability"}', 401, 'invalid_signature'),
                ('invalid JSON', b'{', 400, 'invalid_json'),
                ('candidate names without signature', b'{"id":"probe","currency":"MXN","amount":780.00,"reference":"probe-reference","reference_num":"probe","payments":[],"security":{},"code":"probe","clabe":"000000000000000000"}', 401, 'invalid_signature'),
            ]
            for name, body, status, rejection in cases:
                logfile.write_text('')
                req = urllib.request.Request(url, data=body, method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'SYNTHETIC-DO-NOT-LOG'})
                try:
                    response = urllib.request.urlopen(req, timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    actual_status, actual_body = response.status, response.read()
                assert actual_status == status and actual_body == b'', (name, actual_status, actual_body)
                events = [json.loads(line) for line in logfile.read_text().splitlines()]
                assert [e[0] for e in events] == ['webhook_received', rejection], events
                assert events[-1][1]['signature_verified'] is False
                assert 'SYNTHETIC-DO-NOT-LOG' not in logfile.read_text()
                print(f'PASS HTTP POST {name}: {actual_status}, body={actual_body!r}, traces=webhook_received -> {rejection}; no order access/writes')
            logfile.write_text('')
            try:
                urllib.request.urlopen(url, timeout=5)
                raise AssertionError('GET unexpectedly accepted')
            except urllib.error.HTTPError as error:
                assert error.code == 404 and not logfile.read_text()
            print('PASS HTTP GET: rejected before handler (adapter); WordPress runtime/hosting remain unverified')
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
print('4 local HTTP checks passed. Transport/routing adapter uses WP doubles; not a Horsements or SinergyPay delivery test.')
