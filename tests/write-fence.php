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
 $f->release($id,$owner);$f->enter();
 check(WriteFence::importRoute(['REQUEST_URI'=>'/wp-json/zoer-connect/v1/imports/'.$id.'/rollback'],[])==='/zoer-connect/v1/imports/'.$id.'/rollback','Pretty recovery route missing.');
 check(WriteFence::importRoute([],['rest_route'=>'/zoer-connect/v1/imports'])==='/zoer-connect/v1/imports','Query recovery route missing.');
 foreach(['/wp-json/zoer-connect/v1/imports/../../users','/wp-json/zoer-connect/v1/imports/bad','/wp-json/zoer-connect/v1/export'] as $route)check(WriteFence::importRoute(['REQUEST_URI'=>$route],[])===null,'Broad route bypass.');
 echo "PASS earliest MU installation, drop-in rejection, in-flight drain, durable fence, owner binding, recovery paths and release\n";
}finally{
 $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($walk as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($base);
}
