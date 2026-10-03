<?php
require __DIR__.'/../includes/SnapshotStream.php';
use ZoerConnect\SnapshotStream;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejects($fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Invalid snapshot accepted.');}
$path=tempnam(sys_get_temp_dir(),'zoer-stream-');
$schema=['wp_posts'=>"CREATE TABLE `wp_posts` (\n `ID` bigint NOT NULL,\n `content` longtext,\n PRIMARY KEY (`ID`)\n) ENGINE=InnoDB"];
$prefix=SnapshotStream::HEADER."DROP TABLE IF EXISTS `wp_posts`;\n".$schema['wp_posts'].";\n";
try {
 $h=fopen($path,'wb');fwrite($h,$prefix);
 $payload=str_repeat('a',220); // >8 MiB and >20,000 rows, without whole-file allocation.
 for($i=0;$i<25001;$i++)fwrite($h,"INSERT INTO `wp_posts` (`ID`,`content`) VALUES (X'".bin2hex((string)$i)."',X'".bin2hex($payload)."');\n");
 fwrite($h,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($h);
 check(filesize($path)>8388608,'Fixture must exceed former size limit.');
 $cursor=SnapshotStream::initial();$rows=0;$ticks=0;
 do {
  $r=SnapshotStream::read($path,$schema,$cursor,100);
  if($ticks===1)check($r===SnapshotStream::read($path,$schema,$cursor,100),'Cursor replay differs.');
  $cursor=json_decode(json_encode($r['cursor']),true);
  foreach($r['records'] as $record)if($record['type']==='row'){$rows++;check($record['row']['content']===$payload,'Wrong decoded bytes.');}
  $ticks++;
 }while(!$cursor['done']);
 check($rows===25001&&$ticks>250,'Rows or bounded ticks incorrect.');
 $valid=$prefix."INSERT INTO `wp_posts` (`ID`,`content`) VALUES (X'31',NULL);\nSET FOREIGN_KEY_CHECKS=1;\n";
 foreach([substr($valid,0,-3),$valid.'unexpected',str_replace("X'31'","1",$valid),str_replace('`ID`,`content`','`ID`,`ID`',$valid),str_replace('longtext','text',$valid)] as $bad){file_put_contents($path,$bad);rejects(fn()=>SnapshotStream::read($path,$schema,SnapshotStream::initial()));}
 file_put_contents($path,$valid);$r=SnapshotStream::read($path,$schema,SnapshotStream::initial());check($r['records'][1]['row']['content']===null,'NULL lost.');
 $integerWidth10="CREATE TABLE `wp_terms` (`term_group` bigint(10) NOT NULL DEFAULT 0) ENGINE=InnoDB";
 $integerWidth20="CREATE TABLE `wp_terms` (`term_group` bigint(20) NOT NULL DEFAULT 0) ENGINE=InnoDB";
 check(SnapshotStream::schema($integerWidth10,'wp_terms')===SnapshotStream::schema($integerWidth20,'wp_terms'),'Equivalent integer display widths differ.');
 check(SnapshotStream::schema($integerWidth10,'wp_terms')!==SnapshotStream::schema(str_replace('bigint(20)','int(20)',$integerWidth20),'wp_terms'),'Different integer types matched.');
 check(SnapshotStream::schema($integerWidth10,'WP_terms',true)===SnapshotStream::schema($integerWidth10,'wp_terms'),'Case-folded database DDL resolves to the same table.');
 rejects(fn()=>SnapshotStream::schema($integerWidth10,'WP_terms'));
 rejects(fn()=>SnapshotStream::schema($integerWidth10,'WP_posts',true));
 echo "PASS streaming >8 MiB / 25,001 rows; durable cursor retry; integer display widths; schema, statement, footer and duplicate-column rejection\n";
}finally{unlink($path);}
