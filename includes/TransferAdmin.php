<?php
namespace ZoerConnect;

/** Administrator view of private import journals, with explicit backup cleanup for finished imports.
 * Listing reads journals without the import lock or credential generation; cleanup goes through TransferImport. */
final class TransferAdmin {
    public const TERMINAL=['complete','rolled_back','cancelled'];
    public static function boot(): void {add_action('admin_post_zoer_import_cleanup',[self::class,'cleanup']);}
    /** Newest first, at most 50: [{id, kind, phase, createdAt, target, cleanedUp}]. */
    public static function imports(string $private): array {
        $root=realpath($private);if(!$root||is_link($private))return [];$out=[];
        foreach(glob($root.'/import-*',GLOB_ONLYDIR)?:[] as $dir){
            $path=$dir.'/state.json';
            if(is_link($dir)||is_link($path)||!preg_match('/^import-([a-f0-9]{32})$/D',basename($dir),$m)||!is_file($path))continue;
            $size=filesize($path);if($size===false||$size>16777216)continue;
            $s=json_decode((string)file_get_contents($path),true);if(!is_array($s))continue;
            $out[]=['id'=>$m[1],'kind'=>in_array($s['kind']??null,['transfer','replace'],true)?$s['kind']:'transfer','phase'=>is_string($s['phase']??null)?$s['phase']:'unknown','createdAt'=>is_string($s['createdAt']??null)?$s['createdAt']:'','target'=>is_string($s['target']??null)?$s['target']:'','cleanedUp'=>($s['cleanedUp']??false)===true];
        }
        usort($out,static fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt'])?:strcmp($a['id'],$b['id']));
        return array_slice($out,0,50);
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
            $record=get_option(ConnectionKey::OPTION,[]);$owner=is_array($record)&&is_string($record['hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$record['hash'])?$record['hash']:str_repeat('0',64);
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
        echo '<section><h2>Transfers</h2><p>Recent imports recorded in private storage. Finished imports keep original tables and file backups for rollback until you clean them up. Cleanup is permanent: rollback is no longer possible afterwards.</p>';
        if(isset($_GET['zoer_cleanup']))echo '<div class="notice notice-success inline"><p>Import backups cleaned up.</p></div>';
        if(!$imports){echo '<p>No imports recorded.</p></section>';return;}
        $supported=self::supported();
        echo '<table class="widefat striped"><thead><tr><th>Import</th><th>Kind</th><th>Phase</th><th>Created</th><th>Destination</th><th>Backups</th></tr></thead><tbody>';
        foreach($imports as $i){
            echo '<tr><td><code>'.esc_html(substr($i['id'],0,12)).'</code></td><td>'.esc_html($i['kind']).'</td><td>'.esc_html($i['phase']).'</td><td>'.esc_html($i['createdAt']).'</td><td>'.esc_html($i['target']).'</td><td>';
            if($i['cleanedUp'])echo 'Cleaned up';
            elseif(!in_array($i['phase'],self::TERMINAL,true))echo 'Retained (import active)';
            elseif(!$supported)echo 'Retained';
            else{echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Delete this import\\u2019s backups? Rollback will no longer be possible.\')">';wp_nonce_field('zoer_import_cleanup');echo '<input type="hidden" name="action" value="zoer_import_cleanup"><input type="hidden" name="import_id" value="'.esc_attr($i['id']).'"><button class="button">Clean up backups</button></form>';}
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
}
