<?php
foreach(['StageStore','Selection','ExportProfile','FileExporter','DatabaseExporter','PagedExport'] as $c)require_once __DIR__.'/../includes/'.$c.'.php';
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
$r=sys_get_temp_dir().'/zc-paged-empty-'.bin2hex(random_bytes(6));
mkdir($r.'/public/wp-content/themes/test',0700,true);
file_put_contents($r.'/public/wp-content/themes/test/empty.txt','');
file_put_contents($r.'/public/wp-content/themes/test/text.txt','content');
file_put_contents($r.'/public/wp-content/themes/test/.phpunit.result.cache','development cache');
mkdir($r.'/public/wp-content/themes/test/.speakeasy');file_put_contents($r.'/public/wp-content/themes/test/.speakeasy/gen.yaml','development generator');
try {
 $id=str_repeat('e',32);$store=new ZoerConnect\PagedExport($r.'/private',[$r.'/public'],$r.'/public','owner');
 $store->create(['clientId'=>$id,'profile'=>['name'=>'empty','themes'=>true],'database'=>false,'largeTransfer'=>true],[]);
 // Preserve the zero-byte copy checkpoint produced by 0.5.0 before it failed.
 $statePath=$r.'/private/paged-exports/'.$id.'/state.json';$state=json_decode(file_get_contents($statePath),true);
 $state['queue']=['wp-content/themes/test/text.txt'];$state['copy']=['path'=>'wp-content/themes/test/empty.txt','bytes'=>0,'offset'=>0,'stat'=>array_intersect_key(lstat($r.'/public/wp-content/themes/test/empty.txt'),array_flip(['dev','ino','size','mtime','ctime']))];
 file_put_contents($statePath,json_encode($state));
 $j=$store->step($id,fn()=>null);
 // Reopen the persisted checkpoint exactly as an interrupted hosted export does.
 $store=new ZoerConnect\PagedExport($r.'/private',[$r.'/public'],$r.'/public','owner');
 for($n=0;$n<10&&$j['status']!=='ready';$n++)$j=$store->step($id,fn()=>null);
 check($j['status']==='ready'&&$j['fileCount']===2,'paged export resumes across an empty file');
 $files=$store->manifest($id,0)['files'];$empty=array_search('wp-content/themes/test/empty.txt',array_column($files,'path'),true);
 check($empty!==false&&$files[$empty]['bytes']===0&&$files[$empty]['sha256']===hash('sha256','')&&$files[$empty]['digestFormat']===ZoerConnect\ExportBlocks::FORMAT,'empty snapshot carries a verified zero-block root');
 $batch=$store->batch($id,0,0);check(count($batch['chunks'])===2,'batch advances beyond the empty file');
 foreach($batch['chunks'] as $c)check(hash('sha256',base64_decode($c['data']))===$c['sha256'],'download chunk checksum');
 check($store->batch($id,0,0)===$batch,'empty-file download retries identically');
 $store->cancel($id);
 for($n=0;$n<503;$n++)file_put_contents($r.'/public/wp-content/themes/test/small'.$n.'.txt','small');
 $store->create(['clientId'=>$id,'profile'=>['name'=>'batch','themes'=>true],'database'=>false,'largeTransfer'=>true],[]);
 $before=file_get_contents($statePath);$first=$store->step($id,fn()=>null);
 check($first['fileCount']>1&&$first['fileCount']<=250&&$first['status']==='preparing','small files batch within bounded entries');
 // Simulate losing the response before committing the journal: replay truncates old bytes.
 file_put_contents($statePath,$before);$j=$store->step($id,fn()=>null);
 for($n=0;$n<20&&$j['status']!=='ready';$n++)$j=$store->step($id,fn()=>null);
 $all=$store->manifest($id,0)['files'];$all=array_merge($all,$store->manifest($id,count($all))['files']);
 check($j['status']==='ready'&&count($all)===505&&count(array_unique(array_column($all,'path')))===505,'interrupted small-file batch replays without skipped or duplicate entries');
 check(!in_array('wp-content/themes/test/.phpunit.result.cache',array_column($all,'path'),true)&&!in_array('wp-content/themes/test/.speakeasy/gen.yaml',array_column($all,'path'),true),'unsupported hidden cache and generator files are excluded');
 check(ZoerConnect\FileExporter::portableHiddenPath('wp-content/plugins/package/.github/workflows/build.yml')&&ZoerConnect\FileExporter::portableHiddenPath('wp-content/uploads/.htaccess')&&!ZoerConnect\FileExporter::portableHiddenPath('wp-content/plugins/package/.npmrc')&&!ZoerConnect\FileExporter::portableHiddenPath('wp-content/uploads/.github/file.txt'),'portable metadata remains allowed while credential files and hidden upload parents are excluded');
 $verified=true;foreach($all as $i=>$f){$p=$r.'/private/paged-exports/'.$id.'/'.$i.'.bin';$verified=$verified&&filesize($p)===$f['bytes']&&hash_file('sha256',$p.'.blocks')===$f['sha256'];}
 check($verified,'every replayed snapshot matches its advertised root');$store->cancel($id);
 for($n=0;$n<40;$n++)file_put_contents($r.'/public/wp-content/themes/test/budget'.$n.'.txt',str_repeat('b',262144));
 $store->create(['clientId'=>$id,'profile'=>['name'=>'budget','themes'=>true],'database'=>false,'largeTransfer'=>true],[]);
 $j=$store->step($id,fn()=>null);check($j['status']==='preparing'&&$j['bytes']===4194304&&$j['fileCount']===16&&$j['checkpoint']['fileOffset']===0,'batch byte budget retains the next file as a durable checkpoint');
 for($n=0;$n<20&&$j['status']!=='ready';$n++)$j=$store->step($id,fn()=>null);
 check($j['status']==='ready'&&$j['fileCount']===545,'byte-budget checkpoint resumes to completion');
 $store->cancel($id);file_put_contents($r.'/public/wp-content/themes/test/a-tiny.txt','t');
 $store->create(['clientId'=>$id,'profile'=>['name'=>'mixed download','themes'=>true],'database'=>false,'largeTransfer'=>true],[]);
 for($n=0;$n<20;$n++){$j=$store->step($id,fn()=>null);if($j['status']==='ready')break;}
 $batch=$store->batch($id,0,0);
 check(count($batch['chunks'])===16&&array_sum(array_column($batch['chunks'],'bytes'))===1+15*262144,'mixed small and block files stop before splitting a checksum block');
 $last=$batch['chunks'][count($batch['chunks'])-1];$next=$store->batch($id,$last['index']+1,0);
 check($next['chunks'][0]['index']===$last['index']+1&&$next['chunks'][0]['bytes']===262144,'next download batch resumes with a complete checksum block');
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($r);
}
