<?php
namespace ZoerConnect;

/** WordPress updates from qualified, publicly distributed GitHub packages. */
final class GitHubUpdater {
    private const PLUGIN = 'zoer-connect/zoer-connect.php';
    private const UPDATE_URI = 'https://github.com/ahzs645/zoer-connect';
    // Retain verification of an old download URL already cached by WordPress.
    private const LEGACY_RAW = 'https://raw.githubusercontent.com/ahzs645/zoer-connect-releases/main';

    public static function boot(): void {
        add_filter('update_plugins_github.com', [self::class, 'check'], 10, 4);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 4);
    }

    private static function manifest(string $version = '', bool $legacy = false): ?array {
        $url = $legacy
            ? self::LEGACY_RAW.'/releases/v'.$version.'/manifest.json'
            : self::UPDATE_URI.'/releases/'.($version === '' ? 'latest/download' : 'download/v'.$version).'/update.json';
        // GitHub release assets redirect to its download CDN. Latest resolves only
        // to a published stable release; prereleases have no update.json asset.
        $response = wp_safe_remote_get($url, ['timeout'=>10, 'redirection'=>$legacy ? 0 : 3, 'limit_response_size'=>4097]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || strlen($body) > 4096) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || !is_string($data['version']??null) ||
            !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $data['version']) ||
            !is_string($data['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) return null;
        if ($version !== '' && $version !== $data['version']) return null;
        $data['package'] = $legacy
            ? self::LEGACY_RAW.'/releases/v'.$data['version'].'/zoer-connect-'.$data['version'].'.zip'
            : self::UPDATE_URI.'/releases/download/v'.$data['version'].'/zoer-connect-'.$data['version'].'.zip';
        return $data;
    }

    public static function check($update, array $pluginData, string $pluginFile, array $locales) {
        if ($pluginFile !== self::PLUGIN) return $update;
        $latest = self::manifest();
        if (!$latest || version_compare($latest['version'], $pluginData['Version']??'0', '<=')) return false;
        return [
            'id'=>self::UPDATE_URI,
            'slug'=>'zoer-connect',
            'version'=>$latest['version'],
            'url'=>self::UPDATE_URI,
            'package'=>$latest['package'],
            'requires_php'=>'8.1',
            'autoupdate'=>false,
        ];
    }

    public static function download($reply, string $package, $upgrader, array $hookExtra) {
        if ($reply !== false) return $reply;
        if (($hookExtra['plugin']??self::PLUGIN) !== self::PLUGIN) return false;
        $pattern = '~^'.preg_quote(self::UPDATE_URI, '~').'/releases/download/v([0-9]+\.[0-9]+\.[0-9]+)/zoer-connect-\1\.zip$~D';
        $legacyPattern = '~^'.preg_quote(self::LEGACY_RAW, '~').'/releases/v([0-9]+\.[0-9]+\.[0-9]+)/zoer-connect-\1\.zip$~D';
        $legacy = false;
        if (!preg_match($pattern, $package, $matches)) {
            if (!preg_match($legacyPattern, $package, $matches)) return false;
            $legacy = true;
        }
        $release = self::manifest($matches[1], $legacy);
        if (!$release || $package !== $release['package']) return new \WP_Error('zoer_connect_update_manifest', 'The Zoer Connect release manifest could not be verified.');
        if (!function_exists('download_url')) require_once ABSPATH.'wp-admin/includes/file.php';
        $file = download_url($package, 300);
        if (is_wp_error($file)) return $file;
        if (!is_string($file) || !is_file($file) || !hash_equals($release['sha256'], hash_file('sha256', $file))) {
            if (is_string($file) && is_file($file)) unlink($file);
            return new \WP_Error('zoer_connect_update_checksum', 'The Zoer Connect download did not match its release checksum.');
        }
        return $file;
    }
}
