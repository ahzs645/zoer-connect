<?php
namespace ZoerConnect;
require_once __DIR__.'/TransferRetention.php';

/** Administrator view of private import journals, with explicit backup cleanup for finished imports.
 * Listing reads journals without the import lock or credential generation; cleanup goes through TransferImport. */
final class TransferAdmin {
    public const TERMINAL=['complete','rolled_back','cancelled'];
    public static function boot(): void {add_action('admin_post_zoer_import_cleanup',[self::class,'cleanup']);add_action('admin_post_zoer_import_adopt',[self::class,'adopt']);}
    /** Unfinished imports with the key generation (hash) that may resume or roll them back: {id: owner}. */
    public static function unfinished(string $private): array {
        $root=realpath($private);if(!$root||is_link($private))return [];$out=[];
        foreach(glob($root.'/import-*',GLOB_ONLYDIR)?:[] as $dir){
            $path=$dir.'/state.json';
            if(is_link($dir)||is_link($path)||!preg_match('/^import-([a-f0-9]{32})$/D',basename($dir),$m)||!is_file($path))continue;
            $size=filesize($path);if($size===false||$size>16777216)continue;
            $s=json_decode((string)file_get_contents($path),true);
            if(is_array($s)&&!in_array($s['phase']??null,self::TERMINAL,true))$out[$m[1]]=is_string($s['owner']??null)?$s['owner']:'';
        }
        return $out;
    }
    private static function currentOwner(): ?string {$record=get_option(ConnectionKey::OPTION,[]);return is_array($record)&&is_string($record['hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$record['hash'])?$record['hash']:null;}
    /** Administrator recovery after a key reset or revocation: continue one unfinished import with the current key. */
    public static function adopt(): void {
        if(!current_user_can('manage_options')||is_multisite())wp_die('Administrator on a single site required.',403);
        check_admin_referer('zoer_import_adopt');
        if(!is_ssl())wp_die('HTTPS is required to manage connection keys.');
        try{
            $id=(string)wp_unslash($_POST['import_id']??'');
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid import ID.');
            $owner=self::currentOwner();if($owner===null)throw new \RuntimeException('Generate a connection key first.');
            $private=Plugin::storageRoot();if(!array_key_exists($id,self::unfinished($private)))throw new \RuntimeException('Only an unfinished import can continue with the current key.');
            require_once __DIR__.'/TransferImport.php';global $wpdb;
            (new TransferImport($wpdb,ABSPATH,$private,$owner,rtrim((string)get_option('home'),'/'),true))->adminAdopt($id);
        }catch(\Throwable $e){
            require_once __DIR__.'/TransferImport.php';
            wp_die(esc_html(TransferImport::authored($e)?TransferImport::safeError($e)['message']:'The import could not be moved to the current key.'));
        }
        wp_safe_redirect(admin_url('tools.php?page=zoer-connect&zoer_adopted=1'));exit;
    }
    /** Newest first, at most 50: [{id, kind, phase, createdAt, target, cleanedUp}]. */
    public static function imports(string $private,int $limit=50): array {
        $root=realpath($private);if(!$root||is_link($private))return [];$out=[];
        foreach(glob($root.'/import-*',GLOB_ONLYDIR)?:[] as $dir){
            $path=$dir.'/state.json';
            if(is_link($dir)||is_link($path)||!preg_match('/^import-([a-f0-9]{32})$/D',basename($dir),$m)||!is_file($path))continue;
            $size=filesize($path);if($size===false||$size>16777216)continue;
            $s=json_decode((string)file_get_contents($path),true);if(!is_array($s))continue;
            $out[]=['id'=>$m[1],'kind'=>in_array($s['kind']??null,['transfer','replace'],true)?$s['kind']:'transfer','phase'=>is_string($s['phase']??null)?$s['phase']:'unknown','createdAt'=>is_string($s['createdAt']??null)?$s['createdAt']:'','target'=>is_string($s['target']??null)?$s['target']:'','cleanedUp'=>($s['cleanedUp']??false)===true];
        }
        usort($out,static fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt'])?:strcmp($a['id'],$b['id']));
        return $limit>0?array_slice($out,0,$limit):$out;
    }
    private static function supported(): bool {require_once __DIR__.'/TransferImport.php';return method_exists(TransferImport::class,'adminCleanup');}
    public static function cleanup(): void {
        if(!current_user_can('manage_options')||is_multisite())wp_die('Administrator on a single site required.',403);
        check_admin_referer('zoer_import_cleanup');
        try{
            $id=(string)wp_unslash($_POST['import_id']??'');
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid import ID.');
            if(!self::supported())throw new \RuntimeException('Backup cleanup requires a newer Zoer Connect import engine.');
            $private=Plugin::storageRoot();$import=null;
            foreach(self::imports($private) as $item)if($item['id']===$id)$import=$item;
            if(!$import||!in_array($import['phase'],self::TERMINAL,true))throw new \RuntimeException('Only completed, rolled back or cancelled imports can be cleaned up.');
            // An administrator may clean up journals of any key generation, including
            // ones created before a key rotation; the owner hash is not consulted.
            $owner=self::currentOwner()??str_repeat('0',64);
            global $wpdb;
            (new TransferImport($wpdb,ABSPATH,$private,$owner,rtrim((string)get_option('home'),'/'),true))->adminCleanup($id);
        }catch(\Throwable $e){
            // Only plugin-authored text reaches the administrator; internal failures never expose paths or SQL.
            require_once __DIR__.'/TransferImport.php';
            wp_die(esc_html(TransferImport::authored($e)?TransferImport::safeError($e)['message']:'Backup cleanup failed.'));
        }
        wp_safe_redirect(admin_url('tools.php?page=zoer-connect&zoer_cleanup=1'));exit;
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        try{$imports=self::imports(Plugin::storageRoot());}catch(\Throwable $e){$imports=[];}
        echo '<section class="zc-card" id="zoer-transfers"><h2>Transfers</h2><p class="zc-lead">Recent imports into this site. Finished imports keep backups for rollback until you clean them up.</p>';
        if(isset($_GET['zoer_cleanup']))echo '<div class="notice notice-success inline"><p>Import backups cleaned up.</p></div>';
        if(isset($_GET['zoer_adopted']))echo '<div class="notice notice-success inline"><p>The import now continues with the current key. Paste that key into Zoer to resume or roll it back.</p></div>';
        TransferRetention::render();
        if(!$imports){echo '<p class="zc-empty">No imports recorded.</p></section>';return;}
        $supported=self::supported();$current=self::currentOwner();
        try{$owners=self::unfinished(Plugin::storageRoot());}catch(\Throwable $e){$owners=[];}
        echo '<div class="zc-table-wrap"><table class="widefat striped"><caption class="screen-reader-text">Recent imports</caption><thead><tr><th scope="col">Import</th><th scope="col">Kind</th><th scope="col">Phase</th><th scope="col">Created</th><th scope="col">Destination</th><th scope="col">Retained storage</th><th scope="col">Backups</th></tr></thead><tbody>';
        foreach($imports as $i){
            $tone=in_array($i['phase'],['complete'],true)?'is-ok':(in_array($i['phase'],self::TERMINAL,true)?'is-off':'is-warn');
            echo '<tr><td><code>'.esc_html(substr($i['id'],0,12)).'</code></td><td>'.esc_html($i['kind']).'</td><td><span class="zc-badge '.$tone.'">'.esc_html(str_replace('_',' ',$i['phase'])).'</span></td><td class="zc-nowrap">'.esc_html($i['createdAt']).'</td><td>'.esc_html($i['target']).'</td><td>';
            $size=$i['cleanedUp']?['bytes'=>0,'complete'=>true]:TransferRetention::size($i['id']);
            echo $size?esc_html(($size['complete']?'':'At least ').TransferRetention::format($size['bytes'])):'Not measured';
            if($size&&isset($size['measuredAt']))echo '<br><small>'.esc_html($size['measuredAt']).'</small>';
            echo '</td><td>';
            if($i['cleanedUp'])echo 'Cleaned up';
            elseif(!in_array($i['phase'],self::TERMINAL,true)){
                echo 'Retained (import active)';
                // Created with an earlier key: only an administrator can hand it to the current one.
                if($current!==null&&isset($owners[$i['id']])&&!hash_equals($current,$owners[$i['id']])&&method_exists(TransferImport::class,'adminAdopt')){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Continue this import with the current key? The previous key can no longer resume or roll it back.\')">';wp_nonce_field('zoer_import_adopt');echo '<input type="hidden" name="action" value="zoer_import_adopt"><input type="hidden" name="import_id" value="'.esc_attr($i['id']).'"><button class="button">Continue with the current key</button></form>';}
            }
            elseif(!$supported)echo 'Retained';
            else{echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Delete this import\\u2019s backups? Rollback will no longer be possible.\')">';wp_nonce_field('zoer_import_cleanup');echo '<input type="hidden" name="action" value="zoer_import_cleanup"><input type="hidden" name="import_id" value="'.esc_attr($i['id']).'"><button class="button">Clean up backups</button></form>';}
            echo '</td></tr>';
        }
        echo '</tbody></table></div><details class="zc-more"><summary>Learn more</summary><p>Cleanup permanently deletes an import’s original tables and file backups, so it can no longer be rolled back. Only completed, rolled back or cancelled imports can be cleaned up.</p><p>An unfinished import can be resumed or rolled back only with the key it was created with. After a key was revoked or replaced, “Continue with the current key” lets the new key recover it.</p></details></section>';
    }
}
