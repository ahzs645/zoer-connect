<?php
namespace ZoerConnect;

/** Rebuild generated permalinks after the request fence has been released. */
final class RewriteRefresh {
    public const OPTION = 'zoer_connect_rewrite_flush_pending';

    public static function needed(array $state): bool {
        if ($state['hasDb'] ?? false) return true;
        foreach ($state['artifacts'] ?? [] as $artifact) {
            $path = $artifact['path'] ?? '';
            if (is_string($path) && (str_starts_with($path, 'wp-content/plugins/') || str_starts_with($path, 'wp-content/themes/'))) return true;
        }
        return false;
    }

    public static function queue($db, array $state): bool {
        if (!self::needed($state)) return true;
        // Permalink maintenance must never keep a completed recovery fenced.
        return method_exists($db, 'replace') && $db->replace($db->options, ['option_name'=>self::OPTION,'option_value'=>'1','autoload'=>'no']) !== false;
    }

    public static function run(): void {
        if (!get_option(self::OPTION, false) || !function_exists('flush_rewrite_rules')) return;
        global $wp_rewrite;
        if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'init')) {
            $endpoints = $wp_rewrite->endpoints;
            $wp_rewrite->init();
            $wp_rewrite->endpoints = $endpoints;
        }
        flush_rewrite_rules(false);
        delete_option(self::OPTION);
    }
}
