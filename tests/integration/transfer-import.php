<?php
// Invoke once via wp eval-file on the disposable peer BEFORE installing its MU fence.
// Every table/file mutation is guarded by TransferImport's exact peer identity check.
require_once WP_PLUGIN_DIR.'/zoer-connect/includes/TransferImport.php';
require_once WP_PLUGIN_DIR.'/zoer-connect/includes/DatabaseExporter.php';
require_once WP_PLUGIN_DIR.'/zoer-connect/includes/ConnectionKey.php';
use ZoerConnect\TransferImport;
use ZoerConnect\DatabaseExporter;
use ZoerConnect\ConnectionKey;
use ZoerConnect\StageStore;
global $wpdb;
$target=get_option('home');
if($target!=='http://zoer-connect-transfer-peer.ddev.site:8080'||get_option('siteurl')!==$target)throw new RuntimeException('Transfer peer only.');
$private=dirname(rtrim(ABSPATH,'/')).'/.zoer-connect';new StageStore($private,[ABSPATH]);
$originalRecord=get_option(ConnectionKey::OPTION,null);
if(file_exists($private.'/acceptance-key-backup.json'))throw new RuntimeException('Previous acceptance fixture must be inspected first.');
file_put_contents($private.'/acceptance-key-backup.json',json_encode($originalRecord,JSON_THROW_ON_ERROR));chmod($private.'/acceptance-key-backup.json',0600);
$admins=get_users(['role'=>'administrator','fields'=>'ID']);$admin=(int)($admins[0]??0);if(!$admin)throw new RuntimeException('Destination admin missing.');
[$secret,$record]=ConnectionKey::create($admin);$record['push']=true;$record['pull']=true;update_option(ConnectionKey::OPTION,$record,false);
file_put_contents($private.'/acceptance-native-key',$secret);chmod($private.'/acceptance-native-key',0600);unset($secret);
$source=$private.'/acceptance-source.sql';DatabaseExporter::write($wpdb,$source);
$chunks=[];$h=fopen($source,'rb');while(!feof($h)){$chunk=fread($h,StageStore::CHUNK);if($chunk!=='')$chunks[]=hash('sha256',$chunk);}fclose($h);
$originalUsers=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->users}` ORDER BY ID",ARRAY_A)));
$originalUsermeta=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->usermeta}` ORDER BY umeta_id",ARRAY_A)));
$originalTables=[];foreach(['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options','links'] as $suffix)$originalTables[$suffix]=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$suffix` ORDER BY 1",ARRAY_A)));
$new=fn()=>new TransferImport($wpdb,ABSPATH,$private,$record['hash'],$target,true);
$p=$new();$p->installFence();
$body=['id'=>bin2hex(random_bytes(16)),'target'=>$target,'sourceUrl'=>'https://transfer-source.example','sourcePrefix'=>$wpdb->prefix,'destinationAdminId'=>$admin,'wordpressOnlyWriters'=>true,'database'=>['bytes'=>filesize($source),'sha256'=>hash_file('sha256',$source),'chunkSha256'=>$chunks],'files'=>[]];
$upload=function($body)use($new,$source){$s=$new()->create($body);$h=fopen($source,'rb');$offset=0;while(!feof($h)){$data=fread($h,StageStore::CHUNK);if($data!==''){$new()->chunk($s['id'],0,$offset,$data);$offset+=strlen($data);}}fclose($h);return $s;};
$s=$upload($body);$active=$s['id'];$finished=false;
try{
 for($i=0;$i<3000;$i++){$s=$new()->step($active);if($s['phase']==='verification_required')break;}
 if($s['phase']!=='verification_required')throw new RuntimeException('Full database never reached verification.');
 if(count($s['tableRows'])!==10)throw new RuntimeException('Expected all ten nonidentity WordPress tables.');
 if(hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->users}` ORDER BY ID",ARRAY_A)))!==$originalUsers||hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->usermeta}` ORDER BY umeta_id",ARRAY_A)))!==$originalUsermeta)throw new RuntimeException('Administrator identity changed.');
 for($i=0;$i<3000;$i++){$s=$new()->rollback($active);if($s['phase']==='rolled_back')break;}
 if($s['phase']!=='rolled_back')throw new RuntimeException('Full database rollback failed.');
 foreach($originalTables as $suffix=>$hash)if(hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$suffix` ORDER BY 1",ARRAY_A)))!==$hash)throw new RuntimeException('Rollback bytes differ: '.$suffix);
 echo "PASS ten-table core content/settings import, preserved users/usermeta, complete verified rollback\n";
 $body['id']=bin2hex(random_bytes(16));$s=$upload($body);$active=$s['id'];
 for($i=0;$i<3000;$i++){$s=$new()->step($active);if($s['phase']==='verification_required')break;}
 $s=$new()->finish($active);$finished=true;
 if($s['phase']!=='complete'||is_file($private.'/write-fence.json'))throw new RuntimeException('Final release failed.');
 wp_cache_flush();
 if(get_option('home')!==$target||get_option('siteurl')!==$target||get_option(ConnectionKey::OPTION)['hash']!==$record['hash'])throw new RuntimeException('Destination connection/URL not preserved.');
 if(hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `{$wpdb->users}` ORDER BY ID",ARRAY_A)))!==$originalUsers)throw new RuntimeException('Retained credentials changed.');
 echo "PASS repeated ten-table import and explicit completion, destination URLs/key and administrator credential bytes preserved\n";
}finally{
 if(!$finished){try{for($i=0;$i<3000;$i++){$s=$new()->rollback($active);if($s['phase']==='rolled_back')break;}}catch(Throwable $e){echo "RECOVERY_REQUIRED; retained private journals\n";}}
}
