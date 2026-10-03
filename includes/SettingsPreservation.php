<?php
namespace ZoerConnect;
require_once __DIR__.'/RewriteRefresh.php';
require_once __DIR__.'/CachePurge.php';

/** Preserve destination identity while replacing ordinary site options. */
final class SettingsPreservation {
    /** Destination runtime options refreshed again at cutover (see TableStage). */
    public const RUNTIME=['cron',RewriteRefresh::OPTION,CachePurge::OPTION];
    public static function apply($db, string $stage): void {
        if(!preg_match('/^zoer_s_[a-f0-9]{16}$/D',$stage))throw new \InvalidArgumentException('Invalid staging table.');
        $keys=['cron','home','siteurl','admin_email','new_admin_email','upload_path','upload_url_path','blog_public',$db->prefix.'user_roles','zoer_connect_profiles','zoer_connect_connection','zoer_connect_storage_dir','zoer_connect_transfer_quota_bytes',RewriteRefresh::OPTION,CachePurge::OPTION];
        foreach($keys as $key){
            $row=$db->get_row($db->prepare("SELECT option_name,option_value,autoload FROM `{$db->options}` WHERE option_name=%s",$key),ARRAY_A);
            if($db->delete($stage,['option_name'=>$key])===false)throw new \RuntimeException('Option preservation failed.');
            if($row && $db->insert($stage,$row)===false)throw new \RuntimeException('Option preservation failed.');
        }
        $raw=$db->get_var("SELECT option_value FROM `$stage` WHERE option_name='active_plugins'");
        $plugins=is_string($raw)?@unserialize($raw,['allowed_classes'=>false]):[];
        if(!is_array($plugins))throw new \RuntimeException('Invalid active plugin list.');
        foreach($plugins as $plugin)if(!is_string($plugin))throw new \RuntimeException('Invalid active plugin list.');
        $plugins=array_values(array_unique([...$plugins,'zoer-connect/zoer-connect.php']));
        if($db->replace($stage,['option_name'=>'active_plugins','option_value'=>serialize($plugins),'autoload'=>'yes'])===false)throw new \RuntimeException('Connector preservation failed.');
        // Transients are caches, not portable site configuration.
        if($db->query($db->prepare("DELETE FROM `$stage` WHERE option_name LIKE %s OR option_name LIKE %s",$db->esc_like('_transient_').'%', $db->esc_like('_site_transient_').'%'))===false)throw new \RuntimeException('Cache cleanup failed.');
    }
}
