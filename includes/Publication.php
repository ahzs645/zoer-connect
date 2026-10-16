<?php
namespace ZoerConnect;

/** The identity of what this site currently publishes.
 *
 * Each completed import (push, Find & Replace) or rollback commits a new opaque,
 * non-secret identifier. Normally rendered public HTML carries it in
 * <meta name="zoer-publication"> and the X-Zoer-Publication header, and singular
 * pages carry a revision of their post in <meta name="zoer-revision">. Zoer reads
 * the expected values through the authenticated GET /publication and compares them
 * with what an anonymous visitor receives, so stale page caches, CDN copies and
 * object caches are detected without comparing whole pages.
 */
final class Publication {
    public const OPTION = 'zoer_connect_publication';
    /** Recently changed pages listed for public checks (besides the home page). */
    public const PAGES = 8;

    /** Commits a new identity for a completed import or rollback. */
    public static function commit($db, array $state, string $kind): ?array {
        try {
            $value = ['v'=>1, 'id'=>bin2hex(random_bytes(16)), 'importId'=>(string)($state['id'] ?? ''), 'kind'=>$kind, 'committedAt'=>time()];
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || !method_exists($db, 'replace') || $db->replace($db->options, ['option_name'=>self::OPTION, 'option_value'=>$json, 'autoload'=>'yes']) === false) return null;
            if (function_exists('wp_cache_delete')) { wp_cache_delete(self::OPTION, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options'); }
            return $value;
        } catch (\Throwable $e) { return null; }
    }

    /** The current identity, or null before the first import with this version. */
    public static function current(): ?array {
        if (!function_exists('get_option')) return null;
        $raw = get_option(self::OPTION, null);
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($value) && is_string($value['id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $value['id']) ? $value : null;
    }

    /** Opaque revision of one post: changes with its content or modification time. */
    public static function revision(int $id, string $modifiedGmt, string $content): string {
        return substr(hash('sha256', $id.'|'.$modifiedGmt.'|'.hash('sha256', $content)), 0, 16);
    }

    /** Front-end <head>: the publication and, on singular pages, the post's revision. */
    public static function meta(): void {
        if (self::skip()) return;
        $current = self::current();
        if ($current) echo '<meta name="zoer-publication" content="'.esc_attr($current['id']).'">'."\n";
        if (function_exists('is_singular') && is_singular()) {
            $post = function_exists('get_queried_object') ? get_queried_object() : null;
            if ($post instanceof \WP_Post && $post->post_password === '') echo '<meta name="zoer-revision" content="'.esc_attr(self::revision((int)$post->ID, (string)$post->post_modified_gmt, (string)$post->post_content)).'">'."\n";
        }
    }

    public static function header(): void {
        if (self::skip() || headers_sent()) return;
        $current = self::current();
        if ($current) header('X-Zoer-Publication: '.$current['id']);
    }

    private static function skip(): bool {
        return (function_exists('is_admin') && is_admin()) || (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('is_feed') && is_feed());
    }

    /** Authenticated GET /publication: what the public site should show now. */
    public static function expected($wpdb): array {
        $home = rtrim((string)home_url('/'), '/').'/';
        $pages = [];
        $front = get_option('show_on_front') === 'page' ? (int)get_option('page_on_front') : 0;
        // Read the database directly: the expected revision must not come from the object cache it checks.
        $columns = 'ID,post_modified_gmt,post_content,post_type';
        if ($front > 0) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT $columns FROM {$wpdb->posts} WHERE ID=%d AND post_status='publish' AND post_password=''", $front), ARRAY_A);
            if ($row) $pages[] = ['path'=>'/', 'kind'=>'front-page', 'revision'=>self::revision((int)$row['ID'], (string)$row['post_modified_gmt'], (string)$row['post_content'])];
        }
        if (!$pages) $pages[] = ['path'=>'/', 'kind'=>'home', 'revision'=>null];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $columns FROM {$wpdb->posts} WHERE post_status='publish' AND post_password='' AND post_type IN ('page','post') AND ID<>%d ORDER BY post_modified_gmt DESC, ID DESC LIMIT %d", $front, self::PAGES), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $link = function_exists('get_permalink') ? get_permalink((int)$row['ID']) : false;
            if (!is_string($link) || !str_starts_with($link, $home)) continue;
            $path = '/'.ltrim(substr($link, strlen($home)), '/');
            if (str_contains($path, '?') || strlen($path) > 512) continue;
            $pages[] = ['path'=>$path, 'kind'=>(string)$row['post_type'], 'revision'=>self::revision((int)$row['ID'], (string)$row['post_modified_gmt'], (string)$row['post_content'])];
        }
        return ['v'=>1, 'home'=>$home, 'publication'=>self::current(), 'pages'=>$pages, 'wordpressCache'=>CachePurge::receipt()];
    }
}
