<?php
require __DIR__.'/../includes/DatabaseExporter.php';
define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
use ZoerConnect\DatabaseExporter;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
class SnapshotDb {
 public $prefix='wp_';public int $lower=0;
 function get_var($sql){return $this->lower;}public $last_error='';public array $queries=[];public string $failure='';public int $schemas=0;public float $time=0;
 function query($sql){$this->queries[]=$sql;return $this->failure===$sql?false:1;}
 function get_results($sql,$mode){$this->queries[]=$sql;
  if($sql==='SHOW TABLE STATUS')return [['Name'=>'wp_posts','Engine'=>$this->failure==='engine'?'MyISAM':'InnoDB'],['Name'=>'other_secret','Engine'=>'InnoDB']];
  if(str_starts_with($sql,'SHOW INDEX'))return $this->failure==='key'?[]:[['Seq_in_index'=>1,'Column_name'=>'ID']];
  if($this->failure==='read'){$this->last_error='sensitive database details';return null;}
  if($this->failure==='timeout')$this->time=100;
  if($this->failure==='fatal'){set_time_limit(1);while(true){hash('sha256','timeout fixture');}}
  if(str_contains($sql,'OFFSET 0'))return [['ID'=>1,'body'=>"quote'\0binary",'empty'=>null]];
  return [];
 }
 function get_row($sql,$mode){$this->schemas++;return ['wp_posts','CREATE TABLE `wp_posts` (`ID` bigint PRIMARY KEY, `body` longblob, `empty` text) ENGINE=InnoDB'.($this->failure==='autoinc'?' AUTO_INCREMENT='.(5+$this->schemas):'').' DEFAULT CHARSET=utf8mb4'.($this->failure==='ddl'&&$this->schemas>1?' changed':'')];}
}
if(($argv[1]??'')==='--fatal'){$db=new SnapshotDb();$db->failure='fatal';DatabaseExporter::write($db,$argv[2],static fn()=>0,10);exit(99);}
$root=sys_get_temp_dir().'/zoer-db-'.bin2hex(random_bytes(6));mkdir($root);
try{
 $db=new SnapshotDb();$path=$root.'/good.sql';DatabaseExporter::write($db,$path);$sql=file_get_contents($path);
 check(str_contains($sql,"X'71756f7465270062696e617279'")&&str_contains($sql,'NULL'),'binary values and NULL preserved');check(!str_contains($sql,'other_secret'),'unrelated tables excluded');
 check(in_array('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',$db->queries)&&in_array('COMMIT',$db->queries),'one read-only consistent transaction committed');check((bool)array_filter($db->queries,fn($q)=>str_contains($q,'ORDER BY `ID` LIMIT')),'pagination ordered by primary key');check(!file_exists($path.'.partial'),'only complete artifact published');check((fileperms($path)&0777)===0600,'snapshot private permissions');
 $folded=new SnapshotDb();$folded->prefix='WP_';$folded->lower=1;$foldedPath=$root.'/folded.sql';$tables=DatabaseExporter::write($folded,$foldedPath);$foldedSql=file_get_contents($foldedPath);
 check($tables===['posts']&&str_contains($foldedSql,'DROP TABLE IF EXISTS `WP_posts`')&&str_contains($foldedSql,'CREATE TABLE `WP_posts`')&&str_contains($foldedSql,'INSERT INTO `WP_posts`'),'case-folded database exports preserve the configured prefix in every snapshot identifier');
 // Regression: MySQL 8/MariaDB report the live AUTO_INCREMENT inside the consistent snapshot, so a
 // concurrent INSERT (transient, cron lock) between the two SHOW CREATE TABLE calls is not a schema change.
 $db=new SnapshotDb();$db->failure='autoinc';$path=$root.'/autoinc.sql';DatabaseExporter::write($db,$path);$sql=file_get_contents($path);
 check($db->schemas===2&&str_contains($sql,'AUTO_INCREMENT=6')&&str_contains($sql,"X'71756f7465270062696e617279'"),'concurrent AUTO_INCREMENT change is not a schema change (export succeeds, first DDL kept)');
 foreach(['engine','key','read','ddl','timeout','START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','COMMIT'] as $failure){$db=new SnapshotDb();$db->failure=$failure;$path=$root.'/failed.sql';$caught=false;try{DatabaseExporter::write($db,$path,fn()=>$db->time,10);}catch(Throwable $e){$caught=true;check(!str_contains($e->getMessage(),'sensitive'),'errors omit database details');}check($caught,'failure rejected: '.$failure);check(!file_exists($path)&&!file_exists($path.'.partial'),'failed snapshot never published: '.$failure);if($failure!=='engine')check(in_array('ROLLBACK',$db->queries),'rollback attempted: '.$failure);}
 $path=$root.'/fatal.sql';$process=proc_open([PHP_BINARY,__FILE__,'--fatal',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot start timeout fixture');fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 check($code!==0&&str_contains($stdout.$stderr,'Maximum execution time'),'actual PHP fatal timeout exercised');check(!file_exists($path)&&!file_exists($path.'.partial'),'fatal timeout never publishes incomplete SQL and shutdown cleans partial');
}finally{foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
