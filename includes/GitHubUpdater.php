<?php
namespace ZoerConnect;

/**
 * WordPress updates from qualified, publicly distributed GitHub packages. Sites stay on stable
 * releases unless an administrator also asks for release candidates (0.8.0).
 *
 * Signed updates (0.10.0): update.json carries an Ed25519 `signature` over SIGNED_MESSAGE (version
 * and ZIP SHA-256), so a GitHub release whose assets were replaced cannot vouch for itself. The
 * verifying key is PUBLIC_KEY, or ZOER_CONNECT_UPDATE_PUBLIC_KEY from wp-config.php (base64 of the
 * 32-byte public key). With a key, unsigned or badly signed updates are refused; an unusable key
 * refuses every update. Without a key, updates keep the SHA-256 check only and administrators see
 * a notice that updates are unsigned.
 */
final class GitHubUpdater {
    private const PLUGIN = 'zoer-connect/zoer-connect.php';
    private const UPDATE_URI = 'https://github.com/ahzs645/zoer-connect';
    // Releases including prereleases; "latest" covers stable releases only.
    private const RELEASES_API = 'https://api.github.com/repos/ahzs645/zoer-connect/releases?per_page=10';
    public const CHANNEL_OPTION = 'zoer_connect_update_channel';
    private const STABLE = '/^[0-9]+\.[0-9]+\.[0-9]+$/D';
    private const CANDIDATE = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[1-9][0-9]*)?$/D';
    // Retain verification of an old download URL already cached by WordPress.
    private const LEGACY_RAW = 'https://raw.githubusercontent.com/ahzs645/zoer-connect-releases/main';
    /** Base64 Ed25519 public key that release manifests are signed with; empty until a signing key is provisioned. */
    public const PUBLIC_KEY = '';
    /** Exact bytes a release signature covers. */
    private const SIGNED_MESSAGE = "zoer-connect-update-v1\n%s\n%s\n";

    public static function boot(): void {
        add_filter('update_plugins_github.com', [self::class, 'check'], 10, 4);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 4);
        add_action('admin_post_zoer_update_channel', [self::class, 'saveChannel']);
        add_action('admin_notices', [self::class, 'signingNotice']);
    }

    /** The configured verifying key: null when none is configured, false when one is configured but unusable. */
    public static function publicKey(): string|false|null {
        $configured = defined('ZOER_CONNECT_UPDATE_PUBLIC_KEY') ? constant('ZOER_CONNECT_UPDATE_PUBLIC_KEY') : self::PUBLIC_KEY;
        if ($configured === '' || $configured === null) return null;
        $key = is_string($configured) ? base64_decode($configured, true) : false;
        return is_string($key) && strlen($key) === 32 && function_exists('sodium_crypto_sign_verify_detached') ? $key : false;
    }

    /** True when the manifest may be trusted: signed by the configured key, or no key is configured. */
    public static function signatureAccepted(array $manifest): bool {
        $key = self::publicKey();
        if ($key === null) return true;
        if ($key === false || !is_string($manifest['signature'] ?? null)) return false;
        $signature = base64_decode($manifest['signature'], true);
        if (!is_string($signature) || strlen($signature) !== 64) return false;
        try { return sodium_crypto_sign_verify_detached($signature, sprintf(self::SIGNED_MESSAGE, $manifest['version'], $manifest['sha256']), $key); }
        catch (\Throwable $e) { return false; }
    }

    /** Plugins, Updates and Tools → Zoer Connect: say when updates are not signature-checked. */
    public static function signingNotice(): void {
        if (!current_user_can('update_plugins')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && !in_array($screen->id ?? '', ['plugins', 'update-core', 'tools_page_zoer-connect'], true)) return;
        $text = self::signingStatus();
        if ($text !== null) echo '<div class="notice notice-'.(self::publicKey() === false ? 'error' : 'warning').'"><p>'.esc_html($text).'</p></div>';
    }

    /** Null when signed updates are enforced; otherwise what an administrator needs to know. */
    public static function signingStatus(): ?string {
        $key = self::publicKey();
        if ($key === null) return 'Zoer Connect updates are unsigned: downloads are checked only against the SHA-256 published in the same GitHub release. Define ZOER_CONNECT_UPDATE_PUBLIC_KEY in wp-config.php to require signed updates.';
        if ($key === false) return 'Zoer Connect refuses all updates: the update signing key is not a base64 Ed25519 public key or this PHP has no sodium support. Fix ZOER_CONNECT_UPDATE_PUBLIC_KEY in wp-config.php.';
        return null;
    }

    public static function releaseCandidates(): bool { return get_option(self::CHANNEL_OPTION, 'stable') === 'rc'; }

    /** Tools → Zoer Connect → Settings: opt this site into release candidates. */
    public static function saveChannel(): void {
        if (!current_user_can('update_plugins')) wp_die('You cannot change how this site updates plugins.', 403);
        check_admin_referer('zoer_update_channel');
        if (($_POST['release_candidates'] ?? '') === '1') update_option(self::CHANNEL_OPTION, 'rc', false);
        else delete_option(self::CHANNEL_OPTION);
        // Check again with the new channel instead of the cached result.
        delete_site_transient('update_plugins');
        wp_safe_redirect(admin_url('tools.php?page=zoer-connect&zoer_updates=1#zoer-panel-settings'));
        exit;
    }

    public static function settingsCard(): string {
        if (!current_user_can('update_plugins')) return '';
        $rc = self::releaseCandidates();
        return '<details class="zc-card zc-collapsible"><summary><span class="zc-summary-title">Updates</span> <span class="zc-summary-hint">'.esc_html($rc ? 'Stable releases and release candidates' : 'Stable releases').'</span></summary>'
            .'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('zoer_update_channel', '_wpnonce', true, false)
            .'<input type="hidden" name="action" value="zoer_update_channel">'
            .'<p><label><input type="checkbox" name="release_candidates" value="1"'.($rc ? ' checked' : '').'> Also offer release candidates</label></p>'
            .'<p class="description">Release candidates are test builds published before a stable release. WordPress still asks before updating, and each download is checked against its published SHA-256'.(self::publicKey() === null ? '' : ' and release signature').'. Leave this off on production sites.</p>'
            .(self::signingStatus() !== null ? '<p class="description">'.esc_html(self::signingStatus()).'</p>' : '')
            .'<p><button type="submit" class="button">Save</button></p></form></details>';
    }

    private static function manifest(string $version = '', bool $legacy = false, bool $candidate = false): ?array {
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
            !preg_match($candidate ? self::CANDIDATE : self::STABLE, $data['version']) ||
            !is_string($data['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) return null;
        if ($version !== '' && $version !== $data['version']) return null;
        if (!self::signatureAccepted($data)) return null;
        $data['package'] = $legacy
            ? self::LEGACY_RAW.'/releases/v'.$data['version'].'/zoer-connect-'.$data['version'].'.zip'
            : self::UPDATE_URI.'/releases/download/v'.$data['version'].'/zoer-connect-'.preg_replace('/-rc\.[0-9]+$/', '', $data['version']).'.zip';
        return $data;
    }

    /** Newest stable release or release candidate, by version; its own update.json must confirm it. */
    private static function newestCandidate(): ?array {
        $response = wp_safe_remote_get(self::RELEASES_API, ['timeout'=>10, 'redirection'=>0, 'limit_response_size'=>524288, 'headers'=>['Accept'=>'application/vnd.github+json']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $releases = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) return null;
        $newest = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !is_string($release['tag_name'] ?? null)) continue;
            if (!preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[1-9][0-9]*)?)$/D', $release['tag_name'], $match)) continue;
            if ($newest === null || version_compare($match[1], $newest, '>')) $newest = $match[1];
        }
        return $newest === null ? null : self::manifest($newest, false, true);
    }

    public static function check($update, array $pluginData, string $pluginFile, array $locales) {
        if ($pluginFile !== self::PLUGIN) return $update;
        $latest = self::releaseCandidates() ? self::newestCandidate() : self::manifest();
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
        // A release candidate's ZIP keeps the stable file name: vX.Y.Z-rc.N/zoer-connect-X.Y.Z.zip.
        $pattern = '~^'.preg_quote(self::UPDATE_URI, '~').'/releases/download/v(([0-9]+\.[0-9]+\.[0-9]+)(?:-rc\.[1-9][0-9]*)?)/zoer-connect-\2\.zip$~D';
        $legacyPattern = '~^'.preg_quote(self::LEGACY_RAW, '~').'/releases/v([0-9]+\.[0-9]+\.[0-9]+)/zoer-connect-\1\.zip$~D';
        $legacy = false;
        if (!preg_match($pattern, $package, $matches)) {
            if (!preg_match($legacyPattern, $package, $matches)) return false;
            $legacy = true;
        }
        // Only sites on the release-candidate channel accept a candidate's manifest (and so its ZIP).
        $release = self::manifest($matches[1], $legacy, !$legacy && self::releaseCandidates());
        if (!$release || $package !== $release['package']) return new \WP_Error('zoer_connect_update_manifest', 'The Zoer Connect release manifest could not be verified'.(self::publicKey() === null ? '' : ' or is not signed with the configured update key').'.');
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
