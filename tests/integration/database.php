<?php
if(strpos(home_url(),'zoer-connect-security-test.')===false)throw new RuntimeException('Disposable test site required.');
require_once WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;
$id=bin2hex(random_bytes(8));$table=$wpdb->prefix.'zoer_fixture_'.$id;
$wpdb->query("CREATE TABLE `$table` (id BIGINT PRIMARY KEY, value LONGTEXT NULL) ENGINE=InnoDB");
$wpdb->insert($table,['id'=>1,'value'=>'original']);
function zassert($ok){if(!$ok)throw new RuntimeException('Assertion failed.');}
function zreject($fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection.');}
try{
 $p=new \ZoerConnect\TableStage($wpdb,$table,$id);$p->prepare();
 $rows=[['id'=>2,'value'=>"unicode 🧪 quote ' and slash \\"],['id'=>3,'value'=>null]];
 zreject(fn()=>$p->chunk(1,$rows));
 zreject(fn()=>$p->chunk(0,[['id'=>2,'value'=>'a','bad`column'=>'bad']]));
 $p->chunk(0,$rows);$p->chunk(0,$rows);
 zassert((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===1);
 zreject(fn()=>$p->chunk(0,[['id'=>2,'value'=>'conflict']]));
 zreject(fn()=>$p->verify(3,1));$p->verify(2,1);
 zreject(fn()=>$p->chunk(1,$rows));
 $p->activate();$p->activate();
 zassert((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===2);
 zassert($wpdb->get_var("SELECT value FROM `$table` WHERE id=2")===$rows[0]['value']);
 $p=new \ZoerConnect\TableStage($wpdb,$table,$id);$p->rollback();$p->rollback();
 zassert($wpdb->get_var("SELECT value FROM `$table` WHERE id=1")==='original');
 zreject(fn()=>new \ZoerConnect\TableStage($wpdb,$wpdb->users,$id));
 echo "PASS database stage, transactional retry, unsafe columns, count checks, Unicode, cutover and rollback\n";
}finally{
 foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
}
