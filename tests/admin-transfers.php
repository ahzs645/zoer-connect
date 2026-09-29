<?php
// Editable export profiles and the Transfers section, against the WordPress boundary fixture.
require __DIR__.'/fixtures/admin-wordpress.php';
foreach(['Selection','ExportProfile','FileExporter','ExportAdmin','TransferAdmin'] as $class)require_once __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\ExportAdmin;use ZoerConnect\TransferAdmin;
final class Redirected extends RuntimeException {}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function wp_safe_redirect($url){throw new Redirected($url);}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function outcome(callable $fn): string {try{$fn();return 'returned';}catch(Redirected $e){return 'redirect:'.$e->getMessage();}catch(Throwable $e){return 'error:'.$e->getMessage();}}
function html(callable $fn): string {ob_start();try{$fn();return ob_get_contents();}finally{ob_end_clean();}}
$base=sys_get_temp_dir().'/zoer-admin-transfers-'.bin2hex(random_bytes(6));mkdir($base);mkdir($base.'/site');mkdir($base.'/private',0700);
define('ABSPATH',$base.'/site/');$_SERVER['DOCUMENT_ROOT']=$base.'/site';$GLOBALS['fixtureOptions']['zoer_connect_storage_dir']=$base.'/private';
try{
 // Export profiles: create, edit in place, render editable forms with resource modes.
 $form=['_wpnonce'=>'fixture:zoer_export_profile','operation'=>'save','name'=>'Content','themes'=>'on','themesMode'=>'selected','themesItems'=>"child\n\nparent\n",'plugins'=>'on','pluginsMode'=>'except','pluginsItems'=>'hello.php','media'=>'on','mediaSince'=>'2025-06-01','excludes'=>"**/cache/\n"];
 $_POST=$form;check(str_starts_with(outcome([ExportAdmin::class,'save']),'redirect:'),'profile saved');
 $profiles=get_option('zoer_connect_profiles');$id=array_key_first($profiles);$p=$profiles[$id];
 check($p['themesMode']==='selected'&&$p['themesItems']===['child','parent']&&$p['pluginsMode']==='except'&&$p['pluginsItems']===['hello.php']&&$p['mediaSince']==='2025-06-01'&&end($p['excludes'])==='**/cache/','form fields stored as a normalized profile');
 $_POST=['operation'=>'save','profile_id'=>$id,'name'=>'Content v2','themes'=>'on','themesMode'=>'active','_wpnonce'=>'fixture:zoer_export_profile'];check(str_starts_with(outcome([ExportAdmin::class,'save']),'redirect:'),'profile updated');
 $profiles=get_option('zoer_connect_profiles');check(count($profiles)===1&&$profiles[$id]['name']==='Content v2'&&$profiles[$id]['themesMode']==='active'&&!isset($profiles[$id]['mediaSince'])&&!isset($profiles[$id]['pluginsMode']),'edit replaces the same profile');
 $_POST['profile_id']='missing';check(outcome([ExportAdmin::class,'save'])==='error:Profile not found.','editing an unknown profile refused');
 $_POST=$form;$_POST['_wpnonce']='bad';check(outcome([ExportAdmin::class,'save'])==='error:Invalid nonce.'&&count(get_option('zoer_connect_profiles'))===1,'profile save requires nonce');
 $_POST=$form;$_POST['pluginsMode']='some';check(str_starts_with(outcome([ExportAdmin::class,'save']),'error:Unknown plugins selection mode'),'invalid mode refused');
 $_POST=$form;check(str_starts_with(outcome([ExportAdmin::class,'save']),'redirect:'),'second profile created');
 $profiles=get_option('zoer_connect_profiles');$ids=array_keys($profiles);$second=$ids[1];
 $page=html([ExportAdmin::class,'render']);
 check(substr_count($page,'Update profile')===2&&str_contains($page,'name="profile_id" value="'.$second.'"'),'each profile has an edit form');
 check(str_contains($page,'<option value="selected" selected>')&&str_contains($page,'<option value="except" selected>')&&str_contains($page,'<option value="active" selected>'),'resource modes preselected');
 check(str_contains($page,"child\nparent</textarea>")&&str_contains($page,'value="2025-06-01"')&&str_contains($page,'**/cache/</textarea>')&&!str_contains($page,'**/.DS_Store'),'items, media date and custom exclusions prefilled without defaults');
 // Transfers: private journals listed newest first; cleanup offered only for finished imports.
 $state=static function(string $id,array $s)use($base){mkdir($base.'/private/import-'.$id,0700);file_put_contents($base.'/private/import-'.$id.'/state.json',json_encode($s+['id'=>$id,'target'=>'https://destination.example','owner'=>str_repeat('f',64)]));};
 $done=str_repeat('a',32);$active=str_repeat('b',32);$clean=str_repeat('c',32);
 $state($done,['phase'=>'complete','createdAt'=>'2026-09-01T10:00:00+00:00']);
 $state($active,['phase'=>'reading_database','createdAt'=>'2026-09-03T10:00:00+00:00']);
 $state($clean,['phase'=>'rolled_back','kind'=>'replace','createdAt'=>'2026-09-02T10:00:00+00:00','cleanedUp'=>true]);
 mkdir($base.'/private/import-'.str_repeat('d',32));file_put_contents($base.'/private/import-'.str_repeat('d',32).'/state.json','{broken');
 mkdir($base.'/elsewhere');symlink($base.'/elsewhere',$base.'/private/import-'.str_repeat('e',32));
 $list=TransferAdmin::imports($base.'/private');
 check(array_column($list,'id')===[$active,$clean,$done],'imports listed newest first; malformed and symlinked journals ignored');
 check($list[1]===['id'=>$clean,'kind'=>'replace','phase'=>'rolled_back','createdAt'=>'2026-09-02T10:00:00+00:00','target'=>'https://destination.example','cleanedUp'=>true]&&$list[2]['kind']==='transfer'&&$list[2]['cleanedUp']===false,'summary fields and 0.3.x defaults');
 require_once __DIR__.'/../includes/TransferImport.php';$supported=method_exists(\ZoerConnect\TransferImport::class,'cleanup');
 $page=html([TransferAdmin::class,'render']);
 check(str_contains($page,'<h2>Transfers</h2>')&&str_contains($page,'Retained (import active)')&&str_contains($page,'Cleaned up'),'transfer rows rendered');
 check(substr_count($page,'Clean up backups')===($supported?1:0)&&($supported?str_contains($page,'value="'.$done.'"'):true),$supported?'cleanup offered for the finished import only':'cleanup hidden until the import engine supports it');
 $_POST=['_wpnonce'=>'bad','import_id'=>$done];check(outcome([TransferAdmin::class,'cleanup'])==='error:Invalid nonce.','cleanup requires nonce');
 $GLOBALS['fixtureAdmin']=false;$_POST=['_wpnonce'=>'fixture:zoer_import_cleanup','import_id'=>$done];check(str_starts_with(outcome([TransferAdmin::class,'cleanup']),'error:Administrator'),'cleanup requires administrator');$GLOBALS['fixtureAdmin']=true;
 $_POST=['_wpnonce'=>'fixture:zoer_import_cleanup','import_id'=>'../x'];check(outcome([TransferAdmin::class,'cleanup'])==='error:Invalid import ID.','cleanup validates import ID');
 $_POST=['_wpnonce'=>'fixture:zoer_import_cleanup','import_id'=>$active];
 check(outcome([TransferAdmin::class,'cleanup'])===($supported?'error:Only completed, rolled back or cancelled imports can be cleaned up.':'error:Backup cleanup requires a newer Zoer Connect import engine.'),$supported?'active import cannot be cleaned up':'cleanup guarded before the import engine supports it');
 check(json_decode(file_get_contents($base.'/private/import-'.$active.'/state.json'),true)['phase']==='reading_database','refused cleanup leaves journals unchanged');
 // After a key rotation the administrator still cleans up a journal owned by the earlier key generation.
 $GLOBALS['wpdb']=(object)['prefix'=>'wp_'];$GLOBALS['fixtureOptions']['zoer_connect_connection']=['hash'=>str_repeat('a',64)];
 $path=$base.'/private/import-'.$done.'/state.json';file_put_contents($path,json_encode(json_decode(file_get_contents($path),true)+['tables'=>[],'artifacts'=>[],'cursor'=>0,'sourceUrl'=>'https://source.example']));
 // Cleanup conservatively refuses while any journal is unreadable; drop the malformed fixture first.
 unlink($base.'/private/import-'.str_repeat('d',32).'/state.json');rmdir($base.'/private/import-'.str_repeat('d',32));
 $_POST=['_wpnonce'=>'fixture:zoer_import_cleanup','import_id'=>$done];
 check(outcome([TransferAdmin::class,'cleanup'])==='redirect:/tools.php?page=zoer-connect&zoer_cleanup=1'&&json_decode(file_get_contents($path),true)['cleanedUp']===true&&json_decode(file_get_contents($path),true)['owner']===str_repeat('f',64),'administrator cleans up a journal from an earlier key generation');
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);}
