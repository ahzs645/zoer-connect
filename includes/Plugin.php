<?php
namespace ZoerConnect;

final class Plugin {
    private static bool $applicationPassword = false;
    public static function boot(): void {
        add_action('application_password_did_authenticate', static function () { self::$applicationPassword = true; });
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', static function () {
            add_management_page('Zoer Connect', 'Zoer Connect', 'manage_options', 'zoer-connect', [self::class, 'admin']);
        });
    }
    public static function authorize() {
        if (!is_ssl() || !self::$applicationPassword || !current_user_can('manage_options')) return new \WP_Error('zoer_unauthorized', 'HTTPS and an administrator application password are required.', ['status' => 401]);
        if (is_multisite()) return new \WP_Error('zoer_unsupported', 'Multisite is not supported.', ['status' => 409]);
        return true;
    }
    private static function store(): StageStore {
        $public = [ABSPATH];
        if (empty($_SERVER['DOCUMENT_ROOT'])) throw new \RuntimeException('Document root cannot be verified.');
        $public[] = $_SERVER['DOCUMENT_ROOT'];
        $root = defined('ZOER_CONNECT_STORAGE_DIR') ? ZOER_CONNECT_STORAGE_DIR : dirname(rtrim(ABSPATH, '/')) . '/.zoer-connect';
        return new StageStore($root, $public);
    }
    public static function routes(): void {
        $permission = [self::class, 'authorize'];
        $register = static function ($path, $method, $handler) use ($permission) {
            register_rest_route('zoer-connect/v1', $path, [
                'methods' => $method, 'permission_callback' => $permission,
                'callback' => static function ($request) use ($handler) {
                    try {
                        if (strlen($request->get_body()) > 524288) return new \WP_Error('zoer_large_request', 'Request exceeds 512 KiB.', ['status' => 413]);
                        $result = $handler($request);
                        return is_wp_error($result) ? $result : new \WP_REST_Response($result, 200, ['Cache-Control' => 'no-store']);
                    } catch (\InvalidArgumentException $e) {
                        return new \WP_Error('zoer_invalid', $e->getMessage(), ['status' => 400]);
                    } catch (\Throwable $e) {
                        // Never return filesystem paths, SQL, credentials or raw PHP exceptions.
                        return new \WP_Error('zoer_unavailable', 'Staging unavailable. Check private storage, free space, or an existing job.', ['status' => 409]);
                    }
                },
            ]);
        };
        $register('/status', 'GET', static function () {
            $ready = true;
            try { self::store(); } catch (\Throwable $e) { $ready = false; }
            return ['version' => '0.1.0', 'target' => untrailingslashit(home_url()), 'stagingReady' => $ready, 'capabilities' => ['stageFiles' => true, 'publish' => false, 'databaseImport' => false, 'rollback' => false], 'maxChunkBytes' => StageStore::CHUNK];
        });
        $register('/jobs', 'GET', static fn() => self::store()->jobs());
        $register('/expire', 'POST', static fn() => ['expired' => self::store()->expire(time())]);
        $register('/jobs', 'POST', static function ($r) {
            $body = $r->get_json_params();
            if (!is_array($body)) throw new \InvalidArgumentException('JSON manifest required.');
            return self::store()->create($body, untrailingslashit(home_url()));
        });
        $register('/jobs/(?P<id>[a-f0-9]{32})', 'GET', static fn($r) => self::store()->status($r['id']));
        $register('/jobs/(?P<id>[a-f0-9]{32})', 'DELETE', static fn($r) => self::store()->cancel($r['id']));
        $register('/jobs/(?P<id>[a-f0-9]{32})/chunks', 'POST', static function ($r) {
            $body = $r->get_json_params();
            if (!is_array($body) || !is_int($body['index'] ?? null) || !is_int($body['offset'] ?? null) || !is_string($body['data'] ?? null)) throw new \InvalidArgumentException('Invalid chunk metadata.');
            $data = base64_decode($body['data'], true);
            if ($data === false) throw new \InvalidArgumentException('Invalid base64.');
            return self::store()->chunk($r['id'], $body['index'], $body['offset'], $data);
        });
        $register('/jobs/(?P<id>[a-f0-9]{32})/verify', 'POST', static fn($r) => self::store()->verify($r['id']));
        $register('/jobs/(?P<id>[a-f0-9]{32})/publish', 'POST', static function () {
            return new \WP_Error('zoer_not_implemented', 'Publication is unavailable in 0.1. Staged files have not changed the live site.', ['status' => 501]);
        });
    }
    public static function admin(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Zoer Connect</h1><p>Version 0.1 — connection and file staging preview.</p>';
        echo '<div class="notice notice-warning inline"><p>This version cannot publish or import your database. Staging never changes live files.</p></div>';
        echo '<h2>Connect Zoer</h2><ol><li>Use HTTPS on this site.</li><li>Create a dedicated application password named “Zoer” on your WordPress profile.</li><li>Enter the site URL, username and application password into your client. Keep it out of logs and source control.</li></ol>';
        echo '<p><a class="button" href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">Manage application passwords</a></p>';
        echo '<p>Revoke the application password from your profile to disconnect. WordPress application passwords inherit the account’s capabilities; they are not restricted to this plugin.</p>';
        echo '<h2>Storage</h2><p>Staged files must be outside the public document root. A private sibling folder is used where permitted. Your administrator can configure ZOER_CONNECT_STORAGE_DIR in wp-config.php when necessary.</p>';
        echo '<p>Only one staging job is reserved at a time. Cancel it through the API to remove its files before starting another. Deactivation and uninstall preserve staged data.</p></div>';
    }
}
