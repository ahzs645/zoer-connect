<?php
require __DIR__.'/../includes/StageStore.php';require __DIR__.'/../includes/Plugin.php';
use ZoerConnect\Plugin;
$base=sys_get_temp_dir().'/zoer-storage-'.bin2hex(random_bytes(8));mkdir($base);mkdir($base.'/public');define('ABSPATH',$base.'/public/');$_SERVER['DOCUMENT_ROOT']=$base.'/public';$storage='';
function get_option($key,$default=''){global $storage;return $key==='zoer_connect_storage_dir'?$storage:$default;}
function check($value,$message){if(!$value)throw new RuntimeException($message);echo "PASS $message\n";}
try{
 $storage=$base.'/missing/private';check(Plugin::storageStatus()['code']==='parent_unavailable','missing parent has safe diagnostic');
 $storage=$base.'/public/private';check(Plugin::storageStatus()['code']==='inside_public_root','public storage rejected');
 $storage=$base.'/private';check(Plugin::storageStatus()['ready'],'private sibling accepted');
 chmod($storage,0500);check(Plugin::storageStatus()['code']==='not_writable','unwritable private storage diagnosed');chmod($storage,0700);
 symlink($storage,$base.'/link');$storage=$base.'/link';check(Plugin::storageStatus()['code']==='unsafe_path','symlink rejected');
 check(!str_contains(json_encode(Plugin::storageStatus()),$base),'native diagnostic never exposes filesystem path');
 $_SERVER['DOCUMENT_ROOT']='';check(Plugin::storageStatus()['code']==='public_root_unavailable','missing public root diagnosed');
}finally{unlink($base.'/link');rmdir($base.'/private');rmdir($base.'/public');rmdir($base);}
