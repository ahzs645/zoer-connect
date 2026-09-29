<?php
namespace ZoerConnect;

/** Purge known page caches after the request fence has been released. Queued by
 * an import that asked for it; executed once on the next normal wp_loaded.
 */
final class CachePurge {
    public const OPTION = 'zoer_connect_cache_purge_pending';

    public static function queue($db, array $state): bool {
        if (!($state['options']['purgeCaches'] ?? false)) return true;
        // Cache maintenance must never keep a completed recovery fenced.
        return method_exists($db, 'replace') && $db->replace($db->options, ['option_name'=>self::OPTION,'option_value'=>'1','autoload'=>'no']) !== false;
    }

    /** Best effort: each integration is isolated; a failing cache plugin never
     * breaks the request and the marker is cleared first so it cannot loop. */
    public static function run(): void {
        if (!function_exists('get_option') || !get_option(self::OPTION, false)) return;
        delete_option(self::OPTION);
        $calls = [
            static fn() => do_action('litespeed_purge_all'),
            static fn() => function_exists('rocket_clean_domain') && rocket_clean_domain(),
            static fn() => function_exists('w3tc_flush_all') && w3tc_flush_all(),
            static fn() => function_exists('wp_cache_clear_cache') && wp_cache_clear_cache(),
            static fn() => do_action('breeze_clear_all_cache'),
            static fn() => do_action('wpfc_clear_all_cache', true),
            static fn() => do_action('cache_enabler_clear_complete_cache'),
            static fn() => function_exists('sg_cachepress_purge_cache') && sg_cachepress_purge_cache(),
            static fn() => class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall') && \autoptimizeCache::clearall(),
            static fn() => function_exists('wp_cache_flush') && wp_cache_flush(),
        ];
        foreach ($calls as $call) {
            try { $call(); } catch (\Throwable $e) {}
        }
    }
}
