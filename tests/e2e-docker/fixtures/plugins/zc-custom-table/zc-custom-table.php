<?php
/**
 * Plugin Name: ZC Custom Table (e2e fixture)
 * Description: Creates {prefix}zc_custom on activation with rows containing site URLs.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

register_activation_hook(__FILE__, static function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = $wpdb->prefix . 'zc_custom';
    dbDelta("CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  label varchar(191) NOT NULL DEFAULT '',
  url text NOT NULL,
  payload longtext NOT NULL,
  created datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY label (label)
) " . $wpdb->get_charset_collate() . ";");
    if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`")) {
        $home = untrailingslashit(home_url());
        $wpdb->insert($table, ['label' => 'landing', 'url' => $home . '/landing/', 'payload' => serialize(['cta' => $home . '/buy/', 'brand' => 'Source Brand'])]);
        $wpdb->insert($table, ['label' => 'json', 'url' => $home . '/api/', 'payload' => wp_json_encode(['endpoint' => $home . '/wp-json/zc/v1/'], 0)]);
        $wpdb->insert($table, ['label' => 'plain', 'url' => 'https://example.org/unrelated/', 'payload' => 'no site url here']);
    }
});

add_shortcode('zc_custom_count', static function () {
    global $wpdb;
    return (string) (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}zc_custom`");
});
