<?php
if(strpos(home_url(),'zoer-connect-transfer-peer.')===false)throw new RuntimeException('Disposable peer required.');
require_once getenv('ZOER_TABLE_STAGE_PATH') ?: WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;
$id=bin2hex(random_bytes(8));$metaid=bin2hex(random_bytes(8));$prefix=$wpdb->prefix.'za_'.$id.'_';$table=$prefix.'posts';$meta=$prefix.'postmeta';
$db=new class($wpdb,$prefix){public $prefix,$users,$usermeta,$options,$dbname;private $delegate;public function __construct($db,$prefix){$this->delegate=$db;$this->prefix=$prefix;$this->users=$prefix.'users';$this->usermeta=$prefix.'usermeta';$this->options=$prefix.'options';$this->dbname=$db->dbname;}public function __call($name,$args){return $this->delegate->$name(...$args);}public function __get($name){return $this->delegate->$name;}};
function adcheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
try{
 $wpdb->query("CREATE TABLE `$table` LIKE `{$wpdb->posts}`");$wpdb->query("CREATE TABLE `$meta` LIKE `{$wpdb->postmeta}`");
 $blank=['ID'=>'1','post_author'=>'1','post_date'=>'2026-09-08 01:00:00','post_date_gmt'=>'2026-09-08 01:00:00','post_content'=>'','post_title'=>'Auto Draft','post_excerpt'=>'','post_status'=>'auto-draft','comment_status'=>'open','ping_status'=>'open','post_password'=>'','post_name'=>'','to_ping'=>'','pinged'=>'','post_modified'=>'2026-09-08 01:00:00','post_modified_gmt'=>'2026-09-08 01:00:00','post_content_filtered'=>'','post_parent'=>'0','guid'=>'http://fixture/?p=1','menu_order'=>'0','post_type'=>'post','post_mime_type'=>'','comment_count'=>'0'];
 $original=$blank;$original['post_status']='publish';$original['post_title']='Original';$wpdb->insert($table,$original);
 $stage=new \ZoerConnect\TableStage($db,$table,$id);$stage->prepare();$published=$original;$published['post_title']='Published';$stage->chunk(0,[$published]);$stage->verify(1,1);$stage->activate();
 $metaStage=new \ZoerConnect\TableStage($db,$meta,$metaid);$metaStage->prepare();$metaStage->verify(0,0);$metaStage->activate();
 $blank['ID']='2';$wpdb->insert($table,$blank);
 $stage->resetRollbackPreflight();while(!$stage->rollbackCheckStep()){}
 adcheck($wpdb->get_var("SELECT post_title FROM `$table` WHERE ID=1")==='Published','Check must not swap');
 foreach(['post_content'=>'Written content','post_excerpt'=>'Written excerpt','post_title'=>'A real title','post_status'=>'draft','post_type'=>'revision','post_content_filtered'=>'Filtered content','post_name'=>'saved-slug','post_password'=>'secret','post_parent'=>'9','menu_order'=>'2','comment_count'=>'1'] as $field=>$value){
  $wpdb->update($table,[$field=>$value],['ID'=>2]);$stage->resetRollbackPreflight();$rejected=false;
  try{while(!$stage->rollbackCheckStep()){}}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'edited after publication');}
  adcheck($rejected,'Meaningful '.$field.' must block rollback');$wpdb->update($table,[$field=>$blank[$field]],['ID'=>2]);
 }
 $wpdb->insert($meta,['post_id'=>'2','meta_key'=>'authored_setting','meta_value'=>'meaningful']);$metaStage->resetRollbackPreflight();$rejected=false;
 try{while(!$metaStage->rollbackCheckStep()){}}catch(RuntimeException $e){$rejected=true;}
 adcheck($rejected,'Auto-draft metadata remains independently guarded');$wpdb->query("DELETE FROM `$meta` WHERE post_id=2");
 $stage->resetRollbackPreflight();$stage->rollback();$metaStage->resetRollbackPreflight();$metaStage->rollback();
 adcheck((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===1 && $wpdb->get_var("SELECT post_title FROM `$table` WHERE ID=1")==='Original','Original restored');
 echo "PASS blank editor placeholder tolerance; authored content, excerpt, title, saved drafts, revisions, filtered content, slug, password, hierarchy, ordering, comments and metadata remain guarded\n";
}finally{
 foreach([$table,$meta,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id,'zoer_s_'.$metaid,'zoer_b_'.$metaid,'zoer_l_'.$metaid] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
}
