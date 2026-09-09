<?php
if(strpos(home_url(),'zoer-connect-transfer-peer.')===false)throw new RuntimeException('Disposable peer required.');
require_once getenv('ZOER_TABLE_STAGE_PATH') ?: WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;
$id=bin2hex(random_bytes(8));$table=$wpdb->prefix.'zoer_zero_'.$id;$mode=$wpdb->get_var('SELECT @@SESSION.sql_mode');
try{
 $wpdb->query("CREATE TABLE `$table` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,value VARBINARY(20) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB");
 $stage=new \ZoerConnect\TableStage($wpdb,$table,$id);$stage->prepare();
 $without=implode(',',array_filter(explode(',',$mode),fn($m)=>$m!=='NO_AUTO_VALUE_ON_ZERO'));
 $wpdb->query($wpdb->prepare('SET SESSION sql_mode=%s',$without));
 $stage->chunk(0,[['id'=>'0','value'=>"\xff\x00"],['id'=>'1','value'=>'next']]);
 if($wpdb->get_var('SELECT @@SESSION.sql_mode')!==$without)throw new RuntimeException('SQL mode leaked after success.');
 if($wpdb->get_var("SELECT value FROM `zoer_s_$id` WHERE id=0")!=="\xff\x00"||$wpdb->get_var("SELECT value FROM `zoer_s_$id` WHERE id=1")!=='next')throw new RuntimeException('Zero primary key or binary value changed.');
 $errors=$wpdb->suppress_errors(true);$failed=false;
 try{$stage->chunk(1,[['id'=>'1','value'=>'duplicate']]);}catch(RuntimeException $e){$failed=true;}
 $wpdb->suppress_errors($errors);
 if(!$failed||$wpdb->get_var('SELECT @@SESSION.sql_mode')!==$without)throw new RuntimeException('SQL mode leaked after failed batch.');
 $stage->verify(2,1);$stage->activate();
 if($wpdb->get_var("SELECT value FROM `$table` WHERE id=0")!=="\xff\x00")throw new RuntimeException('Cutover changed zero key.');
 $stage->rollback();
 if((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")!==0)throw new RuntimeException('Original empty table not restored.');
 echo "PASS zero AUTO_INCREMENT key + binary payload, session SQL mode restored on success/failure, cutover and rollback\n";
}finally{
 $wpdb->query($wpdb->prepare('SET SESSION sql_mode=%s',$mode));
 foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
}
