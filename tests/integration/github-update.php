<?php
// Run only in the disposable container made by github-update-wordpress.py.
if (getenv('ZC_UPDATE_FIXTURE') !== '1') throw new RuntimeException('Disposable updater fixture required.');
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
$phase = getenv('ZC_UPDATE_PHASE');
$fixture = getenv('ZC_UPDATE_TRANSPORT') === 'fixture';
$version = '0.5.2';
$repo = 'https://github.com/ahzs645/zoer-connect';
$legacy = 'https://raw.githubusercontent.com/ahzs645/zoer-connect-releases/main';
$zip = '/fixtures/zoer-connect-'.$version.'.zip';
$manifest = ['version'=>$version, 'sha256'=>hash_file('sha256', $zip)];
$requests = [];
add_filter('pre_http_request', function($pre, $args, $url) use (&$requests, $fixture, $manifest, $zip, $repo, $legacy) {
    if (!str_starts_with($url, $repo.'/releases/') && !str_starts_with($url, $legacy.'/')) return $pre;
    $requests[] = $url;
    if (!$fixture) return $pre;
    $isZip = str_ends_with($url, '.zip');
    $body = $isZip ? file_get_contents($zip) : wp_json_encode($manifest);
    if (!empty($args['stream'])) { file_put_contents($args['filename'], $body); $body = ''; }
    return ['headers'=>[], 'body'=>$body, 'response'=>['code'=>200, 'message'=>'OK'], 'cookies'=>[], 'filename'=>$args['filename']??null];
}, 10, 3);
function updater_assert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$plugin = 'zoer-connect/zoer-connect.php';
$journal = ZOER_CONNECT_STORAGE_DIR.'/finished-updater-fixture.json';
if ($phase === 'seed') {
    [$key, $record] = \ZoerConnect\ConnectionKey::create(1);
    $record['push'] = false; $record['pull'] = true;
    update_option(\ZoerConnect\ConnectionKey::OPTION, $record, false);
    update_option('zc_update_fixture_before', $record, false);
    file_put_contents($journal, '{"phase":"finished","fixture":"updater-only"}');
    echo "Seeded private completed journal and scoped connector record.\n";
    return;
}
updater_assert(get_option(\ZoerConnect\ConnectionKey::OPTION) === get_option('zc_update_fixture_before'), 'Key or permissions changed.');
updater_assert(file_get_contents($journal) === '{"phase":"finished","fixture":"updater-only"}', 'Private journal changed.');
if ($phase === 'upgrade') {
    wp_clean_plugins_cache(true);
    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    $update = $updates->response[$plugin]??null;
    updater_assert($update && $update->new_version === $version, 'Bridge update not discovered by WordPress.');
    updater_assert(str_starts_with($update->package, $legacy.'/'), 'Legacy connector did not use its old feed.');
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    // Core's bulk update path retains active plugins through its maintenance window.
    $results = $upgrader->bulk_upgrade([$plugin]);
    $result = $results[$plugin]??null;
    updater_assert($result && !is_wp_error($result), 'Native WordPress upgrade failed: '.wp_json_encode($result));
    wp_clean_plugins_cache();
    $data = get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin, false, false);
    updater_assert($data['Version'] === $version && $data['UpdateURI'] === $repo, 'Bridge package identity incorrect.');
    echo "Native bridge upgrade installed $version from the legacy feed.\n";
} elseif ($phase === 'verify') {
    updater_assert(\ZoerConnect\Plugin::VERSION === $version && is_plugin_active($plugin), 'New plugin not active.');
    $offered = \ZoerConnect\GitHubUpdater::check(false, ['Version'=>'0.5.1'], $plugin, []);
    $canonical = $repo.'/releases/download/v'.$version.'/zoer-connect-'.$version.'.zip';
    updater_assert(is_array($offered) && $offered['package'] === $canonical, 'Direct source-repo discovery failed.');
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $download = $upgrader->download_package($canonical, false, ['plugin'=>$plugin]);
    updater_assert(is_string($download) && hash_file('sha256', $download) === $manifest['sha256'], 'Direct source-repo ZIP verification failed.');
    unlink($download);
    updater_assert(\ZoerConnect\GitHubUpdater::check(false, ['Version'=>$version], $plugin, []) === false, 'Current version offered again.');
    foreach ($requests as $url) updater_assert(!str_starts_with($url, $legacy), 'New updater still uses old feed.');
    echo "Direct source discovery/download verified; current version gated; active plugin, key, permissions and journal retained.\n";
} else throw new RuntimeException('Unknown updater phase.');
updater_assert(get_option(\ZoerConnect\ConnectionKey::OPTION) === get_option('zc_update_fixture_before'), 'Upgrade changed key or permissions.');
updater_assert(file_get_contents($journal) === '{"phase":"finished","fixture":"updater-only"}', 'Upgrade changed private journal.');
echo wp_json_encode(['wordpress'=>get_bloginfo('version'), 'php'=>PHP_VERSION, 'transport'=>$fixture?'intercepted staged bytes':'public HTTPS', 'phase'=>$phase, 'requests'=>$requests])."\n";
