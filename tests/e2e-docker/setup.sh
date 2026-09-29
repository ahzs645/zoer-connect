#!/usr/bin/env bash
# Builds and seeds the two-site fixture. Test credentials and connection keys are
# generated here and written only to .state/ (git-ignored, mode 0600); nothing
# secret is printed.
set -euo pipefail
cd "$(dirname "$0")"
STATE=.state
COMPOSE=(docker compose -f docker-compose.yml)
export DB_IMAGE="${DB_IMAGE:-mariadb:11.4}"

rm -rf "$STATE"; mkdir -p "$STATE"; chmod 700 "$STATE"
"${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
echo "Starting containers (DB_IMAGE=$DB_IMAGE)"
"${COMPOSE[@]}" up -d --build --wait db
"${COMPOSE[@]}" up -d --build

wp() { local site=$1; shift; "${COMPOSE[@]}" exec -T -u www-data "$site" wp "$@"; }
docroot() { [ "$1" = source ] && echo /srv/source/public || echo /var/www/html; }

for site in source dest; do
  root=$(docroot $site)
  for _ in $(seq 1 90); do
    "${COMPOSE[@]}" exec -T "$site" test -s "$root/wp-config.php" && "${COMPOSE[@]}" exec -T "$site" test -e "$root/wp-includes/version.php" && break
    sleep 1
  done
  "${COMPOSE[@]}" exec -T "$site" test -s "$root/wp-config.php" || { echo "WordPress files did not appear on $site"; exit 1; }
done

SRC_PASS=$(openssl rand -hex 16); DEST_PASS=$(openssl rand -hex 16)
umask 077
printf '{"source":{"user":"srcadmin","password":"%s"},"dest":{"user":"destadmin","password":"%s"}}\n' "$SRC_PASS" "$DEST_PASS" > "$STATE/credentials.json"

echo "Installing WordPress"
wp source core install --url=https://source.test --title="Source Brand Site" --admin_user=srcadmin --admin_password="$SRC_PASS" --admin_email=admin@source.test --skip-email >/dev/null
wp dest core install --url=https://dest.test --title="Dest Site" --admin_user=destadmin --admin_password="$DEST_PASS" --admin_email=admin@dest.test --skip-email >/dev/null

HTACCESS='# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress'
for site in source dest; do
  printf '%s\n' "$HTACCESS" | "${COMPOSE[@]}" exec -T -u www-data "$site" sh -c "cat > $(docroot $site)/.htaccess"
done

echo "Installing source fixtures (plugins, child theme)"
for item in plugins/zc-custom-table plugins/zc-cpt themes/zc-child; do
  "${COMPOSE[@]}" cp "fixtures/$item" "source:/srv/source/public/wp-content/$(dirname $item)/" >/dev/null
  "${COMPOSE[@]}" exec -T source chown -R www-data:www-data "/srv/source/public/wp-content/$item"
done
wp source plugin activate zoer-connect zc-cpt zc-custom-table >/dev/null
wp source theme activate zc-child >/dev/null
wp dest plugin activate zoer-connect >/dev/null

echo "Seeding content"
wp source eval-file /zc/seed-source.php --user=srcadmin > "$STATE/source-seed.json"
wp dest eval-file /zc/seed-dest.php --user=destadmin > "$STATE/dest-seed.json"

echo "Generating connection keys through the admin form code path"
wp source eval-file /zc/connection.php rotate --user=srcadmin > "$STATE/source-rotate.json"
wp source eval-file /zc/connection.php permissions push pull --user=srcadmin > "$STATE/source-permissions.json"
wp dest eval-file /zc/connection.php rotate --user=destadmin > "$STATE/dest-rotate.json"
python3 - "$STATE" <<'PY'
import json, os, sys
state = sys.argv[1]
for site in ('source', 'dest'):
    path = os.path.join(state, site + '-rotate.json')
    data = json.load(open(path))
    key = data.pop('key')
    assert key and key.startswith('zc_') and len(key) == 67, 'no key generated for ' + site
    with open(os.path.join(state, site + '.key'), 'w') as fh: fh.write(key)
    json.dump(data, open(path, 'w'))  # keep the notices/permissions, drop the secret
    print(site, 'key generated; push=%s pull=%s; notices: %s' % (data['push'], data['pull'], ' | '.join(data['notices'])))
perm = json.load(open(os.path.join(state, 'source-permissions.json')))
assert perm['push'] and perm['pull'], perm
print('source permissions saved; push=%s pull=%s' % (perm['push'], perm['pull']))
PY
chmod 600 "$STATE"/*

# Render front-end pages once, as a real site would have been viewed. Block themes
# create their fallback wp_navigation post and custom_css_post_id theme mod on the
# first view; a source that was never rendered would push without them and the
# destination would generate them after activation (which rollback then treats as
# a later edit).
PORT="${ZC_HTTPS_PORT:-8443}"
for url in https://source.test/ https://source.test/hello-source/ https://source.test/about/ https://dest.test/; do
  host=${url#https://}; host=${host%%/*}
  curl -sk -o /dev/null --connect-to "$host:443:127.0.0.1:$PORT" "$url" || true
done

# Run the initial due cron events once so fresh-install housekeeping does not
# happen in the middle of a scenario (cron itself stays enabled).
wp source cron event run --due-now >/dev/null 2>&1 || true
wp dest cron event run --due-now >/dev/null 2>&1 || true

"${COMPOSE[@]}" exec -T source php -r 'echo "PHP ", PHP_VERSION, "\n";'
wp source core version | sed 's/^/WordPress /'
"${COMPOSE[@]}" exec -T db sh -c '(command -v mariadb >/dev/null && mariadb -uroot -pzoer-e2e-root -N -e "SELECT VERSION()") || mysql -uroot -pzoer-e2e-root -N -e "SELECT VERSION()"' 2>/dev/null | sed 's/^/Database /'
echo "Setup complete"
