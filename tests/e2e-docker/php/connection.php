<?php
// Run with: wp eval-file /zc/connection.php <rotate|permissions> [push] [pull] --user=<admin>
// Drives the same code path as the Tools -> Zoer Connect connection form:
// ConnectionAdmin::render() with a nonce-verified HTTPS administrator POST. Key
// generation therefore also runs the automatic Push + shared-hosting setup.
// Prints JSON; the one-time key is returned only for 'rotate' and must be stored
// privately by the caller (never logged).
if (!defined('WP_CLI')) exit(1);
$action = $args[0] ?? '';
if (!in_array($action, ['rotate', 'permissions'], true)) WP_CLI::error('Choose rotate or permissions.');
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['DOCUMENT_ROOT'] = rtrim(ABSPATH, '/');
$post = ['zoer_connection_action' => $action, '_wpnonce' => wp_create_nonce('zoer_connection_settings')];
if (in_array('push', $args, true)) $post['allow_push'] = 'on';
if (in_array('pull', $args, true)) $post['allow_pull'] = 'on';
$_POST = $post;
$_REQUEST = $post;
ob_start();
\ZoerConnect\ConnectionAdmin::render();
$html = ob_get_clean();
$key = null;
if (preg_match('~<textarea id="zoer-connection-info"[^>]*>([^<]*)</textarea>~', $html, $m)) {
    $lines = preg_split('/\R/', html_entity_decode($m[1], ENT_QUOTES));
    $key = trim((string) end($lines));
}
$notices = [];
if (preg_match_all('~<div class="notice[^"]*"><p>(.*?)</p></div>~s', $html, $n)) $notices = array_map('wp_strip_all_tags', $n[1]);
$record = get_option(\ZoerConnect\ConnectionKey::OPTION, []);
echo wp_json_encode([
    'key' => $key,
    'notices' => $notices,
    'push' => ($record['push'] ?? false) === true,
    'pull' => ($record['pull'] ?? false) === true,
    'owner' => (int) ($record['owner'] ?? 0),
]);
