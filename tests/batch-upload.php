<?php
// Batched upload (/imports/{id}/batch): framing, shared appendBlock rules with /chunks,
// index files, cursor, deadline, deflate and JSON transports, against the real journal.
require __DIR__.'/fixtures/import-site.php';
use ZoerConnect\TransferImport;
use ZoerConnect\StageStore;
use ZoerConnect\BatchUpload;
use ZoerConnect\BatchBodyLimit;
const B=StageStore::CHUNK;
function frame(array $spans,array $payloads,?string $enc=null,array $extra=[]): string {
 $header=json_encode($extra+['v'=>1,'spans'=>$spans,'payloadBytes'=>array_sum(array_map('strlen',$payloads)),'enc'=>$enc]);
 return 'ZBT1'.pack('N',strlen($header)).$header.implode('',$payloads);
}
function stream(string $bytes){$h=fopen('php://memory','w+b');fwrite($h,$bytes);rewind($h);return $h;}
function framed(string $bytes,?int $length=null,int $max=8388608): BatchUpload {return BatchUpload::framed(stream($bytes),$length??strlen($bytes),$max);}
/** Raw spans [[index, offset, bytes], ...] -> framed body. */
function raw(array $spans): BatchUpload {return framed(frame(array_map(fn($s)=>[$s[0],$s[1],strlen($s[2])],$spans),array_column($spans,2)));}
function blocks(string $data): array {return array_map(fn($c)=>hash('sha256',$c),$data===''?[]:str_split($data,B));}
function file_artifact(string $path,string $data,bool $verified=true): array {return ['path'=>$path,'bytes'=>strlen($data),'sha256'=>hash('sha256',$data)]+($verified?['chunkSha256'=>blocks($data)]:[]);}
$base=fixtureBase();$target='https://dest.example';$owner=hash('sha256','batch owner');
$db=destination();
$new=fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target,true);
try{
 $new()->installFence();
 // --- framing ------------------------------------------------------------------
 expect(BatchUpload::iniBytes('8M')===8388608&&BatchUpload::iniBytes('512K')===524288&&BatchUpload::iniBytes('1G')===1073741824&&BatchUpload::iniBytes('0')===0&&BatchUpload::iniBytes('')===0,'php.ini quantities parsed');
 $post=BatchUpload::iniBytes(ini_get('post_max_size'));$l=BatchUpload::limits();
 expect($l===['blockBytes'=>262144,'maxBatchBytes'=>max(B,min($post>0?$post-65536:PHP_INT_MAX,8388608)),'maxJsonBatchBytes'=>2097152,'maxSpans'=>1024,'deadlineMs'=>2000],'limits: min(post_max_size - 64 KiB, 8 MiB), 1024 spans, 2 s');
 $upload=BatchUpload::iniBytes(ini_get('upload_max_filesize'));
 expect(BatchUpload::limits('multipart')['maxBatchBytes']===max(B,min($l['maxBatchBytes'],$upload>0?$upload-65544:PHP_INT_MAX)),'multipart limit also within upload_max_filesize');
 expect(in_array('octet-stream',BatchUpload::transports(),true)&&in_array('json',BatchUpload::transports(),true),'transports advertised');
 $ok=frame([[1,0,5]],['hello']);
 reject(fn()=>framed('ZBT2'.substr($ok,4)),'bad magic refused','Invalid batch frame');
 reject(fn()=>framed('ZBT1'.pack('N',70000).str_repeat(' ',70000)),'header over 64 KiB refused','64 KiB');
 reject(fn()=>framed($ok,strlen($ok)+1),'Content-Length mismatch refused','Content-Length mismatch');
 reject(fn()=>framed(frame([[1,0,5]],['hello'],null,['payloadBytes'=>6]).'x'),'payloadBytes mismatch refused','payloadBytes');
 reject(fn()=>framed(frame(array_fill(0,1025,[1,0,1]),array_fill(0,1025,'x'))),'more than 1024 spans refused','1 to 1024 spans');
 reject(fn()=>framed(frame([[1,-1,5]],['hello'])),'negative offset refused','Invalid batch span');
 reject(fn()=>framed(frame([[1,0,5,5]],['hello'])),'raw span with four fields refused','Invalid batch span');
 reject(fn()=>framed(frame([[1,0,5]],['hello'],'gzip')),'unknown encoding refused','Invalid batch header');
 reject(fn()=>framed(frame([[1,0,5]],['hello'],null,['extra'=>1])),'unknown header key refused','Invalid batch header');
 reject(fn()=>framed('ZBT1'.pack('N',5).'{"v":'),'malformed header JSON refused','Invalid batch header');
 $e=reject(fn()=>framed(frame([[1,0,9000000]],[str_repeat('x',9000000)])),'payload over maxBatchBytes -> body limit');expect($e instanceof BatchBodyLimit,'  ... as BatchBodyLimit (413 zoer_import_body_limit)');
 $e=reject(fn()=>BatchUpload::framed(stream(''),4096,8388608),'Content-Length > 0 with no body bytes -> body limit');expect($e instanceof BatchBodyLimit,'  ... as BatchBodyLimit (PHP discarded the body)');
 $e=reject(fn()=>BatchUpload::framed(stream(''),9000000,8388608),'declared length beyond the limit refused before reading');expect($e instanceof BatchBodyLimit,'  ... as BatchBodyLimit');
 reject(fn()=>BatchUpload::json(['v'=>1,'spans'=>[[0,0,5]]]),'JSON span without data refused','Invalid batch span');
 reject(fn()=>BatchUpload::json(['v'=>2,'spans'=>[[0,0,5,'aGVsbG8=']]]),'JSON version 2 refused','Invalid batch header');

 // --- import 1: many small files, a multi-block file, the database, a legacy file ----------
 $legacyDump=snapshot(source(),$base.'/legacy.sql');$sql=file_get_contents($base.'/legacy.sql');
 $small=[];for($n=0;$n<240;$n++)$small['wp-content/uploads/batch/f-'.$n.'.txt']=$n%40===0?'':random_bytes(random_int(1,3000));
 $big=random_bytes(2*B+175712);$legacy=random_bytes(1000);$mixed=random_bytes(5000);
 $files=[];foreach($small as $path=>$data)$files[]=file_artifact($path,$data);
 $files[]=file_artifact('wp-content/uploads/batch/big.bin',$big);$files[]=file_artifact('wp-content/uploads/batch/legacy.bin',$legacy,false);$files[]=file_artifact('wp-content/uploads/batch/mixed.bin',$mixed);
 $blobs=[$sql,...array_values($small),$big,$legacy,$mixed];$bigIndex=count($small)+1;$legacyIndex=$bigIndex+1;$mixedIndex=$bigIndex+2;$count=count($blobs);
 $body=['id'=>str_repeat('1',32),'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>'wp_','wordpressOnlyWriters'=>true,'destinationAdminId'=>1,'database'=>$legacyDump,'files'=>$files];
 $s=$new()->create($body);$id=$s['id'];$dir=$base.'/private/import-'.$id;
 $u=json_decode(file_get_contents($dir.'/upload.json'),true);
 expect(filesize($dir.'/artifacts.idx')===16*$count&&filesize($dir.'/blocks.idx')===32*$u['blocks']&&$u['blocks']===array_sum(array_map(fn($d)=>count(blocks($d)),[$sql,...array_values($small),$big,$mixed]))&&$u['cursor']===['index'=>0,'offset'=>0]&&$u['totalBytes']===array_sum(array_map('strlen',$blobs)),'index files and upload.json written at create (legacy artifact has no blocks)');
 $head=new ReflectionMethod(TransferImport::class,'head');$state=file_get_contents($dir.'/state.json');
 file_put_contents($dir.'/state.json',substr($state,0,300));
 expect($head->invoke($new(),$id)==='uploading','journal phase and binding read from its leading keys without decoding the artifact list');
 file_put_contents($dir.'/state.json',$state);
 reject(fn()=>(new TransferImport($db,$base.'/public',$base.'/private',hash('sha256','other generation'),$target,true))->batch($id,raw([[1,0,'x']])),'batch refused for another credential generation','credential generation');

 // Many small files and the first block of a multi-block file in one request.
 $spans=[[0,0,$sql]];foreach(array_values($small) as $n=>$data)if($data!=='')$spans[]=[$n+1,0,$data];$spans[]=[$bigIndex,0,substr($big,0,B)];
 $r=$new()->batch($id,raw($spans));
 expect($r['v']===1&&$r['phase']==='uploading'&&$r['acceptedSpans']===count($spans)&&$r['appendedBytes']===array_sum(array_map(fn($s)=>strlen($s[2]),$spans))&&$r['rejected']===null&&$r['deadlineHit']===false&&$r['complete']===false&&is_int($r['serverMs']),'many small files + one block of a large file accepted in one batch');
 expect($r['cursor']===['index'=>$bigIndex,'offset'=>B]&&$r['uploadedBytes']===$r['appendedBytes']&&$r['totalBytes']===array_sum(array_map('strlen',$blobs)),'cursor skips completed and zero-byte artifacts, stops inside the large file');
 expect(array_keys($r)===['v','phase','acceptedSpans','appendedBytes','cursor','uploadedBytes','totalBytes','complete','deadlineHit','serverMs','rejected'],'small response shape (limits appended by the route)');
 $same=true;foreach(array_values($small) as $n=>$data)$same=$same&&file_get_contents($dir.'/artifact-'.($n+1).'/data')===$data;expect($same&&file_get_contents($dir.'/artifact-0/data')===$sql,'staged bytes equal the sources');
 $again=$new()->batch($id,raw($spans));
 expect($again['acceptedSpans']===count($spans)&&$again['appendedBytes']===0&&$again['cursor']===$r['cursor']&&$again['uploadedBytes']===$r['uploadedBytes']&&$again['rejected']===null,'retry of an already-applied batch is idempotent');
 // Gap, digest mismatch, bounds, stop at the first rejection.
 $r=$new()->batch($id,raw([[$bigIndex,2*B,substr($big,2*B)]]));
 expect($r['acceptedSpans']===0&&$r['rejected']===['span'=>0,'code'=>'gap','expectedOffset'=>B]&&filesize($dir."/artifact-$bigIndex/data")===B,'gap rejected with the expected offset; nothing written');
 $bad=substr($big,B,B);$bad[7]=chr(ord($bad[7])^1);
 $r=$new()->batch($id,raw([[$bigIndex,B,$bad]]));
 expect($r['rejected']===['span'=>0,'code'=>'digest_mismatch','expectedOffset'=>B]&&filesize($dir."/artifact-$bigIndex/data")===B,'digest mismatch rejected before any write');
 $r=$new()->batch($id,raw([[$bigIndex,B,substr($big,B,B)],[$bigIndex,2*B+1,'x'],[$bigIndex,2*B,substr($big,2*B)]]));
 expect($r['acceptedSpans']===1&&$r['rejected']===['span'=>1,'code'=>'bounds','expectedOffset'=>2*B]&&filesize($dir."/artifact-$bigIndex/data")===2*B&&$r['cursor']===['index'=>$bigIndex,'offset'=>2*B],'misaligned span rejected; earlier span kept, later span not applied');
 foreach(['short non-final length'=>[$bigIndex,2*B,'x'],'beyond the artifact'=>[$bigIndex,3*B,str_repeat('x',B)]] as $label=>$span){$r=$new()->batch($id,raw([$span]));expect($r['rejected']['code']==='bounds'&&$r['acceptedSpans']===0,"bounds: $label");}
 $r=$new()->batch($id,raw([[9999,0,'x']]));expect($r['rejected']===['span'=>0,'code'=>'bounds','expectedOffset'=>null],'bounds: unknown artifact (expectedOffset null)');
 $r=$new()->batch($id,raw([[$legacyIndex,0,$legacy]]));expect($r['rejected']===['span'=>0,'code'=>'unverifiable','expectedOffset'=>0]&&filesize($dir."/artifact-$legacyIndex/data")===0,'artifact without chunkSha256 rejected as unverifiable');
 // Torn tail: 1000 wrong bytes after the last complete block are repaired.
 file_put_contents($dir."/artifact-$bigIndex/data",random_bytes(1000),FILE_APPEND);
 expect($new()->upload($id)['cursor']===['index'=>$bigIndex,'offset'=>2*B],'view=upload: cursor block-aligned before a torn tail');
 $r=$new()->batch($id,raw([[$bigIndex,2*B,substr($big,2*B)]]));
 expect($r['rejected']===null&&$r['appendedBytes']===strlen($big)-2*B-1000&&file_get_contents($dir."/artifact-$bigIndex/data")===$big&&$r['cursor']===['index'=>$legacyIndex,'offset'=>0],'torn tail repaired by rewriting the verified block');
 // Mixing: /chunks for the legacy file and part of the mixed file, then /batch again.
 $before=json_encode($new()->status($id));$c=$new()->chunk($id,$legacyIndex,0,$legacy);
 expect(array_keys($c)===array_keys(json_decode($before,true))&&$c['offsets'][$legacyIndex]===1000,'/chunks keeps its full summary response');
 reject(fn()=>$new()->chunk($id,$mixedIndex,0,str_repeat('x',5000)),'/chunks digest mismatch keeps its message','Database chunk digest mismatch.');
 reject(fn()=>$new()->chunk($id,$legacyIndex,0,random_bytes(1000)),'/chunks conflicting legacy retry keeps its message','Conflicting chunk retry.');
 expect(json_encode($new()->chunk($id,$legacyIndex,0,$legacy))===json_encode($new()->status($id)),'/chunks retry response identical to status');
 $v=$new()->upload($id);
 expect($v['cursor']===['index'=>$mixedIndex,'offset'=>0]&&$v['uploadedBytes']===array_sum(array_map('strlen',$blobs))-5000&&$v['phase']==='uploading'&&$v['totalBytes']===array_sum(array_map('strlen',$blobs)),'view=upload sees /chunks progress (cursor scans forward)');
 $new()->chunk($id,$mixedIndex,0,$mixed);
 $r=$new()->batch($id,raw([[$mixedIndex,0,$mixed]]));
 expect($r['acceptedSpans']===1&&$r['appendedBytes']===0&&$r['complete']===true&&$r['cursor']===['index'=>$count,'offset'=>0]&&$r['uploadedBytes']===$r['totalBytes'],'/batch after /chunks: skipped, upload complete');
 expect($new()->status($id)['progress']['uploadedBytes']===$r['totalBytes'],'summary progress agrees');
 // Wrong phase.
 $s=$new()->step($id);expect($s['phase']==='checking_artifacts','uploading -> checking_artifacts');
 reject(fn()=>$new()->batch($id,raw([[0,0,substr($sql,0,min(B,strlen($sql)))]])),'batch refused outside uploading','not accepting uploads');
 $v=$new()->upload($id);expect($v===['cursor'=>['index'=>$count,'offset'=>0],'uploadedBytes'=>$r['totalBytes'],'totalBytes'=>$r['totalBytes'],'phase'=>'checking_artifacts'],'view=upload after uploading');
 [$s]=drive($new,$id,['verification_required'],5000);
 expect(file_get_contents($base.'/public/wp-content/uploads/batch/big.bin')===$big&&file_get_contents($base.'/public/wp-content/uploads/batch/f-1.txt')===$small['wp-content/uploads/batch/f-1.txt']&&file_get_contents($base.'/public/wp-content/uploads/batch/f-0.txt')==='','batched artifacts pass checking_artifacts and publish');
 $new()->finish($id);for($n=0;$n<20&&!($s=$new()->cleanup($id))['cleanedUp'];$n++);
 expect($s['cleanedUp']===true&&array_values(array_diff(scandir($dir),['.','..']))===['state.json'],'cleanup removes index files and upload.json');
 $v=$new()->upload($id);expect($v['cursor']['index']===$count&&$v['uploadedBytes']===$v['totalBytes']&&!is_file($dir.'/upload.json'),'view=upload after cleanup does not rebuild indexes');

 // --- import 2: lazy indexes, deflate, JSON transport, deadline, pause ----------------------
 $text=str_repeat("INSERT INTO `wp_posts` VALUES ('compressible text');\n",14000);$text=substr($text,0,3*B-5000);$tiny='tiny file';$three=random_bytes(3*B);
 $files=[file_artifact('wp-content/uploads/b2/text.sql',$text),file_artifact('wp-content/uploads/b2/tiny.txt',$tiny),file_artifact('wp-content/uploads/b2/three.bin',$three),file_artifact('wp-content/uploads/b2/json.bin',$json=random_bytes(B+10)),file_artifact('wp-content/uploads/b2/random.bin',$rnd=random_bytes(2*B+7))];
 $s=$new()->create(['id'=>str_repeat('2',32),'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>'wp_','wordpressOnlyWriters'=>true,'files'=>$files]);$id=$s['id'];$dir=$base.'/private/import-'.$id;
 foreach(['artifacts.idx','blocks.idx','upload.json'] as $f)unlink($dir.'/'.$f);
 $deflate=function(array $spans,array $mutate=[]){$payloads=[];$shape=[];foreach($spans as $k=>[$i,$o,$data]){$z=zlib_encode($data,ZLIB_ENCODING_DEFLATE);$z=($mutate[$k]??fn($z)=>$z)($z);$payloads[]=$z;$shape[]=[$i,$o,strlen($data),strlen($z)];}return framed(frame($shape,$payloads,'deflate'));};
 $r=$new()->batch($id,$deflate([[1,0,$tiny]]));
 expect($r['acceptedSpans']===1&&file_get_contents($dir.'/artifact-1/data')===$tiny&&is_file($dir.'/upload.json')&&filesize($dir.'/artifacts.idx')===80,'indexes built lazily for a journal without them; deflate span accepted');
 $r=$new()->batch($id,framed(frame([[0,0,B,100]],[random_bytes(100)],'deflate')));
 expect($r['rejected']===['span'=>0,'code'=>'decode','expectedOffset'=>0]&&filesize($dir.'/artifact-0/data')===0,'invalid deflate stream rejected as decode');
 $long=zlib_encode(substr($text,0,2*B),ZLIB_ENCODING_DEFLATE);
 $r=$new()->batch($id,framed(frame([[0,0,B,strlen($long)]],[$long],'deflate')));
 expect($r['rejected']['code']==='decode'&&filesize($dir.'/artifact-0/data')===0,'deflate output beyond rawLen rejected before any write (bounded inflate)');
 $short=zlib_encode(substr($text,0,B-1),ZLIB_ENCODING_DEFLATE);
 $r=$new()->batch($id,framed(frame([[0,0,B,strlen($short)]],[$short],'deflate')));
 expect($r['rejected']['code']==='decode'&&filesize($dir.'/artifact-0/data')===0,'deflate output shorter than rawLen rejected');
 $r=$new()->batch($id,$deflate([[0,0,substr($text,0,B)]],[fn($z)=>$z.'trailing']));
 expect($r['rejected']['code']==='decode'&&$r['acceptedSpans']===0,'bytes after the zlib stream end rejected');
 $r=$new()->batch($id,$deflate([[0,0,$text],[4,0,$rnd]]));
 expect($r['acceptedSpans']===2&&$r['rejected']===null&&file_get_contents($dir.'/artifact-0/data')===$text&&file_get_contents($dir.'/artifact-4/data')===$rnd&&$r['cursor']===['index'=>2,'offset'=>0]&&$r['uploadedBytes']===strlen($text)+strlen($tiny)+strlen($rnd),'multi-block deflate spans accepted (compressible and incompressible); bytes ahead of the cursor counted');
 // Deadline: a zero budget still makes progress one block at a time.
 $r=$new()->batch($id,raw([[2,0,$three],[3,0,$json]]),null,0.0);
 expect($r['deadlineHit']===true&&$r['acceptedSpans']===0&&$r['appendedBytes']===B&&$r['cursor']===['index'=>2,'offset'=>B]&&$r['complete']===false,'deadline: one block per request once the budget is spent');
 $r=$new()->batch($id,raw([[2,0,$three]]),microtime(true)-5);
 expect($r['deadlineHit']===true&&$r['appendedBytes']===0&&$r['cursor']===['index'=>2,'offset'=>B],'deadline: an already-applied first block still counts as progress, then stops');
 $r=$new()->batch($id,raw([[2,0,$three]]));
 expect($r['deadlineHit']===false&&$r['acceptedSpans']===1&&$r['appendedBytes']===2*B&&$r['serverMs']<2000,'retry within budget completes the span');
 // Truncated octet-stream body (client disconnect): decode, earlier blocks kept.
 $frame=frame([[3,0,B+10]],[$json]);$r=$new()->batch($id,BatchUpload::framed(stream(substr($frame,0,-5)),strlen($frame),8388608));
 expect($r['rejected']['code']==='decode'&&filesize($dir.'/artifact-3/data')===B,'truncated body: decode rejection after the complete first block');
 // JSON transport.
 $r=$new()->batch($id,BatchUpload::json(['v'=>1,'spans'=>[[3,B,10,'!!!']]]));
 expect($r['rejected']['code']==='decode','JSON transport: invalid base64 rejected');
 $r=$new()->batch($id,BatchUpload::json(['v'=>1,'spans'=>[[3,B,10,base64_encode('short')]]]));
 expect($r['rejected']['code']==='decode','JSON transport: length mismatch rejected');
 $z=zlib_encode(substr($json,0,B),ZLIB_ENCODING_DEFLATE);
 $r=$new()->batch($id,BatchUpload::json(['v'=>1,'enc'=>'deflate','spans'=>[[3,0,B,strlen($z)+1,base64_encode($z)]]]));
 expect($r['rejected']['code']==='decode','JSON deflate: encLen mismatch rejected');
 $r=$new()->batch($id,BatchUpload::json(['v'=>1,'enc'=>'deflate','spans'=>[[3,0,B,strlen($z),base64_encode($z)]]]));
 expect($r['acceptedSpans']===1&&$r['appendedBytes']===0&&$r['rejected']===null,'JSON deflate span (stored zlib block) accepted and verified');
 reject(fn()=>BatchUpload::json(['v'=>1,'enc'=>'deflate','spans'=>[[3,0,B,base64_encode($z)]]]),'JSON deflate span without encLen refused','Invalid batch span');
 $p=$new()->pause($id);expect($p['phase']==='paused','pause while uploading');
 reject(fn()=>$new()->batch($id,BatchUpload::json(['v'=>1,'spans'=>[[3,B,10,base64_encode(substr($json,B))]]])),'batch refused while paused','not accepting uploads');
 $new()->resume($id);
 $r=$new()->batch($id,BatchUpload::json(['v'=>1,'spans'=>[[3,0,B,base64_encode(substr($json,0,B))],[3,B,10,base64_encode(substr($json,B))]]]));
 expect($r['acceptedSpans']===2&&$r['complete']===true&&$r['uploadedBytes']===$r['totalBytes']&&file_get_contents($dir.'/artifact-3/data')===$json,'JSON transport completes the upload');
 [$s]=drive($new,$id,['verification_required'],5000);expect(file_get_contents($base.'/public/wp-content/uploads/b2/text.sql')===$text,'deflated artifacts verify and publish');
 $s=rollbackAll($new,$id);expect($s['phase']==='rolled_back','rollback');
 reject(fn()=>$new()->batch($id,raw([[1,0,$tiny]])),'batch refused after rollback','not accepting uploads');

 // --- filter -------------------------------------------------------------------------------
 function apply_filters($name,$value,...$args){return $name==='zoer_connect_max_batch_bytes'?1048576:$value;}
 expect(BatchUpload::limits()['maxBatchBytes']===max(B,min(1048576,$post>0?$post-65536:1048576)),'zoer_connect_max_batch_bytes filter applied within PHP limits');
 echo "PASS batched upload\n";
}finally{removeTree($base);}
