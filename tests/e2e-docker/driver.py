#!/usr/bin/env python3
"""End-to-end driver for the local two-site Zoer Connect fixture (see README.md).

Talks to https://source.test and https://dest.test through the Caddy proxy that
docker-compose publishes on 127.0.0.1:$ZC_HTTPS_PORT. Host names are resolved to
that port inside this process only (no /etc/hosts change). TLS certificates come
from Caddy's throwaway internal CA, so certificate verification is DISABLED for
this local fixture only; the driver does not test certificate trust.

Keys and admin passwords are read from .state/ (written by setup.sh) and are never
printed. SQL probes run through `docker compose exec` with WordPress SHORTINIT, so
they keep working while the request fence pauses WordPress.

Prints PASS/FAIL lines and exits non-zero if any check fails.
"""
import base64, hashlib, http.client, json, os, re, socket, ssl, struct, subprocess, sys, time, traceback, uuid, urllib.parse, zlib

HERE = os.path.dirname(os.path.abspath(__file__))
STATE = os.path.join(HERE, '.state')
PORT = int(os.environ.get('ZC_HTTPS_PORT', '8443'))
DB_SERVER = 'mysql' if os.environ.get('DB_IMAGE', 'mariadb').startswith('mysql') else 'mariadb'
CHUNK = 262144
SITES = {
    'source': {'host': 'source.test', 'root': '/srv/source/public', 'private': '/srv/source/zoer-private', 'prefix': 'wp_'},
    'dest': {'host': 'dest.test', 'root': '/var/www/html', 'private': '/var/www/zoer-private', 'prefix': 'wpd_'},
}
KEYS = {site: open(os.path.join(STATE, site + '.key')).read().strip() for site in SITES}
CREDS = json.load(open(os.path.join(STATE, 'credentials.json')))
SEED = {site: json.load(open(os.path.join(STATE, site + '-seed.json'))) for site in SITES}
SOURCE_URL, DEST_URL = 'https://source.test', 'https://dest.test'
RESIDUE = re.compile(r'(?://|\\/\\/|%2F%2F)source\.test', re.I)
TRANSIENT = ('Import is busy.', 'Earlier WordPress requests are still draining; retry.', 'Write fence control is busy.', 'Table is busy.', 'Publication busy.')

# --- local resolver: *.test -> the published proxy port ---------------------
_getaddrinfo = socket.getaddrinfo
def _resolve(host, port, *args, **kwargs):
    if host in ('source.test', 'dest.test'):
        return _getaddrinfo('127.0.0.1', PORT, *args, **kwargs)
    return _getaddrinfo(host, port, *args, **kwargs)
socket.getaddrinfo = _resolve
TLS = ssl._create_unverified_context()  # local fixture only: Caddy internal CA

# --- results -----------------------------------------------------------------
RESULTS = []
def check(name, condition, detail=''):
    ok = bool(condition)
    RESULTS.append((name, ok))
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok or not detail else ' :: ' + str(detail)[:600]), flush=True)
    return ok

class Abort(Exception):
    pass

def require(name, condition, detail=''):
    if not check(name, condition, detail):
        raise Abort(name)

# --- HTTP ----------------------------------------------------------------------
def fetch(site, path, method='GET', body=None, headers=None, raw=None, timeout=90):
    host = SITES[site]['host']
    h = {'Host': host, 'User-Agent': 'zoer-e2e-driver'}
    h.update(headers or {})
    data = raw
    if body is not None:
        data = json.dumps(body).encode()
        h['Content-Type'] = 'application/json'
    conn = http.client.HTTPSConnection(host, 443, context=TLS, timeout=timeout)
    try:
        conn.request(method, path, body=data, headers=h)
        r = conn.getresponse()
        return r.status, r.getheaders(), r.read()
    finally:
        conn.close()

def api(site, route, method='GET', body=None, key=None, params=None):
    path = '/wp-json/zoer-connect/v1' + route + ('?' + urllib.parse.urlencode(params) if params else '')
    headers = {} if key == '' else {'X-Zoer-Connection': key or KEYS[site]}
    status, _, data = fetch(site, path, method, body, headers)
    try:
        payload = json.loads(data) if data else None
    except ValueError:
        payload = {'_raw': data[:300].decode('utf-8', 'replace')}
    return status, payload

def page(site, path='/'):
    status, headers, data = fetch(site, path)
    return status, dict((k.lower(), v) for k, v in headers), data.decode('utf-8', 'replace')

# --- containers / SQL ------------------------------------------------------------
COMPOSE = ['docker', 'compose', '-f', os.path.join(HERE, 'docker-compose.yml')]
def dc(*args, input=None, check_rc=True):
    r = subprocess.run([*COMPOSE, *args], input=input, capture_output=True, text=True)
    if check_rc and r.returncode:
        raise RuntimeError('docker compose %s failed: %s' % (' '.join(args[:4]), r.stderr[-500:]))
    return r

def sql(site, *queries):
    """queries: (sql, fetch) with fetch in all|col|var|exec|unserialize; {prefix} is expanded."""
    payload = json.dumps({'queries': [{'sql': q, 'fetch': f} for q, f in queries]})
    r = dc('exec', '-T', '-u', 'www-data', site, 'php', '/zc/sql.php', SITES[site]['root'], input=payload)
    return json.loads(r.stdout)['results']

def var(site, query):
    return sql(site, (query, 'var'))[0]

def rows(site, query):
    return sql(site, (query, 'all'))[0]

def option(site, name, unserialize=False):
    q = "SELECT option_value FROM {prefix}options WHERE option_name='%s'" % name
    return sql(site, (q, 'unserialize' if unserialize else 'var'))[0]

def exists(site, path):
    return dc('exec', '-T', site, 'test', '-e', path, check_rc=False).returncode == 0

def file_sha(site, path):
    r = dc('exec', '-T', site, 'sha256sum', path, check_rc=False)
    return r.stdout.split()[0] if r.returncode == 0 else None

def tables(site):
    return set(sql(site, ('SHOW TABLES', 'col'))[0])

def table_hashes(site, names=None):
    """Content digest per table, ignoring runtime caches (cron, transients, rewrite rules, session tokens)."""
    p = SITES[site]['prefix']
    out = {}
    for name in sorted(names or tables(site)):
        if not name.startswith(p) or name.startswith('zoer_'):
            continue
        where = ''
        if name == p + 'options':
            where = (" WHERE option_name NOT IN ('cron','rewrite_rules','zoer_connect_rewrite_flush_pending','zoer_connect_cache_purge_pending')"
                     " AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'")
        if name == p + 'usermeta':
            # Per-login state written by the admin-login checks (users are never transferred).
            where = " WHERE meta_key NOT IN ('session_tokens','community-events-location','%sdashboard_quick_press_last_post_id')" % p
        if name == p + 'posts':
            # The dashboard's Quick Draft auto-draft (also ephemeral to the plugin's fingerprints).
            where = " WHERE post_status<>'auto-draft'"
        # option_id is a surrogate key: preserved settings are re-inserted into a staged
        # options table, so compare options by name, value and autoload.
        # Legacy autoload spellings (yes/no) are equivalent to on/off since WordPress 6.6.
        columns, order = ("option_name, option_value, CASE autoload WHEN 'yes' THEN 'on' WHEN 'no' THEN 'off' ELSE autoload END AS autoload", 'option_name') if name == p + 'options' else ('*', '1')
        data = rows(site, 'SELECT %s FROM `%s`%s ORDER BY %s' % (columns, name, where, order))
        out[name] = hashlib.sha256(json.dumps(data, sort_keys=True).encode()).hexdigest()
    return out

def table_rows(site):
    p = SITES[site]['prefix']
    return {t: rows(site, 'SELECT * FROM `%s`' % t) for t in sorted(tables(site)) if t.startswith(p)}

def row_diff(before, after):
    """Rows added/removed/changed between two table_rows() captures (first column is the key)."""
    out = []
    for t in sorted(set(before) | set(after)):
        b = {json.dumps(list(r.values())[0]): r for r in before.get(t, [])}
        a = {json.dumps(list(r.values())[0]): r for r in after.get(t, [])}
        for k in sorted(set(a) | set(b)):
            if a.get(k) != b.get(k):
                changed = sorted(c for c in set(a.get(k) or {}) | set(b.get(k) or {}) if (a.get(k) or {}).get(c) != (b.get(k) or {}).get(c))
                label = (a.get(k) or b.get(k))
                name = label.get('option_name') or label.get('meta_key') or label.get('post_type') or ''
                out.append('%s[%s %s] %s: %s' % (t, k, name, 'added' if k not in b else 'removed' if k not in a else 'changed', ','.join(changed)))
    return out

def stage_ids(import_id, count):
    return [hashlib.sha256(('%s:%d' % (import_id, i)).encode()).hexdigest()[:16] for i in range(count)]

def residues(site, table):
    found = []
    for row in rows(site, 'SELECT * FROM `%s`' % table):
        for column, value in row.items():
            if isinstance(value, str) and RESIDUE.search(value):
                m = RESIDUE.search(value)
                found.append((table, column, value[max(0, m.start() - 40):m.end() + 40]))
    return found

# --- pull client -------------------------------------------------------------------
def pull(profile, database, expect_ready=True):
    cid = uuid.uuid4().hex
    status, view = api('source', '/exports/paged', 'POST', {'clientId': cid, 'profile': profile, 'database': database})
    if status != 200:
        return status, view, None, None
    for _ in range(500):
        if view['status'] == 'ready':
            break
        status, view = api('source', '/exports/paged/%s/step' % cid, 'POST', {})
        if status != 200:
            api('source', '/exports/paged/' + cid, 'DELETE')
            return status, view, None, None
    files, offset = [], 0
    while True:
        status, m = api('source', '/exports/paged/%s/manifest' % cid, params={'offset': offset})
        assert status == 200, (status, m)
        files += m['files']; offset += len(m['files'])
        if offset >= m['total']:
            break
    data = {f['path']: bytearray() for f in files}
    index, off = 0, 0
    while index < len(files):
        status, batch = api('source', '/exports/paged/%s/batch' % cid, params={'index': index, 'offset': off})
        assert status == 200 and batch['chunks'], (status, batch)
        for c in batch['chunks']:
            blob = base64.b64decode(c['data'])
            f = files[c['index']]
            assert hashlib.sha256(blob).hexdigest() == c['sha256'] and len(data[f['path']]) == c['offset'], 'chunk integrity'
            data[f['path']] += blob
            index, off = c['index'], c['offset'] + c['bytes']
            if off == f['bytes']:
                index, off = index + 1, 0
    for f in files:
        assert hashlib.sha256(data[f['path']]).hexdigest() == f['sha256'], 'file digest ' + f['path']
    return 200, view, files, {k: bytes(v) for k, v in data.items()}

def cancel_export(cid):
    return api('source', '/exports/paged/' + cid, 'DELETE')

INSERT = re.compile(rb'^INSERT INTO `([A-Za-z0-9_]+)` \(([^)]*)\) VALUES \((.*)\);$')
def parse_snapshot(blob):
    out = {}
    for line in blob.split(b'\n'):
        m = re.match(rb'^DROP TABLE IF EXISTS `([A-Za-z0-9_]+)`;$', line)
        if m:
            out[m.group(1).decode()] = []
            continue
        m = INSERT.match(line)
        if m:
            cols = [c.strip(b'`').decode() for c in m.group(2).split(b',')]
            vals = [None if c == b'NULL' else bytes.fromhex(c[2:-1].decode()).decode('utf-8', 'replace') for c in m.group(3).split(b',')]
            out[m.group(1).decode()].append(dict(zip(cols, vals)))
    return out

# --- import client -----------------------------------------------------------------
def artifact(blob, blocks=True):
    a = {'bytes': len(blob), 'sha256': hashlib.sha256(blob).hexdigest()}
    if blocks:
        a['chunkSha256'] = [hashlib.sha256(blob[o:o + CHUNK]).hexdigest() for o in range(0, len(blob), CHUNK)]
    return a

def transfer_manifest(view, files, data, options=None, file_blocks=True, source_path=True):
    body = {'id': uuid.uuid4().hex, 'target': DEST_URL, 'sourceUrl': view['source']['url'], 'sourcePrefix': view['source']['prefix'],
            'migrationMode': 'shared-replacement', 'replacementAccepted': True}
    if source_path:
        body['sourcePath'] = view['source']['abspath']
    blobs = []
    if 'database.sql' in data:
        body['database'] = artifact(data['database.sql'])
        blobs.append(data['database.sql'])
    body['files'] = []
    for f in files:
        if f['path'] == 'database.sql':
            continue
        body['files'].append(dict(path=f['path'], **artifact(data[f['path']], file_blocks)))
        blobs.append(data[f['path']])
    if options is not None:
        body['options'] = options
    return body, blobs

REQUESTS = {'chunks': 0, 'batch': 0}

def upload(import_id, blobs):
    for index, blob in enumerate(blobs):
        for offset in range(0, len(blob), CHUNK):
            REQUESTS['chunks'] += 1
            status, s = api('dest', '/imports/%s/chunks' % import_id, 'POST', {'index': index, 'offset': offset, 'data': base64.b64encode(blob[offset:offset + CHUNK]).decode()})
            if status != 200:
                raise Abort('chunk upload %d@%d: %s %s' % (index, offset, status, s))
    return s

def call(import_id, action, method='POST'):
    """One import action, retrying transient lock contention (409 busy/draining)."""
    for _ in range(120):
        route = '/imports/%s' % import_id + ('/' + action if action else '')
        status, s = api('dest', route, method, {} if method == 'POST' else None)
        if status == 409 and isinstance(s, dict) and s.get('message') in TRANSIENT:
            time.sleep(0.5)
            continue
        return status, s
    return status, s

def drive(import_id, action, stop, on_phase=None, limit=4000):
    """Repeat step/rollback until the phase is in `stop`; returns (status, summary, phases seen)."""
    seen = []
    for _ in range(limit):
        status, s = call(import_id, action)
        if status != 200:
            return status, s, seen
        if not seen or seen[-1] != s['phase']:
            seen.append(s['phase'])
            if on_phase:
                on_phase(s['phase'], s)
        if s['phase'] in stop:
            return status, s, seen
    return status, s, seen

def cleanup(import_id):
    for _ in range(100):
        status, s = call(import_id, 'cleanup')
        if status != 200 or s.get('cleanedUp'):
            return status, s
    return status, s

def fence_active():
    return exists('dest', SITES['dest']['private'] + '/write-fence.json')

def zoer_tables(import_id=None, count=0):
    names = {t for t in tables('dest') if t.startswith('zoer_')}
    if import_id is None:
        return names
    ids = set(stage_ids(import_id, count))
    return {t for t in names if t[7:] in ids}

def assert_cleanup(label, import_id, summary):
    count = len(summary['stats']['tables'])
    status, s = cleanup(import_id)
    check(label + ': cleanup succeeds (cleanedUp)', status == 200 and s.get('cleanedUp') is True, (status, s))
    check(label + ': zoer_b_/zoer_s_/zoer_l_ tables for the import are gone', not zoer_tables(import_id, count), zoer_tables(import_id, count))
    listing = dc('exec', '-T', 'dest', 'ls', '-A', SITES['dest']['private'] + '/import-' + import_id).stdout.split()
    check(label + ': private import directory keeps only state.json', listing == ['state.json'], listing)

# =====================================================================================
CTX = {}

def scenario_1():
    print('\n== Scenario 1: status, diagnostics, authentication', flush=True)
    caps = ['replacementRules', 'replacementVariants', 'reviewPause', 'createTables', 'authorMapping', 'keepActivePlugins', 'lateFence', 'importPauseResume', 'importCleanup', 'importList', 'siteReplace', 'cachePurge', 'databaseFilters', 'resourceModes', 'mediaSince', 'diagnostics', 'safeErrors']
    for site in SITES:
        status, s = api(site, '/status')
        check(site + ' /status: 200, version 0.4.0, apiVersion 2', status == 200 and s['version'] == '0.4.0' and s['apiVersion'] == 2, (status, s))
        check(site + ' /status: every API-2 capability advertised', all(s['capabilities'].get(c) is True for c in caps), [c for c in caps if s['capabilities'].get(c) is not True])
        check(site + ' /status: storage ready, shared-replacement setup, import capabilities', s['stagingReady'] and s['migrationMode'] == 'shared-replacement' and s['capabilities']['databaseImport'] and s['capabilities']['rollback'], s)
        check(site + ' /status: target is the https home URL', s['target'] == 'https://' + SITES[site]['host'], s['target'])
    check('source permissions push+pull enabled', api('source', '/status')[1]['permissions'] == {'push': True, 'pull': True})
    check('dest permissions push only (key generation enables Push, Pull stays off)', api('dest', '/status')[1]['permissions'] == {'push': True, 'pull': False})
    check('no key -> 401', api('dest', '/status', key='')[0] == 401)
    check('wrong key -> 401', api('dest', '/status', key='zc_' + '0' * 64)[0] == 401)
    status, body = api('dest', '/exports/paged', 'POST', {'clientId': uuid.uuid4().hex, 'profile': {'name': 'x'}, 'database': True})
    check('dest pull disabled -> 403 zoer_permission_disabled', status == 403 and body.get('code') == 'zoer_permission_disabled', (status, body))
    # Plain HTTP straight to Apache (inside the dest container, bypassing TLS) must be refused.
    r = dc('exec', '-T', 'dest', 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-H', 'X-Zoer-Connection: ' + KEYS['dest'], 'http://localhost/wp-json/zoer-connect/v1/status')
    check('plain HTTP (is_ssl false) -> 401', r.stdout.strip() == '401', r.stdout)

    shape = {'wordpress': ['version', 'prefix', 'home', 'siteurl', 'abspath', 'contentDir', 'uploadsDir', 'locale', 'permalinkStructure', 'blogPublic'],
             'php': ['version', 'memoryLimit', 'maxExecutionTime', 'postMaxSize', 'uploadMaxFilesize', 'extensions'],
             'database': ['server', 'version', 'charset', 'collate', 'lowerCaseTableNames', 'tables'],
             'pluginUpdate': ['current', 'latest']}
    diag = {}
    for site in SITES:
        status, d = api(site, '/diagnostics')
        diag[site] = d
        ok = status == 200 and all(k in d for k in ['wordpress', 'php', 'database', 'postTypes', 'themes', 'plugins', 'muPlugins', 'dropins', 'warnings', 'pluginUpdate'])
        ok = ok and all(set(v) <= set(d[k]) for k, v in shape.items())
        ok = ok and set(d['php']['extensions']) == {'zip', 'openssl', 'curl', 'mysqli'}
        t = d['database']['tables'][0] if ok else {}
        ok = ok and set(t) == {'name', 'suffix', 'prefixed', 'engine', 'rows', 'bytes', 'primaryKey', 'foreignKeys', 'triggers'}
        ok = ok and all(set(x) == {'name', 'label', 'count'} for x in d['postTypes']) and all(set(x) == {'slug', 'name', 'version', 'active', 'parent'} for x in d['themes'])
        ok = ok and all(set(x) == {'slug', 'file', 'name', 'version', 'active'} for x in d['plugins']) and all(set(x) == {'code', 'message'} for x in d['warnings'])
        check(site + ' /diagnostics: 200 and contract shape', ok, (status, str(d)[:400]))
        check(site + ' /diagnostics: prefix, abspath, https home, database server ' + DB_SERVER,
              d['wordpress']['prefix'] == SITES[site]['prefix'] and d['wordpress']['abspath'] == SITES[site]['root'] and d['wordpress']['home'] == 'https://' + SITES[site]['host'] and d['database']['server'] == DB_SERVER,
              (d['wordpress'], d['database']['server']))
        check(site + ' /diagnostics: MU fence and pluginUpdate.current 0.4.0', any(m['file'] == '000-zoer-connect-fence.php' for m in d['muPlugins']) and d['pluginUpdate']['current'] == '0.4.0', (d['muPlugins'], d['pluginUpdate']))
    s, t = diag['source'], {x['name']: x for x in diag['source']['database']['tables']}
    codes = [w['code'] for w in s['warnings']]
    check('source diagnostics: zc_custom InnoDB with primary key, MyISAM table reported', t.get('wp_zc_custom', {}).get('engine') == 'InnoDB' and t['wp_zc_custom']['primaryKey'] and t.get('wp_zc_legacy_myisam', {}).get('engine') == 'MyISAM', t.get('wp_zc_custom'))
    check('source diagnostics: warnings = [non_innodb] (no blog_private/no_https)', codes == ['non_innodb'], s['warnings'])
    pt = {x['name']: x['count'] for x in s['postTypes']}
    check('source diagnostics: post type counts (zc_book 2, zc_internal 1, revisions >= 2)', pt.get('zc_book') == 2 and pt.get('zc_internal') == 1 and pt.get('revision', 0) >= 2, pt)
    th = {x['slug']: x for x in s['themes']}
    check('source diagnostics: active child theme with parent', th.get('zc-child', {}).get('active') and th['zc-child']['parent'] == 'twentytwentyfive' and th['twentytwentyfive']['active'], th.get('zc-child'))
    check('source diagnostics: fixture plugins active', {p['slug'] for p in s['plugins'] if p['active']} == {'zc-cpt', 'zc-custom-table', 'zoer-connect'}, [p['slug'] for p in s['plugins'] if p['active']])
    d = diag['dest']
    check('dest diagnostics: warnings = [blog_private], blogPublic false', [w['code'] for w in d['warnings']] == ['blog_private'] and d['wordpress']['blogPublic'] is False, d['warnings'])
    check('dest diagnostics: no zc_custom table', not any(x['name'].endswith('zc_custom') for x in d['database']['tables']))
    CTX['postTypes'] = sorted(pt)

def scenario_2():
    print('\n== Scenario 2: filtered paged Pull from the source', flush=True)
    status, body = api('source', '/exports/paged', 'POST', {'clientId': uuid.uuid4().hex, 'profile': {'name': 'unfiltered'}, 'database': True})
    cid = body.get('id') if isinstance(body, dict) else None
    status, body = api('source', '/exports/paged/%s/step' % cid, 'POST', {})
    check('unfiltered pull of a site with a MyISAM table -> 409 "Pull requires InnoDB tables."', status == 409 and body.get('message') == 'Pull requires InnoDB tables.', (status, body))
    cancel_export(cid)
    status, body = api('source', '/exports/paged', 'POST', {'clientId': uuid.uuid4().hex, 'profile': {'name': 'x'}, 'database': {'tables': ['posts', 'nope']}})
    cid = body.get('id') if isinstance(body, dict) else None
    status, body = api('source', '/exports/paged/%s/step' % cid, 'POST', {})
    check('unknown table suffix -> 400 refused', status == 400 and body.get('code') == 'zoer_invalid', (status, body))
    cancel_export(cid)

    types = CTX.get('postTypes') or sorted(x['name'] for x in api('source', '/diagnostics')[1]['postTypes'])
    wanted = [t for t in types if t not in ('zc_internal', 'revision')]
    suffixes = sorted(x[3:] for x in tables('source') if x.startswith('wp_') and x != 'wp_zc_legacy_myisam')
    profile = {'name': 'e2e selected', 'themes': True, 'themesMode': 'active', 'plugins': True, 'pluginsMode': 'selected', 'pluginsItems': ['zc-custom-table', 'zc-cpt'], 'media': True}
    database = {'tables': suffixes, 'postTypes': wanted + ['revision'], 'excludeRevisions': True, 'excludeSpam': True}
    status, view, files, data = pull(profile, database)
    require('filtered paged pull completes and every chunk/file digest verifies', status == 200 and view['status'] == 'ready', (status, view))
    CTX['pull'] = (view, files, data)
    src = view['source']
    check('pull source: url, prefix, abspath=/srv/source/public, tables = selected suffixes', src['url'] == SOURCE_URL and src['prefix'] == 'wp_' and src['abspath'] == '/srv/source/public' and src['tables'] == suffixes, src)
    paths = [f['path'] for f in files]
    check('manifest: database.sql first', paths[0] == 'database.sql', paths[:3])
    plugin_dirs = {p.split('/')[2] for p in paths if p.startswith('wp-content/plugins/')}
    theme_dirs = {p.split('/')[2] for p in paths if p.startswith('wp-content/themes/')}
    check('manifest: pluginsMode selected -> only zc-custom-table and zc-cpt', plugin_dirs == {'zc-custom-table', 'zc-cpt'}, plugin_dirs)
    check('manifest: themesMode active -> child theme and its parent only', theme_dirs == {'zc-child', 'twentytwentyfive'}, theme_dirs)
    check('manifest: media file included', any(p.endswith('/zc-media.png') for p in paths))
    snap = parse_snapshot(data['database.sql'])
    check('snapshot: tables are exactly the selected ones (no MyISAM table)', sorted(snap) == ['wp_' + s for s in suffixes], sorted(snap))
    keep = "post_type IN (%s) AND post_type<>'revision'" % ','.join("'%s'" % t for t in wanted)
    dropped = 'SELECT ID FROM wp_posts WHERE NOT (%s)' % keep
    queries = {
        'wp_posts': 'SELECT COUNT(*) FROM wp_posts WHERE ' + keep,
        'wp_postmeta': 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id NOT IN (%s)' % dropped,
        'wp_comments': "SELECT COUNT(*) FROM wp_comments WHERE comment_approved<>'spam' AND comment_post_ID NOT IN (%s)" % dropped,
        'wp_commentmeta': "SELECT COUNT(*) FROM wp_commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM wp_comments WHERE comment_approved='spam' OR comment_post_ID IN (%s))" % dropped,
        'wp_term_relationships': "SELECT COUNT(*) FROM wp_term_relationships WHERE object_id NOT IN (%s) OR term_taxonomy_id IN (SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE taxonomy='link_category')" % dropped,
        'wp_usermeta': "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key NOT IN ('_application_passwords','session_tokens')",
    }
    for name in snap:
        if name == 'wp_options':
            continue
        expected = int(var('source', queries.get(name, 'SELECT COUNT(*) FROM `%s`' % name)))
        check('snapshot rows %s = %d (source DB with filters)' % (name, expected), len(snap[name]) == expected, len(snap[name]))
    names = set(sql('source', ("SELECT option_name FROM wp_options WHERE option_name NOT IN ('zoer_connect_connection','zoer_connect_profiles','zoer_connect_storage_dir') AND option_name NOT LIKE 'wpmdb%%' AND option_name NOT LIKE '\\_transient\\_%%' AND option_name NOT LIKE '\\_site\\_transient\\_%%'".replace('%%', '%'), 'col'))[0])
    dumped = {r['option_name'] for r in snap['wp_options']}
    check('snapshot options = source options minus connector/transient rows', dumped == names, (sorted(dumped - names), sorted(names - dumped)))
    posts = {int(r['ID']): r for r in snap['wp_posts']}
    check('filter: no revisions and no zc_internal posts exported', not any(r['post_type'] in ('revision', 'zc_internal') for r in posts.values()), sorted({r['post_type'] for r in posts.values()}))
    check('source really has revisions and a zc_internal post (filters are meaningful)', int(var('source', "SELECT COUNT(*) FROM wp_posts WHERE post_type='revision'")) >= 2 and int(var('source', "SELECT COUNT(*) FROM wp_posts WHERE post_type='zc_internal'")) == 1)
    check('filter: zc_internal postmeta and term relationship dropped', not any(r['meta_key'] == 'zc_secret' for r in snap['wp_postmeta']) and not any(int(r['object_id']) == SEED['source']['internal'] for r in snap['wp_term_relationships']))
    check('filter: spam comments and their meta dropped', not any(r['comment_approved'] == 'spam' for r in snap['wp_comments']) and not any(r['meta_key'] == 'zc_spam_meta' for r in snap['wp_commentmeta']) and int(var('source', "SELECT COUNT(*) FROM wp_comments WHERE comment_approved='spam'")) == 2)
    check('default excludeTransients: zc_cache_probe transient absent; connector key absent', not any('zc_cache_probe' in r['option_name'] or r['option_name'] == 'zoer_connect_connection' for r in snap['wp_options']) and var('source', "SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_zc_cache_probe'") == '1')
    check('snapshot keeps zc_custom rows and session tokens stay out', len(snap.get('wp_zc_custom', [])) == 3 and not any(r['meta_key'] == 'session_tokens' for r in snap['wp_usermeta']))
    # mediaSince in the future: the uploads root is traversed but nothing qualifies.
    status, v2, f2, _ = pull({'name': 'media since', 'media': True, 'mediaSince': '2099-01-01'}, False)
    check('mediaSince 2099-01-01 exports no uploads', status == 200 and f2 == [], (status, f2))
    if status == 200:
        cancel_export(v2['id'])
    cancel_export(view['id'])

def content_dirs():
    return set(dc('exec', '-T', 'dest', 'find', '/var/www/html/wp-content', '-type', 'd').stdout.split())

def broken_themes():
    return dc('exec', '-T', '-u', 'www-data', 'dest', 'wp', 'eval', 'echo implode(",", array_keys(wp_get_themes(["errors"=>true])));').stdout.strip()

def baseline():
    CTX['dirs'] = content_dirs()
    CTX['baselineRows'] = table_rows('dest')
    CTX['baseline'] = table_hashes('dest')
    CTX['usersHash'] = table_hashes('dest', ['wpd_users'])['wpd_users']

NOISE = re.compile(r'_transient_|\bcron\]|session_tokens|auto-draft|quick_press|rewrite_rules|_pending\]')

def baseline_diff():
    """Tables differing from the baseline plus their non-ephemeral row differences, so a
    failed restore check names the rows (and a legitimate later write is recognisable)."""
    now = table_hashes('dest')
    differing = sorted(t for t in set(now) | set(CTX['baseline']) if now.get(t) != CTX['baseline'].get(t))
    lines = [l for l in row_diff({t: CTX['baselineRows'].get(t, []) for t in differing}, {t: rows('dest', 'SELECT * FROM `%s`' % t) for t in differing}) if not NOISE.search(l)] if differing else []
    return differing, lines

def check_restored(name):
    differing, lines = baseline_diff()
    return check(name, not differing, '%s %s' % (differing, lines))

def scenario_3():
    print('\n== Scenario 3: Push (activation fence, review, replacements, author matching, createTables)', flush=True)
    view, files, data = CTX['pull']
    options = {'replacements': {'automatic': True, 'variants': True, 'paths': True, 'custom': [{'find': 'Source Brand', 'replace': 'Dest Brand', 'regex': False, 'caseSensitive': False}]},
               'authorMapping': 'match', 'createTables': True, 'fence': 'activation', 'review': True, 'purgeCaches': True}
    body, blobs = transfer_manifest(view, files, data, options)
    iid = body['id']; CTX['s3'] = iid
    status, s = api('dest', '/imports', 'POST', body)
    require('create import: 200, phase uploading, options normalized', status == 200 and s['phase'] == 'uploading' and s['options']['replacements']['variants'] and s['options']['authorMapping'] == 'match' and s['fence'] == 'activation', (status, s))
    status, again = api('dest', '/imports', 'POST', body)
    check('idempotent create retry returns the same import', status == 200 and again['id'] == iid)
    s = upload(iid, blobs)
    check('upload: progress uploadedBytes == totalBytes', s['progress']['uploadedBytes'] == s['progress']['totalBytes'] == sum(map(len, blobs)), s['progress'])
    live = []
    def watch(phase, summary):
        if phase in ('checking_artifacts', 'scanning_database', 'mapping_authors', 'preparing_tables', 'reading_database', 'verifying_tables', 'review_required'):
            code = page('dest')[0]
            live.append((phase, code, fence_active()))
    CTX['s3chunks'] = REQUESTS['chunks']
    status, s, seen = drive(iid, 'step', {'review_required', 'cancelled', 'complete'}, watch)
    require('steps reach review_required', status == 200 and s['phase'] == 'review_required', (status, s, seen))
    CTX['s3review'] = s
    order = ['checking_artifacts', 'scanning_database', 'mapping_authors', 'preparing_tables', 'reading_database', 'verifying_tables', 'review_required']
    check('phase order with activation fence + match', [p for p in seen if p in order] == order, seen)
    check('destination serves 200 and holds no fence during staging and review', live and all(code == 200 and not fenced for _, code, fenced in live), live)
    tables_ = {t['name']: t for t in s['stats']['tables']}
    check('review: stats count replacements', s['stats']['replacements'] > 0 and tables_['wpd_posts']['replacements'] > 0 and tables_['wpd_postmeta']['replacements'] > 0, s['stats']['replacements'])
    check('review: wpd_zc_custom marked created', tables_.get('wpd_zc_custom', {}).get('created') is True and not tables_['wpd_zc_custom']['schemaReplaced'], tables_.get('wpd_zc_custom'))
    samples = s['stats']['samples']
    check('review: 1..20 samples, each <=240 chars, showing source->destination', 0 < len(samples) <= 20 and all(len(x['before']) <= 240 and len(x['after']) <= 240 for x in samples) and any('source.test' in x['before'] and 'dest.test' in x['after'] for x in samples), samples[:2])
    check('review: authors matched=1 (jdoe), fallback=2', s['authors'] == {'matched': 1, 'fallback': 2}, s['authors'])
    check('review: error null, progress tableCount matches', s['error'] is None and s['progress']['tableCount'] == len(s['stats']['tables']), (s['error'], s['progress']))
    check('review: destination content still original', var('dest', 'SELECT post_title FROM wpd_posts WHERE ID=%d' % SEED['dest']['destPost']) == 'Destination only post')
    status, a = call(iid, 'approve')
    check('approve: review_required -> reserving', status == 200 and a['phase'] == 'reserving', (status, a))
    status, a2 = call(iid, 'approve')
    check('approve retry is a no-op', status == 200 and a2['phase'] == 'reserving', (status, a2))
    fenced = []
    def watch2(phase, summary):
        if phase in ('preparing_files', 'applying_files', 'activating_tables', 'verification_required'):
            code, _, text = page('dest')
            fenced.append((phase, code, 'zoer_transfer_paused' in text))
    status, s, seen2 = drive(iid, 'step', {'verification_required', 'cancelled', 'complete'}, watch2)
    require('steps reach verification_required', status == 200 and s['phase'] == 'verification_required', (status, s, seen2))
    check('late fence: site paused (503 zoer_transfer_paused) only after approval', fenced and all(code == 503 and paused for _, code, paused in fenced), fenced)
    check('status readable while fenced', call(iid, '', 'GET')[1]['phase'] == 'verification_required')
    status, s = call(iid, 'finish')
    require('finish -> complete', status == 200 and s['phase'] == 'complete' and s['finishedAt'], (status, s))
    CTX['published'] = table_rows('dest')
    CTX['s3hashes'] = table_hashes('dest')
    CTX['s3summary'] = s
    check('finish retry is idempotent', call(iid, 'finish')[1]['phase'] == 'complete')
    check('site reopened: GET / 200 and fence file gone', page('dest')[0] == 200 and not fence_active())
    verify_push()

def verify_push():
    d = SEED['dest']; src = SEED['source']
    check('home/siteurl/admin_email/blog_public retained', option('dest', 'home') == DEST_URL and option('dest', 'siteurl') == DEST_URL and option('dest', 'admin_email') == 'admin@dest.test' and option('dest', 'blog_public') == '0')
    check('blogname migrated with custom replacement ("Dest Brand Site")', option('dest', 'blogname') == 'Dest Brand Site', option('dest', 'blogname'))
    found = []
    for t in ['wpd_posts', 'wpd_postmeta', 'wpd_options', 'wpd_comments', 'wpd_commentmeta', 'wpd_zc_custom', 'wpd_termmeta', 'wpd_terms']:
        found += residues('dest', t)
    check('no source URL residue (plain, JSON-escaped, urlencoded, protocol-relative) in replaced tables', not found, found[:5])
    hello = var('dest', 'SELECT post_content FROM wpd_posts WHERE ID=%d' % src['hello'])
    check('blocks: absolute, http-twin, protocol-relative and urlencoded URLs rewritten', all(x in hello for x in ['https://dest.test/about/', 'https://dest.test/contact/', '//dest.test/assets/app.css', 'https%3A%2F%2Fdest.test%2Fhello%2F', 'https://dest.test/wp-content/uploads/']), hello[:300])
    check('blocks: email addresses untouched, Source Brand -> Dest Brand', 'jdoe@source.test' in hello and 'Dest Brand' in hello and 'Source Brand' not in hello)
    about = var('dest', 'SELECT post_content FROM wpd_posts WHERE ID=%d' % src['aboutPage'])
    check('custom case-insensitive replacement hits every case variant', about.count('Dest Brand') == 3 and 'source brand' not in about.lower(), about)
    raw = var('dest', "SELECT meta_value FROM wpd_postmeta WHERE post_id=%d AND meta_key='_elementor_data'" % src['elementor'])
    try:
        el = json.loads(raw)[0]['elements'][0]['settings']
        ok = el['image']['url'].startswith('https://dest.test/wp-content/uploads/') and el['link']['url'] == 'https://dest.test/landing/' and 'https:\\/\\/dest.test' in raw
    except Exception as e:
        ok, el = False, e
    check('Elementor JSON meta: escaped URLs rewritten and JSON still valid', ok, el)
    zc = option('dest', 'zc_settings', unserialize=True)
    check('serialized option: valid serialization with URLs, twin, path, nested JSON and brand rewritten',
          isinstance(zc, dict) and zc.get('logo', '').startswith('https://dest.test/wp-content/uploads/') and zc.get('links') == ['https://dest.test/about/', 'https://dest.test/contact/']
          and zc.get('uploads_path') == '/var/www/html/wp-content/uploads' and zc.get('brand') == 'Dest Brand' and json.loads(zc['nested']['json']) == {'u': 'https://dest.test/x/'} and zc.get('count') == 3, zc)
    authors = {int(r['ID']): int(r['post_author']) for r in rows('dest', 'SELECT ID, post_author FROM wpd_posts')}
    check('authorMapping match: jdoe posts -> destination jdoe (ID %d); unmatched -> connection admin' % d['jdoe'],
          authors[src['janePost']] == d['jdoe'] and authors[src['aboutPage']] == d['jdoe'] and all(authors[b] == d['jdoe'] for b in src['books']) and authors[src['hello']] == 1 and authors[src['soloPost']] == 1,
          {k: authors.get(v) for k, v in [('jane', src['janePost']), ('hello', src['hello']), ('solo', src['soloPost'])]})
    cu = {int(r['comment_ID']): int(r['user_id']) for r in rows('dest', 'SELECT comment_ID, user_id FROM wpd_comments')}
    check('comment users mapped (jdoe -> %d, guest 0); spam absent' % d['jdoe'], cu.get(src['janeComment']) == d['jdoe'] and cu.get(src['guestComment']) == 0 and not set(src['spam']) & set(cu), cu)
    check('users table untouched (identity retained)', table_hashes('dest', ['wpd_users'])['wpd_users'] == CTX['usersHash'])
    custom = rows('dest', 'SELECT * FROM wpd_zc_custom ORDER BY id') if 'wpd_zc_custom' in tables('dest') else []
    payload = None
    if custom:
        r = dc('exec', '-T', 'dest', 'php', '-r', 'echo json_encode(unserialize(stream_get_contents(STDIN),["allowed_classes"=>false]));', input=custom[0]['payload'])
        payload = json.loads(r.stdout or 'null')
    check('createTables: wpd_zc_custom created with rewritten rows', len(custom) == 3 and custom[0]['url'] == 'https://dest.test/landing/' and payload == {'cta': 'https://dest.test/buy/', 'brand': 'Dest Brand'} and 'https:\\/\\/dest.test\\/wp-json' in custom[1]['payload'] and custom[2]['url'] == 'https://example.org/unrelated/', custom[:2])
    check('filters carried through: no revisions, zc_internal, spam or transient on destination',
          var('dest', "SELECT COUNT(*) FROM wpd_posts WHERE post_type IN ('revision','zc_internal')") == '0' and var('dest', "SELECT COUNT(*) FROM wpd_comments WHERE comment_approved='spam'") == '0' and var('dest', "SELECT COUNT(*) FROM wpd_options WHERE option_name LIKE '%zc_cache_probe%'") == '0')
    plugins = option('dest', 'active_plugins', unserialize=True)
    check('active_plugins inferred from selected plugin files (source list + connector)', sorted(plugins or []) == ['zc-cpt/zc-cpt.php', 'zc-custom-table/zc-custom-table.php', 'zoer-connect/zoer-connect.php'], plugins)
    check('theme inferred from selected theme files (zc-child on twentytwentyfive)', option('dest', 'stylesheet') == 'zc-child' and option('dest', 'template') == 'twentytwentyfive')
    view, files, data = CTX['pull']
    media = next(f for f in files if f['path'].endswith('/zc-media.png'))
    check('files published: plugin, child theme and media match source digests',
          file_sha('dest', '/var/www/html/' + media['path']) == media['sha256'] and exists('dest', '/var/www/html/wp-content/plugins/zc-custom-table/zc-custom-table.php') and exists('dest', '/var/www/html/wp-content/themes/zc-child/style.css'))
    code, _, html = page('dest', '/hello-source/')
    check('HTTP: post renders 200 through child theme with destination URLs', code == 200 and 'content="zc-child"' in html and 'https://dest.test/wp-content/uploads/' in html and 'Dest Brand' in html and not RESIDUE.search(html), (code, RESIDUE.search(html)))
    status, headers, blob = fetch('dest', '/' + media['path'])
    check('HTTP: media file served (200, same bytes)', status == 200 and hashlib.sha256(blob).hexdigest() == media['sha256'], status)
    check('HTTP: page and CPT permalinks resolve after rewrite refresh', page('dest', '/about/')[0] == 200 and page('dest', '/zc_book/first-book/')[0] == 200, (page('dest', '/about/')[0], page('dest', '/zc_book/first-book/')[0]))
    check('purgeCaches marker consumed and rewrite refresh done on first normal request', option('dest', 'zoer_connect_cache_purge_pending') is None and option('dest', 'zoer_connect_rewrite_flush_pending') is None)
    check('connection key still valid after import', api('dest', '/status')[0] == 200)
    check('destination administrator can still log in over HTTPS', admin_login('dest'))

def admin_login(site):
    user = CREDS[site]
    status, headers, _ = fetch(site, '/wp-login.php', 'POST', raw=urllib.parse.urlencode({'log': user['user'], 'pwd': user['password'], 'wp-submit': 'Log In', 'redirect_to': 'https://%s/wp-admin/' % SITES[site]['host'], 'testcookie': '1'}).encode(),
                              headers={'Content-Type': 'application/x-www-form-urlencoded', 'Cookie': 'wordpress_test_cookie=WP%20Cookie%20check'})
    cookies = [v.split(';', 1)[0] for k, v in headers if k.lower() == 'set-cookie']
    if status != 302 or not any(c.startswith('wordpress_logged_in_') for c in cookies):
        return False
    status, _, body = fetch(site, '/wp-admin/', headers={'Cookie': '; '.join(cookies)})
    return status == 200 and 'wp-admin-bar-my-account' in body.decode('utf-8', 'replace')

def scenario_4():
    print('\n== Scenario 4: rollback after finish, then cleanup', flush=True)
    iid = CTX['s3']
    changes = row_diff(CTX['published'], table_rows('dest'))
    print('INFO rows changed on the destination between finish and rollback (HTTP checks, login, cron): %d' % len(changes), flush=True)
    for line in changes:
        print('INFO   ' + line, flush=True)
    # A substantive edit after publication must make rollback refuse safely...
    hello = SEED['source']['hello']
    title = var('dest', 'SELECT post_title FROM wpd_posts WHERE ID=%d' % hello)
    sql('dest', ("UPDATE wpd_posts SET post_title='Edited after finish' WHERE ID=%d" % hello, 'exec'))
    status, r, seen = drive(iid, 'rollback', {'rolled_back', 'cancelled'})
    st = call(iid, '', 'GET')[1]
    check('rollback refused after a later edit (409 safe error)', status == 409 and r.get('message') == 'Destination edited after publication; refusing rollback overwrite.', (status, r, seen))
    check('refused rollback reopens the site: phase complete, rollbackRefused, 200, no fence', st['phase'] == 'complete' and st['rollbackRefused'] is True and page('dest')[0] == 200 and not fence_active(), (st['phase'], st['rollbackRefused']))
    check('refused rollback changed nothing (edit and imported content kept)', var('dest', 'SELECT post_title FROM wpd_posts WHERE ID=%d' % hello) == 'Edited after finish' and 'wpd_zc_custom' in tables('dest'))
    # ...and succeed once the destination matches what was published again.
    sql('dest', ("UPDATE wpd_posts SET post_title='%s' WHERE ID=%d" % (title.replace("'", "''"), hello), 'exec'))
    status, s, seen = drive(iid, 'rollback', {'rolled_back', 'cancelled'})
    check('rollback after finish -> rolled_back', status == 200 and s['phase'] == 'rolled_back', (status, s, seen))
    if status != 200 or s['phase'] != 'rolled_back':
        # A refused rollback reopens the site; report what differs and restore by hand is not attempted.
        raise Abort('rollback refused')
    check('site reopened after rollback (200, no fence)', page('dest')[0] == 200 and not fence_active())
    check('created table removed from service (wpd_zc_custom gone)', 'wpd_zc_custom' not in tables('dest'))
    check_restored('every destination table matches its pre-import content')
    check('published files restored/removed', not exists('dest', '/var/www/html/wp-content/plugins/zc-custom-table/zc-custom-table.php') and not exists('dest', '/var/www/html/wp-content/themes/zc-child/style.css'))
    extra = content_dirs() - CTX['dirs']
    check('rollback removes directories created by the import (no empty plugin/theme folders)', not extra, sorted(extra))
    check('no broken themes after rollback', broken_themes() == '', broken_themes())
    check('destination admin still logs in after rollback', admin_login('dest'))
    assert_cleanup('scenario 4', iid, s)
    status, again = call(iid, 'rollback')
    check('rollback retry after cleanup of a rolled-back import stays rolled_back', status == 200 and again['phase'] == 'rolled_back', (status, again))

def scenario_5():
    print('\n== Scenario 5: legacy client (no options, no sourcePath)', flush=True)
    view, files, data = CTX['pull']
    body, blobs = transfer_manifest(view, files, data, None, file_blocks=False, source_path=False)
    iid = body['id']
    status, s = api('dest', '/imports', 'POST', body)
    check('legacy create: defaults normalized (early fence, administrator authors, no variants)', status == 200 and s['fence'] == 'early' and s['options']['authorMapping'] == 'administrator' and not s['options']['replacements']['variants'] and not s['options']['createTables'], (status, s))
    upload(iid, blobs)
    status, s, seen = drive(iid, 'step', {'verification_required', 'complete', 'cancelled'})
    check('legacy push with a plugin table missing on destination is refused before any fence (0.3.14 rule)', status == 409 and s.get('code') == 'zoer_import_failed' and 'matching destination schema' in s.get('message', '') and s.get('phase') == 'scanning_database', (status, s))
    check('refusal leaves the site live (200, no fence)', page('dest')[0] == 200 and not fence_active())
    st = call(iid, '', 'GET')[1]
    check('failure persisted as summary.error', (st.get('error') or {}).get('code') == 'zoer_import_failed' and st['error']['phase'] == 'scanning_database', st.get('error'))
    status, s = call(iid, 'rollback')
    check('rollback before activation cancels', status == 200 and s['phase'] == 'cancelled', (status, s))
    assert_cleanup('scenario 5a', iid, s)

    core = ['commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users']
    status, v2, f2, d2 = pull({'name': 'core tables + active theme', 'themes': True, 'themesMode': 'active'}, {'tables': core})
    require('core-table pull for the legacy push', status == 200, (status, v2))
    cancel_export(v2['id'])
    CTX['corePull'] = (v2, f2, d2)
    body, blobs = transfer_manifest(v2, f2, d2, None, file_blocks=False, source_path=False)
    iid = body['id']
    status, s = api('dest', '/imports', 'POST', body)
    require('legacy create (core tables)', status == 200, (status, s))
    upload(iid, blobs)
    paused = []
    def watch(phase, summary):
        if phase in ('preparing_tables', 'reading_database', 'verifying_tables', 'preparing_files', 'applying_files', 'activating_tables', 'verification_required'):
            code, _, text = page('dest')
            paused.append((phase, code, 'zoer_transfer_paused' in text, fence_active()))
    status, s, seen = drive(iid, 'step', {'verification_required', 'complete', 'cancelled'}, watch)
    require('legacy steps reach verification_required', status == 200 and s['phase'] == 'verification_required', (status, s, seen))
    check('legacy phase order (early fence: reserving before table staging, no mapping_authors)', 'mapping_authors' not in seen and seen.index('scanning_database') < seen.index('reserving') < seen.index('preparing_tables') < seen.index('reading_database') < seen.index('preparing_files'), seen)
    check('early fence: site returns 503 through every protected phase', paused and all(code == 503 and p and f for _, code, p, f in paused), paused)
    status, s = call(iid, 'finish')
    check('legacy finish -> complete, site 200', status == 200 and s['phase'] == 'complete' and page('dest')[0] == 200, (status, s))
    authors = {r['post_author'] for r in rows('dest', 'SELECT DISTINCT post_author FROM wpd_posts')}
    check('legacy authors: every post assigned to the connection administrator (1)', authors == {'1'}, authors)
    check('legacy comments: unlinked from users', var('dest', 'SELECT COUNT(*) FROM wpd_comments WHERE user_id<>0') == '0')
    hello = var('dest', 'SELECT post_content FROM wpd_posts WHERE ID=%d' % SEED['source']['hello'])
    raw = var('dest', "SELECT meta_value FROM wpd_postmeta WHERE post_id=%d AND meta_key='_elementor_data'" % SEED['source']['elementor'])
    check('legacy 0.3.14 rules: sourceUrl replaced; http twin and JSON-escaped URLs left as-is', 'https://dest.test/about/' in hello and 'http://source.test/contact/' in hello and 'https:\\/\\/source.test' in raw, hello[:200])
    zc = option('dest', 'zc_settings', unserialize=True)
    check('legacy: serialized option valid, no path or custom replacement', isinstance(zc, dict) and zc['links'][0] == 'https://dest.test/about/' and zc['uploads_path'] == '/srv/source/public/wp-content/uploads' and zc['brand'] == 'Source Brand', zc)
    check('legacy: destination active_plugins kept (plugins not selected), theme taken from source', option('dest', 'active_plugins', unserialize=True) == ['zoer-connect/zoer-connect.php'] and option('dest', 'stylesheet') == 'zc-child')
    check('legacy: admin login and key still valid', admin_login('dest') and api('dest', '/status')[0] == 200)
    status, s, _ = drive(iid, 'rollback', {'rolled_back', 'cancelled'})
    check('legacy rollback -> rolled_back', status == 200 and s['phase'] == 'rolled_back', (status, s))
    check_restored('legacy rollback restores every table')
    check('legacy (32 MiB file path) rollback removes created directories', not content_dirs() - CTX['dirs'], sorted(content_dirs() - CTX['dirs']))
    assert_cleanup('scenario 5b', iid, s)

def replace_import(custom, review, tables_=None):
    iid = uuid.uuid4().hex
    options = {'replacements': {'custom': custom}, 'review': review, 'fence': 'activation', 'purgeCaches': True}
    if tables_:
        options['tables'] = tables_
    status, s = api('dest', '/imports', 'POST', {'kind': 'replace', 'id': iid, 'target': DEST_URL, 'migrationMode': 'shared-replacement', 'replacementAccepted': True, 'options': options})
    return iid, status, s

def scenario_6():
    print('\n== Scenario 6: destination-only Find & Replace (kind replace)', flush=True)
    d = SEED['dest']
    custom = [{'find': 'Destination only', 'replace': 'Replaced on destination', 'regex': False, 'caseSensitive': True},
              {'find': '~destination\\s+PAGE~', 'replace': 'Regex page', 'regex': True, 'caseSensitive': False}]
    iid, status, s = replace_import(custom, True)
    require('replace create: kind replace, snapshotting, automatic rules off', status == 200 and s['kind'] == 'replace' and s['phase'] == 'snapshotting' and s['options']['replacements']['automatic'] is False and s['options']['partialDatabase'], (status, s))
    live = []
    status, s, seen = drive(iid, 'step', {'review_required', 'cancelled', 'complete'}, lambda p, _: live.append((p, page('dest')[0])))
    require('replace reaches review_required', status == 200 and s['phase'] == 'review_required', (status, s, seen))
    check('replace: site live throughout snapshot/staging/review', all(code == 200 for _, code in live), live)
    names = [t['name'] for t in s['stats']['tables']]
    check('replace: default tables = every prefixed table except users/usermeta', 'wpd_users' not in names and 'wpd_usermeta' not in names and 'wpd_posts' in names and 'wpd_options' in names, names)
    check('replace: review stats and samples', s['stats']['replacements'] >= 3 and any('Replaced on destination' in x['after'] for x in s['stats']['samples']) and any('Regex page' in x['after'] for x in s['stats']['samples']), (s['stats']['replacements'], s['stats']['samples'][:2]))
    check('replace: authors untouched policy', s['authors'] is None and 'unchanged' in s['authorPolicy'])
    status, a = call(iid, 'approve')
    status, s, _ = drive(iid, 'step', {'verification_required', 'cancelled', 'complete'})
    require('replace approve -> verification_required', status == 200 and s['phase'] == 'verification_required', (status, s))
    status, s = call(iid, 'finish')
    check('replace finish -> complete', status == 200 and s['phase'] == 'complete', (status, s))
    post = rows('dest', 'SELECT post_title, post_content FROM wpd_posts WHERE ID=%d' % d['destPost'])[0]
    check('replace: literal rule applied to title and content', post['post_title'] == 'Replaced on destination post' and 'Replaced on destination content' in post['post_content'], post)
    check('replace: case-insensitive regex rule applied', var('dest', 'SELECT post_title FROM wpd_posts WHERE ID=%d' % d['destPage']) == 'Regex page')
    check('replace: identity, users and key retained', option('dest', 'home') == DEST_URL and table_hashes('dest', ['wpd_users'])['wpd_users'] == CTX['usersHash'] and api('dest', '/status')[0] == 200 and page('dest')[0] == 200)
    status, s, _ = drive(iid, 'rollback', {'rolled_back', 'cancelled'})
    check('replace rollback -> rolled_back and content restored', status == 200 and s['phase'] == 'rolled_back' and var('dest', 'SELECT post_title FROM wpd_posts WHERE ID=%d' % d['destPost']) == 'Destination only post', (status, s.get('error')))
    check_restored('replace rollback restores every table')
    assert_cleanup('scenario 6', iid, s)
    # Without review; after cleanup the finished import can no longer be rolled back.
    iid, status, s = replace_import([{'find': 'Destination tagline', 'replace': 'Tagline replaced', 'regex': False, 'caseSensitive': True}], False, ['options'])
    status, s, seen = drive(iid, 'step', {'verification_required', 'cancelled', 'complete'})
    check('replace without review goes straight to verification_required', status == 200 and s['phase'] == 'verification_required' and 'review_required' not in seen, seen)
    call(iid, 'finish')
    check('replace (options only) applied', option('dest', 'blogdescription') == 'Tagline replaced')
    status, s = cleanup(iid)
    status, r = call(iid, 'rollback')
    check('rollback after cleanup of a complete import is refused (409, safe error)', status == 409 and r.get('code') == 'zoer_import_failed' and 'cleaned up' in r.get('message', ''), (status, r))
    iid, status, s = replace_import([{'find': 'Tagline replaced', 'replace': 'Destination tagline', 'regex': False, 'caseSensitive': True}], False, ['options'])
    drive(iid, 'step', {'verification_required'}); call(iid, 'finish'); cleanup(iid)
    check('reverse replace restores the tagline', option('dest', 'blogdescription') == 'Destination tagline')

def scenario_7():
    print('\n== Scenario 7: late-fence abort on a live edit during review', flush=True)
    view, files, data = CTX['pull']
    options = {'replacements': {'variants': True}, 'authorMapping': 'match', 'createTables': True, 'fence': 'activation', 'review': True}
    body, blobs = transfer_manifest(view, files, data, options)
    iid = body['id']
    status, s = api('dest', '/imports', 'POST', body)
    require('create', status == 200, (status, s))
    upload(iid, blobs)
    status, s, _ = drive(iid, 'step', {'review_required', 'cancelled'})
    require('reach review_required', status == 200 and s['phase'] == 'review_required', (status, s))
    pid = SEED['dest']['destPost']
    sql('dest', ("UPDATE wpd_posts SET post_title='Edited during review' WHERE ID=%d" % pid, 'exec'))
    status, s = call(iid, 'approve')
    status, s, seen = drive(iid, 'step', {'cancelled', 'verification_required', 'complete'})
    check('approve after live edit -> cancelled with safe error', status == 200 and s['phase'] == 'cancelled' and (s.get('error') or {}).get('code') == 'zoer_import_failed' and s['error']['phase'] == 'reserving' and 'changed' in s['error']['message'], (status, s.get('phase'), s.get('error'), seen))
    check('site NOT left in maintenance (200, write-fence.json absent)', page('dest')[0] == 200 and not fence_active())
    check('live edit kept; nothing activated (no wpd_zc_custom)', var('dest', 'SELECT post_title FROM wpd_posts WHERE ID=%d' % pid) == 'Edited during review' and 'wpd_zc_custom' not in tables('dest'))
    sql('dest', ("UPDATE wpd_posts SET post_title='Destination only post' WHERE ID=%d" % pid, 'exec'))
    assert_cleanup('scenario 7', iid, s)

def scenario_8():
    print('\n== Scenario 8: pause/resume, list, safe errors', flush=True)
    v2, f2, d2 = CTX['corePull']
    body, blobs = transfer_manifest(v2, f2, d2, {'fence': 'activation'})
    iid = body['id']
    status, s = api('dest', '/imports', 'POST', body)
    require('create', status == 200, (status, s))
    status, e = api('dest', '/imports', 'POST', dict(body, id=uuid.uuid4().hex))
    check('second import while one is active -> 409 "Recover the existing import first."', status == 409 and e.get('message') == 'Recover the existing import first.', (status, e))
    status, e = api('dest', '/imports', 'POST', dict(body, sourcePrefix='zz_'))
    check('conflicting create retry -> 400', status == 400 and e.get('message') == 'Conflicting import creation retry.', (status, e))
    db = blobs[0]
    bad = bytes([db[0] ^ 1]) + db[1:CHUNK]
    status, e = api('dest', '/imports/%s/chunks' % iid, 'POST', {'index': 0, 'offset': 0, 'data': base64.b64encode(bad).decode()})
    check('chunk digest mismatch -> 400 {code, message, phase}', status == 400 and e == {'code': 'zoer_import_failed', 'message': 'Database chunk digest mismatch.', 'phase': 'uploading'}, (status, e))
    status, e = api('dest', '/imports/%s/chunks' % iid, 'POST', {'index': 0, 'offset': 0, 'data': 123})
    check('malformed chunk -> 400', status == 400 and e.get('message') == 'Invalid import chunk.', (status, e))
    status, p = call(iid, 'pause')
    check('pause while uploading -> paused (resumePhase uploading)', status == 200 and p['phase'] == 'paused' and p['resumePhase'] == 'uploading', (status, p))
    status, e = api('dest', '/imports/%s/chunks' % iid, 'POST', {'index': 0, 'offset': 0, 'data': base64.b64encode(db[:CHUNK]).decode()})
    check('upload refused while paused', status == 400, (status, e))
    check('pause is idempotent', call(iid, 'pause')[1]['phase'] == 'paused')
    status, r = call(iid, 'resume')
    check('resume -> uploading', status == 200 and r['phase'] == 'uploading' and r['resumePhase'] is None, (status, r))
    upload(iid, blobs)
    status, s = call(iid, 'step')
    status, p = call(iid, 'pause')
    before = p['resumePhase']
    check('pause mid-pipeline records resumePhase', status == 200 and p['phase'] == 'paused' and before == s['phase'], (s['phase'], p))
    status, st = call(iid, 'step')
    check('step while paused makes no progress', status == 200 and st['phase'] == 'paused' and st['resumePhase'] == before)
    status, r = call(iid, 'resume')
    check('resume restores the phase', status == 200 and r['phase'] == before)
    check('resume when not paused is a no-op', call(iid, 'resume')[1]['phase'] == before)
    status, e = call(iid, 'approve')
    check('approve without review -> 409 safe error', status == 409 and e.get('message') == 'Import is not waiting for review.' and e.get('code') == 'zoer_import_failed', (status, e))
    status, lst = api('dest', '/imports')
    entries = lst.get('imports', []) if isinstance(lst, dict) else []
    check('GET /imports lists newest first with light entries', status == 200 and entries and entries[0]['id'] == iid and 'artifactCount' in entries[0] and 'artifacts' not in entries[0] and len(entries) <= 50 and CTX['s3'] in [x['id'] for x in entries], (status, [x.get('id') for x in entries][:3]))
    check('list entries carry kind/phase/cleanedUp/updatedAt', all({'kind', 'phase', 'cleanedUp', 'updatedAt', 'finishedAt', 'stats', 'progress'} <= set(x) for x in entries))
    for label, opts, message in [('fence bogus', {'fence': 'bogus'}, 'Invalid fence mode.'), ('review with early fence', {'review': True}, 'Review requires the activation fence.'), ('unknown option', {'surprise': 1}, 'Invalid import options.')]:
        status, e = api('dest', '/imports', 'POST', {'id': uuid.uuid4().hex, 'target': DEST_URL, 'sourceUrl': SOURCE_URL, 'sourcePrefix': 'wp_', 'migrationMode': 'shared-replacement', 'replacementAccepted': True, 'database': body['database'], 'options': opts})
        check('bad request (%s) -> 400 {code:zoer_import_failed, message, phase:null}' % label, status == 400 and e == {'code': 'zoer_import_failed', 'message': message, 'phase': None}, (status, e))
    status, e = api('dest', '/imports/%s' % ('f' * 32))
    check('unknown import id -> safe generic error without paths', status == 409 and e.get('code') == 'zoer_import_failed' and '/var/' not in json.dumps(e) and len(e.get('message', '')) <= 300, (status, e))
    status, e = api('dest', '/imports/%s/explode' % iid, 'POST', {})
    check('unknown action -> 404', status == 404, (status, e))
    status, e = api('dest', '/imports', key='zc_' + '1' * 64)
    check('import list with a wrong key -> 401', status == 401, (status, e))
    status, s = call(iid, 'rollback')
    check('rollback before activation cancels', status == 200 and s['phase'] == 'cancelled', (status, s))
    assert_cleanup('scenario 8', iid, s)

# --- batched upload client (C1) ------------------------------------------------------
def zbt1(spans, payloads, enc=None):
    header = json.dumps({'v': 1, 'spans': spans, 'payloadBytes': sum(map(len, payloads)), 'enc': enc}, separators=(',', ':')).encode()
    return b'ZBT1' + struct.pack('>I', len(header)) + header + b''.join(payloads)

def multipart(frame):
    boundary = uuid.uuid4().hex
    body = (b'--' + boundary.encode() + b'\r\nContent-Disposition: form-data; name="batch"; filename="batch.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'
            + frame + b'\r\n--' + boundary.encode() + b'--\r\n')
    return 'multipart/form-data; boundary=' + boundary, body

def post_batch(iid, content_type, data):
    REQUESTS['batch'] += 1
    try:
        status, _, raw = fetch('dest', '/wp-json/zoer-connect/v1/imports/%s/batch' % iid, 'POST', raw=data, headers={'X-Zoer-Connection': KEYS['dest'], 'Content-Type': content_type})
    except (OSError, http.client.HTTPException) as e:
        return None, {'_error': repr(e)}
    try:
        return status, json.loads(raw)
    except ValueError:
        return status, {'_raw': raw[:300].decode('utf-8', 'replace')}

def ini_bytes(v):
    m = re.match(r'\s*(\d+)\s*([kmg]?)', str(v or ''), re.I)
    return int(m.group(1)) * {'': 1, 'k': 1024, 'm': 1 << 20, 'g': 1 << 30}[m.group(2).lower()] if m else 0

def pack(blobs, cursor, budget, only=None):
    """Spans forward from the server cursor: whole blocks, <= budget raw bytes, <= 1024 spans; zero-byte artifacts have none."""
    spans, payloads, total = [], [], 0
    i, off = cursor['index'], cursor['offset']
    while i < len(blobs) and len(spans) < 1024 and (only is None or i in only):
        blob = blobs[i]
        if off >= len(blob):
            i, off = i + 1, 0
            continue
        length = len(blob) - off
        if length > budget - total:
            length = (budget - total) // CHUNK * CHUNK
            if not length:
                break
        spans.append([i, off, length]); payloads.append(blob[off:off + length]); total += length; off += length
    return spans, payloads

def scenario_9():
    print('\n== Scenario 9: batched Push (/batch: octet-stream + deflate, JSON, multipart) matches the /chunks Push', flush=True)
    status, st = api('dest', '/status')
    limits = st.get('batchLimits') or {}
    check('/status: batchUpload, batchDeflate, transports and limits advertised', st['capabilities'].get('batchUpload') is True and st['capabilities'].get('batchDeflate') is True
          and st.get('batchTransports') == ['octet-stream', 'multipart', 'json'] and limits.get('blockBytes') == CHUNK and limits.get('maxSpans') == 1024 and limits.get('deadlineMs') == 2000
          and limits.get('maxJsonBatchBytes') == 2097152 and CHUNK <= limits.get('maxBatchBytes', 0) <= 8388608, (st.get('batchTransports'), limits))
    view, files, data = CTX['pull']
    options = {'replacements': {'automatic': True, 'variants': True, 'paths': True, 'custom': [{'find': 'Source Brand', 'replace': 'Dest Brand', 'regex': False, 'caseSensitive': False}]},
               'authorMapping': 'match', 'createTables': True, 'fence': 'activation', 'review': True, 'purgeCaches': True}
    body, blobs = transfer_manifest(view, files, data, options)
    iid = body['id']
    status, s = api('dest', '/imports', 'POST', body)
    require('create import for the batched push', status == 200 and s['phase'] == 'uploading', (status, s))
    listing = set(dc('exec', '-T', 'dest', 'ls', '-A', SITES['dest']['private'] + '/import-' + iid).stdout.split())
    check('index files written at create (artifacts.idx, blocks.idx, upload.json)', {'artifacts.idx', 'blocks.idx', 'upload.json', 'state.json'} <= listing, listing)
    status, u = api('dest', '/imports/' + iid, params={'view': 'upload'})
    total = sum(map(len, blobs))
    check('view=upload at start: cursor 0/0, totals', status == 200 and u == {'cursor': {'index': 0, 'offset': 0}, 'uploadedBytes': 0, 'totalBytes': total, 'phase': 'uploading'}, (status, u))
    start = REQUESTS['batch']
    counts = {'octet-stream': 0, 'deflate': 0, 'json': 0, 'json-deflate': 0, 'multipart': 0}
    shape = ['v', 'phase', 'acceptedSpans', 'appendedBytes', 'cursor', 'uploadedBytes', 'totalBytes', 'complete', 'deadlineHit', 'serverMs', 'rejected', 'limits']

    def send(kind, spans, payloads):
        if kind == 'deflate':
            z = [zlib.compress(p) for p in payloads]
            frame = zbt1([sp + [len(zz)] for sp, zz in zip(spans, z)], z, 'deflate')
            status, r = post_batch(iid, 'application/octet-stream', frame)
        elif kind == 'json-deflate':
            z = [zlib.compress(p) for p in payloads]
            status, r = post_batch(iid, 'application/json', json.dumps({'v': 1, 'enc': 'deflate', 'spans': [sp + [len(zz), base64.b64encode(zz).decode()] for sp, zz in zip(spans, z)]}).encode())
        elif kind == 'json':
            status, r = post_batch(iid, 'application/json', json.dumps({'v': 1, 'spans': [sp + [base64.b64encode(p).decode()] for sp, p in zip(spans, payloads)]}).encode())
        elif kind == 'multipart':
            status, r = post_batch(iid, *multipart(zbt1(spans, payloads)))
        else:
            status, r = post_batch(iid, 'application/octet-stream', zbt1(spans, payloads))
        counts[kind] += 1
        return status, r

    # 1. database.sql as zlib (RFC 1950) deflate spans, 2. JSON (<= 1 MiB raw), 3. JSON with deflate,
    # 4. multipart (<= 1 MiB raw), then fixed 4 MiB octet-stream batches, each packed from the server cursor.
    cursor, first, last = {'index': 0, 'offset': 0}, {}, None
    plan = [('deflate', 4 << 20, {0}), ('json', 1 << 20, None), ('json-deflate', 1 << 20, None), ('multipart', 1 << 20, None)]
    for _ in range(400):
        kind, budget, only = plan.pop(0) if plan else ('octet-stream', 4 << 20, None)
        spans, payloads = pack(blobs, cursor, budget, only)
        if not spans:
            break
        status, r = send(kind, spans, payloads)
        if status != 200 or r.get('rejected') is not None or r.get('acceptedSpans') is None:
            check('batch (%s) accepted' % kind, False, (status, r))
            raise Abort('batch upload failed')
        first.setdefault(kind, (spans, payloads, r))
        last = (spans, payloads, r)
        cursor = r['cursor']
        if r['complete']:
            break
    rs = {k: v[2] for k, v in first.items()}
    check('every transport accepted its batch in full (octet-stream, deflate, json, json-deflate, multipart)', set(rs) == set(counts)
          and all(r['acceptedSpans'] == len(first[k][0]) and not r['deadlineHit'] and r['appendedBytes'] == sum(map(len, first[k][1])) for k, r in rs.items()), {k: (r['acceptedSpans'], r['deadlineHit'], r['serverMs']) for k, r in rs.items()})
    check('batch response has exactly the C1 keys and limits', all(list(r) == shape for r in rs.values()) and rs['octet-stream']['limits'] == limits, list(rs['octet-stream']))
    r = last[2]
    check('last batch: complete, cursor at end, uploadedBytes == totalBytes', r['complete'] and r['cursor'] == {'index': len(blobs), 'offset': 0} and r['uploadedBytes'] == r['totalBytes'] == total, r)
    status, u = api('dest', '/imports/' + iid, params={'view': 'upload'})
    check('view=upload after the batches agrees', u == {'cursor': {'index': len(blobs), 'offset': 0}, 'uploadedBytes': total, 'totalBytes': total, 'phase': 'uploading'}, u)
    status, again = send('octet-stream', last[0], last[1])
    check('retry of the last batch is idempotent (appendedBytes 0, all spans accepted)', status == 200 and again['appendedBytes'] == 0 and again['acceptedSpans'] == len(last[0]) and again['rejected'] is None, (status, again))
    batch_requests = REQUESTS['batch'] - start
    # Body limits: a multipart part over upload_max_filesize that PHP still reads (UPLOAD_ERR_INI_SIZE),
    # and an octet-stream body over post_max_size and maxBatchBytes, refused from its declared length
    # (the plugin drains the bounded tail, so the 413 arrives instead of a connection reset).
    php = api('dest', '/diagnostics')[1]['php']
    post_max, upload_max = ini_bytes(php['postMaxSize']), ini_bytes(php['uploadMaxFilesize'])
    if 0 < upload_max and upload_max + (1 << 20) < post_max:
        pad = upload_max + (256 << 10)
        status, e = post_batch(iid, *multipart(zbt1([[0, 0, pad]], [b'\0' * pad])))
        check('multipart part over upload_max_filesize (%s) -> 413 zoer_import_body_limit with limits' % php['uploadMaxFilesize'], status == 413 and e.get('code') == 'zoer_import_body_limit' and e.get('limits', {}).get('blockBytes') == CHUNK, (status, e))
    else:
        print('INFO multipart body-limit check skipped (upload_max_filesize %s, post_max_size %s)' % (php['uploadMaxFilesize'], php['postMaxSize']), flush=True)
    over = max(post_max, limits['maxBatchBytes']) + CHUNK
    status, e = post_batch(iid, 'application/octet-stream', zbt1([[0, 0, over]], [b'\0' * over]))
    check('octet-stream body over post_max_size/maxBatchBytes -> 413 zoer_import_body_limit with limits', status == 413 and e.get('code') == 'zoer_import_body_limit' and e.get('limits') == limits, (status, e))
    frame = bytearray(zbt1(last[0][:1], last[1][:1])); frame[-1] ^= 1
    status, e = post_batch(iid, 'application/octet-stream', bytes(frame))
    check('corrupted block -> 200 with rejected digest_mismatch (nothing changed)', status == 200 and (e.get('rejected') or {}).get('code') == 'digest_mismatch' and e.get('appendedBytes') == 0, (status, e))
    status, e = post_batch(iid, 'application/octet-stream', b'ZBT1' + struct.pack('>I', 70000) + b' ' * 70000)
    check('header over 64 KiB -> 400 safe error', status == 400 and e.get('code') == 'zoer_import_failed' and '64 KiB' in e.get('message', ''), (status, e))
    status, e = api('dest', '/imports/%s/batch' % iid, 'POST', {'v': 1, 'spans': 'nope'})
    check('malformed JSON batch -> 400 safe error', status == 400 and e.get('code') == 'zoer_import_failed', (status, e))

    chunks = CTX.get('s3chunks') or sum((len(b) + CHUNK - 1) // CHUNK for b in blobs)
    print('INFO request counts for the same export (%d artifacts, %d bytes): /chunks %d vs /batch %d (octet-stream %d, deflate %d, json %d, json-deflate %d, multipart %d; plus 1 idempotent retry)'
          % (len(blobs), total, chunks, batch_requests - 1, counts['octet-stream'] - 1, counts['deflate'], counts['json'], counts['json-deflate'], counts['multipart']), flush=True)
    check('/batch needs far fewer upload requests than /chunks', batch_requests - 1 < chunks, (batch_requests - 1, chunks))

    status, s, seen = drive(iid, 'step', {'review_required', 'cancelled', 'complete'})
    require('batched push reaches review_required', status == 200 and s['phase'] == 'review_required', (status, s, seen))
    status, e = post_batch(iid, 'application/octet-stream', zbt1(last[0][:1], last[1][:1]))
    check('batch outside uploading -> 409 safe error with phase', status == 409 and e.get('code') == 'zoer_import_failed' and e.get('phase') == 'review_required', (status, e))
    ref = CTX.get('s3review')
    if ref:
        strip = lambda x: {k: x[k] for k in ('stats', 'authors', 'progress', 'tableRows')}
        check('review summary identical to the /chunks push (stats, samples, authors, progress, rows)', strip(s) == strip(ref), [k for k in ('stats', 'authors', 'progress', 'tableRows') if s[k] != ref[k]])
    call(iid, 'approve')
    status, s, _ = drive(iid, 'step', {'verification_required', 'cancelled', 'complete'})
    require('batched push reaches verification_required', status == 200 and s['phase'] == 'verification_required', (status, s))
    status, s = call(iid, 'finish')
    require('batched push finish -> complete', status == 200 and s['phase'] == 'complete', (status, s))
    now = table_hashes('dest')
    if CTX.get('s3hashes'):
        diff = [t for t in set(now) | set(CTX['s3hashes']) if now.get(t) != CTX['s3hashes'].get(t)]
        check('published destination tables identical to the /chunks push', not diff, diff)
    media = next(f for f in files if f['path'].endswith('/zc-media.png'))
    check('published files match source digests', file_sha('dest', '/var/www/html/' + media['path']) == media['sha256'] and exists('dest', '/var/www/html/wp-content/themes/zc-child/style.css'))
    status, s, _ = drive(iid, 'rollback', {'rolled_back', 'cancelled'})
    check('batched push rollback -> rolled_back', status == 200 and s['phase'] == 'rolled_back', (status, s))
    check_restored('rollback restores every table')
    check('rollback removes created directories', not content_dirs() - CTX['dirs'], sorted(content_dirs() - CTX['dirs']))
    assert_cleanup('scenario 9', iid, s)

def final_checks():
    print('\n== Final state', flush=True)
    differing, lines = baseline_diff()
    for line in lines:
        print('INFO   ' + line, flush=True)
    check('destination fully restored to its pre-test content', not differing, differing)
    check('no zoer_ private tables remain after cleanups', not zoer_tables(), zoer_tables())
    check('site live, no fence, admin login works', page('dest')[0] == 200 and not fence_active() and admin_login('dest'))
    check('source untouched by pulls (still serves, still has revisions)', page('source', '/hello-source/')[0] == 200 and int(var('source', "SELECT COUNT(*) FROM wp_posts WHERE post_type='revision'")) >= 2)

def main():
    plan = [('1', scenario_1), ('2', scenario_2), ('baseline', baseline), ('3', scenario_3), ('4', scenario_4), ('5', scenario_5), ('6', scenario_6), ('7', scenario_7), ('8', scenario_8), ('9', scenario_9), ('final', final_checks)]
    only = set(sys.argv[1:])
    for name, fn in plan:
        if only and name not in only and name != 'baseline':
            continue
        try:
            fn()
        except Abort as e:
            print('ABORT scenario %s: %s' % (name, e), flush=True)
            if name in ('2', '3'):
                break
        except Exception as e:
            check('scenario %s raised no exception' % name, False, ''.join(traceback.format_exception_only(type(e), e)).strip())
            traceback.print_exc()
            if name in ('2', '3'):
                break
    failed = [n for n, ok in RESULTS if not ok]
    print('\n%d checks, %d failed' % (len(RESULTS), len(failed)))
    for n in failed:
        print('  FAILED: ' + n)
    sys.exit(1 if failed or not RESULTS else 0)

if __name__ == '__main__':
    main()
