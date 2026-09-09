<?php
if(strpos(home_url(),'zoer-connect-transfer-peer.')===false)throw new RuntimeException('Disposable peer required.');
require_once getenv('ZOER_TABLE_STAGE_PATH') ?: WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;
$id=bin2hex(random_bytes(8));$table=$wpdb->prefix.'zoer_preflight_'.$id;
// Delegate to the actual MariaDB connection while selecting an owned options
// fixture. Actual WordPress options/users and login state remain untouched.
$db=new class($wpdb,$table){
 public $prefix,$users,$usermeta,$options,$dbname;public bool $failRename=false;private $delegate;
 public function __construct($db,$options){$this->delegate=$db;foreach(['prefix','users','usermeta','dbname'] as $key)$this->$key=$db->$key;$this->options=$options;}
 public function __call($name,$args){if($name==='query'&&$this->failRename&&str_starts_with($args[0],'RENAME TABLE')){$this->failRename=false;throw new RuntimeException('Injected after cron copy before rename');}return $this->delegate->$name(...$args);}
 public function __get($name){return $this->delegate->$name;}
};
function pfcheck($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
function pfrun($db,$table,$id,$method,$args=[]){$n=0;do{$stage=new \ZoerConnect\TableStage($db,$table,$id,true);$done=$stage->$method(...$args);if(++$n>100)throw new RuntimeException('Not converging');}while(!$done);return $n;}
try{
 pfcheck($wpdb->query("CREATE TABLE `$table` (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,option_name VARCHAR(191) NOT NULL,option_value LONGTEXT NOT NULL,autoload VARCHAR(20) NOT NULL,PRIMARY KEY(option_id),UNIQUE KEY option_name(option_name)) ENGINE=InnoDB")!==false,'Fixture creation');
 $values=['home'=>'http://fixture.test','siteurl'=>'http://fixture.test','active_plugins'=>serialize([]),'business'=>'original','cron'=>serialize(['original-runtime-queue']),'_transient_original'=>'cache'];
 for($i=0;$i<105;$i++)$values['setting_'.$i]='stable';
 foreach($values as $key=>$value)$wpdb->insert($table,['option_name'=>$key,'option_value'=>$value,'autoload'=>'yes']);
 pfrun($db,$table,$id,'prepareStep');
 $rows=$wpdb->get_results("SELECT * FROM `$table` ORDER BY option_id",ARRAY_A);
 foreach($rows as &$row)if($row['option_name']==='business')$row['option_value']='published';unset($row);
 $stage=new \ZoerConnect\TableStage($db,$table,$id,true);$stage->chunk(0,$rows);
 pfrun($db,$table,$id,'verifyStep',[count($rows),1]);pfrun($db,$table,$id,'activateStep');
 $wpdb->insert($table,['option_name'=>'_transient_generated','option_value'=>'cache after finish','autoload'=>'no']);
 $wpdb->insert($table,['option_name'=>'_site_transient_generated','option_value'=>'network cache','autoload'=>'no']);
 $runtimeCron=serialize(['current-runtime-queue'=>['hook'=>'wp_privacy_delete_old_export_files']]);
 $wpdb->update($table,['option_value'=>$runtimeCron],['option_name'=>'cron']);
 $stage->resetRollbackPreflight();pfcheck(pfrun($db,$table,$id,'rollbackCheckStep')>1,'Preflight must be incremental');
 pfcheck($wpdb->get_var("SELECT option_value FROM `$table` WHERE option_name='business'")==='published','Preflight must not restore');
 pfcheck($wpdb->get_var("SELECT phase FROM `zoer_l_$id` WHERE sequence_id=-1")==='activated','Preflight must not change phase');
 // A new fence attempt must invalidate prior successful scan caches.
 $wpdb->update($table,['option_value'=>'edited after finish'],['option_name'=>'business']);$stage->resetRollbackPreflight();
 $rejected=false;try{pfrun($db,$table,$id,'rollbackCheckStep');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'edited after publication');}
 pfcheck($rejected,'Real content change must reject despite benign caches');
 pfcheck($wpdb->get_var("SELECT option_value FROM `$table` WHERE option_name='business'")==='edited after finish','Rejected preflight must preserve edits');
 pfcheck($wpdb->get_var("SELECT option_value FROM `zoer_b_$id` WHERE option_name='business'")==='original','Rejected preflight must preserve backup');
 $wpdb->update($table,['option_value'=>'published'],['option_name'=>'business']);$stage->resetRollbackPreflight();
 pfrun($db,$table,$id,'rollbackCheckStep');$db->failRename=true;$interrupted=false;
 try{$stage->rollbackStep();}catch(RuntimeException $e){$interrupted=str_contains($e->getMessage(),'after cron copy');}
 pfcheck($interrupted,'Copy-before-swap interruption exercised');
 pfcheck($wpdb->get_var("SELECT option_value FROM `$table` WHERE option_name='business'")==='published','Interrupted copy must not swap');
 pfcheck($wpdb->get_var("SELECT option_value FROM `zoer_b_$id` WHERE option_name='cron'")===$runtimeCron,'Current queue copied durably before swap');
 $stage=new \ZoerConnect\TableStage($db,$table,$id,true);pfcheck($stage->rollbackStep(),'Checked rollback should resume after queue copy');
 pfcheck($wpdb->get_var("SELECT option_value FROM `$table` WHERE option_name='business'")==='original','Rollback content');
 pfcheck($wpdb->get_var("SELECT option_value FROM `$table` WHERE option_name='cron'")===$runtimeCron,'Rollback must retain current runtime cron queue');
 echo "PASS current runtime cron retained; incremental non-mutating rollback preflight, regenerated transient tolerance, exact row counts, fresh-cache invalidation, real content edit rejection and restore\n";
}finally{foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");}
