<?php
namespace ZoerConnect;

final class ConnectionAdmin {
    public static function render(): void {
        if(!current_user_can('manage_options') || is_multisite())return;
        $record=get_option(ConnectionKey::OPTION,[]);
        if(!is_array($record))$record=[];
        $secret=null;$message='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_POST['zoer_connection_action'])){
            check_admin_referer('zoer_connection_settings');
            if(!is_ssl())wp_die('HTTPS is required to manage connection keys.');
            $action=sanitize_key(wp_unslash($_POST['zoer_connection_action']));
            if($action==='rotate'){
                [$secret,$record]=ConnectionKey::create(get_current_user_id());
                update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='New key created. The previous key is revoked. Copy this key now; it is shown only once. Push and Pull permissions have been turned off.';
            }elseif($action==='revoke'){
                // Retain disabled policy so revocation cannot fall back to legacy write access.
                $record=['push'=>false,'pull'=>false];update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='Connection key revoked. Remote Push and Pull requests are disabled.';
            }elseif($action==='permissions' && isset($record['hash'])){
                $record['push']=isset($_POST['allow_push']);
                $record['pull']=isset($_POST['allow_pull']);
                update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='Permissions saved.';
            }else wp_die('Invalid connection action.');
        }
        echo '<section style="max-width:880px;background:#fff;border:1px solid #c3c4c7;padding:20px;margin:20px 0"><h2>Connection info</h2>';
        if($message)echo '<div class="notice notice-success inline"><p>'.esc_html($message).'</p></div>';
        echo '<p>Site address: <code>'.esc_html(untrailingslashit(home_url())).'</code></p>';
        if($secret!==null){
            echo '<label for="zoer-connection-info">Connection info — site address and secret key</label><textarea id="zoer-connection-info" readonly rows="3" autocomplete="off" spellcheck="false" style="display:block;width:100%;max-width:100%;margin:8px 0;font-family:monospace">'.esc_textarea(untrailingslashit(home_url())."\n".$secret).'</textarea>';
            echo '<button type="button" class="button" onclick="const t=document.getElementById(\'zoer-connection-info\');t.focus();t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(()=>{this.textContent=\'Copied\';},()=>{this.textContent=\'Select and copy the text above\';});}else{this.textContent=\'Select and copy the text above\';}">Copy connection info</button>';
        }elseif(isset($record['hash']))echo '<p>Key configured. Its secret is hidden. Reset it to create a new connection code.</p>';
        else echo '<p>No connection key configured.</p>';
        if(!is_ssl())echo '<p>HTTPS is required to generate a key. Check the site’s HTTPS configuration if you reached this page over HTTPS.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('tools.php?page=zoer-connect')).'" style="margin-top:12px">';
        wp_nonce_field('zoer_connection_settings');
        echo '<button class="button" name="zoer_connection_action" value="rotate" '.disabled(!is_ssl(),true,false).'>'.(isset($record['hash'])?'Reset secret key':'Generate connection key').'</button> ';
        if(isset($record['hash']))echo '<button class="button" name="zoer_connection_action" value="revoke" '.disabled(!is_ssl(),true,false).'>Revoke key</button>';
        echo '</form><h2>Permissions</h2><form method="post" action="'.esc_url(admin_url('tools.php?page=zoer-connect')).'">';
        wp_nonce_field('zoer_connection_settings');
        echo '<p><label><input type="checkbox" name="allow_push" '.checked(($record['push']??false)===true,true,false).' '.disabled(!isset($record['hash'])||!is_ssl(),true,false).'> <strong>Push — receive files and imports</strong></label><br>Allows authenticated clients to stage files. After destination import setup is completed, also allows verified file and database replacement and recovery.</p>';
        echo '<p><label><input type="checkbox" name="allow_pull" '.checked(($record['pull']??false)===true,true,false).' '.disabled(!isset($record['hash'])||!is_ssl(),true,false).'> <strong>Pull — export files and database</strong></label><br>Allows connected Zoer clients to download selected files and a database snapshot. Database exports contain private site data and password hashes; connector keys, application passwords and login sessions are excluded. This does not change this site.</p>';
        echo '<button class="button button-primary" name="zoer_connection_action" value="permissions" '.disabled(!isset($record['hash'])||!is_ssl(),true,false).'>Save permissions</button></form>';
        echo '<p>The key is restricted to Zoer Connect endpoints and requires its creating administrator to retain access. Reset or revoke it here to disconnect. Enter connection info in Zoer to pull an export or import from a local source after destination setup. Keep the original key available while a transfer needs recovery.</p></section>';
    }
}
