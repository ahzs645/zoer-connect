<?php
require_once __DIR__.'/../includes/ExportBlocks.php';
use ZoerConnect\ExportBlocks;
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function denied($f,$s){try{$f();}catch(Throwable $e){check(true,$s);return;}throw new RuntimeException($s);}
$r=sys_get_temp_dir().'/zc-export-blocks-'.bin2hex(random_bytes(6));mkdir($r,0700);$data=random_bytes(7*1024*1024+13);file_put_contents($r.'/source',$data);
try {
 $offset=ExportBlocks::append($r.'/source',$r.'/snapshot',0,strlen($data));check($offset>0&&$offset<=4194304&&$offset%262144===0,'copy bounded and block aligned');
 denied(fn()=>ExportBlocks::append($r.'/source',$r.'/missing',262144,strlen($data)),'missing committed prefix cannot resume');
 // Process died after writing a tail, before saving its returned offset.
 file_put_contents($r.'/snapshot','torn tail',FILE_APPEND);file_put_contents($r.'/snapshot.blocks','torn digest',FILE_APPEND);
 while($offset<strlen($data))$offset=ExportBlocks::append($r.'/source',$r.'/snapshot',$offset,strlen($data));
 check(file_get_contents($r.'/snapshot')===$data,'resume truncates uncommitted tails');
 $root=hash_init('sha256');for($i=0;$i<strlen($data);$i+=262144){$block=substr($data,$i,262144);hash_update($root,hash('sha256',$block,true));ExportBlocks::verify($r.'/snapshot',$i,$block);}
 $entry=ExportBlocks::entry($r.'/snapshot','wp-content/uploads/large.bin',strlen($data));check($entry['sha256']===hash_final($root)&&$entry['digestFormat']==='sha256-blocks-v1','sealed ordered block root');
  $verified=0;while($verified<strlen($data))$verified=ExportBlocks::verifySource($r.'/source',$r.'/snapshot',$verified,strlen($data));check($verified===strlen($data),'source verification is bounded and complete');
 $h=fopen($r.'/source','r+b');fwrite($h,'changed');fclose($h);denied(fn()=>ExportBlocks::verifySource($r.'/source',$r.'/snapshot',0,strlen($data)),'same-size changed source cannot be advertised');
 denied(fn()=>ExportBlocks::verify($r.'/snapshot',0,str_repeat('x',262144)),'corruption rejected');denied(fn()=>ExportBlocks::verify($r.'/snapshot',1,substr($data,1,262144)),'unaligned offset rejected');
 file_put_contents($r.'/empty','');ExportBlocks::append($r.'/empty',$r.'/empty-out',0,0);check(ExportBlocks::entry($r.'/empty-out','wp-content/uploads/empty',0)['sha256']===hash('sha256',''),'empty file root');
 unlink($r.'/snapshot.blocks');$offset=0;while($offset<strlen($data))$offset=ExportBlocks::seal($r.'/snapshot',$offset,strlen($data));check(ExportBlocks::entry($r.'/snapshot','database.sql',strlen($data))===$entry+[]||hash_file('sha256',$r.'/snapshot.blocks')===$entry['sha256'],'existing SQL snapshot seals in bounded requests');
}finally{foreach(glob($r.'/*') as $p)unlink($p);rmdir($r);}
