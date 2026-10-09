<?php
require __DIR__.'/../includes/WriteFence.php';
use ZoerConnect\WriteFence;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejects($fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Unsafe fence operation accepted.');}
$base=sys_get_temp_dir().'/zoer-fence-'.bin2hex(random_bytes(8));mkdir($base,0700);mkdir($base.'/private',0700);mkdir($base.'/public/wp-content',0755,true);
$id=str_repeat('a',32);$owner=hash('sha256','generation:source:destination');
$f=new WriteFence($base.'/private',$base.'/public');
try{
 rejects(fn()=>$f->reserve($id,$owner));
 file_put_contents($base.'/public/wp-content/object-cache.php','<?php');rejects(fn()=>$f->install());unlink($base.'/public/wp-content/object-cache.php');
 $f->install();check($f->installed(),'Installed fence not verified.');$f->install();
 // A separate ordinary PHP process retains its shared lease across ticks.
 $script=$base.'/request.php';
 file_put_contents($script,'<?php require '.var_export(realpath(__DIR__.'/../includes/WriteFence.php'),true).'; $f=new \\ZoerConnect\\WriteFence('.var_export($base.'/private',true).','.var_export($base.'/public',true).'); $f->enter(); echo "READY\\n"; fflush(STDOUT); fgets(STDIN);');
 $pipes=[];$proc=proc_open([PHP_BINARY,$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 check(is_resource($proc)&&trim(fgets($pipes[1]))==='READY','Could not start request lease.');
 $f->reserve($id,$owner);$f->reserve($id,$owner);
 rejects(fn()=>$f->exclusive($id,$owner,fn()=>true));
 rejects(fn()=>$f->reserve(str_repeat('b',32),$owner));
 rejects(fn()=>$f->exclusive($id,hash('sha256','revoked generation'),fn()=>true));
 rejects(fn()=>$f->enter());
 fwrite($pipes[0],"finish\n");fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Request failed.');
 check($f->exclusive($id,$owner,fn()=>42)===42,'Drain did not permit exclusive recovery.');
 $f=new WriteFence($base.'/private',$base.'/public');rejects(fn()=>$f->enter()); // surviving process death/new request
 // Exercise the actual generated MU file in fresh processes. The stub models
 // the compulsory native-key gate; actual WordPress authentication is integration coverage.
 mkdir($base.'/public/wp-content/plugins/zoer-connect/includes',0755,true);
 copy(__DIR__.'/../includes/WriteFence.php',$base.'/public/wp-content/plugins/zoer-connect/includes/WriteFence.php');
 copy(__DIR__.'/../includes/RequestDrain.php',$base.'/public/wp-content/plugins/zoer-connect/includes/RequestDrain.php');
 file_put_contents($base.'/public/wp-content/plugins/zoer-connect/includes/Plugin.php','<?php namespace ZoerConnect; final class Plugin { public static function earlyImportRecovery($route) { if(($_SERVER["HTTP_X_ZOER_CONNECTION"]??"")!=="fixture-key") { http_response_code(401); return ["denied"=>true]; } return ["recovery"=>true,"route"=>$route]; }}');
 $boot=$base.'/bootstrap-test.php';
 file_put_contents($boot,'<?php define("ABSPATH",'.var_export($base.'/public/',true).'); $_SERVER["REQUEST_URI"]=$argv[1]; $_SERVER["HTTP_X_ZOER_CONNECTION"]=$argv[2]; require '.var_export($base.'/public/wp-content/mu-plugins/000-zoer-connect-fence.php',true).'; echo "ORDINARY_PLUGIN_EXECUTED";');
 $run=function($route,$key)use($boot){$p=[];$h=proc_open([PHP_BINARY,$boot,$route,$key],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$p);fclose($p[0]);$out=stream_get_contents($p[1]);$err=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);check(proc_close($h)===0&&$err==='', 'Bootstrap failed: '.$err);return $out;};
 $route='/wp-json/zoer-connect/v1/imports/'.$id.'/rollback';
 check((json_decode($run($route,'fixture-key'),true)['recovery']??false)===true,'Early authenticated recovery did not intercept ordinary plugins.');
 check((json_decode($run($route,'revoked-key'),true)['denied']??false)===true,'Early authentication denial did not intercept ordinary plugins.');
 check((json_decode($run('/wp-cron.php',''),true)['code']??'')==='zoer_transfer_paused','Cron bypassed persistent fence.');
 check((json_decode($run('/wp-admin/admin-ajax.php',''),true)['code']??'')==='zoer_transfer_paused','Admin writer bypassed persistent fence.');
 $f->release($id,$owner);
 $f->reserveExport($id,$owner);$marker=$base.'/private/write-fence.json';$state=json_decode(file_get_contents($marker),true);check($state['kind']==='export'&&$state['expiresAt']>time(),'Source pause has bounded lifetime.');
 $f->expireExport();check(is_file($marker),'Live source pause must not expire.');$state['expiresAt']=time()-1;file_put_contents($marker,json_encode($state));$f->expireExport();check(!file_exists($marker),'Expired read-only export releases source.');
 $f->reserve($id,$owner);$state=json_decode(file_get_contents($marker),true);$state['expiresAt']=time()-1;file_put_contents($marker,json_encode($state));$f->expireExport();check(is_file($marker),'Import must never auto-release on expiry.');$f->release($id,$owner);$f->enter();
 check(WriteFence::importRoute(['REQUEST_URI'=>'/wp-json/zoer-connect/v1/imports/'.$id.'/rollback'],[])==='/zoer-connect/v1/imports/'.$id.'/rollback','Pretty recovery route missing.');
 check(WriteFence::importRoute([],['rest_route'=>'/zoer-connect/v1/imports'])==='/zoer-connect/v1/imports','Query recovery route missing.');
 foreach(['/wp-json/zoer-connect/v1/imports/../../users','/wp-json/zoer-connect/v1/imports/bad','/wp-json/zoer-connect/v1/export'] as $route)check(WriteFence::importRoute(['REQUEST_URI'=>$route],[])===null,'Broad route bypass.');
 // The generated MU file in a copy of the site at another path, and after the plugin files are removed.
 $mu=$base.'/public/wp-content/mu-plugins/000-zoer-connect-fence.php';
 $copy=$base.'/copy';mkdir($copy,0755);
 $runAt=function($abspath,$route='/')use($base,$mu){$boot=$base.'/bootstrap-at.php';file_put_contents($boot,'<?php define("ABSPATH",$argv[1]); $_SERVER["REQUEST_URI"]=$argv[2]; require '.var_export($mu,true).'; echo "ORDINARY_PLUGIN_EXECUTED";');$p=[];$h=proc_open([PHP_BINARY,$boot,$abspath,$route],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$p);fclose($p[0]);$out=stream_get_contents($p[1]);$err=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);check(proc_close($h)===0&&$err==='','Bootstrap failed: '.$err);return $out;};
 check($runAt($copy.'/')==='ORDINARY_PLUGIN_EXECUTED','A copy at another path must not load this site\'s fence.');
 check($runAt($base.'/public/')==='ORDINARY_PLUGIN_EXECUTED','Installed fence must admit ordinary requests while no transfer is paused.');
 $includes=$base.'/public/wp-content/plugins/zoer-connect/includes';rename($includes,$includes.'.removed');
 check($runAt($base.'/public/')==='ORDINARY_PLUGIN_EXECUTED','Removed plugin without a paused transfer must not break the site.');
 file_put_contents($base.'/private/write-fence.json',json_encode(['id'=>$id,'binding'=>$owner,'createdAt'=>time()]));
 $paused=json_decode($runAt($base.'/public/'),true);check(($paused['code']??'')==='zoer_transfer_paused'&&str_contains($paused['message']??'','plugin files are missing'),'Removed plugin during a paused transfer must answer 503, not run ordinary code.');
 check($runAt($copy.'/')==='ORDINARY_PLUGIN_EXECUTED','A copy at another path stays inert even when the original paths are gone and the original is paused.');
 unlink($base.'/private/write-fence.json');rename($includes.'.removed',$includes);
 // A 0.5.x bootstrap keeps working after the upgrade, is replaced by upgradeLegacy() and never during a transfer.
 $plugin=$base.'/public/wp-content/plugins/zoer-connect/includes/';$private=realpath($base.'/private');$root=realpath($base.'/public');
 $legacy=fn(bool $shared)=>"<?php\n".($shared?"// Shared-hosting cache coexistence; early cache responses are outside this fence.\n":"")."// Zoer Connect request fence: keep before ordinary plugins and MU plugins.\ndefined('ABSPATH') || exit;\nrequire_once ".var_export($realPlugin=realpath($plugin).'/WriteFence.php',true).";\n\\ZoerConnect\\WriteFence::boot(".var_export($private,true).", ".var_export($root,true).", static function (string \$route) {\n require_once ".var_export(realpath($plugin).'/Plugin.php',true).";\n return \\ZoerConnect\\Plugin::earlyImportRecovery(\$route);\n});\n";
 $current=file_get_contents($mu);
 file_put_contents($mu,$legacy(false));check($f->installed(),'A 0.5.x fence must still verify after the upgrade.');
 check($runAt($base.'/public/')==='ORDINARY_PLUGIN_EXECUTED','A 0.5.x fence must keep serving ordinary requests after the upgrade.');
 file_put_contents($base.'/private/write-fence.json',json_encode(['id'=>$id,'binding'=>$owner,'createdAt'=>time()]));
 check(!$f->upgradeLegacy()&&file_get_contents($mu)===$legacy(false),'The fence must not be replaced while a transfer holds it.');unlink($base.'/private/write-fence.json');
 check($f->upgradeLegacy()&&file_get_contents($mu)===$current&&$f->installed(),'upgradeLegacy must install the current bootstrap.');
 check(!$f->upgradeLegacy(),'The current bootstrap is not upgraded again.');
 file_put_contents($mu,$legacy(true));check($f->sharedCaches()&&$f->upgradeLegacy()&&$f->sharedCaches()&&str_contains(file_get_contents($mu),'realpath(ABSPATH)'),'The shared-cache 0.5.x fence keeps its mode when upgraded.');
 $f->install(false);check($f->installed()&&!$f->sharedCaches(),'Setup switches modes from an upgraded fence.');
 file_put_contents($mu,"<?php // someone else's file\n");rejects(fn()=>$f->install());
 echo "PASS copied sites and removed plugins leave the fence inert, a paused transfer answers 503, 0.5.x fences keep working and upgrade outside transfers\n";
 echo "PASS earliest MU installation, drop-in rejection, in-flight drain, durable fence, owner binding, recovery paths and release\n";
}finally{
 $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($walk as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($base);
}
