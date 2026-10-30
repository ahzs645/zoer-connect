<?php
namespace ZoerConnect;

final class ConnectionAdmin {
    /** IDs of unfinished imports created with this key generation. Unreadable storage holds none. */
    private static function dependentImports(string $hash): array {
        try{require_once __DIR__.'/TransferAdmin.php';return array_keys(array_filter(TransferAdmin::unfinished(Plugin::storageRoot()),static fn($owner)=>hash_equals($hash,$owner)));}catch(\Throwable $e){return [];}
    }
    public static function render(): void {
        if(!current_user_can('manage_options') || is_multisite())return;
        $record=get_option(ConnectionKey::OPTION,[]);
        if(!is_array($record))$record=[];
        $secret=null;$message='';$setupError='';$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_POST['zoer_connection_action'])){
            check_admin_referer('zoer_connection_settings');
            if(!is_ssl())wp_die('HTTPS is required to manage connection keys.');
            $action=sanitize_key(wp_unslash($_POST['zoer_connection_action']));
            // A working key that an unfinished import depends on is not replaced: only that key can
            // resume or roll it back. Revocation stays available for an exposed key.
            $pending=$action==='rotate'&&isset($record['hash'])?self::dependentImports((string)$record['hash']):[];
            if($pending){
                $error='The key was not reset: an import into this site is not finished, and only the current key can resume or roll it back. Finish or roll it back in Zoer first. If the key may be exposed, revoke it instead; after you generate a new key, use “Continue with the current key” under Transfers.';
            }elseif($action==='rotate'){
                // Push and Code are explicit choices on a new key; a reset keeps Push and Pull and
                // asks for Code again, so legacy (pre-0.10.0) code access is never carried forward silently.
                $first=!$record;
                [$secret,$record]=ConnectionKey::create(get_current_user_id(), $record ?: null, isset($_POST['allow_push']), isset($_POST['allow_code']));
                update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message=$first?'Key created.':'New key created; the previous key no longer works. Push and Pull choices are kept.';
                $message.=$record['code']?' This key can install plugin and theme code.':' This key cannot install plugin or theme code.';
            }elseif($action==='revoke'){
                // Retain disabled policy so revocation cannot fall back to legacy write access.
                $record=['push'=>false,'pull'=>false,'code'=>false];update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='Key revoked. Zoer can no longer push or pull.';
            }elseif($action==='permissions' && isset($record['hash'])){
                $record['push']=isset($_POST['allow_push']);
                $record['pull']=isset($_POST['allow_pull']);
                // Saving makes a legacy key's code access an explicit choice (Code requires Push).
                $record['code']=$record['push']&&isset($_POST['allow_code']);
                update_option(ConnectionKey::OPTION,$record,false);
                if(get_option(ConnectionKey::OPTION)!==$record)wp_die('Connection settings could not be saved.');
                $message='Permissions saved.';
            }else wp_die('Invalid connection action.');
            if(!$pending&&in_array($action,['rotate','permissions'],true) && ($record['push']??false)===true){
                try{$message.=' '.ImportAdmin::setupDefault(Plugin::storageRoot(),rtrim(ABSPATH,'/'));}
                catch(\Throwable $e){$setupError='Connection saved, but shared-hosting migration could not be prepared: '.$e->getMessage().' See Imports and Private storage below.';}
            }
        }
        $hasKey=isset($record['hash']);$off=!is_ssl();$site=untrailingslashit(home_url());$form='<form method="post" action="'.esc_url(admin_url('tools.php?page=zoer-connect')).'"';
        echo '<section class="zc-card" id="zoer-connection" aria-labelledby="zoer-connection-title"><h2 id="zoer-connection-title">Connection info</h2>';
        if($message)echo '<div class="notice notice-success inline"><p>'.esc_html($message).'</p></div>';
        if($setupError)echo '<div class="notice notice-warning inline"><p>'.esc_html($setupError).'</p></div>';
        if($error)echo '<div class="notice notice-error inline"><p>'.esc_html($error).'</p></div>';
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
        $codeChoice=static fn(bool $on)=>'<div class="zc-toggle"><input type="checkbox" id="zoer-new-code" name="allow_code" value="1" aria-describedby="zoer-new-code-help" '.checked($on,true,false).' '.disabled($off,true,false).'><label for="zoer-new-code"><strong>Also allow installing plugins and themes (Code)</strong><span id="zoer-new-code-help">Warning: this lets anyone holding the key write PHP into wp-content/plugins and wp-content/themes, which WordPress runs as code with full access to this site. Leave it off unless you push plugins or themes from Zoer. Requires Push.</span></label></div>';
        if($hasKey){
            echo $codeChoice(($record['code']??false)===true);
            echo '<button class="button" name="zoer_connection_action" value="rotate" data-zc-confirm="Reset the secret key? Zoer stops working with this site until you paste the new key." '.disabled($off,true,false).'>Reset secret key</button> ';
            echo '<button class="button zc-danger" name="zoer_connection_action" value="revoke" data-zc-confirm="Revoke the key? Zoer can no longer push to or pull from this site." '.disabled($off,true,false).'>Revoke key</button>';
        }else{
            echo '<div class="zc-toggle"><input type="checkbox" id="zoer-new-push" name="allow_push" value="1" aria-describedby="zoer-new-push-help" '.disabled($off,true,false).'><label for="zoer-new-push"><strong>Allow Push</strong><span id="zoer-new-push-help">Let Zoer upload media and replace the database here after you confirm it in Zoer, and prepare this site for imports.</span></label></div>';
            echo $codeChoice(false);
            echo '<button class="button button-primary" name="zoer_connection_action" value="rotate" '.disabled($off,true,false).'>Generate connection key</button>';
        }
        echo '</form><details class="zc-more"><summary>Learn more</summary><p>New keys start with Push, Pull and Code off unless you turn them on here. Turning on Push prepares this site for imports automatically. You still choose and confirm what to replace in Zoer.</p><p>The key works only for Zoer Connect and stops working if the administrator who created it loses access. Reset or revoke it here to disconnect. While an import into this site is unfinished, the key cannot be reset; revoking it still works.</p></details></section>';
        echo '<section class="zc-card" id="zoer-permissions" aria-labelledby="zoer-permissions-title"><h2 id="zoer-permissions-title">Permissions</h2><p class="zc-lead">Choose what Zoer may do with this site.</p>';
        if(ConnectionKey::legacyCode($record))echo '<div class="notice notice-warning inline" data-zc-legacy-code><p>Legacy key: it was created before Zoer Connect 0.10.0, when Push also allowed installing plugins and themes, so it keeps that access. Turn Code off and save, or save to keep it as an explicit choice.</p></div>';
        echo $form.'>';
        wp_nonce_field('zoer_connection_settings');
        $locked=!$hasKey||$off;
        echo '<div class="zc-toggle"><input type="checkbox" role="switch" class="zc-switch" id="zoer-allow-pull" name="allow_pull" aria-describedby="zoer-allow-pull-help" '.checked(($record['pull']??false)===true,true,false).' '.disabled($locked,true,false).'><label for="zoer-allow-pull"><strong>Pull</strong><span id="zoer-allow-pull-help">Let Zoer copy files and the database from this site.</span></label></div>';
        echo '<div class="zc-toggle"><input type="checkbox" role="switch" class="zc-switch" id="zoer-allow-push" name="allow_push" aria-describedby="zoer-allow-push-help" '.checked(($record['push']??false)===true,true,false).' '.disabled($locked,true,false).'><label for="zoer-allow-push"><strong>Push</strong><span id="zoer-allow-push-help">Let Zoer replace content here after you confirm it in Zoer.</span></label></div>';
        echo '<div class="zc-toggle"><input type="checkbox" role="switch" class="zc-switch" id="zoer-allow-code" name="allow_code" aria-describedby="zoer-allow-code-help" '.checked($hasKey&&ConnectionKey::permits($record,'code'),true,false).' '.disabled($locked,true,false).'><label for="zoer-allow-code"><strong>Code</strong><span id="zoer-allow-code-help">Also let a Push install or replace plugins and themes. Warning: these are PHP files WordPress runs with full access to this site. Requires Push.</span></label></div>';
        echo '<div class="zc-actions"><button class="button button-primary" name="zoer_connection_action" value="permissions" '.disabled($locked,true,false).'>Save permissions</button>'.(!$hasKey?'<span class="zc-help">Create a connection key first.</span>':'').'</div></form>';
        echo '<details class="zc-more"><summary>Learn more</summary><p><strong>Pull</strong> downloads selected files and a database snapshot and does not change this site. Database exports contain private site data and password hashes; connector keys, application passwords and login sessions are excluded.</p><p><strong>Push</strong> lets authenticated clients stage media uploads and the database. <strong>Code</strong> additionally lets a push write plugin and theme files; without it, transfers that include plugins or themes are refused. A new destination is prepared for shared-hosting migration automatically, and publishing still needs your confirmation in Zoer.</p></details></section>';
    }
}
