<?php
// Tools → Zoer Connect page layout against the WordPress boundary fixture: readiness header,
// setup steps, tab placement, preserved form contract, scoped CSS and markup hygiene.
require __DIR__.'/fixtures/admin-wordpress.php';
foreach(['Selection','ExportProfile','FileExporter','ExportAdmin','TransferStorage','TransferAdmin','TransferImport','GitHubUpdater','AdminPage'] as $class)require_once __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\ConnectionKey;use ZoerConnect\ImportAdmin;use ZoerConnect\Plugin;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function page(array $post=[]): string {$_POST=$post;$_GET=[];$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';ob_start();try{Plugin::admin();return ob_get_contents();}finally{ob_end_clean();}}
function panel(string $html,string $id): string {
 $d=new DOMDocument();libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();
 $node=$d->getElementById('zoer-panel-'.$id);return $node?$d->saveHTML($node):'';
}
function pill(string $html,string $name): string {return preg_match('~<li class="zc-pill is-([a-z]+)">(?:(?!</li>).)*?<span class="zc-pill-name">'.preg_quote($name,'~').'</span><span class="zc-pill-state">([^<]+)</span>(?:<a class="zc-pill-fix" href="([^"]+)">)?~',$html,$m)?$m[1].'|'.$m[2].'|'.($m[3]??''):'missing';}
function balanced(string $html): bool {
 $html=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~s','',$html);$stack=[];
 preg_match_all('~<(/?)([a-z][a-z0-9]*)\b[^>]*?(/?)>~i',$html,$tags,PREG_SET_ORDER);
 foreach($tags as [$all,$close,$name,$self]){$name=strtolower($name);if($self||in_array($name,['input','br','hr','img','meta','link'],true))continue;if(!$close){$stack[]=$name;continue;}if(array_pop($stack)!==$name)return false;}
 return $stack===[];
}
$base=sys_get_temp_dir().'/zoer-admin-page-'.bin2hex(random_bytes(6));mkdir($base);$root=$base.'/wordpress';mkdir($root);mkdir($root.'/wp-content');define('ABSPATH',$root.'/');
$outside=sys_get_temp_dir().'/zoer-admin-page-private-'.bin2hex(random_bytes(6));
// The default sibling storage folder lies inside this document root, so storage starts unavailable.
$_SERVER['DOCUMENT_ROOT']=$base;$nonce='fixture:zoer_connection_settings';
try{
 $html=page();
 check(str_contains($html,'<p>Version '.Plugin::VERSION.' — ')&&substr_count($html,'<div class="zoer-connect-admin"')===1,'version line kept and one scoped page container');
 check(pill($html,'Pull')==='off|Off|#zoer-connection'&&pill($html,'Push')==='off|Off|#zoer-connection','Pull and Push pills point to key creation before a key exists');
 check(pill($html,'Imports')==='off|Not set up|#zoer-import-setup'&&pill($html,'Private storage')==='warn|Unavailable|#zoer-storage','Imports and storage pills each offer one fix');
 check(str_contains($html,'Not connected')&&substr_count($html,'<ol class="zc-steps">')===1&&substr_count(panel($html,'connection'),'<li class="is-')===3&&str_contains($html,'aria-current="step"'),'three setup steps shown while incomplete');
 $connection=panel($html,'connection');$settings=panel($html,'settings');$activity=panel($html,'activity');
 check(str_contains($connection,'id="zoer-import-setup"')&&str_contains($connection,'id="zoer-storage"')&&!str_contains($settings,'id="zoer-storage"'),'incomplete imports and storage are fixed on the Connection tab');
 check(str_contains($activity,'<h2>Transfers</h2>')&&str_contains($activity,'id="zoer-exports"')&&str_contains($settings,'Application passwords'),'activity and settings tabs hold history, exports and advanced options');
 check(str_contains($settings,'value="zoer_update_channel"')&&str_contains($settings,'Also offer release candidates')&&str_contains($settings,'fixture:zoer_update_channel'),'settings tab offers the update channel with its nonce');
 foreach(['connection','activity','settings'] as $tab)check(str_contains($html,'aria-controls="zoer-panel-'.$tab.'"')&&panel($html,$tab)!=='','tab '.$tab.' controls an existing panel');
 // Form contract: every action, field and nonce name of the previous page is still posted.
 foreach(['name="zoer_connection_action" value="rotate"','name="allow_push"','name="allow_pull"','name="zoer_connection_action" value="permissions"','name="zoer_import_setup_action" value="shared"','name="replacement_accepted" value="1"','name="zoer_import_setup_action" value="install"','name="zoer_storage_path"','name="zoer_storage_save" value="1"','name="zoer_quota_bytes"','name="zoer_quota_save" value="1"','name="action" value="zoer_export_profile"','name="themesMode"','name="pluginsItems"','name="mediaSince"','name="excludes"','value="fixture:zoer_connection_settings"','value="fixture:zoer_import_setup_shared"','value="fixture:zoer_import_setup_install"','value="fixture:zoer_connect_storage"','value="fixture:zoer_connect_quota"','value="fixture:zoer_export_profile"'] as $needle)if(!str_contains($html,$needle))throw new RuntimeException('Missing form contract: '.$needle);
 check(true,'form actions, field names and nonce actions preserved');
 check(substr_count($html,'action="/tools.php?page=zoer-connect"')===2&&substr_count($html,'action="/admin-post.php"')===2,'connection forms keep their action URL; the export and update-channel forms post to admin-post');
 // Markup hygiene: balanced tags, unique IDs, no inline style attributes, scoped CSS, labelled switches.
 check(balanced($html),'page markup is balanced');
 preg_match_all('~\sid="([^"]+)"~',$html,$ids);check(count($ids[1])===count(array_unique($ids[1])),'element IDs are unique');
 check(!preg_match('~\sstyle="~',$html),'no inline style attributes');
 preg_match('~<style>(.*?)</style>~s',$html,$css);$rules=preg_replace(['~@media[^{]*\{~','~\{[^{}]*\}~'],['','{}'],$css[1]);
 $selectors=array_filter(array_map('trim',preg_split('~[{},]~',str_replace('{}',',',$rules))));
 $unscoped=array_filter($selectors,static fn($s)=>!str_starts_with($s,'.zoer-connect-admin'));
 check($selectors&&!$unscoped,'every CSS selector is scoped to .zoer-connect-admin'.($unscoped?': '.implode(' | ',$unscoped):''));
 foreach(['zoer-allow-pull','zoer-allow-push','zoer-storage-path','zoer-quota-bytes','zoer-replacement-accepted'] as $id)if(!str_contains($html,'for="'.$id.'"'))throw new RuntimeException('Unlabelled control '.$id);
 check(str_contains($html,'role="switch"'),'permission toggles are labelled switches');
 // Storage form keeps its behavior; the card moves to Settings once ready.
 $html=page(['zoer_storage_save'=>'1','zoer_storage_path'=>$outside,'_wpnonce'=>'fixture:zoer_connect_storage']);
 check(get_option('zoer_connect_storage_dir')===$outside&&str_contains($html,'Private storage configured.'),'storage form configures the private folder');
 check(pill($html,'Private storage')==='ok|Ready|'&&str_contains(panel($html,'settings'),'id="zoer-storage"')&&str_contains(panel($html,'settings'),'Private storage configured.'),'ready storage moves to Settings with its notice');
 check(substr_count(panel($html,'connection'),'<li class="is-done"')===1,'completed step stays visible while others remain');
 // First key: Push is on, imports are prepared in the same POST and setup disappears.
 $html=page(['zoer_connection_action'=>'rotate','_wpnonce'=>$nonce]);
 check(ImportAdmin::ready($outside,$root)&&substr_count($html,'<textarea id="zoer-connection-info"')===1&&str_contains($html,'Copy connection info'),'key shown once with copy control');
 check(pill($html,'Push')==='ok|On|'&&pill($html,'Imports')==='ok|Ready|'&&pill($html,'Pull')==='off|Off|#zoer-permissions','pills reflect the POST result in the same response');
 check(!str_contains($html,'<ol class="zc-steps">')&&str_contains($html,'Connection key active')&&str_contains(panel($html,'settings'),'id="zoer-import-setup"'),'setup steps hidden once complete; imports card moves to Settings');
 $html=page();check(!str_contains($html,'zoer-connection-info"')&&str_contains($html,'Reset secret key')&&str_contains($html,'data-zc-confirm'),'reload hides the secret; reset and revoke ask for confirmation');
 // Turning Push off removes the import step from the checklist (Pull-only source sites).
 page(['zoer_connection_action'=>'permissions','_wpnonce'=>$nonce,'allow_pull'=>'on']);update_option('zoer_connect_storage_dir',$base.'/inside');
 $html=page();check(substr_count(panel($html,'connection'),'<li class="is-')===2&&pill($html,'Imports')==='off|Not set up|#zoer-import-setup'&&pill($html,'Push')==='off|Off|#zoer-permissions','import step and warning apply only while Push is on');
}finally{foreach([$base,$outside] as $dir){if(!is_dir($dir))continue;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry)$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($dir);}}
