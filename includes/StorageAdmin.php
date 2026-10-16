<?php
namespace ZoerConnect;

/** Private storage folder and transfer quota forms (moved unchanged from Plugin::storageSettings). */
final class StorageAdmin {
    public static function render(): void {
        $error='';$notice='';
        if(isset($_POST['zoer_storage_save'])){
            check_admin_referer('zoer_connect_storage');
            try {
                if(defined('ZOER_CONNECT_STORAGE_DIR')||get_option('zoer_connect_storage_dir','')!==''||file_exists(ABSPATH.'wp-content/mu-plugins/000-zoer-connect-fence.php'))throw new \RuntimeException('Storage is already configured or import protection is installed. Keep the existing location for recovery; administrator configuration changes are required.');
                $old=Plugin::storageRoot();
                if(is_dir($old)){ $entries=@scandir($old); if($entries===false||array_diff($entries,['.','..','lock']))throw new \RuntimeException('The current storage contains transfer data. Keep its location until transfers and recovery are complete.'); }
                $path=trim((string)wp_unslash($_POST['zoer_storage_path']??''));
                if(!str_starts_with($path,'/')||strlen($path)>1000||preg_match('/[\x00-\x1f]/',$path))throw new \RuntimeException('Enter an absolute private filesystem path.');
                new StageStore($path,[ABSPATH,$_SERVER['DOCUMENT_ROOT']??'']);
                update_option('zoer_connect_storage_dir',$path,false);$notice='Private storage configured. Test the connection in Zoer and resume the Pull.';
            }catch(\Throwable $e){$error=$e instanceof StorageUnavailable ? $e->reason : $e->getMessage();}
        }
        if(isset($_POST['zoer_quota_save'])){check_admin_referer('zoer_connect_quota');try{$value=trim((string)wp_unslash($_POST['zoer_quota_bytes']??''));if(!ctype_digit($value)||(int)$value<1048576||(int)$value>68719476736)throw new \RuntimeException('Enter a quota from 1048576 to 68719476736 bytes.');if(defined('ZOER_CONNECT_TRANSFER_QUOTA_BYTES'))throw new \RuntimeException('The server configuration controls this quota.');update_option('zoer_connect_transfer_quota_bytes',$value,false);$notice='Transfer quota saved. Existing recovery data is preserved.';}catch(\Throwable $e){$error=$e->getMessage();}}
        $status=Plugin::storageStatus();$configurable=!defined('ZOER_CONNECT_STORAGE_DIR')&&get_option('zoer_connect_storage_dir','')==='';
        $quota=TransferStorage::quota();$quotaLocked=defined('ZOER_CONNECT_TRANSFER_QUOTA_BYTES');
        echo '<section class="zc-card" id="zoer-storage" aria-labelledby="zoer-storage-title"><h2 id="zoer-storage-title">Private storage '.($status['ready']?'<span class="zc-badge is-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>Ready</span>':'<span class="zc-badge is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span>Unavailable</span>').'</h2>';
        if($notice)echo '<div class="notice notice-success inline"><p>'.esc_html($notice).'</p></div>';
        if($error)echo '<div class="notice notice-error inline"><p>'.esc_html($error).'</p></div>';
        echo '<p class="zc-lead">'.($status['ready']?'Transfers are staged in a private folder outside the public website.':esc_html($status['message'])).'</p>';
        if($configurable){
            echo '<form method="post" class="zc-inline-form">';
            wp_nonce_field('zoer_connect_storage');
            echo '<label for="zoer-storage-path">Private folder path <span class="zc-help" id="zoer-storage-path-help">An absolute path outside every public web folder. Existing transfers are not moved.</span><input type="text" class="regular-text code" id="zoer-storage-path" name="zoer_storage_path" required autocomplete="off" spellcheck="false" aria-describedby="zoer-storage-path-help"></label> <button class="button'.($status['ready']?'':' button-primary').'" name="zoer_storage_save" value="1">Validate and save folder</button></form>';
        }
        echo '<details class="zc-advanced"><summary>Storage details and transfer limit</summary>';
        echo '<dl class="zc-meta"><dt>Status</dt><dd><code>'.esc_html($status['code']).'</code></dd><dt>Current path</dt><dd><code>'.esc_html(Plugin::storageRoot()).'</code></dd><dt>PHP restriction</dt><dd><code>'.esc_html(ini_get('open_basedir')?:'not configured').'</code></dd></dl>';
        echo '<details class="zc-more"><summary>Learn more</summary><p>Staged files must stay outside the public document root; a private sibling folder is used where permitted. A server administrator can set ZOER_CONNECT_STORAGE_DIR in wp-config.php instead. If PHP cannot reach any private folder, ask your host for one.</p><p>Only one staging job is reserved at a time; cancel it through the API to remove its files. Deactivation and uninstall keep staged data. The transfer limit applies per transfer; free space and recovery storage are checked separately.</p></details>';
        echo '<h3 id="zoer-quota-title">Transfer size limit</h3><form method="post" class="zc-inline-form">';wp_nonce_field('zoer_connect_quota');
        echo '<label for="zoer-quota-bytes">Maximum per transfer, in bytes <span class="zc-help" id="zoer-quota-help">Currently '.esc_html(self::bytes($quota)).'. Single files are limited to 4 GiB.</span><input type="number" id="zoer-quota-bytes" name="zoer_quota_bytes" min="1048576" max="68719476736" value="'.esc_attr((string)$quota).'" aria-describedby="zoer-quota-help"'.($quotaLocked?' disabled':'').'></label> <button class="button" name="zoer_quota_save" value="1"'.($quotaLocked?' disabled':'').'>Save limit</button></form>';
        if($quotaLocked)echo '<p class="zc-help">Set by the server configuration.</p>';
        echo '</details></section>';
    }
    private static function bytes(int $bytes): string {
        foreach(['GiB'=>1073741824,'MiB'=>1048576] as $unit=>$size)if($bytes>=$size)return rtrim(rtrim(number_format($bytes/$size,1,'.',''),'0'),'.').' '.$unit;
        return $bytes.' bytes';
    }
}
