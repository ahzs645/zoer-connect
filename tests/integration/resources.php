<?php
if(!preg_match('~zoer-connect-(security-test|transfer-peer)\.~',home_url()))throw new RuntimeException('Disposable site required.');
foreach(['FilePublication','TableStage'] as $c)require_once WP_PLUGIN_DIR.'/zoer-connect/includes/'.$c.'.php';
$paths=['themes'=>'wp-content/themes/zoer-resource-fixture/data.txt','plugins'=>'wp-content/plugins/zoer-resource-fixture/data.txt','media'=>'wp-content/uploads/zoer-resource-fixture/data.txt'];
$mode=getenv('ZOER_RESOURCE_MODE');$label=getenv('ZOER_RESOURCE_LABEL');
global $wpdb;$table=$wpdb->prefix.'zoer_resource_fixture';
if($mode==='seed'){
 foreach($paths as $kind=>$path){if(file_exists(ABSPATH.$path))throw new RuntimeException('Fixture exists');mkdir(dirname(ABSPATH.$path),0755,true);file_put_contents(ABSPATH.$path,$label.'-'.$kind);}
 $wpdb->query("CREATE TABLE `$table` (id BIGINT PRIMARY KEY, value TEXT) ENGINE=InnoDB");$wpdb->insert($table,['id'=>1,'value'=>$label.'-database']);echo "seeded\n";return;
}
if($mode==='export'){
 $out=[];foreach($paths as $kind=>$path)$out[$kind]=base64_encode(file_get_contents(ABSPATH.$path));
 $out['database']=$wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A);echo json_encode($out);return;
}
if($mode==='receive'){
 $data=json_decode(file_get_contents('/tmp/zoer-resource-data.json'),true,512,JSON_THROW_ON_ERROR);$kind=getenv('ZOER_RESOURCE_KIND');
 if(!in_array($kind,['themes','plugins','media','database'],true))throw new RuntimeException('Bad resource');
 $before=[];foreach($paths as $k=>$path)$before[$k]=file_get_contents(ABSPATH.$path);
 $oldRows=$wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A);
 $id=bin2hex(random_bytes(8));$private=sys_get_temp_dir().'/zoer-resource-'.$id;mkdir($private,0700);
 try{
  if($kind==='database'){
   $p=new \ZoerConnect\TableStage($wpdb,$table,$id);$p->prepare();$p->chunk(0,$data['database']);$p->verify(count($data['database']),1);$p->activate();
   if($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)!==$data['database'])throw new RuntimeException('DB mismatch');
   foreach($paths as $k=>$path)if(file_get_contents(ABSPATH.$path)!==$before[$k])throw new RuntimeException('Unselected file changed');
   $p->rollback();if($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)!==$oldRows)throw new RuntimeException('DB rollback mismatch');
  }else{
   $bytes=base64_decode($data[$kind],true);if($bytes===false)throw new RuntimeException('Bad payload');file_put_contents($private.'/source',$bytes);
   $p=new \ZoerConnect\FilePublication(ABSPATH,$private);$p->prepare(['version'=>1,'target'=>home_url(),'files'=>[['path'=>$paths[$kind],'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)]]],[$private.'/source']);
   for($i=0;$i<4;$i++)$p->step();
   if(file_get_contents(ABSPATH.$paths[$kind])!==$bytes)throw new RuntimeException('Transfer mismatch');
   foreach($paths as $k=>$path)if($k!==$kind&&file_get_contents(ABSPATH.$path)!==$before[$k])throw new RuntimeException('Unselected resource changed');
   if($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)!==$oldRows)throw new RuntimeException('Unselected database changed');
   $p->rollbackStep();$p->rollbackStep();if(file_get_contents(ABSPATH.$paths[$kind])!==$before[$kind])throw new RuntimeException('Restore mismatch');
  }
  echo "PASS $kind transfer, unselected isolation, rollback\n";
 }finally{
  foreach(['zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $t)$wpdb->query("DROP TABLE IF EXISTS `$t`");
  foreach(glob($private.'/*') as $f)unlink($f);rmdir($private);
 }
 return;
}
if($mode==='cleanup'){
 foreach($paths as $path){if(is_file(ABSPATH.$path))unlink(ABSPATH.$path);if(is_dir(dirname(ABSPATH.$path)))rmdir(dirname(ABSPATH.$path));}
 $wpdb->query("DROP TABLE IF EXISTS `$table`");echo "cleaned\n";return;
}
throw new RuntimeException('Unknown mode');
