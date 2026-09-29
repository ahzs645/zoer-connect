<?php
// Test-only database probe. SHORTINIT loads wp-config and $wpdb but no plugins or
// MU plugins, so it works while the Zoer request fence pauses WordPress.
// Usage: php /zc/sql.php <docroot> < {"queries":[{"sql":"...","fetch":"all|col|var|exec"}]}
if (PHP_SAPI !== 'cli') exit(1);
define('SHORTINIT', true);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require rtrim($argv[1], '/') . '/wp-load.php';
global $wpdb;
$wpdb->suppress_errors(true);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = ['prefix' => $wpdb->prefix, 'results' => []];
foreach ($input['queries'] as $q) {
    $sql = str_replace('{prefix}', $wpdb->prefix, $q['sql']);
    $fetch = $q['fetch'] ?? 'all';
    if ($fetch === 'all') $value = $wpdb->get_results($sql, ARRAY_A);
    elseif ($fetch === 'col') $value = $wpdb->get_col($sql);
    elseif ($fetch === 'var') $value = $wpdb->get_var($sql);
    elseif ($fetch === 'unserialize') {
        // Proves stored serialization is valid: false (not a value) when it is not.
        $raw = $wpdb->get_var($sql);
        $value = $raw === null ? null : @unserialize($raw, ['allowed_classes' => false]);
        if ($raw !== null && $value === false && $raw !== 'b:0;') $value = ['__invalid_serialization__' => true];
    }
    else $value = $wpdb->query($sql);
    if ($wpdb->last_error) { fwrite(STDERR, "SQL error: {$wpdb->last_error}\n"); exit(2); }
    $out['results'][] = $value;
}
echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
