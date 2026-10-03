<?php
foreach(['StageStore','Selection','ExportProfile','FileExporter','ConnectionKey','PagedExport'] as $c)require_once __DIR__.'/../includes/'.$c.'.php';
require __DIR__.'/fixtures/paged-db.php';
define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function denied($f,$s){try{$f();}catch(Throwable $e){check(true,$s);return;}throw new RuntimeException($s);}
function get_option($key,$default=null){return $key==='home'?'https://source.example':$default;}
function is_ssl(){return true;}function is_multisite(){return false;}function current_user_can($x){return true;}function get_current_user_id(){return 7;}function wp_verify_nonce($nonce,$action){return $nonce==='nonce:'.$action;}
$r=sys_get_temp_dir().'/zc-paged-maintenance-'.bin2hex(random_bytes(6));mkdir($r,0700);mkdir($r.'/public');mkdir($r.'/public/wp-content');mkdir($r.'/private',0700);$_SERVER['DOCUMENT_ROOT']=$r.'/public';$_SERVER['REQUEST_METHOD']='POST';$_POST=['zoer_import_setup_action'=>'shared','_wpnonce'=>'nonce:zoer_import_setup_shared','replacement_accepted'=>'1'];
try {
 ZoerConnect\ImportAdmin::submit($r.'/private',$r.'/public');
 $id=str_repeat('a',32);$owner=hash('sha256','owner');$input=['clientId'=>$id,'profile'=>['name'=>'DB'],'database'=>true,'largeTransfer'=>true,'snapshotMode'=>'maintenance','sourceQuiescenceAccepted'=>true];
 $new=fn()=>new ZoerConnect\PagedExport($r.'/private',[$r.'/public'],$r.'/public',$owner);$db=new PagedDb();
 $new()->create($input,['url'=>'https://source.example','prefix'=>'Wp_']);$j=$new()->step($id,fn()=>throw new RuntimeException('Legacy snapshot callback must not run'),$db);check($j['sourcePaused']&&$j['phase']==='database','reserve source pause through paged controller');
 $fence=new ZoerConnect\WriteFence($r.'/private',$r.'/public');denied(fn()=>$fence->enter(),'source remains fenced between HTTP steps');
 do{$j=$new()->step($id,fn()=>throw new RuntimeException('Legacy callback invoked'),$db);}while($j['status']!=='ready');check(!$j['sourcePaused'],'source released after sealing');
 $manifest=$new()->manifest($id,0);check($manifest['files'][0]['digestFormat']==='sha256-blocks-v1'&&$j['source']['tables']===['posts'],'completed SQL artifact and exact tables available');
 $new()->cancel($id);check(!file_exists($r.'/private/write-fence.json'),'terminal cleanup never fences source again');
 $new()->create($input,[]);$new()->step($id,fn()=>null,$db);check(file_exists($r.'/private/write-fence.json'),'new source pause reserved');$new()->cancel($id);check(!file_exists($r.'/private/write-fence.json'),'cancel restores ordinary source access');
 $new()->create($input,[]);$new()->step($id,fn()=>null,$db);$path=$r.'/private/write-fence.json';$s=json_decode(file_get_contents($path),true);$s['expiresAt']=time()-1;file_put_contents($path,json_encode($s));$fence->expireExport();denied(fn()=>$new()->step($id,fn()=>null,$db),'expired source pause cannot resume old cursor');$new()->cancel($id);
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($r);}
