<?php
namespace ZoerConnect;
require_once __DIR__.'/StorageAdmin.php';

/** Layout of Tools → Zoer Connect: a readiness header, setup steps while incomplete and three tabs.
 * Every section still handles its own form POST, in the original order, before any HTML is sent;
 * this class only buffers their output and places it. No form, nonce or capability check lives here. */
final class AdminPage {
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        $html=[];
        foreach(['connection'=>[ConnectionAdmin::class,'render'],'exports'=>[ExportAdmin::class,'render'],'imports'=>[ImportAdmin::class,'render'],'transfers'=>[TransferAdmin::class,'render'],'storage'=>[StorageAdmin::class,'render']] as $key=>$section){
            ob_start();
            try{$section();}finally{$html[$key]=(string)ob_get_clean();}
        }
        $state=self::state();
        echo '<div class="zoer-connect-admin" id="zoer-connect-admin"><style>'.self::CSS.'</style>';
        self::header($state);
        echo '<nav class="nav-tab-wrapper zc-tabs" role="tablist" aria-label="Zoer Connect sections">';
        foreach(['connection'=>'Connection','activity'=>'Activity','settings'=>'Settings'] as $id=>$label)echo '<a class="nav-tab" role="tab" id="zoer-tab-'.$id.'" href="#zoer-panel-'.$id.'" aria-controls="zoer-panel-'.$id.'">'.esc_html($label).'</a>';
        echo '</nav>';
        // Items that still need attention are fixed on the Connection tab; once ready they move to Settings.
        echo '<div class="zc-panel" role="tabpanel" id="zoer-panel-connection" aria-labelledby="zoer-tab-connection"><h2 class="screen-reader-text">Connection</h2>';
        self::steps($state);
        echo '<div class="zc-grid">'.$html['connection'].'</div>';
        if(!$state['imports'])echo $html['imports'];
        if(!$state['storage'])echo $html['storage'];
        echo '</div><div class="zc-panel" role="tabpanel" id="zoer-panel-activity" aria-labelledby="zoer-tab-activity"><h2 class="screen-reader-text">Activity</h2>'.$html['transfers'].$html['exports'].'</div>';
        echo '<div class="zc-panel" role="tabpanel" id="zoer-panel-settings" aria-labelledby="zoer-tab-settings"><h2 class="screen-reader-text">Settings</h2>';
        if($state['imports'])echo $html['imports'];
        if($state['storage'])echo $html['storage'];
        echo '<details class="zc-card zc-collapsible"><summary><span class="zc-summary-title">Application passwords</span> <span class="zc-summary-hint">Legacy API clients</span></summary>';
        echo '<p>API clients can also sign in with a WordPress application password. Push permission applies to them once a connection key exists.</p>';
        echo '<details class="zc-more"><summary>Learn more</summary><p>Application passwords inherit the account’s capabilities; they are not restricted to this plugin. Revoke the application password from your profile to disconnect.</p></details>';
        echo '<p><a class="button" href="'.esc_url(admin_url('profile.php#application-passwords-section')).'">Manage application passwords</a></p></details>';
        echo '</div><script>'.self::JS.'</script></div>';
    }

    /** The four items Zoer reports for a site: Pull, Push, Imports (destination import setup) and Private storage. */
    public static function state(): array {
        $record=get_option(ConnectionKey::OPTION,[]);if(!is_array($record))$record=[];
        $key=is_string($record['hash']??null);
        $storage=Plugin::storageStatus();
        return ['key'=>$key,'createdAt'=>$key&&is_string($record['createdAt']??null)?$record['createdAt']:'','pull'=>$key&&ConnectionKey::permits($record,'pull'),'push'=>$key&&ConnectionKey::permits($record,'push'),'imports'=>ImportAdmin::ready(Plugin::storageRoot(),ABSPATH),'storage'=>$storage['ready'],'storageMessage'=>$storage['message']];
    }

    private static function header(array $s): void {
        $created=preg_match('/^\d{4}-\d{2}-\d{2}/',$s['createdAt'],$m)?' · created '.$m[0]:'';
        echo '<div class="zc-header"><div class="zc-conn '.($s['key']?'is-on':'is-off').'"><span class="dashicons '.($s['key']?'dashicons-admin-links':'dashicons-editor-unlink').'" aria-hidden="true"></span><p><strong>'.($s['key']?'Connection key active':'Not connected').'</strong><span>'.esc_html(untrailingslashit(home_url()).$created).'</span></p></div>';
        $keyFix=$s['key']?['#zoer-permissions','Turn on']:['#zoer-connection','Create key'];
        $items=[
            ['Pull',$s['pull']?'ok':'off',$s['pull']?'On':'Off',$s['pull']?null:$keyFix],
            ['Push',$s['push']?'ok':'off',$s['push']?'On':'Off',$s['push']?null:$keyFix],
            ['Imports',$s['imports']?'ok':($s['push']?'warn':'off'),$s['imports']?'Ready':'Not set up',$s['imports']?null:['#zoer-import-setup','Set up']],
            ['Private storage',$s['storage']?'ok':'warn',$s['storage']?'Ready':'Unavailable',$s['storage']?null:['#zoer-storage','Fix']],
        ];
        $icons=['ok'=>'dashicons-yes-alt','off'=>'dashicons-marker','warn'=>'dashicons-warning'];
        echo '<ul class="zc-pills" aria-label="Readiness reported to Zoer">';
        foreach($items as [$name,$tone,$label,$fix]){
            echo '<li class="zc-pill is-'.$tone.'"><span class="dashicons '.$icons[$tone].'" aria-hidden="true"></span><span class="zc-pill-name">'.esc_html($name).'</span><span class="zc-pill-state">'.esc_html($label).'</span>';
            if($fix)echo '<a class="zc-pill-fix" href="'.esc_attr($fix[0]).'">'.esc_html($fix[1]).'<span class="screen-reader-text"> '.esc_html($name).'</span></a>';
            echo '</li>';
        }
        echo '</ul></div>';
    }

    /** At most three steps, shown only while one still applies and is incomplete. */
    private static function steps(array $s): void {
        $steps=[['Create a connection key','Paste it into Zoer to link this site.',$s['key'],'#zoer-connection','Create key'],['Make private storage available','Transfers are staged in a folder outside the public website.',$s['storage'],'#zoer-storage','Choose folder']];
        // New keys turn on Push, so import setup applies until Push is turned off.
        if(!$s['key']||$s['push'])$steps[]=['Set up imports','Lets Zoer push content to this site.',$s['imports'],'#zoer-import-setup','Set up'];
        if(!in_array(false,array_column($steps,2),true))return;
        echo '<section class="zc-card zc-setup" aria-labelledby="zoer-setup-title"><h2 id="zoer-setup-title">Finish setup</h2><ol class="zc-steps">';
        $current=false;
        foreach($steps as $i=>[$title,$hint,$done,$href,$action]){
            $isCurrent=!$done&&!$current;$current=$current||$isCurrent;
            echo '<li class="'.($done?'is-done':($isCurrent?'is-current':'is-todo')).'"'.($isCurrent?' aria-current="step"':'').'><span class="zc-step-n" aria-hidden="true">'.($done?'<span class="dashicons dashicons-yes"></span>':($i+1)).'</span><span class="zc-step-text"><strong>'.esc_html($title).'</strong><span class="screen-reader-text">'.($done?' (done)':' (to do)').'</span><span class="zc-step-hint">'.esc_html($hint).'</span></span>';
            if(!$done)echo '<a class="button'.($isCurrent?' button-primary':'').'" href="'.esc_attr($href).'">'.esc_html($action).'<span class="screen-reader-text"> '.esc_html($title).'</span></a>';
            echo '</li>';
        }
        echo '</ol></section>';
    }

    private const CSS = <<<'CSS'
.zoer-connect-admin{--zc-border:#dcdcde;--zc-muted:#50575e;--zc-ok-bg:#edfaef;--zc-ok-fg:#005c12;--zc-ok-bd:#68de7c;--zc-warn-bg:#fcf9e8;--zc-warn-fg:#614200;--zc-warn-bd:#dba617;--zc-off-bg:#f6f7f7;--zc-off-fg:#50575e;--zc-off-bd:#c3c4c7;max-width:1040px;margin-top:12px}
.zoer-connect-admin *,.zoer-connect-admin *::before,.zoer-connect-admin *::after{box-sizing:border-box}
.zoer-connect-admin .zc-header{display:flex;flex-wrap:wrap;align-items:center;gap:12px 24px;background:#fff;border:1px solid var(--zc-border);border-radius:8px;padding:16px 20px;margin:0 0 16px}
.zoer-connect-admin .zc-conn{display:flex;align-items:center;gap:12px;flex:1 1 260px;min-width:0}
.zoer-connect-admin .zc-conn p{margin:0;display:flex;flex-direction:column;min-width:0}
.zoer-connect-admin .zc-conn strong{font-size:15px}
.zoer-connect-admin .zc-conn p span{color:var(--zc-muted);overflow-wrap:anywhere}
.zoer-connect-admin .zc-conn>.dashicons{width:36px;height:36px;font-size:22px;line-height:36px;text-align:center;border-radius:50%;flex:none}
.zoer-connect-admin .zc-conn.is-on>.dashicons{background:var(--zc-ok-bg);color:var(--zc-ok-fg)}
.zoer-connect-admin .zc-conn.is-off>.dashicons{background:var(--zc-off-bg);color:var(--zc-off-fg)}
.zoer-connect-admin .zc-pills{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}
.zoer-connect-admin .zc-pill{display:inline-flex;align-items:center;gap:6px;margin:0;padding:4px 6px 4px 10px;min-height:32px;border:1px solid;border-radius:999px;font-size:13px;line-height:1.3}
.zoer-connect-admin .zc-pill .dashicons{font-size:16px;width:16px;height:16px}
.zoer-connect-admin .zc-pill-name{font-weight:600}
.zoer-connect-admin .zc-pill-state{padding-right:4px}
.zoer-connect-admin .zc-pill.is-ok{background:var(--zc-ok-bg);color:var(--zc-ok-fg);border-color:var(--zc-ok-bd)}
.zoer-connect-admin .zc-pill.is-warn{background:var(--zc-warn-bg);color:var(--zc-warn-fg);border-color:var(--zc-warn-bd)}
.zoer-connect-admin .zc-pill.is-off{background:var(--zc-off-bg);color:var(--zc-off-fg);border-color:var(--zc-off-bd)}
.zoer-connect-admin .zc-pill-fix{display:inline-block;padding:2px 10px;border-radius:999px;background:#fff;border:1px solid currentColor;color:inherit;font-weight:600;text-decoration:none}
.zoer-connect-admin .zc-pill-fix:hover{background:#f0f0f1}
.zoer-connect-admin .zc-tabs{margin:0 0 16px;padding-top:0}
.zoer-connect-admin .zc-tabs .nav-tab{margin-left:0;margin-right:6px}
.zoer-connect-admin.zc-js .zc-panel[hidden]{display:none}
.zoer-connect-admin .zc-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;margin-bottom:16px}
@media (min-width:1100px){.zoer-connect-admin .zc-grid{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr)}}
.zoer-connect-admin .zc-card{background:#fff;border:1px solid var(--zc-border);border-radius:8px;padding:20px 24px;margin:0 0 16px}
.zoer-connect-admin .zc-grid>.zc-card{margin:0}
.zoer-connect-admin .zc-card>h2,.zoer-connect-admin .zc-card-title{margin:0 0 4px;font-size:16px;line-height:1.4;display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.zoer-connect-admin .zc-card h3{font-size:14px;margin:20px 0 8px}
.zoer-connect-admin .zc-lead{margin:0 0 16px;color:var(--zc-muted)}
.zoer-connect-admin .zc-card .notice{margin:0 0 16px}
.zoer-connect-admin .zc-card:focus,.zoer-connect-admin .zc-card:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
.zoer-connect-admin .zc-badge{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:2px 8px;border-radius:999px;border:1px solid}
.zoer-connect-admin .zc-badge .dashicons{font-size:14px;width:14px;height:14px}
.zoer-connect-admin .zc-badge.is-ok{background:var(--zc-ok-bg);color:var(--zc-ok-fg);border-color:var(--zc-ok-bd)}
.zoer-connect-admin .zc-badge.is-warn{background:var(--zc-warn-bg);color:var(--zc-warn-fg);border-color:var(--zc-warn-bd)}
.zoer-connect-admin .zc-badge.is-off{background:var(--zc-off-bg);color:var(--zc-off-fg);border-color:var(--zc-off-bd)}
.zoer-connect-admin .zc-keybox{display:flex;flex-direction:column;gap:2px;padding:12px 14px;background:#f6f7f7;border:1px solid var(--zc-border);border-radius:6px;margin:0 0 12px;font-family:Consolas,Monaco,monospace;font-size:13px;overflow-wrap:anywhere}
.zoer-connect-admin .zc-keybox .zc-secret{color:var(--zc-muted)}
.zoer-connect-admin .zc-field-label{display:block;font-weight:600;margin:0 0 6px}
.zoer-connect-admin textarea.zc-key{display:block;width:100%;margin:0 0 10px;padding:10px 12px;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.5;background:#f6f7f7;resize:vertical}
.zoer-connect-admin .zc-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0 0}
.zoer-connect-admin .zc-actions .button{margin:0}
.zoer-connect-admin .zc-copy-status{color:var(--zc-muted)}
.zoer-connect-admin .button-link-delete,.zoer-connect-admin .zc-danger{color:#b32d2e;border-color:#b32d2e}
.zoer-connect-admin .zc-danger:hover,.zoer-connect-admin .zc-danger:focus{color:#8a2424;border-color:#8a2424}
.zoer-connect-admin .zc-toggle{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-top:1px solid #f0f0f1}
.zoer-connect-admin .zc-toggle:first-of-type{border-top:0}
.zoer-connect-admin .zc-toggle label{display:flex;flex-direction:column;gap:2px;cursor:pointer}
.zoer-connect-admin .zc-toggle label span{color:var(--zc-muted)}
.zoer-connect-admin input.zc-switch[type=checkbox]{-webkit-appearance:none;appearance:none;flex:none;position:relative;width:40px;height:22px;margin:1px 0 0;border:1px solid #8c8f94;border-radius:999px;background:#dcdcde;cursor:pointer;box-shadow:none;transition:background-color .15s}
.zoer-connect-admin input.zc-switch[type=checkbox]::before{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;margin:0;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:transform .15s}
.zoer-connect-admin input.zc-switch[type=checkbox]:checked{background:#2271b1;border-color:#2271b1}
.zoer-connect-admin input.zc-switch[type=checkbox]:checked::before{transform:translateX(18px);content:"";background:#fff}
.zoer-connect-admin input.zc-switch[type=checkbox]:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
.zoer-connect-admin input.zc-switch[type=checkbox]:disabled{opacity:.5;cursor:not-allowed}
@media (forced-colors:active){.zoer-connect-admin input.zc-switch[type=checkbox]{appearance:auto;-webkit-appearance:auto;width:auto;height:auto}.zoer-connect-admin input.zc-switch[type=checkbox]::before{display:none}}
@media (prefers-reduced-motion:reduce){.zoer-connect-admin *{transition:none!important}}
.zoer-connect-admin details.zc-more{margin:8px 0 0}
.zoer-connect-admin details.zc-more>summary{cursor:pointer;color:#2271b1;display:inline-block}
.zoer-connect-admin details.zc-more>summary:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
.zoer-connect-admin details.zc-more[open]>summary{margin-bottom:4px}
.zoer-connect-admin details.zc-more p,.zoer-connect-admin details.zc-more li{color:var(--zc-muted);max-width:70ch}
.zoer-connect-admin details.zc-advanced{margin:16px 0 0;border-top:1px solid #f0f0f1;padding-top:12px}
.zoer-connect-admin details.zc-advanced>summary,.zoer-connect-admin details.zc-collapsible>summary{cursor:pointer;font-weight:600;padding:4px 0}
.zoer-connect-admin details.zc-collapsible>summary{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline;font-size:14px;list-style:none}
.zoer-connect-admin details.zc-collapsible>summary::-webkit-details-marker{display:none}
.zoer-connect-admin details.zc-collapsible>summary::before{content:"";width:0;height:0;border-style:solid;border-width:5px 0 5px 7px;border-color:transparent transparent transparent currentColor;align-self:center;transition:transform .15s}
.zoer-connect-admin details.zc-collapsible[open]>summary::before{transform:rotate(90deg)}
.zoer-connect-admin details.zc-collapsible>summary:focus-visible,.zoer-connect-admin details.zc-advanced>summary:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
.zoer-connect-admin .zc-summary-hint{font-weight:400;color:var(--zc-muted)}
.zoer-connect-admin details.zc-collapsible:not([open]){padding-top:14px;padding-bottom:14px}
.zoer-connect-admin .zc-setup{border-left:4px solid #2271b1}
.zoer-connect-admin .zc-steps{list-style:none;margin:8px 0 0;padding:0;counter-reset:none}
.zoer-connect-admin .zc-steps li{display:flex;align-items:center;gap:12px;padding:10px 0;margin:0;border-top:1px solid #f0f0f1}
.zoer-connect-admin .zc-steps li:first-child{border-top:0}
.zoer-connect-admin .zc-step-n{flex:none;width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:600;border:1px solid var(--zc-off-bd);color:var(--zc-off-fg);background:#fff}
.zoer-connect-admin .is-current .zc-step-n{background:#2271b1;border-color:#2271b1;color:#fff}
.zoer-connect-admin .is-done .zc-step-n{background:var(--zc-ok-bg);border-color:var(--zc-ok-bd);color:var(--zc-ok-fg)}
.zoer-connect-admin .is-done .zc-step-text strong{color:var(--zc-muted);font-weight:400;text-decoration:line-through}
.zoer-connect-admin .zc-step-text{flex:1 1 auto;display:flex;flex-direction:column;min-width:0}
.zoer-connect-admin .zc-step-hint{color:var(--zc-muted)}
.zoer-connect-admin .zc-steps .button{flex:none;margin:0}
.zoer-connect-admin .zc-check{display:flex;gap:8px;align-items:flex-start;margin:12px 0}
.zoer-connect-admin .zc-check input{margin-top:2px}
.zoer-connect-admin .zc-inline-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px;margin:8px 0 0}
.zoer-connect-admin .zc-inline-form label{display:flex;flex-direction:column;gap:4px;font-weight:600;flex:1 1 260px;max-width:480px}
.zoer-connect-admin .zc-inline-form input[type=text],.zoer-connect-admin .zc-inline-form input[type=number],.zoer-connect-admin .zc-inline-form input:not([type]){width:100%;max-width:none}
.zoer-connect-admin .zc-inline-form .button{margin:0}
.zoer-connect-admin .zc-help{display:block;font-weight:400;color:var(--zc-muted);font-size:13px}
.zoer-connect-admin .zc-meta{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:4px 12px;margin:8px 0}
.zoer-connect-admin .zc-meta dt{color:var(--zc-muted)}
.zoer-connect-admin .zc-meta dd{margin:0;overflow-wrap:anywhere}
.zoer-connect-admin .zc-table-wrap{overflow-x:auto;margin:12px 0 0}
.zoer-connect-admin .zc-table-wrap table{min-width:640px}
.zoer-connect-admin .zc-table-wrap td,.zoer-connect-admin .zc-table-wrap th{vertical-align:middle}
.zoer-connect-admin .zc-table-wrap form{margin:0}
.zoer-connect-admin .zc-table-wrap code,.zoer-connect-admin .zc-table-wrap .zc-nowrap,.zoer-connect-admin .zc-table-wrap .zc-badge{white-space:nowrap}
.zoer-connect-admin .zc-empty{color:var(--zc-muted);margin:8px 0 0}
.zoer-connect-admin .zc-profile{border:1px solid var(--zc-border);border-radius:6px;padding:12px 16px;margin:12px 0 0}
.zoer-connect-admin .zc-profile-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.zoer-connect-admin .zc-profile-head h3{margin:0;flex:1 1 auto}
.zoer-connect-admin .zc-profile-head form{margin:0}
.zoer-connect-admin .zc-profile-form{margin:8px 0 0}
.zoer-connect-admin .zc-profile-form>label,.zoer-connect-admin .zc-profile-form fieldset>label{display:block;margin:10px 0}
.zoer-connect-admin .zc-profile-form fieldset{border:0;margin:12px 0 0;padding:0}
.zoer-connect-admin .zc-profile-form legend{font-weight:600;padding:0}
.zoer-connect-admin .zc-profile-form .zc-sub{display:block;margin:0 0 10px 26px}
.zoer-connect-admin .zc-profile-form .zc-sub label{display:block;margin:6px 0}
.zoer-connect-admin .zc-profile-form textarea{width:100%;max-width:480px}
@media (max-width:782px){
.zoer-connect-admin .zc-header{padding:14px 16px}
.zoer-connect-admin .zc-pills{display:grid;grid-template-columns:minmax(0,1fr);width:100%}
.zoer-connect-admin .zc-pill{border-radius:8px;justify-content:flex-start}
.zoer-connect-admin .zc-pill-fix{margin-left:auto}
.zoer-connect-admin .zc-card{padding:16px}
.zoer-connect-admin .zc-tabs .nav-tab{font-size:14px;padding:6px 10px}
.zoer-connect-admin .zc-steps li{flex-wrap:wrap}
.zoer-connect-admin .zc-step-text{flex:1 1 calc(100% - 40px)}
.zoer-connect-admin .zc-steps .button{margin-left:40px}
.zoer-connect-admin .zc-profile-form .zc-sub{margin-left:0}
}
CSS;

    private const JS = <<<'JS'
(function(){
var root=document.getElementById('zoer-connect-admin');if(!root)return;
var tabs=[].slice.call(root.querySelectorAll('.zc-tabs [role=tab]')),panels=tabs.map(function(t){return document.getElementById(t.getAttribute('aria-controls'));});
root.classList.add('zc-js');
function select(i,focus){tabs.forEach(function(t,j){var on=i===j;t.setAttribute('aria-selected',on?'true':'false');t.tabIndex=on?0:-1;t.classList.toggle('nav-tab-active',on);panels[j].hidden=!on;});if(focus)tabs[i].focus();}
function panelOf(el){for(var i=0;i<panels.length;i++)if(panels[i].contains(el))return i;return -1;}
tabs.forEach(function(t,i){
 t.addEventListener('click',function(e){e.preventDefault();select(i);if(history.replaceState)history.replaceState(null,'','#'+t.id.replace('zoer-tab-',''));});
 t.addEventListener('keydown',function(e){var k={ArrowRight:1,ArrowLeft:-1,Home:'first',End:'last'}[e.key];if(k===undefined)return;e.preventDefault();var n=k==='first'?0:k==='last'?tabs.length-1:(i+k+tabs.length)%tabs.length;select(n,true);});
});
function reveal(target){var p=panelOf(target);if(p<0)return false;select(p);if(target.tagName==='DETAILS')target.open=true;var d=target.closest('details');if(d)d.open=true;if(!target.hasAttribute('tabindex')&&!/^(A|BUTTON|INPUT|SELECT|TEXTAREA|SUMMARY)$/.test(target.tagName))target.setAttribute('tabindex','-1');target.scrollIntoView({block:'start'});target.focus({preventScroll:true});return true;}
root.addEventListener('click',function(e){
 var a=e.target.closest('a[href^="#zoer-"]');if(a&&!a.matches('[role=tab]')){var t=document.getElementById(a.getAttribute('href').slice(1));if(t&&reveal(t))e.preventDefault();}
 var b=e.target.closest('[data-zc-confirm]');if(b&&!window.confirm(b.getAttribute('data-zc-confirm')))e.preventDefault();
 var c=e.target.closest('[data-zc-copy]');if(c){var f=document.getElementById(c.getAttribute('data-zc-copy')),s=root.querySelector('[data-zc-copy-status]');f.focus();f.select();var done=function(m){if(s)s.textContent=m;};if(navigator.clipboard)navigator.clipboard.writeText(f.value).then(function(){done('Copied.');},function(){done('Select and copy the text above.');});else done('Select and copy the text above.');}
});
var names=tabs.map(function(t){return t.id.replace('zoer-tab-','');});
function fromHash(initial){var hash=location.hash.slice(1),named=names.indexOf(hash);if(named>=0){select(named);return true;}var t=hash.indexOf('zoer-')===0&&document.getElementById(hash);if(t&&root.contains(t))return reveal(t);if(initial){var n=root.querySelector('.zc-panel .notice');select(n&&panelOf(n)>=0?panelOf(n):0);}return false;}
fromHash(true);
window.addEventListener('hashchange',function(){fromHash(false);});
})();
JS;
}
