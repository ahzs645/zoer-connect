<?php
namespace ZoerConnect;
require_once __DIR__.'/RequestDrain.php';

/** Default shared-hosting setup and advanced two-step destination setup. A private receipt cannot arrive in an
 * imported options table. Worker adoption is verified through local process identities. Writer isolation
 * and a single host remain explicit administrator declarations. */
final class ImportAdmin {
    private const PENDING='import-setup-pending.json';
    private const READY='import-readiness.json';
    private const MU='wp-content/mu-plugins/000-zoer-connect-fence.php';
    private static function target(): string {
        $target=rtrim((string)get_option('home'),' /');$parts=parse_url($target);
        if(!is_array($parts)||!in_array($parts['scheme']??'', ['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||strlen($target)>2048)throw new \RuntimeException('Cannot verify the destination URL.');
        return $target;
    }
    private static function identity(string $private,string $root): array {
        $fence=new WriteFence($private,$root);
        if(!$fence->installed())throw new \RuntimeException('Install and verify the earliest MU request fence first.');
        $mu=rtrim($root,'/').'/'.self::MU;
        return ['target'=>self::target(),'muSha256'=>hash_file('sha256',$mu),'fenceSha256'=>hash_file('sha256',__DIR__.'/WriteFence.php'),'drainSha256'=>hash_file('sha256',__DIR__.'/RequestDrain.php')];
    }
    private static function read(string $private,string $name): ?array {
        $path=rtrim($private,'/').'/'.$name;
        if(is_link($path))throw new \RuntimeException('Unsafe setup receipt.');
        if(!is_file($path))return null;
        if(filesize($path)>8192)throw new \RuntimeException('Invalid setup receipt.');
        $value=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        return is_array($value)?$value:null;
    }
    private static function sameIdentity(array $receipt,array $identity): bool {
        foreach(['target','muSha256','fenceSha256','drainSha256'] as $key)if(!is_string($receipt[$key]??null)||!hash_equals($identity[$key],$receipt[$key]))return false;
        return true;
    }
    /** Read-only gate for new imports/capability reporting. Existing recovery must
     * never depend on this flag: an interrupted import retains its own fence. */
    public static function ready(string $private,string $root): bool {
        try{
            $identity=self::identity($private,$root);$receipt=self::read($private,self::READY);
            if(!$receipt||!in_array($receipt['version']??null,[2,3,4],true)||!self::sameIdentity($receipt,$identity)||!is_int($receipt['confirmedAt']??null)||!is_int($receipt['administratorId']??null)||$receipt['administratorId']<1)return false;
            if(($receipt['version']??null)===4)return ($receipt['mode']??null)==='shared-replacement'&&($receipt['setupSource']??null)==='connection-default';
            if(($receipt['version']??null)===3)return ($receipt['mode']??null)==='shared-replacement'&&($receipt['replacementAccepted']??false)===true;
            foreach(['workersVerified','noExternalWriters','singleHostLocalLocks'] as $key)if(($receipt[$key]??null)!==true)return false;
            return true;
        }catch(\Throwable $e){return false;}
    }
    public static function mode(string $private,string $root): ?string {
        if(!self::ready($private,$root))return null;
        $receipt=self::read($private,self::READY);
        return in_array($receipt['version']??null,[3,4],true)?'shared-replacement':'verified-workers';
    }
    private static function write(string $private,string $name,array $receipt): void {
        $path=rtrim($private,'/').'/'.$name;
        if(is_link($path))throw new \RuntimeException('Unsafe setup receipt.');
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$data=json_encode($receipt,JSON_THROW_ON_ERROR);$h=fopen($tmp,'x');
        if(!$h)throw new \RuntimeException('Cannot create setup receipt.');
        try{if(!chmod($tmp,0600)||fwrite($h,$data)!==strlen($data)||!fflush($h))throw new \RuntimeException('Cannot save setup receipt.');if(function_exists('fsync')&&!fsync($h))throw new \RuntimeException('Cannot sync setup receipt.');}catch(\Throwable $e){fclose($h);@unlink($tmp);throw $e;}
        fclose($h);if(!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Cannot finalize setup receipt.');}
    }
    /** Prepare a new destination during the authenticated connection form POST.
     * Existing setup and explicit permissions are never migrated by a page view or update. */
    public static function setupDefault(string $private,string $root): string {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!current_user_can('manage_options')||is_multisite()||!is_ssl())throw new \RuntimeException('HTTPS administrator POST required.');
        $action=$_POST['zoer_connection_action']??null;$nonce=$_POST['_wpnonce']??null;
        if(!in_array($action,['rotate','permissions'],true)||!is_string($nonce)||!wp_verify_nonce($nonce,'zoer_connection_settings'))throw new \RuntimeException('Connection confirmation expired. Reload this page.');
        $record=get_option(ConnectionKey::OPTION,[]);
        if(!is_array($record)||!is_string($record['hash']??null)||!ConnectionKey::permits($record,'push')||!user_can((int)($record['owner']??0),'manage_options'))throw new \RuntimeException('An active connection with Push permission is required.');
        return self::configure($private,$root,'default');
    }
    /** Called only by the administrator form. Exposed for boundary tests; it does
     * not bypass admin capability, HTTP method, WordPress nonce, or confirmations. */
    public static function submit(string $private,string $root): string {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!current_user_can('manage_options'))throw new \RuntimeException('Administrator POST required.');
        $action=$_POST['zoer_import_setup_action']??null;
        if(!is_string($action)||!in_array($action,['install','confirm','shared'],true))throw new \InvalidArgumentException('Choose an explicit setup action.');
        $nonce=$_POST['_wpnonce']??null;
        if(!is_string($nonce)||!wp_verify_nonce($nonce,'zoer_import_setup_'.$action))throw new \RuntimeException('Setup confirmation expired. Reload this page.');
        return self::configure($private,$root,$action);
    }
    private static function configure(string $private,string $root,string $action): string {
        $public=[$root];if(!empty($_SERVER['DOCUMENT_ROOT']))$public[]=$_SERVER['DOCUMENT_ROOT'];else throw new \RuntimeException('Document root cannot be verified.');
        new StageStore($private,$public);
        $lockPath=rtrim($private,'/').'/import-setup.lock';if(is_link($lockPath))throw new \RuntimeException('Unsafe setup lock.');$h=fopen($lockPath,'c');
        if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new \RuntimeException('Setup is already in progress.');}chmod($lockPath,0600);
        try{
            if(file_exists(rtrim($private,'/').'/write-fence.json'))throw new \RuntimeException('Finish or recover the active import before changing setup.');
            if($action==='default'){
                // Do not downgrade strict setup, renew stale receipts, or replace recovery state.
                foreach([self::READY,self::PENDING] as $name)if(file_exists(rtrim($private,'/').'/'.$name)||is_link(rtrim($private,'/').'/'.$name))return self::ready($private,$root)?'Destination setup is ready.':'Existing destination setup needs attention below.';
                if(file_exists(rtrim($root,'/').'/'.self::MU)||is_link(rtrim($root,'/').'/'.self::MU))return 'Existing destination setup needs attention below.';
                $administratorId=(int)get_current_user_id();if($administratorId<1)throw new \RuntimeException('An administrator identity is required.');
                (new WriteFence($private,$root))->install(true);
                $identity=self::identity($private,$root);
                self::write($private,self::READY,['version'=>4,...$identity,'mode'=>'shared-replacement','setupSource'=>'connection-default','confirmedAt'=>time(),'administratorId'=>$administratorId]);
                return 'Shared-hosting migration is ready. Confirm selected content in Zoer before publishing.';
            }
            if($action==='shared'){
                if(($_POST['replacement_accepted']??null)!=='1')throw new \InvalidArgumentException('Confirm replacement of the selected destination resources.');
                (new WriteFence($private,$root))->install(true);
                $identity=self::identity($private,$root);
                $administratorId=(int)get_current_user_id();if($administratorId<1)throw new \RuntimeException('An administrator identity is required.');
                self::write($private,self::READY,['version'=>3,...$identity,'mode'=>'shared-replacement','replacementAccepted'=>true,'confirmedAt'=>time(),'administratorId'=>$administratorId]);
                return 'Shared-hosting replacement enabled. Selected resources will be replaced; concurrent edits are not merged.';
            }
            if($action==='install'){
                (new WriteFence($private,$root))->install();
                $inventory=RequestDrain::workers();
                $baseline=['boot'=>$inventory['boot'],'workers'=>$inventory['workers']];
                $ready=rtrim($private,'/').'/'.self::READY;if(is_link($ready))throw new \RuntimeException('Unsafe setup receipt.');if(is_file($ready)&&!unlink($ready))throw new \RuntimeException('Cannot reset import readiness.');
                self::write($private,self::PENDING,['version'=>2,'workerBaseline'=>$baseline,'setupId'=>bin2hex(random_bytes(16)),'installedAt'=>time(),...self::identity($private,$root)]);
                return 'Request protection installed. Continue below to verify earlier requests automatically and enable imports.';
            }
            $pending=self::read($private,self::PENDING);$identity=self::identity($private,$root);
            if(!$pending||!self::sameIdentity($pending,$identity)||!is_string($_POST['setup_id']??null)||!is_string($pending['setupId']??null)||!hash_equals($pending['setupId'],$_POST['setup_id']))throw new \RuntimeException('Setup changed. Repeat step 1 before confirming.');
            foreach(['no_external_writers','single_host_local_locks'] as $field)if(($_POST[$field]??null)!=='1')throw new \InvalidArgumentException('Confirm that this is one WordPress host and that all database writers use WordPress.');
            $drain=(new RequestDrain($private))->check($pending);
            if($drain['remaining']>0)throw new \RuntimeException($drain['remaining'].' PHP worker(s) have not yet entered request protection. Nothing was imported. Wait for existing work to finish, then check again. No restart is performed or required by this check.');
            $administratorId=(int)get_current_user_id();if($administratorId<1)throw new \RuntimeException('An administrator identity is required.');
            self::write($private,self::READY,['version'=>2,...$identity,'setupId'=>$pending['setupId'],'installedAt'=>$pending['installedAt'],'confirmedAt'=>time(),'administratorId'=>$administratorId,'workersVerified'=>true,'workerCount'=>$drain['checked'],'serverBoot'=>$drain['boot'],'noExternalWriters'=>true,'singleHostLocalLocks'=>true]);
            return 'Destination setup confirmed. New imports may now be enabled by the connection Push permission.';
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
    public static function boot(): void {
        add_action('wp_ajax_zoer_connect_drain_probe',static function(){
            if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Administrator required.'],403);
            check_ajax_referer('zoer_import_drain');
            // Overlapping short requests let idle pool workers adopt the lease.
            usleep(200000);
            wp_send_json_success(['checked'=>true]);
        });
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        $root=rtrim(ABSPATH,'/');$private=Plugin::storageRoot();$notice='';$error='';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['zoer_import_setup_action'])){try{$notice=self::submit($private,$root);}catch(\Throwable $e){$error=$e->getMessage();}}
        echo '<section><h2>Destination import setup</h2><p>New connections prepare shared-hosting migration automatically. Use the setup below if preparation needs attention, or choose advanced worker isolation.</p>';
        if($notice)echo '<div class="notice notice-success inline"><p>'.esc_html($notice).'</p></div>';
        if($error)echo '<div class="notice notice-error inline"><p>'.esc_html($error).'</p></div>';
        if(self::mode($private,$root)==='shared-replacement'){echo '<p><strong>Shared-hosting replacement enabled.</strong> Transfers retain original tables and selected files for recovery. WordPress is paused while applying the update. Concurrent edits are not merged; detected later edits can prevent automatic rollback.</p></section>';return;}
        if(self::ready($private,$root)){echo '<p><strong>Destination setup confirmed.</strong> The current URL and request-fence code match the private readiness receipt. Keep external SQL writers disabled during imports.</p></section>';return;}
        echo '<h3>Shared-hosting migration</h3><p>Transfer in chunks, prepare replacement tables, and keep the originals for recovery. This mode does not inspect or restart shared PHP workers. WordPress requests that reach this site’s protection are paused during application and recovery; earlier requests and external writers are not guaranteed to be stopped. Cache drop-ins may run or serve cached pages before this protection. Exclude Zoer Connect API routes from page caching and purge page caches after migration.</p><form method="post">';wp_nonce_field('zoer_import_setup_shared');
        echo '<input type="hidden" name="zoer_import_setup_action" value="shared"><p><label><input required type="checkbox" name="replacement_accepted" value="1"> Replace selected destination content. Concurrent edits are not merged and may be overwritten; avoid editing during migration.</label></p><button type="submit" class="button button-primary">Enable shared-hosting migration</button></form><h3>Advanced: verified worker isolation</h3>';
        echo '<p><strong>Step 1: Install the earliest MU request fence.</strong> Unsupported early drop-ins or an earlier MU plugin prevent installation.</p><form method="post">';wp_nonce_field('zoer_import_setup_install');
        echo '<input type="hidden" name="zoer_import_setup_action" value="install"><button type="submit" class="button">Install request fence</button></form>';
        try{$pending=self::read($private,self::PENDING);$valid=$pending&&self::sameIdentity($pending,self::identity($private,$root));}catch(\Throwable $e){$valid=false;$pending=null;}
        if($valid){
            echo '<h3>Step 2: Verify requests and enable imports</h3><p>Zoer Connect checks that earlier PHP requests have ended or entered request protection. This runs entirely through WordPress and does not restart workers, change hosting settings or stop another site.</p><p>Automatic verification requires readable Linux worker information. If a worker is still unprotected or the server hides that information, imports remain disabled. Long-running CLI jobs and workers serving another site on the same account may need to finish naturally.</p><form id="zoer-drain-confirm" method="post">';wp_nonce_field('zoer_import_setup_confirm');
            echo '<input type="hidden" name="zoer_import_setup_action" value="confirm"><input type="hidden" name="setup_id" value="'.esc_attr($pending['setupId']).'">';
            foreach(['no_external_writers'=>'All database writes for this site run through WordPress. No direct SQL client, forked PHP job or unfenced background writer is active.','single_host_local_locks'=>'This site runs on one web host with local filesystem locks.'] as $field=>$label)echo '<p><label><input required type="checkbox" name="'.$field.'" value="1"> '.esc_html($label).'</label></p>';
            echo '<button type="submit" class="button button-primary">Check requests and enable imports</button><p id="zoer-drain-progress" role="status" aria-live="polite"></p></form>';
            $ajax=wp_json_encode(admin_url('admin-ajax.php'));$nonce=wp_json_encode(wp_create_nonce('zoer_import_drain'));
            echo '<script>(function(){const f=document.getElementById("zoer-drain-confirm"),b=f.querySelector("button"),p=document.getElementById("zoer-drain-progress");f.addEventListener("submit",async function(e){e.preventDefault();if(b.disabled)return;b.disabled=true;p.textContent="Checking request protection…";try{for(let wave=0;wave<4;wave++){await Promise.all(Array.from({length:6},async()=>{const r=await fetch('.$ajax.',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"zoer_connect_drain_probe",_ajax_nonce:'.$nonce.'})});const j=await r.json();if(!r.ok||!j.success)throw new Error("Request check failed. Reload this page and try again.");}));}f.submit();}catch(err){p.textContent=err.message;b.disabled=false;}});})();</script>';

        }
        echo '</section>';
    }
}
