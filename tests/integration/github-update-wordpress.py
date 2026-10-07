#!/usr/bin/env python3
"""Native WordPress bridge upgrade in owned, ephemeral Docker containers.

Before publication use the default intercepted, exact staged package transport.
After publication use --published to verify the same path over public HTTPS.
No cluster, Zoer state, existing containers or persistent volumes are touched.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import urllib.request
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--published', action='store_true')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
identifier = 'zc-update-' + uuid.uuid4().hex[:10]
db, wp, network = identifier+'-db', identifier+'-wp', identifier+'-net'

def docker(*arguments, check=True, capture=False):
    return subprocess.run(['docker', *arguments], check=check, text=True, capture_output=capture)

def fetch(url, path):
    with urllib.request.urlopen(url, timeout=60) as response:
        path.write_bytes(response.read())

with tempfile.TemporaryDirectory(prefix='zc-update-') as directory:
    fixtures = Path(directory)
    shutil.copyfile(root/'dist/zoer-connect-0.5.2.zip', fixtures/'zoer-connect-0.5.2.zip')
    shutil.copyfile(root/'tests/integration/github-update.php', fixtures/'github-update.php')
    fetch('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', fixtures/'wp-cli.phar')
    old = fixtures/'zoer-connect-0.5.1.zip'
    fetch('https://raw.githubusercontent.com/ahzs645/zoer-connect-releases/main/releases/v0.5.1/zoer-connect-0.5.1.zip', old)
    receipt = json.loads((root/'releases/0.5.1.json').read_text())
    assert hashlib.sha256(old.read_bytes()).hexdigest() == receipt['zipSha256']
    created = []
    try:
        docker('network', 'create', '--label', 'zoer-connect-updater-fixture=1', network)
        created.append(('network', network))
        docker('run', '-d', '--name', db, '--network', network, '--label', 'zoer-connect-updater-fixture=1',
               '--tmpfs', '/var/lib/mysql:rw', '-e', 'MARIADB_ROOT_PASSWORD=updater-disposable-root',
               '-e', 'MARIADB_DATABASE=wordpress', '-e', 'MARIADB_USER=wordpress', '-e', 'MARIADB_PASSWORD=updater-disposable', 'mariadb:11.4')
        created.append(('container', db))
        for _ in range(60):
            if docker('exec', db, 'mariadb-admin', 'ping', '-uroot', '-pupdater-disposable-root', '--silent', check=False, capture=True).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Disposable database readiness timed out')
        docker('run', '-d', '--name', wp, '--network', network, '--label', 'zoer-connect-updater-fixture=1',
               '--tmpfs', '/var/www/html:rw', '--tmpfs', '/var/www/zoer-private:rw',
               '-v', str(fixtures)+':/fixtures:ro', '-e', 'WORDPRESS_DB_HOST='+db,
               '-e', 'WORDPRESS_DB_NAME=wordpress', '-e', 'WORDPRESS_DB_USER=wordpress',
               '-e', 'WORDPRESS_DB_PASSWORD=updater-disposable', '-e', 'ZC_UPDATE_FIXTURE=1',
               '-e', 'WORDPRESS_CONFIG_EXTRA=define("DISABLE_WP_CRON",true);define("FS_METHOD","direct");define("ZOER_CONNECT_STORAGE_DIR","/var/www/zoer-private");',
               'wordpress:php8.3-apache')
        created.append(('container', wp))
        for _ in range(60):
            if docker('exec', wp, 'test', '-f', '/var/www/html/wp-config.php', check=False, capture=True).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Disposable WordPress readiness timed out')
        docker('exec', wp, 'chown', 'www-data:www-data', '/var/www/zoer-private')
        cli = ['exec', '-u', 'www-data', wp, 'php', '/fixtures/wp-cli.phar']
        docker(*cli, 'core', 'install', '--url=https://updates.test', '--title=Disposable updater qualification',
               '--admin_user=updater-admin', '--admin_password=updater-disposable-admin', '--admin_email=updater@example.test', '--skip-email')
        docker(*cli, 'plugin', 'install', '/fixtures/zoer-connect-0.5.1.zip', '--activate')
        for phase in ['seed', 'upgrade', 'verify']:
            docker('exec', '-u', 'www-data', '-e', 'ZC_UPDATE_PHASE='+phase,
                   '-e', 'ZC_UPDATE_TRANSPORT='+('published' if args.published else 'fixture'),
                   wp, 'php', '/fixtures/wp-cli.phar', 'eval-file', '/fixtures/github-update.php')
        print('PASS native WordPress upgrade with preserved connector state and direct source-repository updates')
    finally:
        for kind, name in reversed(created):
            if kind == 'container':
                docker('rm', '-fv', name, check=False, capture=True)
            else:
                docker('network', 'rm', name, check=False, capture=True)
