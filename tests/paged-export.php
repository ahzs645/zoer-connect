<?php
foreach(['StageStore','Selection','ExportProfile','DatabaseExporter','PagedExport'] as $c)require __DIR__.'/../includes/'.$c.'.php';
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function denied($f,$s){try{$f();}catch(Throwable $e){check(true,$s);return;}throw new RuntimeException($s);}
$r=sys_get_temp_dir().'/zc-paged-'.bin2hex(random_bytes(6));mkdir($r);mkdir($r.'/public');mkdir($r.'/public/wp-content',0700);mkdir($r.'/public/wp-content/themes',0700);mkdir($r.'/public/wp-content/themes/test',0700);
try{
 for($i=0;$i<20005;$i++)file_put_contents($r.'/public/wp-content/themes/test/f'.$i.'.txt',(string)$i);
 file_put_contents($r.'/public/wp-content/themes/test/.DS_Store','metadata');
 file_put_contents($r.'/public/wp-content/themes/test/.gitattributes','metadata');
 $store=new ZoerConnect\PagedExport($r.'/private',[$r.'/public'],$r.'/public','owner-a');$id=str_repeat('a',32);$input=['clientId'=>$id,'profile'=>['name'=>'many','themes'=>true],'database'=>false];
 $j=$store->create($input,['url'=>'https://example.test','prefix'=>'wp_']);check($j['fileCount']===0&&$j['phase']==='files','create defers scanning');
 $j=$store->step($id,fn()=>null);check($j['status']==='preparing'&&$j['fileCount']<250,'scan bounded by entries');
 $store=new ZoerConnect\PagedExport($r.'/private',[$r.'/public'],$r.'/public','owner-a');$before=$store->create($input,[]);check($before['fileCount']===$j['fileCount'],'new request resumes durable scan');
 do{$j=$store->step($id,fn()=>null);}while($j['status']!=='ready');check($j['fileCount']===20005&&!isset($j['files']),'more than twenty thousand files without giant response');
 $files=[];do{$p=$store->manifest($id,count($files));check(count($p['files'])<=500,'manifest page bounded');$files=array_merge($files,$p['files']);}while(count($files)<$p['total']);check(count(array_unique(array_column($files,'path')))===20005,'manifest has no skipped or duplicate files');check(!in_array('wp-content/themes/test/.DS_Store',array_column($files,'path'),true)&&!in_array('wp-content/themes/test/.gitattributes',array_column($files,'path'),true),'host metadata absent from paged export');
 $b=$store->batch($id,0,0);check(count($b['chunks'])===32,'small files batched');foreach($b['chunks'] as $c)check(hash('sha256',base64_decode($c['data']))===$files[$c['index']]['sha256'],'download checksum');check($store->batch($id,0,0)===$b,'interrupted response retry is identical');
 $other=new ZoerConnect\PagedExport($r.'/private',[$r.'/public'],$r.'/public','owner-b');denied(fn()=>$other->manifest($id,0),'revoked key generation denied');
 $path=$r.'/private/paged-exports/'.$id.'/state.json';$s=json_decode(file_get_contents($path),true);$s['expiresAt']=time()-1;file_put_contents($path,json_encode($s));denied(fn()=>$store->manifest($id,0),'expired export denied');check($store->cancel($id)['status']==='cancelled','expired export cleanup');check($store->cancel($id)['status']==='cancelled','cancel retry');
 $input['database']=true;$input['profile']=['name'=>'db'];$store->create($input,[]);denied(fn()=>$store->step($id,function($p){file_put_contents($p.'.partial','bad');throw new RuntimeException('interrupted');}),'database interruption');$j=$store->step($id,fn($p)=>file_put_contents($p,'complete snapshot'));$j=$store->step($id,fn()=>null);check($j['status']==='ready'&&$j['fileCount']===1,'database restart clears partial snapshot');$store->cancel($id);
 symlink('/etc/passwd',$r.'/public/wp-content/themes/test/unsafe');$input['database']=false;$input['profile']=['name'=>'unsafe','themes'=>true];$store->create($input,[]);denied(function()use($store,$id){do{$j=$store->step($id,fn()=>null);}while($j['status']!=='ready');},'symlink fails closed');$store->cancel($id);
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($r);}
