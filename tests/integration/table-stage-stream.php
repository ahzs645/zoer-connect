<?php
/** Run start and resume in separate WP-CLI processes on transfer-peer only. */
if(strpos(home_url(),'zoer-connect-transfer-peer.')===false)throw new RuntimeException('Disposable transfer peer required.');
require_once getenv('ZOER_TABLE_STAGE_PATH') ?: WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;
$stateFile='/tmp/zoer-table-stage-stream.json';
$mode=$args[0]??'resume';
function tscheck($ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function tsstage($db,string $table,string $id): \ZoerConnect\TableStage { return new \ZoerConnect\TableStage($db,$table,$id); }
if($mode==='start'){
 if(file_exists($stateFile))throw new RuntimeException('An owned stream fixture already exists; resume it first.');
 $id=bin2hex(random_bytes(8));$table=$wpdb->prefix.'zoer_scale_'.$id;
 file_put_contents($stateFile,json_encode(['id'=>$id,'table'=>$table]));
 tscheck($wpdb->query("CREATE TABLE `$table` (bucket VARCHAR(16) COLLATE utf8mb4_bin NOT NULL,id BIGINT UNSIGNED NOT NULL,value LONGTEXT NULL, PRIMARY KEY(bucket,id)) ENGINE=InnoDB")!==false,'Create fixture');
 // MariaDB's virtual sequence supplies a fixture beyond both former limits.
 tscheck($wpdb->query("INSERT INTO `$table` SELECT IF(seq%2=0,'alpha','beta'),9007199254740992+seq,REPEAT('x',700) FROM seq_1_to_100101")!==false,'Seed >100k rows / >64 MiB');
 tscheck(tsstage($wpdb,$table,$id)->prepareStep()===false,'Preparation must yield');
 tscheck((int)$wpdb->get_var("SELECT row_count FROM `zoer_l_$id` WHERE sequence_id=-10")===50,'One step bounded to 50 rows');
 echo "PASS initial 50-row page committed; terminate this PHP process and resume in another\n";
 return;
}
$state=json_decode(file_get_contents($stateFile),true);$id=$state['id'];$table=$state['table'];
if(!preg_match('/^[a-f0-9]{16}$/D',$id)||$table!==$wpdb->prefix.'zoer_scale_'.$id)throw new RuntimeException('Invalid fixture identity.');
$max=0.0;$counts=[];
$run=function(string $method,array $arguments=[])use($wpdb,$table,$id,&$max,&$counts){
 $calls=0;
 do {
  $t=microtime(true);$done=tsstage($wpdb,$table,$id)->$method(...$arguments);$max=max($max,microtime(true)-$t);$calls++;
  if($calls>10000)throw new RuntimeException('Fingerprint failed to converge.');
 } while(!$done);
 $counts[$method]=$calls;
};
try{
 $run('prepareStep');
 tscheck((int)$wpdb->get_var("SELECT row_count FROM `zoer_l_$id` WHERE sequence_id=-2")===100101,'Composite keyset must neither skip nor duplicate large integer keys');
 $rows=[];for($n=1;$n<=120;$n++)$rows[]=['bucket'=>'new','id'=>(string)$n,'value'=>$n===2?null:'new '.$n];
 tsstage($wpdb,$table,$id)->chunk(0,$rows);
 $run('verifyStep',[120,1]);
 tscheck($counts['verifyStep']>=3,'Stage verification must yield');
 $run('activateStep');
 tscheck((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===120,'Cutover content count');
 tscheck($counts['activateStep']>2000,'Destination verification must yield');
 $run('rollbackStep');
 tscheck((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===100101,'Restored original count');
 tscheck((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE value=REPEAT('x',700)")===100101,'Restored original content');
 tscheck($counts['rollbackStep']>2000,'Backup verification must yield');
 echo json_encode(['status'=>'PASS','rows'=>100101,'dataBytesAtLeast'=>70070100,'calls'=>$counts,'maxStepSeconds'=>$max,'crossProcessResume'=>true,'compositeLargeIntegerKeys'=>true])."\n";
}finally{
 foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
 unlink($stateFile);
}
