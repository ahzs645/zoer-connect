<?php
require __DIR__.'/../includes/TransferImport.php';
use ZoerConnect\TransferImport;
use ZoerConnect\StageStore;
$cacheFails=false;$cacheFlushes=0;
function wp_cache_flush(){global $cacheFails,$cacheFlushes;$cacheFlushes++;return !$cacheFails;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejects($fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Unsafe import operation accepted.');}
$base=sys_get_temp_dir().'/zoer-transfer-'.bin2hex(random_bytes(8));mkdir($base,0700);mkdir($base.'/private',0700);mkdir($base.'/public/wp-content/themes/fixture',0755,true);
$target='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh';$owner=hash('sha256','owner generation');
$db=new class($target){public $prefix='wp_';public $options='wp_options';public $last_error='';public $url;public function __construct($url){$this->url=$url;}public function prepare($sql,...$args){return $sql;}public function get_var($sql){return $this->url;}};
$new=fn($key=null)=>new TransferImport($db,$base.'/public',$base.'/private',$key??$owner,$target,true);
try{
 rejects(fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target));
 rejects(fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,'https://user:password@example.test',true));
 // Generic destination support, exercised only against this temporary fake DB/tree.
 $other='https://synthetic.example.test';$general=new TransferImport($db,$base.'/public',$base.'/private',$owner,$other,true);$general->installFence();
 $genericBody=['target'=>$other,'sourceUrl'=>'https://source.example','sourcePrefix'=>'wp_','wordpressOnlyWriters'=>true,'files'=>[['path'=>'wp-content/themes/fixture/other.css','bytes'=>0,'sha256'=>hash('sha256','')]]];
 rejects(fn()=>$general->create($genericBody)); // actual DB identity still points to peer
 $db->url=$other;$generic=$general->create($genericBody);check($general->rollback($generic['id'])['phase']==='cancelled','Generic local fixture not supported');$db->url=$target;
 $p=$new();$p->installFence();$data=str_repeat('verified-file-',50000);$path='wp-content/themes/fixture/style.css';file_put_contents($base.'/public/'.$path,'original');
 $body=['id'=>str_repeat('a',32),'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>'source_','wordpressOnlyWriters'=>true,'files'=>[['path'=>$path,'bytes'=>strlen($data),'sha256'=>hash('sha256',$data),'expectedDestinationSha256'=>hash('sha256','original')]]];
 $body['migrationMode']='shared-replacement';unset($body['wordpressOnlyWriters']);rejects(fn()=>$p->create($body));$body['replacementAccepted']=true;
 $s=$p->create($body);check($p->create($body)['id']===$s['id'],'Creation retry not idempotent');
 rejects(fn()=>$new(hash('sha256','revoked'))->status($s['id']));
 rejects(fn()=>$p->step($s['id']));
 for($offset=0;$offset<strlen($data);$offset+=StageStore::CHUNK){$chunk=substr($data,$offset,StageStore::CHUNK);$p=$new();$p->chunk($s['id'],0,$offset,$chunk);$p->chunk($s['id'],0,$offset,$chunk);}
 rejects(fn()=>$p->chunk($s['id'],0,0,str_repeat('b',StageStore::CHUNK)));
 for($i=0;$i<30;$i++){$p=$new();$s=$p->step($s['id']);if(file_get_contents($base.'/public/'.$path)===$data)break;}
 check(file_get_contents($base.'/public/'.$path)===$data,'File activation failed.');
 check(is_file($base.'/private/write-fence.json'),'Fence released before verification.');
 for($i=0;$i<30;$i++){$p=$new();$s=$p->rollback($s['id']);if($s['phase']==='rolled_back')break;}
 check($s['phase']==='rolled_back'&&file_get_contents($base.'/public/'.$path)==='original','Interrupted file import rollback failed.');
 check(!file_exists($base.'/private/write-fence.json'),'Restored import retained fence.');
 $body['id']=str_repeat('b',32);$body['reuseImportId']=str_repeat('a',32);
 $bad=$body;$bad['files'][0]['sha256']=str_repeat('0',64);rejects(fn()=>$p->create($bad));
 rejects(fn()=>$new(hash('sha256','revoked'))->create($body));
 $s=$p->create($body);check($s['phase']==='reusing_artifacts','Reuse phase missing');
 $s=$new()->step($s['id']);check($s['phase']==='uploading','Reuse did not reach upload');
 if(function_exists('link'))check($s['offsets'][0]===strlen($data),'Cached file not reused');
 else {check($s['offsets'][0]===0,'Disabled hard links did not fall back to upload');for($offset=0;$offset<strlen($data);$offset+=StageStore::CHUNK)$new()->chunk($s['id'],0,$offset,substr($data,$offset,StageStore::CHUNK));}
 check(!file_exists($base.'/private/write-fence.json'),'Reuse fenced live site prematurely');
 // Reused files receive the same digest verification as fresh uploads.
 $cached=$base.'/private/import-'.$s['id'].'/artifact-0/data';file_put_contents($cached,str_repeat('x',strlen($data)));
 $new()->step($s['id']);rejects(fn()=>$new()->step($s['id']));
 check(!file_exists($base.'/private/write-fence.json'),'Corrupt cached file reached cutover');file_put_contents($cached,$data);
 for($i=0;$i<40;$i++){$s=$new()->step($s['id']);if($s['phase']==='verification_required')break;}
 check($s['phase']==='verification_required','Import never reached verification.');
 $cacheFails=true;rejects(fn()=>$new()->finish($s['id']));check(file_exists($base.'/private/write-fence.json'),'Failed cache flush released protection');$cacheFails=false;
 $s=$new()->finish($s['id']);check($s['phase']==='complete'&&!file_exists($base.'/private/write-fence.json'),'Finish failed.');check($cacheFlushes>=2,'Cache flush retry missing');
 // After release, a real user edit must refuse before any backup is restored.
 file_put_contents($base.'/public/'.$path,'later edit');
 $refused=false;
 for($i=0;$i<30;$i++){try{$s=$new()->rollback($s['id']);}catch(Throwable $e){$refused=true;break;}}
 check($refused&&file_get_contents($base.'/public/'.$path)==='later edit'&&!file_exists($base.'/private/write-fence.json'),'Post-finish rollback overwrote a later edit or stranded the site.');
 // Restore fixture bytes, then retry through a fresh preflight and recover fully.
 file_put_contents($base.'/public/'.$path,$data);
 for($i=0;$i<30;$i++){$s=$new()->rollback($s['id']);if($s['phase']==='rolled_back')break;}
 check($s['phase']==='rolled_back'&&file_get_contents($base.'/public/'.$path)==='original','Post-finish rollback retry failed.');
 $body['id']=str_repeat('c',32);unset($body['reuseImportId']);
 $s=$new()->create($body);
 for($offset=0;$offset<strlen($data);$offset+=StageStore::CHUNK)$new()->chunk($s['id'],0,$offset,substr($data,$offset,StageStore::CHUNK));
 file_put_contents($base.'/public/'.$path,'changed after preview');$conflict=false;
 for($i=0;$i<40;$i++){try{$s=$new()->step($s['id']);}catch(Throwable $e){$conflict=str_contains($e->getMessage(),'Destination changed since preview');break;}}
 check($conflict&&file_get_contents($base.'/public/'.$path)==='changed after preview','Stale preview overwrote a live edit');
 for($i=0;$i<40;$i++){$s=$new()->rollback($s['id']);if(in_array($s['phase'],['rolled_back','cancelled'],true))break;}
 check(!file_exists($base.'/private/write-fence.json')&&file_get_contents($base.'/public/'.$path)==='changed after preview','Conflict rollback changed live edit or retained fence');
 echo "PASS public importer explicit enablement and destination identity, generation binding, creation/chunk retry, multi-request upload/activation, resumed rollback and explicit finish\n";
}finally{$walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($walk as $e)$e->isDir()?rmdir($e->getPathname()):unlink($e->getPathname());rmdir($base);}
