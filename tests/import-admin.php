<?php
foreach(['StageStore','WriteFence','ConnectionKey','ImportAdmin'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\ImportAdmin;use ZoerConnect\WriteFence;
$ssl=true;$multi=false;$connection=[];$admin=true;$home='https://destination.example';$optionReads=[];
function current_user_can($cap){global $admin;return $cap==='manage_options'&&$admin;}
function get_option($name,$default=null){global $home,$optionReads,$connection;$optionReads[]=$name;return $name==='home'?$home:($name===\ZoerConnect\ConnectionKey::OPTION?$connection:$default);}
function is_ssl(){global $ssl;return $ssl;}
function is_multisite(){global $multi;return $multi;}
function user_can($id,$cap){return $id===7;}
function get_current_user_id(){return 7;}
function wp_verify_nonce($nonce,$action){return $nonce==='nonce:'.$action;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function denied(callable $fn,$message){try{$fn();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message);}
$base=sys_get_temp_dir().'/zoer-import-admin-'.bin2hex(random_bytes(6));mkdir($base);$root=$base.'/wordpress';$private=$base.'/private';mkdir($root);mkdir($root.'/wp-content');mkdir($private,0700);$_SERVER=['DOCUMENT_ROOT'=>$root,'REQUEST_METHOD'=>'POST'];
$install=['zoer_import_setup_action'=>'install','_wpnonce'=>'nonce:zoer_import_setup_install'];
try{
 check(!ImportAdmin::ready($private,$root),'readiness is false before explicit setup');check(!is_dir($root.'/wp-content/mu-plugins'),'readiness read does not silently install MU');
 [$secret,$connection]=\ZoerConnect\ConnectionKey::create(7);
 check($connection['push']===true&&$connection['pull']===false,'new connections default to Push only');
 [$replacement,$rotated]=\ZoerConnect\ConnectionKey::create(7,['push'=>false,'pull'=>true]);
 check(!$rotated['push']&&$rotated['pull'],'rotation preserves explicit permissions');
 [$replacement,$revoked]=\ZoerConnect\ConnectionKey::create(7,['push'=>false,'pull'=>false]);
 check(!$revoked['push']&&!$revoked['pull'],'regeneration after revocation does not reenable permissions');
 $defaultPost=['zoer_connection_action'=>'rotate','_wpnonce'=>'nonce:zoer_connection_settings'];$_POST=$defaultPost;
 $_SERVER['REQUEST_METHOD']='GET';denied(fn()=>ImportAdmin::setupDefault($private,$root),'GET cannot prepare defaults');$_SERVER['REQUEST_METHOD']='POST';
 $admin=false;denied(fn()=>ImportAdmin::setupDefault($private,$root),'non-admin cannot prepare defaults');$admin=true;
 $ssl=false;denied(fn()=>ImportAdmin::setupDefault($private,$root),'HTTP cannot prepare defaults');$ssl=true;
 $multi=true;denied(fn()=>ImportAdmin::setupDefault($private,$root),'multisite cannot prepare defaults');$multi=false;
 $_POST['_wpnonce']='wrong';denied(fn()=>ImportAdmin::setupDefault($private,$root),'invalid connection nonce cannot prepare defaults');$_POST=$defaultPost;
 $connection['push']=false;denied(fn()=>ImportAdmin::setupDefault($private,$root),'disabled Push cannot prepare defaults');$connection['push']=true;
 $connection['owner']=8;denied(fn()=>ImportAdmin::setupDefault($private,$root),'demoted owner cannot prepare defaults');$connection['owner']=7;
 check(!is_dir($root.'/wp-content/mu-plugins'),'denied defaults do not write protection');
 file_put_contents($root.'/wp-content/db.php','<?php');denied(fn()=>ImportAdmin::setupDefault($private,$root),'unsupported drop-in prevents default setup');unlink($root.'/wp-content/db.php');
 ImportAdmin::setupDefault($private,$root);
 check(ImportAdmin::mode($private,$root)==='shared-replacement','connection POST prepares shared migration without another form');
 $receiptPath=$private.'/import-readiness.json';$raw=file_get_contents($receiptPath);$receipt=json_decode($raw,true);
 check($receipt['version']===4&&$receipt['setupSource']==='connection-default'&&!isset($receipt['replacementAccepted'])&&!isset($receipt['workersVerified']),'default receipt records configuration without fabricating acceptance or isolation');
 check(!file_exists($private.'/write-fence.json'),'default preparation does not pause WordPress');
 ImportAdmin::setupDefault($private,$root);check(file_get_contents($receiptPath)===$raw,'repeated setup preserves receipt');
 $home='https://other.example';ImportAdmin::setupDefault($private,$root);check(!ImportAdmin::ready($private,$root)&&file_get_contents($receiptPath)===$raw,'automatic setup does not renew stale destination receipt');$home='https://destination.example';
 file_put_contents($private.'/write-fence.json','{}');denied(fn()=>ImportAdmin::setupDefault($private,$root),'default setup leaves active recovery alone');unlink($private.'/write-fence.json');
 unlink($receiptPath);unlink($root.'/wp-content/mu-plugins/000-zoer-connect-fence.php');rmdir($root.'/wp-content/mu-plugins');$optionReads=[];
 $_POST=[];denied(fn()=>ImportAdmin::submit($private,$root),'empty POST cannot install');$_POST=$install;$_SERVER['REQUEST_METHOD']='GET';denied(fn()=>ImportAdmin::submit($private,$root),'GET cannot mutate setup');$_SERVER['REQUEST_METHOD']='POST';
 $admin=false;denied(fn()=>ImportAdmin::submit($private,$root),'non-admin cannot install');$admin=true;$_POST['_wpnonce']='wrong';denied(fn()=>ImportAdmin::submit($private,$root),'invalid nonce cannot install');
 check(!is_dir($root.'/wp-content/mu-plugins'),'rejected requests leave MU directory absent');
 $_POST=$install;ImportAdmin::submit($private,$root);check((new WriteFence($private,$root))->installed(),'explicit install writes exact earliest MU bootstrap');check(!ImportAdmin::ready($private,$root),'install alone never enables imports');
 $pendingRaw=file_get_contents($private.'/import-setup-pending.json');$_POST=$defaultPost;ImportAdmin::setupDefault($private,$root);check(!ImportAdmin::ready($private,$root)&&file_get_contents($private.'/import-setup-pending.json')===$pendingRaw,'defaults preserve unfinished advanced setup');$optionReads=[];
 $pending=json_decode($pendingRaw,true);check(($pending['target']??null)===$home&&strlen($pending['muSha256']??'')===64,'pending setup binds target and bootstrap');
 $confirm=['zoer_import_setup_action'=>'confirm','_wpnonce'=>'nonce:zoer_import_setup_confirm','setup_id'=>$pending['setupId']];$_POST=$confirm;denied(fn()=>ImportAdmin::submit($private,$root),'confirmation requires writer-isolation acknowledgements');
 $confirm+=['no_external_writers'=>'1','single_host_local_locks'=>'1'];$_POST=$confirm;$_POST['setup_id']=str_repeat('0',32);denied(fn()=>ImportAdmin::submit($private,$root),'stale setup ID cannot enable imports');
 $_POST=$confirm;$_POST['_wpnonce']='nonce:zoer_import_setup_install';denied(fn()=>ImportAdmin::submit($private,$root),'install nonce cannot authorize confirmation');
 $_POST=$confirm;denied(fn()=>ImportAdmin::submit($private,$root),'unprotected current worker cannot enable imports');(new WriteFence($private,$root))->enter();ImportAdmin::submit($private,$root);check(ImportAdmin::ready($private,$root),'separate confirmed setup enables readiness');
 $_POST=$defaultPost;ImportAdmin::setupDefault($private,$root);check(ImportAdmin::mode($private,$root)==='verified-workers','defaults preserve verified worker mode');$optionReads=[];
 $receipt=json_decode(file_get_contents($private.'/import-readiness.json'),true);check($receipt['administratorId']===7&&$receipt['workersVerified']&&!isset($receipt['phpWorkersRestarted'])&&$receipt['noExternalWriters'],'private readiness records verified workers without claiming a restart');check((fileperms($private.'/import-readiness.json')&0777)===0600,'readiness receipt is private');
 $home='https://different.example';check(!ImportAdmin::ready($private,$root),'destination URL change invalidates receipt');$home='https://destination.example';
 $mu=$root.'/wp-content/mu-plugins/000-zoer-connect-fence.php';$original=file_get_contents($mu);file_put_contents($mu,$original."\n// changed\n");check(!ImportAdmin::ready($private,$root),'changed MU bootstrap invalidates receipt');file_put_contents($mu,$original);
 file_put_contents($root.'/wp-content/object-cache.php','<?php');check(!ImportAdmin::ready($private,$root),'early drop-in added after setup invalidates readiness');unlink($root.'/wp-content/object-cache.php');
 file_put_contents($private.'/write-fence.json','{}');$_POST=$install;denied(fn()=>ImportAdmin::submit($private,$root),'setup cannot change while an import fence is active');unlink($private.'/write-fence.json');
 unlink($private.'/import-readiness.json');check(!ImportAdmin::ready($private,$root),'imported option values cannot substitute for missing private receipt');check(array_unique($optionReads)===['home'],'readiness never trusts a WordPress options flag');
 check((new WriteFence($private,$root))->installed(),'lost readiness does not uninstall recovery bootstrap');
 $_POST=$confirm;ImportAdmin::submit($private,$root);$_POST=$install;ImportAdmin::submit($private,$root);check(!ImportAdmin::ready($private,$root),'reinstall requires fresh worker verification');
 $_POST=$confirm;denied(fn()=>ImportAdmin::submit($private,$root),'old confirmation cannot replay after reinstallation');
 $shared=['zoer_import_setup_action'=>'shared','_wpnonce'=>'nonce:zoer_import_setup_shared'];$_POST=$shared;denied(fn()=>ImportAdmin::submit($private,$root),'shared mode requires explicit replacement acceptance');
 $_POST=$shared+['replacement_accepted'=>'1'];$admin=false;denied(fn()=>ImportAdmin::submit($private,$root),'shared mode requires administrator');$admin=true;
 $_POST['_wpnonce']='invalid';denied(fn()=>ImportAdmin::submit($private,$root),'shared mode requires its nonce');$_POST=$shared+['replacement_accepted'=>'1'];ImportAdmin::submit($private,$root);
 check(ImportAdmin::mode($private,$root)==='shared-replacement','shared mode works without worker declarations');
 file_put_contents($root.'/wp-content/advanced-cache.php','<?php');file_put_contents($root.'/wp-content/object-cache.php','<?php');
 check(ImportAdmin::ready($private,$root),'explicit shared mode supports cache drop-ins');
 file_put_contents($root.'/wp-content/db.php','<?php');check(!ImportAdmin::ready($private,$root),'shared mode still rejects database drop-ins');unlink($root.'/wp-content/db.php');
 denied(fn()=>(new WriteFence($private,$root))->install(),'strict setup still rejects cache drop-ins');
 unlink($root.'/wp-content/advanced-cache.php');unlink($root.'/wp-content/object-cache.php');
 $r=json_decode(file_get_contents($private.'/import-readiness.json'),true);check($r['replacementAccepted']&&!isset($r['workersVerified'])&&!isset($r['noExternalWriters']),'shared receipt never claims verified isolation');
 $home='https://other.example';check(!ImportAdmin::ready($private,$root),'shared receipt binds destination');$home='https://destination.example';
 file_put_contents($private.'/write-fence.json','{}');denied(fn()=>ImportAdmin::submit($private,$root),'shared setup cannot overwrite active recovery');unlink($private.'/write-fence.json');

}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($base);}
