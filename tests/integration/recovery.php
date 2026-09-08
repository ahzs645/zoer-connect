<?php
if(strpos(home_url(),'zoer-connect-security-test.')===false)throw new RuntimeException('Disposable site required.');
foreach(['FilePublication','TableStage','RecoveryCoordinator'] as $class)require_once WP_PLUGIN_DIR.'/zoer-connect/includes/'.$class.'.php';
global $wpdb;
$id=bin2hex(random_bytes(8));$table=$wpdb->prefix.'zoer_fixture_'.$id;
$private=sys_get_temp_dir().'/zoer-recovery-'.$id;$dir=ABSPATH.'wp-content/themes/zoer-recovery-'.$id;
mkdir($private,0700);mkdir($dir,0755);
$wpdb->query("CREATE TABLE `$table` (id BIGINT PRIMARY KEY, value TEXT) ENGINE=InnoDB");$wpdb->insert($table,['id'=>1,'value'=>'old']);
try{
 file_put_contents($dir.'/style.css','old');file_put_contents($private.'/source','new');
 $files=new \ZoerConnect\FilePublication(ABSPATH,$private);
 $files->prepare(['version'=>1,'target'=>home_url(),'files'=>[['path'=>'wp-content/themes/zoer-recovery-'.$id.'/style.css','bytes'=>3,'sha256'=>hash('sha256','new')]]],[$private.'/source']);
 $stage=new \ZoerConnect\TableStage($wpdb,$table,$id);$stage->prepare();$stage->chunk(0,[['id'=>2,'value'=>'new']]);$stage->verify(1,1);
 $lostReply=new class($stage) {private $stage; function __construct($stage){$this->stage=$stage;}function activate(){$this->stage->activate();throw new RuntimeException('Simulated lost response');}function rollback(){$this->stage->rollback();}};
 $c=new \ZoerConnect\RecoveryCoordinator($private.'/coordinator.json',$files,[$lostReply]);$c->start();
 $failed=false;for($i=0;$i<10;$i++){try{$c->tick();}catch(RuntimeException $e){$failed=true;break;}}
 if(!$failed)throw new RuntimeException('Fault not exercised');
 $c=new \ZoerConnect\RecoveryCoordinator($private.'/coordinator.json',$files,[$stage]);
 for($i=0;$i<10;$i++){$s=$c->tick();if($s['phase']==='rolled_back')break;}
 if($s['phase']!=='rolled_back'||file_get_contents($dir.'/style.css')!=='old'||$wpdb->get_var("SELECT value FROM `$table` WHERE id=1")!=='old')throw new RuntimeException('Combined recovery failed');
 echo "PASS real file/database recovery after injected lost cutover response on disposable WordPress\n";
}finally{
 foreach([$table,'zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
 foreach(glob($private.'/*') as $file)unlink($file);rmdir($private);unlink($dir.'/style.css');rmdir($dir);
}
