<?php
namespace ZoerConnect;

/** Purge known page caches after the request fence has been released. Queued by
 * an import that asked for it; executed once on the next normal wp_loaded, or at
 * once by an authenticated POST /cache/purge.
 *
 * Every run leaves a receipt (RECEIPT) with one entry per layer. A WordPress cache
 * plugin's purge hook gives no confirmation, so a successful call is reported as
 * "requested", never as evicted. Hosting and CDN caches outside WordPress are not
 * covered here; they need the hosting provider's own purge.
 */
final class CachePurge {
    public const OPTION = 'zoer_connect_cache_purge_pending';
    public const RECEIPT = 'zoer_connect_cache_purge_receipt';

    public static function queue($db, array $state): bool {
        if (!($state['options']['purgeCaches'] ?? false)) return true;
        // Cache maintenance must never keep a completed recovery fenced.
        $queued = method_exists($db, 'replace') && $db->replace($db->options, ['option_name'=>self::OPTION,'option_value'=>'1','autoload'=>'no']) !== false;
        if ($queued) self::writeReceipt($db, ['v'=>1, 'state'=>'queued', 'source'=>'import', 'importId'=>(string)($state['id'] ?? ''), 'queuedAt'=>time(), 'layers'=>[]]);
        return $queued;
    }

    /** Best effort: each integration is isolated; a failing cache plugin never
     * breaks the request and the marker is cleared first so it cannot loop. */
    public static function run(): void {
        if (!function_exists('get_option') || !get_option(self::OPTION, false)) return;
        delete_option(self::OPTION);
        $previous = self::receipt();
        self::purge(is_array($previous) && ($previous['state'] ?? null) === 'queued' ? $previous : ['source'=>'import']);
    }

    /** Runs every known purge now and returns the receipt (authenticated request). */
    public static function purgeNow(): array {
        if (function_exists('delete_option')) delete_option(self::OPTION);
        return self::purge(['source'=>'request']);
    }

    /** @return array{key:string,label:string,detected:bool,call:callable}[] */
    private static function layers(): array {
        $hooked = static fn(string $hook): bool => function_exists('has_action') && has_action($hook) !== false;
        return [
            ['key'=>'litespeed', 'label'=>'LiteSpeed Cache', 'detected'=>defined('LSCWP_V') || $hooked('litespeed_purge_all'), 'call'=>static fn() => do_action('litespeed_purge_all')],
            ['key'=>'wp-rocket', 'label'=>'WP Rocket', 'detected'=>function_exists('rocket_clean_domain'), 'call'=>static fn() => function_exists('rocket_clean_domain') && rocket_clean_domain()],
            ['key'=>'w3tc', 'label'=>'W3 Total Cache', 'detected'=>function_exists('w3tc_flush_all'), 'call'=>static fn() => function_exists('w3tc_flush_all') && w3tc_flush_all()],
            ['key'=>'wp-super-cache', 'label'=>'WP Super Cache', 'detected'=>function_exists('wp_cache_clear_cache'), 'call'=>static fn() => function_exists('wp_cache_clear_cache') && wp_cache_clear_cache()],
            ['key'=>'breeze', 'label'=>'Breeze', 'detected'=>$hooked('breeze_clear_all_cache'), 'call'=>static fn() => do_action('breeze_clear_all_cache')],
            ['key'=>'wp-fastest-cache', 'label'=>'WP Fastest Cache', 'detected'=>$hooked('wpfc_clear_all_cache'), 'call'=>static fn() => do_action('wpfc_clear_all_cache', true)],
            ['key'=>'cache-enabler', 'label'=>'Cache Enabler', 'detected'=>$hooked('cache_enabler_clear_complete_cache'), 'call'=>static fn() => do_action('cache_enabler_clear_complete_cache')],
            ['key'=>'sg-optimizer', 'label'=>'SiteGround Optimizer', 'detected'=>function_exists('sg_cachepress_purge_cache'), 'call'=>static fn() => function_exists('sg_cachepress_purge_cache') && sg_cachepress_purge_cache()],
            ['key'=>'autoptimize', 'label'=>'Autoptimize', 'detected'=>class_exists('autoptimizeCache'), 'call'=>static fn() => class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall') && \autoptimizeCache::clearall()],
        ];
    }

    private static function purge(array $receipt): array {
        $layers = [];
        foreach (self::layers() as $layer) {
            $status = $layer['detected'] ? 'requested' : 'not-detected';
            try { ($layer['call'])(); } catch (\Throwable $e) { $status = 'failed'; }
            $layers[] = ['key'=>$layer['key'], 'label'=>$layer['label'], 'status'=>$status];
        }
        $external = function_exists('wp_using_ext_object_cache') ? (bool)wp_using_ext_object_cache() : null;
        $object = 'unavailable';
        try { if (function_exists('wp_cache_flush')) $object = wp_cache_flush() === false ? 'failed' : 'flushed'; } catch (\Throwable $e) { $object = 'failed'; }
        $layers[] = ['key'=>'object-cache', 'label'=>$external === false ? 'Object cache (in-memory only)' : 'Object cache', 'status'=>$object];
        $receipt = ['v'=>1, 'state'=>'ran', 'source'=>(string)($receipt['source'] ?? 'import'), 'importId'=>(string)($receipt['importId'] ?? ''), 'queuedAt'=>$receipt['queuedAt'] ?? null, 'ranAt'=>time(), 'layers'=>$layers,
            'scope'=>'WordPress cache plugins and the object cache on this server. Hosting and CDN caches need the provider’s purge.'];
        self::writeReceipt(null, $receipt);
        return $receipt;
    }

    /** The last receipt, or null. */
    public static function receipt(): ?array {
        if (!function_exists('get_option')) return null;
        $raw = get_option(self::RECEIPT, null);
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($value) ? $value : null;
    }

    private static function writeReceipt($db, array $receipt): void {
        $json = json_encode($receipt, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;
        try {
            if ($db !== null && method_exists($db, 'replace')) $db->replace($db->options, ['option_name'=>self::RECEIPT, 'option_value'=>$json, 'autoload'=>'no']);
            elseif (function_exists('update_option')) update_option(self::RECEIPT, $json, false);
            if (function_exists('wp_cache_delete')) wp_cache_delete(self::RECEIPT, 'options');
        } catch (\Throwable $e) { /* a receipt never breaks the request */ }
    }
}
