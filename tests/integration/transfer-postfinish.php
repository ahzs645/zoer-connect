<?php
// Run with plain PHP on the transfer peer. SHORTINIT keeps this controller outside
// normal request leases, modeling the protected early recovery channel.
define('SHORTINIT',true);require '/var/www/html/wp-load.php';
require '/var/www/html/wp-content/plugins/zoer-connect/includes/TransferImport.php';
use ZoerConnect\TransferImport;use ZoerConnect\StageStore;
$private='/var/www/.zoer-connect';$target=$wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='home'");
if($target!=='http://zoer-connect-transfer-peer.ddev.site:8080')throw new RuntimeException('Transfer peer only.');
$record=unserialize($wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='zoer_connect_connection'"),['allowed_classes'=>false]);
$new=fn()=>new TransferImport($wpdb,'/var/www/html',$private,$record['hash'],$target,true);
$source=$private.'/acceptance-source.sql';$hashes=[];$h=fopen($source,'rb');while(!feof($h)){$data=fread($h,StageStore::CHUNK);if($data!=='')$hashes[]=hash('sha256',$data);}fclose($h);
$body=['id'=>bin2hex(random_bytes(16)),'target'=>$target,'sourceUrl'=>'https://transfer-source.example','sourcePrefix'=>$wpdb->prefix,'destinationAdminId'=>$record['owner'],'wordpressOnlyWriters'=>true,'database'=>['bytes'=>filesize($source),'sha256'=>hash_file('sha256',$source),'chunkSha256'=>$hashes],'files'=>[]];
$original=[];$names=['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options','links'];
foreach($names as $suffix)$original[$suffix]=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$suffix` ORDER BY 1",ARRAY_A)));
$s=$new()->create($body);$h=fopen($source,'rb');$offset=0;while(!feof($h)){$data=fread($h,StageStore::CHUNK);if($data!==''){$new()->chunk($s['id'],0,$offset,$data);$offset+=strlen($data);}}fclose($h);
$description=null;
try{
 for($i=0;$i<3000;$i++){$s=$new()->step($s['id']);if($s['phase']==='verification_required')break;}
 $s=$new()->finish($s['id']);if($s['phase']!=='complete')throw new RuntimeException('Completion failed.');
 $wpdb->replace($wpdb->options,['option_name'=>'_transient_zoer_postfinish_probe','option_value'=>'recreated cache','autoload'=>'off']);
 $description=$wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='blogdescription'");
 $wpdb->update($wpdb->options,['option_value'=>$description.' intentional later edit'],['option_name'=>'blogdescription']);
 $after=[];foreach($names as $suffix)$after[$suffix]=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$suffix` ORDER BY 1",ARRAY_A)));
 $refused=false;for($i=0;$i<3000;$i++){try{$s=$new()->rollback($s['id']);}catch(Throwable $e){$refused=true;break;}}
 if(!$refused||is_file($private.'/write-fence.json'))throw new RuntimeException('Changed destination was overwritten or stranded behind a fence.');
 foreach($after as $suffix=>$hash)if(hash('sha256',serialize($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$suffix` ORDER BY 1",ARRAY_A)))!==$hash)throw new RuntimeException('Rollback mutated a table before global preflight finished: '.$suffix);
 echo "PASS genuine postfinish option edit refuses before ANY of ten tables change and releases fence\n";
 $wpdb->update($wpdb->options,['option_value'=>$description],['option_name'=>'blogdescription']);$description=null;
 for($i=0;$i<3000;$i++){$s=$new()->rollback($s['id']);if($s['phase']==='rolled_back')break;}
 if($s['phase']!=='rolled_back'||is_file($private.'/write-fence.json'))throw new RuntimeException('Cache-tolerant postfinish rollback did not complete.');
 foreach($original as $suffix=>$hash)if(hash('sha256',serialize($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$suffix` ORDER BY 1",ARRAY_A)))!==$hash)throw new RuntimeException('Original table bytes not restored: '.$suffix);
 echo "PASS cache regeneration tolerated; reset preflight + full ten-table postfinish rollback restores exact originals\n";
}finally{
 if($description!==null)$wpdb->update($wpdb->options,['option_value'=>$description],['option_name'=>'blogdescription']);
 if(($s['phase']??null)!=='rolled_back'){try{for($i=0;$i<3000;$i++){$s=$new()->rollback($s['id']);if($s['phase']==='rolled_back')break;}}catch(Throwable $e){echo "RECOVERY_REQUIRED; private journals retained\n";}}
}
