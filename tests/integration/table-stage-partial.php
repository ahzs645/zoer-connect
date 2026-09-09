<?php
if(strpos(home_url(),'zoer-connect-transfer-peer.')===false)throw new RuntimeException('Peer only');
require_once getenv('ZOER_TABLE_STAGE_PATH') ?: WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';global $wpdb;
foreach(['before','empty-ledger','preparing','uploading','verifying'] as $phase){
 $id=bin2hex(random_bytes(8));$table=$wpdb->prefix.'zoer_partial_'.$id;
 $wpdb->query("CREATE TABLE `$table` (id INT PRIMARY KEY,value TEXT) ENGINE=InnoDB");
 try{
  $wpdb->query("INSERT INTO `$table` SELECT seq,'old' FROM seq_1_to_101");
  $stage=new \ZoerConnect\TableStage($wpdb,$table,$id);
  if($phase==='empty-ledger')$wpdb->query("CREATE TABLE `zoer_l_$id` (sequence_id BIGINT PRIMARY KEY,digest CHAR(64) NOT NULL,row_count BIGINT NOT NULL,phase VARCHAR(24) NOT NULL,progress_json LONGTEXT NULL) ENGINE=InnoDB");
  if(in_array($phase,['preparing','uploading','verifying']))$stage->prepareStep();
  if(in_array($phase,['uploading','verifying']))while(!(new \ZoerConnect\TableStage($wpdb,$table,$id))->prepareStep()){}
  if($phase==='verifying'){
   $rows=[];for($i=1;$i<=101;$i++)$rows[]=['id'=>$i,'value'=>'new'];$stage->chunk(0,$rows);
   if($stage->verifyStep(101,1)!==false)throw new RuntimeException('verification must yield');
  }
  if(!$stage->rollbackStep()||!$stage->rollbackStep())throw new RuntimeException('Rollback incomplete '.$phase);
  if((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE value='old'")!==101)throw new RuntimeException('Original changed '.$phase);
 }finally{foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");}
}
echo "PASS repeatable rollback before preparation, after empty ledger creation, during preparation, upload and verification; original 101 rows preserved\n";
