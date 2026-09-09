<?php
foreach(['StageStore','Selection','ExportProfile','FileExporter','DatabaseExporter','RemoteExport'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\RemoteExport;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function denied(callable $fn,$name){try{$fn();}catch(Throwable $e){check(true,$name);return;}throw new RuntimeException($name);}
if(($argv[1]??'')==='--fatal'){
 $root=$argv[2];$store=new RemoteExport($root.'/private',[$root.'/public'],$root.'/public','key-generation-a');
 $store->create(['clientId'=>str_repeat('d',32),'profile'=>['name'=>'database'],'database'=>true],[],static function($path){file_put_contents($path.'.partial','incomplete');set_time_limit(1);while(true){hash('sha256','timeout fixture');}});exit(99);
}
$root=sys_get_temp_dir().'/zoer-remote-'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/public');mkdir($root.'/public/wp-content/themes/demo',0755,true);file_put_contents($root.'/public/wp-content/themes/demo/a.txt',str_repeat('a',300000));
try{
 $store=new RemoteExport($root.'/private',[$root.'/public'],$root.'/public','key-generation-a');
 $other=new RemoteExport($root.'/private',[$root.'/public'],$root.'/public','key-generation-b');
 $id=str_repeat('a',32);$input=['clientId'=>$id,'profile'=>['name'=>'files','themes'=>true],'database'=>false];$unused=static function(){throw new RuntimeException('Unexpected DB');};
 $job=$store->create($input,[],$unused);check($job['status']==='preparing','file preparation starts resumably');
 check(!isset($job['owner'])&&!isset($job['requestHash']),'private ownership omitted');
 denied(fn()=>$other->status($id),'different credential cannot inspect');denied(fn()=>$other->cancel($id),'different credential cannot cancel');denied(fn()=>$other->create($input,[],$unused),'different credential cannot reuse ID');
 denied(fn()=>$store->chunk($id,0,0),'incomplete export unavailable');
 $job=$store->step($id);check($job['status']==='ready','prepared export ready');$chunk=$store->chunk($id,0,0);check($chunk['bytes']===RemoteExport::CHUNK&&!$chunk['eof'],'first bounded chunk');
 check($store->create($input,[],$unused)===$job,'create retry returns identical artifact');check($store->chunk($id,0,0)===$chunk,'interrupted download retries identical chunk');check($store->chunk($id,0,RemoteExport::CHUNK)['eof'],'resumed download reaches EOF');
 denied(fn()=>$store->chunk($id,0,300001),'out of bounds offset denied');
 file_put_contents($root.'/private/exports/'.$id.'/0.bin',str_repeat('b',300000));denied(fn()=>$store->chunk($id,0,0),'tampered snapshot denied before download');
 $state=$root.'/private/exports/'.$id.'/state.json';$j=json_decode(file_get_contents($state),true);$j['expiresAt']=time()-1;file_put_contents($state,json_encode($j));denied(fn()=>$store->status($id),'expired export unavailable');denied(fn()=>$store->create($input,[],$unused),'expired ID cannot silently change snapshot');check($store->cancel($id)['status']==='cancelled','owner can cancel expired export');check($store->cancel($id)['status']==='cancelled','cancel retry is idempotent');
 // Simulate process death after durable ownership, before database completion.
 $id=str_repeat('b',32);$input=['clientId'=>$id,'profile'=>['name'=>'database'],'database'=>true];$dir=$root.'/private/exports/'.$id;mkdir($dir);$profile=ZoerConnect\ExportProfile::normalize($input['profile']);
 file_put_contents($dir.'/state.json',json_encode(['id'=>$id,'owner'=>'key-generation-a','requestHash'=>hash('sha256',json_encode([$profile,true])),'status'=>'preparing','profile'=>$profile,'database'=>true,'databasePending'=>true,'source'=>[],'files'=>[],'nextIndex'=>0,'expiresAt'=>time()+3600,'maxChunkBytes'=>RemoteExport::CHUNK]));file_put_contents($dir.'/0.bin.partial','incomplete');
 $calls=0;$db=function($path)use(&$calls){$calls++;file_put_contents($path,'complete SQL');};$job=$store->create($input,[],$db);check($job['status']==='ready'&&$calls===1&&!file_exists($dir.'/0.bin.partial'),'interrupted preparation restarts clean database snapshot');$store->create($input,[],$db);check($calls===1,'completed database export not regenerated');$store->cancel($id);
 $orphan=$root.'/private/exports/'.str_repeat('c',32);mkdir($orphan);file_put_contents($orphan.'/0.bin.partial','abandoned');touch($orphan,time()-7200);$store->create($input,[],$db);check(!file_exists($orphan),'expired orphan cleanup');$store->cancel($id);
 $fatalId=str_repeat('d',32);$fatalDir=$root.'/private/exports/'.$fatalId;
 $process=proc_open([PHP_BINARY,__FILE__,'--fatal',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot start timeout fixture');fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 check($code!==0&&str_contains($stdout.$stderr,'Maximum execution time'),'fatal request during remote database preparation exercised');
 check($store->status($fatalId)['status']==='preparing'&&is_file($fatalDir.'/0.bin.partial'),'fatal preparation retains recoverable ownership, never ready');
 $fatalInput=['clientId'=>$fatalId,'profile'=>['name'=>'database'],'database'=>true];
 denied(fn()=>$other->create($fatalInput,[],$db),'fatal preparation remains credential scoped');
 check($store->create($fatalInput,[],$db)['status']==='ready'&&!file_exists($fatalDir.'/0.bin.partial'),'same create restarts snapshot after actual fatal request');$store->cancel($fatalId);
 denied(fn()=>$store->create($input,[],static function($path){file_put_contents($path,'partial');throw new RuntimeException('database unavailable');}),'database failure propagated');check(!file_exists($dir),'failed create removes incomplete artifact');
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);}
