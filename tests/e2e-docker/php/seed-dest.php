<?php
// wp eval-file /zc/seed-dest.php --user=destadmin
// Different destination content, search engines discouraged (blog_private warning,
// retained through imports), and a user whose login matches the source's second
// author but has a different ID and email (authorMapping 'match').
if (!defined('WP_CLI')) exit(1);
$ids = [];
$password = static fn() => wp_generate_password(32, true, true);
$ids['filler'] = wp_insert_user(['user_login' => 'filler', 'user_email' => 'filler@dest.test', 'user_pass' => $password(), 'role' => 'subscriber']);
$ids['jdoe'] = wp_insert_user(['user_login' => 'jdoe', 'user_email' => 'jdoe@dest.test', 'user_pass' => $password(), 'role' => 'author', 'display_name' => 'Jane Doe (destination)']);
$ids['destPost'] = wp_insert_post(['post_title' => 'Destination only post', 'post_status' => 'publish', 'post_author' => 1, 'post_content' => '<!-- wp:paragraph --><p>Destination only content on Dest Site.</p><!-- /wp:paragraph -->'], true);
$ids['destPage'] = wp_insert_post(['post_type' => 'page', 'post_title' => 'Destination page', 'post_status' => 'publish', 'post_author' => $ids['jdoe'] ?? 1, 'post_content' => 'Destination only page.'], true);
update_option('zc_dest_marker', 'destination-original');
update_option('blog_public', '0');
update_option('blogdescription', 'Destination tagline');
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);
foreach ($ids as $k => $v) if (is_wp_error($v)) WP_CLI::error("$k: " . $v->get_error_message());
echo wp_json_encode($ids);
