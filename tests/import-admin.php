<?php
foreach(['StageStore','WriteFence','ImportAdmin'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\ImportAdmin;use ZoerConnect\WriteFence;
$admin=true;$home='https://destination.example';$optionReads=[];
function current_user_can($cap){global $admin;return $cap==='manage_options'&&$admin;}
function get_option($name){global $home,$optionReads;$optionReads[]=$name;return $name==='home'?$home:true;}
function get_current_user_id(){return 7;}
function wp_verify_nonce($nonce,$action){return $nonce==='nonce:'.$action;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function denied(callable $fn,$message){try{$fn();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message);}
$base=sys_get_temp_dir().'/zoer-import-admin-'.bin2hex(random_bytes(6));mkdir($base);$root=$base.'/wordpress';$private=$base.'/private';mkdir($root);mkdir($root.'/wp-content');mkdir($private,0700);$_SERVER=['DOCUMENT_ROOT'=>$root,'REQUEST_METHOD'=>'POST'];
$install=['zoer_import_setup_action'=>'install','_wpnonce'=>'nonce:zoer_import_setup_install'];
try{
 check(!ImportAdmin::ready($private,$root),'readiness is false before explicit setup');check(!is_dir($root.'/wp-content/mu-plugins'),'readiness read does not silently install MU');
 $_POST=[];denied(fn()=>ImportAdmin::submit($private,$root),'empty POST cannot install');$_POST=$install;$_SERVER['REQUEST_METHOD']='GET';denied(fn()=>ImportAdmin::submit($private,$root),'GET cannot mutate setup');$_SERVER['REQUEST_METHOD']='POST';
 $admin=false;denied(fn()=>ImportAdmin::submit($private,$root),'non-admin cannot install');$admin=true;$_POST['_wpnonce']='wrong';denied(fn()=>ImportAdmin::submit($private,$root),'invalid nonce cannot install');
 check(!is_dir($root.'/wp-content/mu-plugins'),'rejected requests leave MU directory absent');
 $_POST=$install;ImportAdmin::submit($private,$root);check((new WriteFence($private,$root))->installed(),'explicit install writes exact earliest MU bootstrap');check(!ImportAdmin::ready($private,$root),'install alone never enables imports');
 $pending=json_decode(file_get_contents($private.'/import-setup-pending.json'),true);check(($pending['target']??null)===$home&&strlen($pending['muSha256']??'')===64,'pending setup binds target and bootstrap');
 $confirm=['zoer_import_setup_action'=>'confirm','_wpnonce'=>'nonce:zoer_import_setup_confirm','setup_id'=>$pending['setupId']];$_POST=$confirm;denied(fn()=>ImportAdmin::submit($private,$root),'confirmation requires writer-isolation acknowledgements');
 $confirm+=['no_external_writers'=>'1','single_host_local_locks'=>'1'];$_POST=$confirm;$_POST['setup_id']=str_repeat('0',32);denied(fn()=>ImportAdmin::submit($private,$root),'stale setup ID cannot enable imports');
 $_POST=$confirm;$_POST['_wpnonce']='nonce:zoer_import_setup_install';denied(fn()=>ImportAdmin::submit($private,$root),'install nonce cannot authorize confirmation');
 $_POST=$confirm;denied(fn()=>ImportAdmin::submit($private,$root),'unprotected current worker cannot enable imports');(new WriteFence($private,$root))->enter();ImportAdmin::submit($private,$root);check(ImportAdmin::ready($private,$root),'separate confirmed setup enables readiness');
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
