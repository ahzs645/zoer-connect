<?php
require_once __DIR__.'/../includes/PagedDatabase.php';
define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
use ZoerConnect\PagedDatabase;
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function denied($f,$s){try{$f();}catch(Throwable $e){check(true,$s);return;}throw new RuntimeException($s);}
$quota=17179869184;function get_option($name,$default=null){global $quota;return $name==='zoer_connect_transfer_quota_bytes'?$quota:$default;}
require __DIR__.'/fixtures/paged-db.php';
$r=sys_get_temp_dir().'/zc-paged-database-'.bin2hex(random_bytes(6));mkdir($r,0700);$path=$r.'/snapshot.sql';
try {
 $db=new PagedDb();$s=PagedDatabase::start($db,[]);check($s['tables']===['Wp_posts'],'case-folded physical tables retain logical prefix');
 $before=$s;$s=PagedDatabase::step($db,$path,$s);check(!$s['complete']&&count($s['parts'])===1&&$s['row']['ID']===base64_encode('18446744073709551614'),'first request commits typed uint64 row checkpoint');
 $first=file_get_contents($path);$firstState=$s;file_put_contents($path,'torn uncommitted tail',FILE_APPEND);
 $replayed=PagedDatabase::step($db,$path,$before);check(file_get_contents($path)===$first&&$replayed===$firstState,'lost response truncates bytes and replays identically from durable cursor');
 while(!$s['complete'])$s=PagedDatabase::step($db,$path,$s);
 check(count($s['parts'])===3&&$s['table']===1,'bounded parts complete across independent requests');foreach($s['parts'] as $part)PagedDatabase::verifyPart($path,$part);
 $sql=file_get_contents($path);check(substr_count($sql,'INSERT INTO `Wp_posts`')===2&&str_contains($sql,bin2hex('18446744073709551615'))&&str_contains($sql,'NULL'),'no skipped or duplicated uint64 rows; NULL and binary SQL preserved');check(!str_contains($sql,'other_secret'),'unrelated database excluded');
 $h=fopen($path,'r+b');fseek($h,12);fwrite($h,'corrupt');fclose($h);denied(fn()=>PagedDatabase::verifyPart($path,$s['parts'][0]),'output-part tampering refused');
 $quota=1048576;denied(fn()=>PagedDatabase::step($db,$r.'/small.sql',$before),'quota checked before extending output');$quota=17179869184;
 $db->legacy=true;denied(fn()=>PagedDatabase::start($db,[]),'nontransactional tables refused');$db->legacy=false;
 $db->changed=true;denied(fn()=>PagedDatabase::step($db,$path,$firstState),'schema drift refused');
 check(!array_filter($db->queries,fn($q)=>str_contains($q,'OFFSET')),'resumption never scans with OFFSET');
}finally{foreach(glob($r.'/*') as $p)unlink($p);rmdir($r);}
