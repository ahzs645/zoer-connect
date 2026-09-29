<?php
// wp eval-file /zc/seed-source.php --user=srcadmin
// Realistic source content: blocks with absolute URLs, Elementor-style JSON meta,
// serialized options, revisions, spam, custom post types, a transient, a second
// author, media, a custom table (plugin) and a non-InnoDB table. Prints IDs as JSON.
if (!defined('WP_CLI')) exit(1);
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$home = untrailingslashit(home_url());
$abspath = untrailingslashit(ABSPATH);
$ids = [];

$password = static fn() => wp_generate_password(32, true, true);
$ids['jdoe'] = wp_insert_user(['user_login' => 'jdoe', 'user_email' => 'jdoe@source.test', 'user_pass' => $password(), 'role' => 'author', 'display_name' => 'Jane Doe (source)']);
$ids['onlysource'] = wp_insert_user(['user_login' => 'onlysource', 'user_email' => 'onlysource@source.test', 'user_pass' => $password(), 'role' => 'author', 'display_name' => 'Only Source']);
foreach (['jdoe', 'onlysource'] as $k) if (is_wp_error($ids[$k])) WP_CLI::error($ids[$k]->get_error_message());

// Media: a real PNG through WordPress's sideload (creates the attachment and sub-sizes).
$img = imagecreatetruecolor(640, 360);
imagefill($img, 0, 0, imagecolorallocate($img, 30, 110, 190));
imagestring($img, 5, 20, 20, 'Source Brand', imagecolorallocate($img, 255, 255, 255));
$tmp = wp_tempnam('zc-media.png');
imagepng($img, $tmp);
$ids['attachment'] = media_handle_sideload(['name' => 'zc-media.png', 'tmp_name' => $tmp], 0, 'ZC media');
if (is_wp_error($ids['attachment'])) WP_CLI::error($ids['attachment']->get_error_message());
$image = wp_get_attachment_url($ids['attachment']);
$ids['imageUrl'] = $image;

$cat = wp_insert_term('Source Brand News', 'category');
$ids['category'] = is_wp_error($cat) ? 0 : $cat['term_id'];

$blocks = '<!-- wp:paragraph --><p>Welcome to Source Brand. Visit <a href="' . $home . '/about/">about</a>, the http twin <a href="http://source.test/contact/">contact</a> and a protocol-relative //source.test/assets/app.css reference.</p><!-- /wp:paragraph -->' . "\n\n"
    . '<!-- wp:image {"id":' . $ids['attachment'] . ',"sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full"><img src="' . $image . '" alt="" class="wp-image-' . $ids['attachment'] . '"/></figure><!-- /wp:image -->' . "\n\n"
    . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $home . '/shop/">Shop</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' . "\n\n"
    . '<!-- wp:paragraph --><p><a href="https://share.example/?u=' . rawurlencode($home . '/hello/') . '">Share</a> and contact jdoe@source.test.</p><!-- /wp:paragraph -->';
$ids['hello'] = wp_insert_post(['post_title' => 'Hello Source', 'post_name' => 'hello-source', 'post_content' => $blocks, 'post_status' => 'publish', 'post_author' => 1, 'post_category' => [$ids['category']]], true);

$ids['aboutPage'] = wp_insert_post(['post_type' => 'page', 'post_title' => 'About Source Brand', 'post_name' => 'about', 'post_status' => 'publish', 'post_author' => $ids['jdoe'],
    'post_content' => '<!-- wp:paragraph --><p>source brand is lowercase here, SOURCE BRAND is loud, and Source Brand is proper. Home: ' . $home . '/</p><!-- /wp:paragraph -->'], true);

$ids['janePost'] = wp_insert_post(['post_title' => 'Jane on Source', 'post_status' => 'publish', 'post_author' => $ids['jdoe'], 'post_content' => '<!-- wp:paragraph --><p>Draft one.</p><!-- /wp:paragraph -->'], true);
// Two updates create revisions (post_type 'revision').
foreach (['Second version linking ' . $home . '/about/', 'Final version linking ' . $home . '/about/ and ' . $home . '/shop/'] as $text)
    wp_update_post(['ID' => $ids['janePost'], 'post_content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->']);

$ids['soloPost'] = wp_insert_post(['post_title' => 'Only on source author', 'post_status' => 'publish', 'post_author' => $ids['onlysource'], 'post_content' => 'Written by a user with no destination account.'], true);

// Elementor-style builder data: JSON with escaped slashes stored verbatim (no unslash).
$ids['elementor'] = wp_insert_post(['post_type' => 'page', 'post_title' => 'Builder Landing', 'post_status' => 'publish', 'post_author' => 1, 'post_content' => ''], true);
$elementor = json_encode([['id' => 'a1b2', 'elType' => 'section', 'elements' => [['id' => 'c3d4', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => ['url' => $image, 'id' => $ids['attachment']], 'link' => ['url' => $home . '/landing/', 'is_external' => '']]]]]]);
$wpdb->insert($wpdb->postmeta, ['post_id' => $ids['elementor'], 'meta_key' => '_elementor_data', 'meta_value' => $elementor]);
$wpdb->insert($wpdb->postmeta, ['post_id' => $ids['elementor'], 'meta_key' => '_elementor_edit_mode', 'meta_value' => 'builder']);

// Custom post types: zc_book (kept by the pull filter) and zc_internal (excluded).
$ids['books'] = [];
foreach (['First Book', 'Second Book'] as $n => $title) {
    $id = wp_insert_post(['post_type' => 'zc_book', 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $ids['jdoe'], 'post_content' => 'See ' . $home . '/books/' . ($n + 1) . '/'], true);
    update_post_meta($id, 'zc_link', $home . '/books/' . ($n + 1) . '/');
    $ids['books'][] = $id;
}
$ids['internal'] = wp_insert_post(['post_type' => 'zc_internal', 'post_title' => 'Internal note', 'post_status' => 'publish', 'post_author' => 1, 'post_content' => 'secret', 'post_category' => [$ids['category']]], true);
update_post_meta($ids['internal'], 'zc_secret', 'do-not-export');
wp_set_object_terms($ids['internal'], [$ids['category']], 'category');

// Comments: approved by the second author, a guest, and spam with its meta.
$ids['janeComment'] = wp_insert_comment(['comment_post_ID' => $ids['hello'], 'user_id' => $ids['jdoe'], 'comment_author' => 'Jane Doe', 'comment_author_email' => 'jdoe@source.test', 'comment_author_url' => $home . '/jane/', 'comment_content' => 'Great post about Source Brand!', 'comment_approved' => 1]);
$ids['guestComment'] = wp_insert_comment(['comment_post_ID' => $ids['hello'], 'user_id' => 0, 'comment_author' => 'Guest', 'comment_author_email' => 'guest@example.org', 'comment_content' => 'Guest comment', 'comment_approved' => 1]);
$ids['spam'] = [];
foreach (['Buy cheap things', 'More spam'] as $text) {
    $id = wp_insert_comment(['comment_post_ID' => $ids['hello'], 'comment_author' => 'Spammer', 'comment_author_email' => 'spam@example.org', 'comment_content' => $text, 'comment_approved' => 'spam']);
    add_comment_meta($id, 'zc_spam_meta', 'spam-meta');
    $ids['spam'][] = $id;
}
add_comment_meta($ids['janeComment'], 'zc_comment_meta', $home . '/jane/');
wp_update_comment_count_now($ids['hello']);

// Serialized options with URLs, a filesystem path, nested JSON and a brand string.
update_option('zc_settings', [
    'logo' => $image,
    'links' => [$home . '/about/', 'http://source.test/contact/'],
    'brand' => 'Source Brand',
    'uploads_path' => $abspath . '/wp-content/uploads',
    'nested' => ['json' => json_encode(['u' => $home . '/x/'])],
    'count' => 3,
]);
update_option('zc_plain_url', $home);
update_option('blogdescription', 'Home of Source Brand');
set_transient('zc_cache_probe', $home . '/cached/', 3600);

// A non-InnoDB table: reported by diagnostics and excluded from the filtered pull.
$wpdb->query("CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}zc_legacy_myisam` (id int NOT NULL PRIMARY KEY, note varchar(64) NOT NULL) ENGINE=MyISAM");
$wpdb->query("INSERT IGNORE INTO `{$wpdb->prefix}zc_legacy_myisam` VALUES (1,'legacy')");

update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);
foreach ($ids as $k => $v) if (is_wp_error($v)) WP_CLI::error("$k: " . $v->get_error_message());
echo wp_json_encode($ids);
