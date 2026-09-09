<?php
namespace ZoerConnect {
    function disk_free_space($p){return $GLOBALS['quota']??\disk_free_space($p);}
    function fwrite($h,$data){if(!empty($GLOBALS['shortWrite'])&&strlen($data)>1)return \fwrite($h,substr($data,0,intdiv(strlen($data),2)));return \fwrite($h,$data);}
}
namespace {
require __DIR__.'/../includes/StageStore.php';require __DIR__.'/../includes/ChunkedFilePublication.php';
use ZoerConnect\ChunkedFilePublication as P;
function ok($x,$m){if(!$x)throw new RuntimeException($m);echo "PASS $m\n";}
function reject($f,$m){try{$f();}catch(Throwable $e){echo "PASS $m\n";return;}throw new RuntimeException($m);}
$base=sys_get_temp_dir().'/zoer-chunk-errors-'.bin2hex(random_bytes(6));mkdir($base.'/public/wp-content/uploads',0755,true);mkdir($base.'/private',0700,true);
$target=$base.'/public/wp-content/uploads/a.bin';$source=$base.'/private/data';file_put_contents($target,'original');file_put_contents($source,'replacement');
$a=['path'=>'wp-content/uploads/a.bin','bytes'=>11,'sha256'=>hash('sha256','replacement'),'chunkSha256'=>[hash('sha256','replacement')]];$manifest=['version'=>1,'target'=>'x','files'=>[$a]];$new=fn()=>new P($base.'/public',$base.'/private');
try{
 $GLOBALS['quota']=0;reject(fn()=>$new()->prepare($manifest,[$source]),'low backup space refused before journal');unset($GLOBALS['quota']);$new()->prepare($manifest,[$source]);
 file_put_contents($source,'corrupt-src');reject(fn()=>$new()->step(),'source corruption refused');file_put_contents($source,'replacement');
 do{$s=$new()->step();}while($s['phase']!=='backup_copy');$GLOBALS['shortWrite']=true;reject(fn()=>$new()->step(),'partial backup write refused');unset($GLOBALS['shortWrite']);
 do{$s=$new()->step();}while($s['phase']!=='copy_new');ok(file_get_contents($base.'/private/backup')==='original','partial backup retry truncates and restores verified bytes');
 $GLOBALS['quota']=0;reject(fn()=>$new()->step(),'low destination space refused');unset($GLOBALS['quota']);
 $GLOBALS['shortWrite']=true;reject(fn()=>$new()->step(),'partial activation copy refused');unset($GLOBALS['shortWrite']);ok(file_get_contents($target)==='original','failed copy never exposed partial content');
 do{$s=$new()->step();}while($s['phase']!=='rename');$before=file_get_contents($base.'/private/publication.json');$new()->step();file_put_contents($base.'/private/publication.json',$before);
 do{$s=$new()->step();}while($s['status']!=='verification_required');ok(file_get_contents($target)==='replacement','lost rename journal resumes by verifying destination');
 file_put_contents($base.'/private/backup','bad-data');$new()->resetRollbackPreflight();reject(function()use($new){while(!$new()->rollbackPreflightStep()){}},'corrupted backup refuses preflight');ok(file_get_contents($target)==='replacement','backup failure does not alter live content');file_put_contents($base.'/private/backup','original');$new()->resetRollbackPreflight();while(!$new()->rollbackPreflightStep()){}
 $GLOBALS['quota']=0;reject(fn()=>$new()->rollbackStep(),'low restore space refused');unset($GLOBALS['quota']);$GLOBALS['shortWrite']=true;reject(fn()=>$new()->rollbackStep(),'short restore write refused');unset($GLOBALS['shortWrite']);
 do{$s=$new()->rollbackStep();}while($s['phase']!=='restore_rename');$before=file_get_contents($base.'/private/publication.json');$new()->rollbackStep();file_put_contents($base.'/private/publication.json',$before);
 do{$s=$new()->rollbackStep();}while($s['status']!=='rolled_back');ok(file_get_contents($target)==='original','lost restore rename journal recovers exact original');
 // Empty incoming and absent original use the same bounded lifecycle.
 unlink($base.'/private/publication.json');unlink($target);file_put_contents($source,'');$a['bytes']=0;$a['sha256']=hash('sha256','');$a['chunkSha256']=[];$new()->prepare(['version'=>1,'target'=>'x','files'=>[$a]],[$source]);
 do{$s=$new()->step();}while($s['status']!=='verification_required');ok(is_file($target)&&filesize($target)===0,'empty file activates');do{$s=$new()->rollbackStep();}while($s['status']!=='rolled_back');ok(!file_exists($target),'rollback restores absent file');
}finally{unset($GLOBALS['quota'],$GLOBALS['shortWrite']);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($base);}
}
