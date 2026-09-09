<?php
require __DIR__.'/../includes/StageStore.php';require __DIR__.'/../includes/ChunkedFilePublication.php';
use ZoerConnect\ChunkedFilePublication as Publication;use ZoerConnect\StageStore;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function reject($fn,$label){try{$fn();}catch(Throwable $e){echo "PASS $label\n";return;}throw new RuntimeException($label);}
$base=sys_get_temp_dir().'/zoer-large-'.bin2hex(random_bytes(8));mkdir($base.'/public/wp-content/uploads',0755,true);mkdir($base.'/private',0700,true);
$target=$base.'/public/wp-content/uploads/large.bin';$source=$base.'/private/data';
function fixture($p,$byte,$size){$h=fopen($p,'wb');$hashes=[];$full=hash_init('sha256');for($o=0;$o<$size;$o+=StageStore::CHUNK){$v=str_repeat($byte,min(StageStore::CHUNK,$size-$o));fwrite($h,$v);hash_update($full,$v);$hashes[]=hash('sha256',$v);}fclose($h);return ['bytes'=>$size,'sha256'=>hash_final($full),'chunkSha256'=>$hashes];}
$new=fn()=>new Publication($base.'/public',$base.'/private');
try{
 $old=fixture($target,'o',41*1024*1024+37);chmod($target,0600);$a=fixture($source,'n',37*1024*1024+19);$a['path']='wp-content/uploads/large.bin';
 $bad=$a;array_pop($bad['chunkSha256']);reject(fn()=>Publication::validate($bad),'incomplete block manifest refused');
 $s=$new()->prepare(['version'=>1,'target'=>'test','files'=>[$a]],[$source]);$lost=false;$phases=[];
 for($i=0;$i<3000;$i++){
  $before=file_get_contents($base.'/private/publication.json');$phase=$s['phase'];$s=$new()->step();$phases[$phase]=true;
  if(!$lost&&$phase==='backup_copy'&&$s['offset']>0){file_put_contents($base.'/private/publication.json',$before);$lost=true;}
  if(!in_array($s['phase'],['applied_check','applied'],true)&&$phase!==$s['phase']&&hash_file('sha256',$target)!==$old['sha256'])throw new RuntimeException('Partial content became public');
  if($s['status']==='verification_required')break;
 }
 check($s['status']==='verification_required'&&$lost,'large import resumes after copied block without journal commit');
 check(hash_file('sha256',$target)===$a['sha256'],'37 MiB incoming bytes activated');check(hash_file('sha256',$base.'/private/backup')===$old['sha256'],'41 MiB original backed up');
 clearstatcache(true,$target);check((fileperms($target)&0777)===0600,'original mode retained');
 $h=fopen($target,'r+b');fwrite($h,'x');fclose($h);$new()->resetRollbackPreflight();reject(function()use($new){while(!$new()->rollbackPreflightStep()){}},'later edit refuses rollback before restoration');
 $h=fopen($target,'r+b');fwrite($h,'n');fclose($h);$new()->resetRollbackPreflight();while(!$new()->rollbackPreflightStep()){}
 $lost=false;
 for($i=0;$i<2000;$i++){$before=file_get_contents($base.'/private/publication.json');$s=$new()->rollbackStep();if(!$lost&&$s['phase']==='restore_copy'&&$s['offset']>0){file_put_contents($base.'/private/publication.json',$before);$lost=true;}if($s['status']==='rolled_back')break;}
 check($s['status']==='rolled_back'&&$lost&&hash_file('sha256',$target)===$old['sha256'],'interrupted large restore resumes to exact original');
 check(!glob($target.'.zoer-*'),'temporary public copies cleaned');
 echo 'Phases exercised: '.implode(',',array_keys($phases))."\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($base);}
