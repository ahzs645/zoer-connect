<?php
namespace ZoerConnect;

final class Plugin {
    public const VERSION = '0.5.2';
    private static bool $applicationPassword = false;
    public static function boot(): void {
        add_action('application_password_did_authenticate', static function () { self::$applicationPassword = true; });
        add_action('wp_loaded', [RewriteRefresh::class, 'run']);
        require_once __DIR__.'/CachePurge.php';
        add_action('wp_loaded', [CachePurge::class, 'run']);
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
    public static function storageRoot(): string {
        if(defined('ZOER_CONNECT_STORAGE_DIR'))return ZOER_CONNECT_STORAGE_DIR;
        $configured=get_option('zoer_connect_storage_dir','');
        return is_string($configured)&&$configured!=='' ? $configured : dirname(rtrim(ABSPATH,'/')).'/.zoer-connect';
    }
    private static function storageSettings(): void {
        $error='';$notice='';
        if(isset($_POST['zoer_storage_save'])){
            check_admin_referer('zoer_connect_storage');
            try {
                if(defined('ZOER_CONNECT_STORAGE_DIR')||get_option('zoer_connect_storage_dir','')!==''||file_exists(ABSPATH.'wp-content/mu-plugins/000-zoer-connect-fence.php'))throw new \RuntimeException('Storage is already configured or import protection is installed. Keep the existing location for recovery; administrator configuration changes are required.');
                $old=self::storageRoot();
                if(is_dir($old)){ $entries=@scandir($old); if($entries===false||array_diff($entries,['.','..','lock']))throw new \RuntimeException('The current storage contains transfer data. Keep its location until transfers and recovery are complete.'); }
                $path=trim((string)wp_unslash($_POST['zoer_storage_path']??''));
                if(!str_starts_with($path,'/')||strlen($path)>1000||preg_match('/[\x00-\x1f]/',$path))throw new \RuntimeException('Enter an absolute private filesystem path.');
                new StageStore($path,[ABSPATH,$_SERVER['DOCUMENT_ROOT']??'']);
                update_option('zoer_connect_storage_dir',$path,false);$notice='Private storage configured. Test the connection in Zoer and resume the Pull.';
            }catch(\Throwable $e){$error=$e instanceof StorageUnavailable ? $e->reason : $e->getMessage();}
        }
        if(isset($_POST['zoer_quota_save'])){check_admin_referer('zoer_connect_quota');try{$value=trim((string)wp_unslash($_POST['zoer_quota_bytes']??''));if(!ctype_digit($value)||(int)$value<1048576||(int)$value>68719476736)throw new \RuntimeException('Enter a quota from 1048576 to 68719476736 bytes.');if(defined('ZOER_CONNECT_TRANSFER_QUOTA_BYTES'))throw new \RuntimeException('The server configuration controls this quota.');update_option('zoer_connect_transfer_quota_bytes',$value,false);$notice='Transfer quota saved. Existing recovery data is preserved.';}catch(\Throwable $e){$error=$e->getMessage();}}
        $status=self::storageStatus();
        echo '<h2>Storage diagnostics</h2><p><strong>'.esc_html($status['code']).'</strong>: '.esc_html($status['message']).'</p>';
        echo '<p>Current path: <code>'.esc_html(self::storageRoot()).'</code></p><p>PHP filesystem restriction: <code>'.esc_html(ini_get('open_basedir')?:'not configured').'</code></p>';
        if($notice)echo '<div class="notice notice-success"><p>'.esc_html($notice).'</p></div>';
        if($error)echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';
        echo '<h3>Transfer storage quota</h3><p>Per transfer, in bytes. Free space and recovery storage are checked separately. Individual artifacts are limited to 4 GiB.</p><form method="post">';wp_nonce_field('zoer_connect_quota');
        echo '<label>Quota bytes <input type="number" name="zoer_quota_bytes" min="1048576" max="68719476736" value="'.esc_attr((string)TransferStorage::quota()).'"'.(defined('ZOER_CONNECT_TRANSFER_QUOTA_BYTES')?' disabled':'').'></label> <button class="button" name="zoer_quota_save" value="1"'.(defined('ZOER_CONNECT_TRANSFER_QUOTA_BYTES')?' disabled':'').'>Save quota</button></form>';
        if(!defined('ZOER_CONNECT_STORAGE_DIR')&&get_option('zoer_connect_storage_dir','')===''){
            echo '<form method="post"><p>For first-time setup, choose a writable folder outside every public document root. Zoer will not move existing transfers. If PHP cannot access any private folder, ask the server administrator to provide one.</p>';
            wp_nonce_field('zoer_connect_storage');
            echo '<label>Private storage path <input class="regular-text" name="zoer_storage_path" required autocomplete="off"></label> <button class="button" name="zoer_storage_save" value="1">Validate and configure storage</button></form>';
        }
    }
    private static function store(): StageStore {
        $public = [ABSPATH];
        if (empty($_SERVER['DOCUMENT_ROOT'])) throw new StorageUnavailable('public_root_unavailable');
        $public[] = $_SERVER['DOCUMENT_ROOT'];
        $root = self::storageRoot();
        return new StageStore($root, $public);
    }
    public static function storageStatus(): array {
        $messages=[
            'unsafe_path'=>'The private storage location is a symbolic link or cannot be verified.',
            'parent_unavailable'=>'PHP cannot access the parent of the private storage folder. Check the path and hosting filesystem restrictions.',
            'public_root_unavailable'=>'PHP cannot verify the public document root. Check the hosting document-root configuration.',
            'inside_public_root'=>'The storage folder is inside a public directory. Configure a private location outside every public document root.',
            'create_denied'=>'PHP cannot create the private storage folder. Check parent-folder permissions, available space and hosting restrictions.',
            'not_writable'=>'The private storage folder is not writable or resolves to an unexpected location.',
            'unavailable'=>'Private storage could not be verified. Check the WordPress administrator storage diagnostics.',
        ];
        try { self::store(); return ['ready'=>true,'code'=>'ready','message'=>'Private storage is available.']; }
        catch (\Throwable $error) { $code=$error instanceof StorageUnavailable ? $error->reason : 'unavailable'; return ['ready'=>false,'code'=>$code,'message'=>$messages[$code]??$messages['unavailable']]; }
    }
    private static function exports(bool $paged=false): RemoteExport|PagedExport {
        if(empty($_SERVER['DOCUMENT_ROOT']))throw new StorageUnavailable('public_root_unavailable');
        $root=self::storageRoot();
        $record=get_option(ConnectionKey::OPTION,[]);
        $owner=is_array($record)?(string)($record['hash']??''):'';
        if($owner==='')throw new \RuntimeException('Configure a connection key first.');
        return $paged ? new PagedExport($root,[ABSPATH,$_SERVER['DOCUMENT_ROOT']],ABSPATH,$owner) : new RemoteExport($root,[ABSPATH,$_SERVER['DOCUMENT_ROOT']],ABSPATH,$owner);
    }
    /** Active theme slugs (stylesheet and parent template) and active plugin slugs for 'active' resource modes. */
    public static function activeResources(): array {
        $plugins=[];foreach((array)get_option('active_plugins',[]) as $file)if(is_string($file)&&$file!=='')$plugins[]=str_contains($file,'/')?strstr($file,'/',true):$file;
        return ['themes'=>array_values(array_unique(array_filter([(string)get_stylesheet(),(string)get_template()]))),'plugins'=>array_values(array_unique($plugins))];
    }
    /** Runs before regular plugins, including when a transfer left them unusable. */
    public static function earlyImportRecovery(string $route): array {
        global $wpdb;
        require_once __DIR__.'/ConnectionKey.php';
        require_once __DIR__.'/TransferImport.php';
        require_once __DIR__.'/ImportAdmin.php';
        $fail=static function(int $status,string $message): array { http_response_code($status);return ['code'=>'zoer_import_unavailable','message'=>$message]; };
        if (!is_ssl() || is_multisite()) return $fail(401,'HTTPS and a single WordPress site are required.');
        // Persistent caches must never keep a revoked key valid during recovery.
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s",ConnectionKey::OPTION));
        $record=is_string($raw)?@unserialize($raw,['allowed_classes'=>false]):null;
        $token=(string)($_SERVER['HTTP_X_ZOER_CONNECTION']??'');
        if(!is_array($record)||!ConnectionKey::matches($token,$record))return $fail(401,'Invalid or revoked connection key.');
        $export=str_starts_with($route,'/zoer-connect/v1/exports/paged');
        if(!ConnectionKey::permits($record,($route==='/zoer-connect/v1/status'?'status':($export?'pull':'push'))))return $fail(403,$export?'Pull permission is disabled.':'Push permission is disabled.');
        // User/pluggable initialization has not run at MU loading time. Read the
        // current native account and capabilities directly, never trust request IDs.
        $owner=(int)($record['owner']??0);
        $caps=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `{$wpdb->usermeta}` WHERE user_id=%d AND meta_key=%s",$owner,$wpdb->prefix.'capabilities'));
        $caps=is_string($caps)?@unserialize($caps,['allowed_classes'=>false]):null;
        if(!is_array($caps)||($caps['administrator']??false)!==true||!$wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->users}` WHERE ID=%d",$owner)))return $fail(401,'The connection owner must remain an administrator.');
        if($route==='/zoer-connect/v1/status'){if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return $fail(405,'Unsupported status method.');return self::statusView($record);}
        if($export)return self::earlyExportRecovery($route,$record,$fail);
        if(!preg_match('~^/zoer-connect/v1/imports(?:/([a-f0-9]{32})(?:/(chunks|batch|step|rollback|finish|pause|resume|approve|cleanup))?)?$~D',$route,$m))return $fail(404,'Unknown import operation.');
        $import=null;$id=$m[1]??null;
        try {
            self::store();
            $root=self::storageRoot();
            $target=rtrim((string)get_option('home'),'/');
            $import=new TransferImport($wpdb,ABSPATH,$root,$record['hash'],$target,true);
            $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$m[2]??null;
            if($method==='GET'&&!$id)return $import->list();
            if($method==='GET'&&$id&&!$action)return ($_GET['view']??null)==='upload'?$import->upload($id):$import->status($id);
            if($method!=='POST')return $fail(405,'Unsupported import method.');
            if($action==='batch')return self::batchRequest($import,$id);
            $max=$id===null?8388608:2097152;
            $raw=file_get_contents('php://input',false,null,0,$max+1);
            if(strlen($raw)>$max)return $fail(413,$id===null?'Import manifest exceeds 8 MiB. Select fewer artifacts.':'Import request exceeds 2 MiB.');
            $body=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($body))return $fail(400,'JSON import data required.');
            if(!$id){if(!ImportAdmin::ready($root,ABSPATH))return $fail(409,'Complete destination import setup in Tools → Zoer Connect first.');$mode=ImportAdmin::mode($root,ABSPATH);if(($body['migrationMode']??'verified-workers')!==$mode)return $fail(409,'Migration mode differs from destination setup. Refresh the connection.');$body['destinationAdminId']=$owner;return $import->create($body);}
            if($action==='chunks'){
                if(!is_int($body['index']??null)||!is_int($body['offset']??null)||!is_string($body['data']??null)||strlen($body['data'])>349528)return $fail(400,'Invalid import chunk.');
                $data=base64_decode($body['data'],true);if($data===false)return $fail(400,'Invalid chunk encoding.');
                return $import->chunk($id,$body['index'],$body['offset'],$data);
            }
            if(in_array($action,['step','rollback','finish','pause','resume','approve','cleanup'],true))return $import->$action($id);
            return $fail(404,'Unknown import operation.');
        }catch(\Throwable $e){
            // Safe text only: our own exception messages with paths stripped.
            http_response_code($e instanceof \InvalidArgumentException?400:409);
            return TransferImport::safeError($e,$import&&$id?$import->phase($id):null);
        }
    }
    /** Paged source operations also run before ordinary plugins while paused. */
    private static function earlyExportRecovery(string $route,array $record,callable $fail):array {
        global $wpdb,$wp_version;
        foreach(['Selection','ExportProfile','FileExporter','PagedExport'] as $c)require_once __DIR__.'/'.$c.'.php';
        if(!preg_match('~^/zoer-connect/v1/exports/paged(?:/([a-f0-9]{32})(?:/(step|manifest|batch))?)?$~D',$route,$m))return $fail(404,'Unknown export operation.');
        try {
            $store=self::exports(true);$id=$m[1]??null;$action=$m[2]??null;$method=$_SERVER['REQUEST_METHOD']??'GET';
            if($id&&$method==='DELETE'&&!$action)return $store->cancel($id);
            if($id&&$method==='GET'&&!$action)return $store->status($id);
            if($id&&$method==='GET'&&in_array($action,['manifest','batch'],true)){
                $keys=$action==='manifest'?['offset']:['index','offset'];$values=[];foreach($keys as $k){$v=$_GET[$k]??'';if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,12})$/D',$v))throw new \InvalidArgumentException('Invalid export range.');$values[$k]=(int)$v;}
                return $action==='manifest'?$store->manifest($id,$values['offset']):$store->batch($id,$values['index'],$values['offset']);
            }
            if($method!=='POST')return $fail(405,'Unsupported export method.');
            if($id&&$action==='step'){
                $state=$store->status($id);if(($state['snapshotMode']??null)==='maintenance'&&!ConnectionKey::permits($record,'push'))return $fail(403,'Source pause requires Push permission. Cancel to resume the source.');
                return $store->step($id,static fn($p,array $filters=[])=>DatabaseExporter::write($wpdb,$p,null,40,$filters),$wpdb);
            }
            if($id)return $fail(404,'Unknown export operation.');
            $raw=file_get_contents('php://input',false,null,0,524289);if(strlen($raw)>524288)return $fail(413,'Export selections exceed 512 KiB.');$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($body))throw new \InvalidArgumentException('JSON selections required.');
            if(($body['snapshotMode']??null)==='maintenance'&&!ConnectionKey::permits($record,'push'))return $fail(403,'Source pause requires Push permission.');
            $get=static fn($name)=>(string)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s",$name));
            $plugins=@unserialize($get('active_plugins'),['allowed_classes'=>false]);$plugins=is_array($plugins)?$plugins:[];
            $active=['themes'=>array_values(array_unique(array_filter([$get('template'),$get('stylesheet')]))),'plugins'=>array_values(array_unique(array_map(static fn($p)=>str_contains($p,'/')?strstr($p,'/',true):$p,array_filter($plugins,'is_string'))))];
            return $store->create($body,['url'=>rtrim($get('home'),'/'),'prefix'=>$wpdb->prefix,'wordpressVersion'=>$wp_version,'abspath'=>rtrim(ABSPATH,'/')],$active);
        }catch(\Throwable $e){http_response_code($e instanceof \InvalidArgumentException?400:409);$error=TransferImport::safeError($e,'exporting');$error['code']='zoer_export_blocked';return $error;}
    }
    /** POST /imports/{id}/batch. Octet-stream and multipart bodies are streamed from
     * php://input or the uploaded part in bounded pieces, never read whole; the JSON
     * transport keeps the 2 MiB limit. Oversized bodies answer 413 with the limits. */
    private static function batchRequest(TransferImport $import,string $id): array {
        $started=microtime(true);
        $type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
        $declared=preg_match('/^\d{1,15}$/D',(string)($_SERVER['CONTENT_LENGTH']??''))?(int)$_SERVER['CONTENT_LENGTH']:null;
        $transport=$type==='application/octet-stream'?'octet-stream':($type==='multipart/form-data'?'multipart':'json');
        $limits=BatchUpload::limits($transport);$h=null;
        try{
            if($transport==='multipart'){
                $f=$_FILES['batch']??null;
                if(is_array($f)&&in_array($f['error']??null,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new BatchBodyLimit('The batch exceeds upload_max_filesize.');
                if(!is_array($f)){if(($declared??0)>0&&!$_POST&&!$_FILES)throw new BatchBodyLimit('The request body was discarded by a PHP size limit.');throw new \InvalidArgumentException('Batch file part required.');}
                if(($f['error']??null)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name']))throw new \InvalidArgumentException('Invalid batch file part.');
                $h=fopen($f['tmp_name'],'rb');if(!$h)throw new \RuntimeException('Cannot read batch file part.');
                $body=BatchUpload::framed($h,(int)filesize($f['tmp_name']),$limits['maxBatchBytes']);
            }elseif($transport==='octet-stream'){
                if($declared===null)throw new \InvalidArgumentException('Content-Length required.');
                $h=fopen('php://input','rb');if(!$h)throw new \RuntimeException('Cannot read batch body.');
                $body=BatchUpload::framed($h,$declared,$limits['maxBatchBytes']);
            }else{
                $raw=(string)file_get_contents('php://input',false,null,0,BatchUpload::JSON_BYTES+1);
                if(strlen($raw)>BatchUpload::JSON_BYTES||($raw===''&&($declared??0)>0))throw new BatchBodyLimit('JSON batch exceeds 2 MiB or was discarded by a PHP size limit.');
                try{$json=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('JSON batch required.');}
                if(!is_array($json))throw new \InvalidArgumentException('JSON batch required.');
                $body=BatchUpload::json($json);unset($raw,$json);
            }
            return $import->batch($id,$body,$started)+['limits'=>$limits];
        }catch(BatchBodyLimit $e){http_response_code(413);return ['code'=>'zoer_import_body_limit','message'=>$e->getMessage(),'limits'=>$limits];}
        finally{
            // Consume an unread octet-stream tail (rejection, deadline, refused size) before
            // answering, so proxies deliver the response instead of resetting a client that
            // is still sending. Bounded; the bytes are discarded in 256 KiB pieces.
            if($h&&$transport==='octet-stream'&&$declared<=2*($limits['maxBatchBytes']+8+BatchUpload::MAX_HEADER))while(!feof($h)&&is_string($piece=fread($h,StageStore::CHUNK))&&$piece!=='');
            if($h)fclose($h);
        }
    }
    private static function statusView(?array $record):array {
        global $wpdb;
            require_once __DIR__.'/BatchUpload.php';
            $storage=self::storageStatus(); $ready=$storage['ready'];

            $private=self::storageRoot();
            $importReady=ImportAdmin::ready($private,ABSPATH);
            $capabilities=['pagedExport'=>true,'connectionKey'=>true,'stageFiles' => true, 'pull'=>true, 'publish' => $importReady, 'selectivePush'=>true,'artifactReuse'=>function_exists('link'), 'chunkedFilePublication'=>true, 'databaseImport' => $importReady, 'rollback' => $importReady];
            // API version 2 (0.4.0). Import-side flags describe the protocol implemented by this version.
            // Batched upload (C1): 256 KiB blocks packed per request; see BatchUpload.
            $capabilities['largeTransfer']=true;$capabilities['blockDigestExport']=true;$capabilities['resumableDatabaseExport']=$importReady;
            $capabilities['batchUpload']=true;$capabilities['batchDeflate']=function_exists('inflate_init')&&function_exists('inflate_add');
            foreach(['replacementRules','replacementVariants','reviewPause','createTables','authorMapping','keepActivePlugins','lateFence','importPauseResume','importCleanup','importList','siteReplace','cachePurge','databaseFilters','resourceModes','mediaSince','diagnostics','safeErrors'] as $capability)$capabilities[$capability]=true;
            return ['version' => '0.5.2', 'apiVersion' => 2, 'target' => rtrim((string)get_option('home'),'/'), 'stagingReady' => $ready, 'storage'=>$storage, 'migrationMode'=>ImportAdmin::mode($private,ABSPATH), 'capabilities' => $capabilities, 'permissions'=>['push'=>$record===null || (is_array($record)&&ConnectionKey::permits($record,'push')),'pull'=>is_array($record)&&ConnectionKey::permits($record,'pull')], 'maxChunkBytes' => StageStore::CHUNK, 'batchTransports'=>BatchUpload::transports(), 'transferLimits'=>['manifestMaxBytes'=>8388608,'maxFileBytes'=>TransferStorage::FILE_BYTES,'quotaBytes'=>TransferStorage::quota(),'reserveBytes'=>TransferStorage::RESERVE_BYTES],'batchLimits'=>BatchUpload::limits()];
    }
    public static function routes(): void {
        $register = static function ($path, $method, $handler) {
            register_rest_route('zoer-connect/v1', $path, [
                'methods' => $method, 'permission_callback' => static function($request) use($path){
                    $auth=self::authorize($request);if(is_wp_error($auth))return $auth;
                    $record=get_option(ConnectionKey::OPTION,null);
                    // Diagnostics are read-only preflight data for either transfer direction.
                    $scopes=str_starts_with($path,'/exports')?['pull']:($path==='/status'?['status']:($path==='/diagnostics'?['push','pull']:['push']));
                    $permitted=is_array($record)&&array_filter($scopes,static fn($scope)=>ConnectionKey::permits($record,$scope));
                    if(($scopes===['pull']||$record!==null) && !$permitted)return new \WP_Error('zoer_permission_disabled',implode(' or ',array_map('ucfirst',$scopes)).' permission is disabled on this site.',['status'=>403]);
                    return true;
                },
                'callback' => static function ($request) use ($handler) {
                    try {
                        if (strlen($request->get_body()) > 524288) return new \WP_Error('zoer_large_request', 'Request exceeds 512 KiB.', ['status' => 413]);
                        $result = $handler($request);
                        return is_wp_error($result) ? $result : new \WP_REST_Response($result, 200, ['Cache-Control' => 'no-store']);
                    } catch (StorageUnavailable $e) {
                        $storage=self::storageStatus();
                        return new \WP_Error('zoer_storage_'.$storage['code'], $storage['message'], ['status'=>409]);
                    } catch (\InvalidArgumentException $e) {
                        return new \WP_Error('zoer_invalid', $e->getMessage(), ['status' => 400]);
                    } catch (\Throwable $e) {
                        $safe=['Too many selected files.','A selected file exceeds the current 32 MiB export limit.','Paged export exceeds its file or byte limit.','Insufficient private export storage.','Pull requires InnoDB tables.','Pull requires primary keys for stable table ordering.','Database snapshot exceeded its request time budget. Retry a fresh export.','Database snapshot exceeds the 256 MiB or 40 second limit.','Export expired. Start a new pull.','Source changed during pull.','Symlink source rejected.','Unsafe source file.'];
                        if(in_array($e->getMessage(),$safe,true))return new \WP_Error('zoer_export_blocked',$e->getMessage(),['status'=>409]);
                        // Never return filesystem paths, SQL, credentials or raw PHP exceptions.
                        return new \WP_Error('zoer_unavailable', 'Staging unavailable. Check private storage, free space, or an existing job.', ['status' => 409]);
                    }
                },
            ]);
        };
        foreach(['/imports','/imports/(?P<id>[a-f0-9]{32})','/imports/(?P<id>[a-f0-9]{32})/(?P<action>chunks|batch|step|rollback|finish|pause|resume|approve|cleanup)'] as $path) {
            register_rest_route('zoer-connect/v1',$path,['methods'=>'GET,POST','permission_callback'=>'__return_true','callback'=>static function($r){
                $result=self::earlyImportRecovery($r->get_route());$status=http_response_code();return new \WP_REST_Response($result,$status>=400?$status:200,['Cache-Control'=>'no-store']);
            }]);
        }
        $register('/status', 'GET', static fn()=>self::statusView(get_option(ConnectionKey::OPTION,null)));
        $register('/diagnostics','GET',static function(){global $wpdb;return Diagnostics::collect($wpdb);});
        $register('/exports/paged', 'POST', static function($r){global $wpdb,$wp_version;$b=$r->get_json_params();if(!is_array($b))throw new \InvalidArgumentException('JSON selections required.');if(($b['snapshotMode']??null)==='maintenance'&&empty($_SERVER['HTTP_X_ZOER_CONNECTION']))throw new \InvalidArgumentException('Source pause requires a connection key.');return self::exports(true)->create($b,['url'=>untrailingslashit(home_url()),'prefix'=>$wpdb->prefix,'wordpressVersion'=>$wp_version,'abspath'=>untrailingslashit(ABSPATH)],self::activeResources());});
        $register('/exports/paged/(?P<id>[a-f0-9]{32})/step','POST',static function($r){global $wpdb;return self::exports(true)->step($r['id'],static fn($p,array $filters=[])=>DatabaseExporter::write($wpdb,$p,null,40,$filters),$wpdb);});
        $register('/exports/paged/(?P<id>[a-f0-9]{32})/manifest','GET',static function($r){$o=$r['offset'];if(!is_string($o)||!preg_match('/^(0|[1-9][0-9]{0,6})$/D',$o))throw new \InvalidArgumentException('Invalid manifest offset.');return self::exports(true)->manifest($r['id'],(int)$o);});
        $register('/exports/paged/(?P<id>[a-f0-9]{32})/batch','GET',static function($r){foreach(['index','offset'] as $k)if(!is_string($r[$k])||!preg_match('/^(0|[1-9][0-9]{0,12})$/D',$r[$k]))throw new \InvalidArgumentException('Invalid export range.');return self::exports(true)->batch($r['id'],(int)$r['index'],(int)$r['offset']);});
        $register('/exports/paged/(?P<id>[a-f0-9]{32})','DELETE',static fn($r)=>self::exports(true)->cancel($r['id']));
        $register('/exports', 'POST', static function($r){
            global $wpdb, $wp_version;
            $body=$r->get_json_params();if(!is_array($body))throw new \InvalidArgumentException('JSON export selections required.');
            return self::exports()->create($body,['url'=>untrailingslashit(home_url()),'prefix'=>$wpdb->prefix,'wordpressVersion'=>$wp_version,'abspath'=>untrailingslashit(ABSPATH)],static fn($path,array $filters=[])=>DatabaseExporter::write($wpdb,$path,null,40,$filters),self::activeResources());
        });
        $register('/exports/(?P<id>[a-f0-9]{32})','GET',static fn($r)=>self::exports()->status($r['id']));
        $register('/exports/(?P<id>[a-f0-9]{32})','DELETE',static fn($r)=>self::exports()->cancel($r['id']));
        $register('/exports/(?P<id>[a-f0-9]{32})/step','POST',static fn($r)=>self::exports()->step($r['id']));
        $register('/exports/(?P<id>[a-f0-9]{32})/chunks','GET',static function($r){
            foreach(['index','offset'] as $key)if(!is_string($r[$key])||!preg_match('/^(0|[1-9][0-9]{0,12})$/D',$r[$key]))throw new \InvalidArgumentException('Non-negative integer range required.');
            return self::exports()->chunk($r['id'],(int)$r['index'],(int)$r['offset']);
        });
        $register('/files/compare', 'POST', static function($r){
            require_once __DIR__.'/FileComparison.php';
            $body=$r->get_json_params();
            if(!is_array($body)||!is_array($body['files']??null))throw new \InvalidArgumentException('File list required.');
            return FileComparison::compare(ABSPATH,$body['files']);
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
        echo '<div class="wrap"><h1>Zoer Connect</h1><p>Version 0.5.2 — WordPress transfers and recovery.</p>';
        echo '<p>Imports require explicit destination setup and Push permission. Review the destination and selected resources in Zoer before importing.</p>';
        ConnectionAdmin::render();
        ExportAdmin::render();
        ImportAdmin::render();
        TransferAdmin::render();
        echo '<details><summary>Advanced: application passwords</summary><p>API clients can also use WordPress application passwords. The Push permission above applies to these clients too once connection settings have been configured.</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">Manage application passwords</a></p>';
        echo '<p>Revoke the application password from your profile to disconnect. WordPress application passwords inherit the account’s capabilities; they are not restricted to this plugin.</p></details>';
        self::storageSettings();
        echo '<h2>Storage</h2><p>Staged files must be outside the public document root. A private sibling folder is used where permitted. Your administrator can configure ZOER_CONNECT_STORAGE_DIR in wp-config.php when necessary.</p>';
        echo '<p>Only one staging job is reserved at a time. Cancel it through the API to remove its files before starting another. Deactivation and uninstall preserve staged data.</p></div>';
    }
}
