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
    public static function authorize($request = null) {
        if (!is_ssl()) return new \WP_Error('zoer_unauthorized', 'HTTPS is required.', ['status' => 401]);
        if (is_multisite()) return new \WP_Error('zoer_unsupported', 'Multisite is not supported.', ['status' => 409]);
        $token=$request ? (string)$request->get_header('x-zoer-connection') : '';
        if($token!==''){
            $record=get_option(ConnectionKey::OPTION,[]);
            if(!is_array($record)||!ConnectionKey::matches($token,$record)||!user_can((int)($record['owner']??0),'manage_options'))return new \WP_Error('zoer_unauthorized','Invalid or revoked connection key.',['status'=>401]);
        }elseif(!self::$applicationPassword || !current_user_can('manage_options'))return new \WP_Error('zoer_unauthorized','A connection key or administrator application password is required.',['status'=>401]);
        return true;
    }
    private static function store(): StageStore {
        $public = [ABSPATH];
        if (empty($_SERVER['DOCUMENT_ROOT'])) throw new \RuntimeException('Document root cannot be verified.');
        $public[] = $_SERVER['DOCUMENT_ROOT'];
        $root = defined('ZOER_CONNECT_STORAGE_DIR') ? ZOER_CONNECT_STORAGE_DIR : dirname(rtrim(ABSPATH, '/')) . '/.zoer-connect';
        return new StageStore($root, $public);
    }
    private static function exports(): RemoteExport {
        if(empty($_SERVER['DOCUMENT_ROOT']))throw new \RuntimeException('Document root cannot be verified.');
        $root=defined('ZOER_CONNECT_STORAGE_DIR')?ZOER_CONNECT_STORAGE_DIR:dirname(rtrim(ABSPATH,'/')).'/.zoer-connect';
        $record=get_option(ConnectionKey::OPTION,[]);
        $owner=is_array($record)?(string)($record['hash']??''):'';
        if($owner==='')throw new \RuntimeException('Configure a connection key first.');
        return new RemoteExport($root,[ABSPATH,$_SERVER['DOCUMENT_ROOT']],ABSPATH,$owner);
    }
    /** Runs before regular plugins, including when a transfer left them unusable. */
    public static function earlyImportRecovery(string $route): array {
        global $wpdb;
        require_once __DIR__.'/ConnectionKey.php';
        require_once __DIR__.'/TransferImport.php';
        require_once __DIR__.'/ImportAdmin.php';
        $fail=static function(int $status,string $message): array { http_response_code($status);return ['code'=>'zoer_import_unavailable','message'=>$message]; };
        if (!is_ssl() || is_multisite()) return $fail(401,'HTTPS and a single WordPress site are required.');
        $record=get_option(ConnectionKey::OPTION,[]);
        $token=(string)($_SERVER['HTTP_X_ZOER_CONNECTION']??'');
        if(!is_array($record)||!ConnectionKey::matches($token,$record))return $fail(401,'Invalid or revoked connection key.');
        if(!ConnectionKey::permits($record,'push'))return $fail(403,'Push permission is disabled.');
        // User/pluggable initialization has not run at MU loading time. Read the
        // current native account and capabilities directly, never trust request IDs.
        $owner=(int)($record['owner']??0);
        $caps=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `{$wpdb->usermeta}` WHERE user_id=%d AND meta_key=%s",$owner,$wpdb->prefix.'capabilities'));
        $caps=is_string($caps)?@unserialize($caps,['allowed_classes'=>false]):null;
        if(!is_array($caps)||($caps['administrator']??false)!==true||!$wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->users}` WHERE ID=%d",$owner)))return $fail(401,'The connection owner must remain an administrator.');
        if(!preg_match('~^/zoer-connect/v1/imports(?:/([a-f0-9]{32})(?:/(chunks|step|rollback|finish))?)?$~D',$route,$m))return $fail(404,'Unknown import operation.');
        try {
            self::store();
            $root=defined('ZOER_CONNECT_STORAGE_DIR')?ZOER_CONNECT_STORAGE_DIR:dirname(rtrim(ABSPATH,'/')).'/.zoer-connect';
            $target=rtrim((string)get_option('home'),'/');
            $import=new TransferImport($wpdb,ABSPATH,$root,$record['hash'],$target,true);
            $method=$_SERVER['REQUEST_METHOD']??'GET';$id=$m[1]??null;$action=$m[2]??null;
            if($method==='GET'&&$id&&!$action)return $import->status($id);
            if($method!=='POST')return $fail(405,'Unsupported import method.');
            $raw=file_get_contents('php://input',false,null,0,2097153);
            if(strlen($raw)>2097152)return $fail(413,'Import request exceeds 2 MiB.');
            $body=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($body))return $fail(400,'JSON import data required.');
            if(!$id){if(!ImportAdmin::ready($root,ABSPATH))return $fail(409,'Complete destination import setup in Tools → Zoer Connect first.');$mode=ImportAdmin::mode($root,ABSPATH);if(($body['migrationMode']??'verified-workers')!==$mode)return $fail(409,'Migration mode differs from destination setup. Refresh the connection.');$body['destinationAdminId']=$owner;return $import->create($body);}
            if($action==='chunks'){
                if(!is_int($body['index']??null)||!is_int($body['offset']??null)||!is_string($body['data']??null)||strlen($body['data'])>349528)return $fail(400,'Invalid import chunk.');
                $data=base64_decode($body['data'],true);if($data===false)return $fail(400,'Invalid chunk encoding.');
                return $import->chunk($id,$body['index'],$body['offset'],$data);
            }
            if(in_array($action,['step','rollback','finish'],true))return $import->$action($id);
            return $fail(404,'Unknown import operation.');
        }catch(\InvalidArgumentException $e){return $fail(400,'Import input failed validation.');}
        catch(\Throwable $e){return $fail(409,'Import could not advance. Retry or roll back using the same connection.');}
    }
    public static function routes(): void {
        $register = static function ($path, $method, $handler) {
            register_rest_route('zoer-connect/v1', $path, [
                'methods' => $method, 'permission_callback' => static function($request) use($path){
                    $auth=self::authorize($request);if(is_wp_error($auth))return $auth;
                    $record=get_option(ConnectionKey::OPTION,null);
                    $scope=str_starts_with($path,'/exports')?'pull':($path==='/status'?'status':'push');
                    if(($scope==='pull'||$record!==null) && (!is_array($record)||!ConnectionKey::permits($record,$scope)))return new \WP_Error('zoer_permission_disabled',ucfirst($scope).' permission is disabled on this site.',['status'=>403]);
                    return true;
                },
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
        foreach(['/imports','/imports/(?P<id>[a-f0-9]{32})','/imports/(?P<id>[a-f0-9]{32})/(?P<action>chunks|step|rollback|finish)'] as $path) {
            register_rest_route('zoer-connect/v1',$path,['methods'=>'GET,POST','permission_callback'=>'__return_true','callback'=>static function($r){
                $result=self::earlyImportRecovery($r->get_route());$status=http_response_code();return new \WP_REST_Response($result,$status>=400?$status:200,['Cache-Control'=>'no-store']);
            }]);
        }
        $register('/status', 'GET', static function () {
            $ready = true;
            try { self::store(); } catch (\Throwable $e) { $ready = false; }
            $record=get_option(ConnectionKey::OPTION,null);
            $private=defined('ZOER_CONNECT_STORAGE_DIR')?ZOER_CONNECT_STORAGE_DIR:dirname(rtrim(ABSPATH,'/')).'/.zoer-connect';
            $importReady=ImportAdmin::ready($private,ABSPATH);
            return ['version' => '0.3.5', 'target' => rtrim((string)get_option('home'),'/'), 'stagingReady' => $ready, 'migrationMode'=>ImportAdmin::mode($private,ABSPATH), 'capabilities' => ['connectionKey'=>true,'stageFiles' => true, 'pull'=>true, 'publish' => $importReady, 'artifactReuse'=>true, 'chunkedFilePublication'=>true, 'databaseImport' => $importReady, 'rollback' => $importReady], 'permissions'=>['push'=>$record===null || (is_array($record)&&ConnectionKey::permits($record,'push')),'pull'=>is_array($record)&&ConnectionKey::permits($record,'pull')], 'maxChunkBytes' => StageStore::CHUNK];
        });
        $register('/exports', 'POST', static function($r){
            global $wpdb, $wp_version;
            $body=$r->get_json_params();if(!is_array($body))throw new \InvalidArgumentException('JSON export selections required.');
            return self::exports()->create($body,['url'=>untrailingslashit(home_url()),'prefix'=>$wpdb->prefix,'wordpressVersion'=>$wp_version],static fn($path)=>DatabaseExporter::write($wpdb,$path));
        });
        $register('/exports/(?P<id>[a-f0-9]{32})','GET',static fn($r)=>self::exports()->status($r['id']));
        $register('/exports/(?P<id>[a-f0-9]{32})','DELETE',static fn($r)=>self::exports()->cancel($r['id']));
        $register('/exports/(?P<id>[a-f0-9]{32})/step','POST',static fn($r)=>self::exports()->step($r['id']));
        $register('/exports/(?P<id>[a-f0-9]{32})/chunks','GET',static function($r){
            foreach(['index','offset'] as $key)if(!is_string($r[$key])||!preg_match('/^(0|[1-9][0-9]{0,12})$/D',$r[$key]))throw new \InvalidArgumentException('Non-negative integer range required.');
            return self::exports()->chunk($r['id'],(int)$r['index'],(int)$r['offset']);
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
            return new \WP_Error('zoer_not_implemented', 'Legacy staging cannot publish. Use the authenticated import workflow after completing destination setup.', ['status' => 501]);
        });
    }
    public static function admin(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Zoer Connect</h1><p>Version 0.3.5 — WordPress transfers and recovery.</p>';
        echo '<p>Imports require explicit destination setup and Push permission. Review the destination and selected resources in Zoer before importing.</p>';
        ConnectionAdmin::render();
        ExportAdmin::render();
        ImportAdmin::render();
        echo '<details><summary>Advanced: application passwords</summary><p>API clients can also use WordPress application passwords. The Push permission above applies to these clients too once connection settings have been configured.</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">Manage application passwords</a></p>';
        echo '<p>Revoke the application password from your profile to disconnect. WordPress application passwords inherit the account’s capabilities; they are not restricted to this plugin.</p></details>';
        echo '<h2>Storage</h2><p>Staged files must be outside the public document root. A private sibling folder is used where permitted. Your administrator can configure ZOER_CONNECT_STORAGE_DIR in wp-config.php when necessary.</p>';
        echo '<p>Only one staging job is reserved at a time. Cancel it through the API to remove its files before starting another. Deactivation and uninstall preserve staged data.</p></div>';
    }
}
