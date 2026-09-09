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
 $wpdb->update('zoer_s_'.$id,['value'=>'tampered'],['id'=>2]);
 zreject(fn()=>$p->activate());
 zassert($wpdb->get_var("SELECT value FROM `$table` WHERE id=1")==='original');
 $wpdb->update('zoer_s_'.$id,['value'=>$rows[0]['value']],['id'=>2]);
 $wpdb->update($table,['value'=>'concurrent edit'],['id'=>1]);
 zreject(fn()=>$p->activate());
 $wpdb->update($table,['value'=>'original'],['id'=>1]);
 $p->activate();$p->activate();
 zassert((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===2);
 zassert($wpdb->get_var("SELECT value FROM `$table` WHERE id=2")===$rows[0]['value']);
 $p=new \ZoerConnect\TableStage($wpdb,$table,$id);
 $wpdb->update($table,['value'=>'post-publication edit'],['id'=>2]);
 zreject(fn()=>$p->rollback());
 zassert($wpdb->get_var("SELECT value FROM `$table` WHERE id=2")==='post-publication edit');
 $wpdb->update($table,['value'=>$rows[0]['value']],['id'=>2]);
 $wpdb->update('zoer_b_'.$id,['value'=>'corrupt backup'],['id'=>1]);
 zreject(fn()=>$p->rollback());
 $wpdb->update('zoer_b_'.$id,['value'=>'original'],['id'=>1]);
 $p->rollback();$p->rollback();
 zassert($wpdb->get_var("SELECT value FROM `$table` WHERE id=1")==='original');
 zreject(fn()=>new \ZoerConnect\TableStage($wpdb,$wpdb->users,$id));
 echo "PASS database staging, retries, Unicode, verified-stage tampering, concurrent destination edits, post-publication edits, corrupt backups, cutover and rollback\n";
}finally{
 foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
}
