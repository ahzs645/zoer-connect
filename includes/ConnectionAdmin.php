<?php
namespace ZoerConnect;

final class ConnectionAdmin {
    public static function render(): void {
        if(!current_user_can('manage_options') || is_multisite())return;
        $record=get_option(ConnectionKey::OPTION,[]);
        if(!is_array($record))$record=[];
        $secret=null;$message='';$setupError='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_POST['zoer_connection_action'])){
            check_admin_referer('zoer_connection_settings');
            if(!is_ssl())wp_die('HTTPS is required to manage connection keys.');
            $action=sanitize_key(wp_unslash($_POST['zoer_connection_action']));
            if($action==='rotate'){
                [$secret,$record]=ConnectionKey::create(get_current_user_id(), $record ?: null);
                update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='New key created; the previous key no longer works. Existing permission choices are kept.';
            }elseif($action==='revoke'){
                // Retain disabled policy so revocation cannot fall back to legacy write access.
                $record=['push'=>false,'pull'=>false];update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='Key revoked. Zoer can no longer push or pull.';
            }elseif($action==='permissions' && isset($record['hash'])){
                $record['push']=isset($_POST['allow_push']);
                $record['pull']=isset($_POST['allow_pull']);
                update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='Permissions saved.';
            }else wp_die('Invalid connection action.');
            if(in_array($action,['rotate','permissions'],true) && ($record['push']??false)===true){
                try{$message.=' '.ImportAdmin::setupDefault(Plugin::storageRoot(),rtrim(ABSPATH,'/'));}
                catch(\Throwable $e){$setupError='Connection saved, but shared-hosting migration could not be prepared: '.$e->getMessage().' See Imports and Private storage below.';}
            }
        }
        $hasKey=isset($record['hash']);$off=!is_ssl();$site=untrailingslashit(home_url());$form='<form method="post" action="'.esc_url(admin_url('tools.php?page=zoer-connect')).'"';
        echo '<section class="zc-card" id="zoer-connection" aria-labelledby="zoer-connection-title"><h2 id="zoer-connection-title">Connection info</h2>';
        if($message)echo '<div class="notice notice-success inline"><p>'.esc_html($message).'</p></div>';
        if($setupError)echo '<div class="notice notice-warning inline"><p>'.esc_html($setupError).'</p></div>';
        if($off)echo '<div class="notice notice-warning inline"><p>HTTPS is required to manage connection keys. Check this site’s HTTPS settings.</p></div>';
        if($secret!==null){
            echo '<p class="zc-lead">Copy this into Zoer now. It is shown only once.</p>';
            echo '<label class="zc-field-label" for="zoer-connection-info">Site address and secret key</label><textarea id="zoer-connection-info" class="zc-key" readonly rows="3" autocomplete="off" spellcheck="false">'.esc_textarea($site."\n".$secret).'</textarea>';
            echo '<div class="zc-actions"><button type="button" class="button button-primary" data-zc-copy="zoer-connection-info">Copy connection info</button><span class="zc-copy-status" data-zc-copy-status role="status" aria-live="polite"></span></div>';
        }elseif($hasKey){
            echo '<p class="zc-lead">Zoer uses this key to reach this site. Reset it to get a new one.</p><div class="zc-keybox" aria-label="Current connection"><span>'.esc_html($site).'</span><span class="zc-secret">Secret key hidden</span></div>';
        }else echo '<p class="zc-lead">Create a key, then paste it into Zoer to link this site.</p><div class="zc-keybox" aria-label="Site address"><span>'.esc_html($site).'</span><span class="zc-secret">No connection key yet</span></div>';
        echo $form.' class="zc-actions">';
        wp_nonce_field('zoer_connection_settings');
        if($hasKey){
            echo '<button class="button" name="zoer_connection_action" value="rotate" data-zc-confirm="Reset the secret key? Zoer stops working with this site until you paste the new key." '.disabled($off,true,false).'>Reset secret key</button> ';
            echo '<button class="button zc-danger" name="zoer_connection_action" value="revoke" data-zc-confirm="Revoke the key? Zoer can no longer push to or pull from this site." '.disabled($off,true,false).'>Revoke key</button>';
        }else echo '<button class="button button-primary" name="zoer_connection_action" value="rotate" '.disabled($off,true,false).'>Generate connection key</button>';
        echo '</form><details class="zc-more"><summary>Learn more</summary><p>New keys turn on Push and prepare this site for imports automatically; Pull stays off until you turn it on. You still choose and confirm what to replace in Zoer.</p><p>The key works only for Zoer Connect and stops working if the administrator who created it loses access. Reset or revoke it here to disconnect. Keep the original key while a transfer may need recovery.</p></details></section>';
        echo '<section class="zc-card" id="zoer-permissions" aria-labelledby="zoer-permissions-title"><h2 id="zoer-permissions-title">Permissions</h2><p class="zc-lead">Choose what Zoer may do with this site.</p>'.$form.'>';
        wp_nonce_field('zoer_connection_settings');
        $locked=!$hasKey||$off;
        echo '<div class="zc-toggle"><input type="checkbox" role="switch" class="zc-switch" id="zoer-allow-pull" name="allow_pull" aria-describedby="zoer-allow-pull-help" '.checked(($record['pull']??false)===true,true,false).' '.disabled($locked,true,false).'><label for="zoer-allow-pull"><strong>Pull</strong><span id="zoer-allow-pull-help">Let Zoer copy files and the database from this site.</span></label></div>';
        echo '<div class="zc-toggle"><input type="checkbox" role="switch" class="zc-switch" id="zoer-allow-push" name="allow_push" aria-describedby="zoer-allow-push-help" '.checked(($record['push']??false)===true,true,false).' '.disabled($locked,true,false).'><label for="zoer-allow-push"><strong>Push</strong><span id="zoer-allow-push-help">Let Zoer replace content here after you confirm it in Zoer.</span></label></div>';
        echo '<div class="zc-actions"><button class="button button-primary" name="zoer_connection_action" value="permissions" '.disabled($locked,true,false).'>Save permissions</button>'.(!$hasKey?'<span class="zc-help">Create a connection key first.</span>':'').'</div></form>';
        echo '<details class="zc-more"><summary>Learn more</summary><p><strong>Pull</strong> downloads selected files and a database snapshot and does not change this site. Database exports contain private site data and password hashes; connector keys, application passwords and login sessions are excluded.</p><p><strong>Push</strong> lets authenticated clients stage files. A new destination is prepared for shared-hosting migration automatically, and publishing still needs your confirmation in Zoer.</p></details></section>';
    }
}
