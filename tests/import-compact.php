<?php
// Real durable import/recovery transitions on temporary files and the existing
// synthetic database; compact transport must never alter the persisted job.
// The 100k-artifact case keeps both synthetic metadata and the real decoded
// journal in memory. Compact bounds transport, not journal decoding memory.
ini_set('memory_limit','512M');
require __DIR__.'/fixtures/import-site.php';
require __DIR__.'/../includes/Plugin.php';
require __DIR__.'/../includes/ConnectionKey.php';
use ZoerConnect\TransferImport;
use ZoerConnect\ConnectionKey;
use ZoerConnect\Plugin;
$base=fixtureBase();$target='https://dest.example';$db=destination();
$wpdb=$db;
[$token,$record]=ConnectionKey::create(1);$owner=$record['hash'];
$db->replace($db->options,['option_name'=>ConnectionKey::OPTION,'option_value'=>serialize($record),'autoload'=>'no']);
define('ABSPATH',$base.'/public/');define('ZOER_CONNECT_STORAGE_DIR',$base.'/private');
$_SERVER=['DOCUMENT_ROOT'=>$base.'/public','REQUEST_METHOD'=>'GET','HTTP_X_ZOER_CONNECTION'=>$token];$_GET=[];
function is_ssl(){return true;}function is_multisite(){return false;}
function get_option($name,$default=null){global $db;return option($db,$name)??$default;}
$new=fn(bool $compact=false)=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target,true,$compact);
$statePath=fn($id)=>$base.'/private/import-'.$id.'/state.json';
$read=fn($id)=>json_decode(file_get_contents($statePath($id)),true);
$body=fn($id)=>['id'=>$id,'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>'wp_','migrationMode'=>'shared-replacement','replacementAccepted'=>true,
    'files'=>[['path'=>'wp-content/themes/fixture/style.css','bytes'=>strlen('new verified style'),'sha256'=>hash('sha256','new verified style'),'chunkSha256'=>[hash('sha256','new verified style')]]]];
try{
 $new()->installFence();$path=$base.'/public/wp-content/themes/fixture/style.css';file_put_contents($path,'original style');
 $s=$new(true)->create($body(str_repeat('a',32)));$id=$s['id'];
 expect($s['responseView']==='compact'&&!isset($s['artifacts'],$s['offsets'],$s['options'])&&$s['artifactCount']===1,'explicit compact creation omits unbounded arrays');
 expect($s['target']===$target&&$s['sourceUrl']==='https://source.example'&&$s['migrationMode']==='shared-replacement','compact response retains exact destination binding');
 expect($s['progress']['uploadedBytes']===null&&!$s['progress']['uploadProgressAvailable'],'compact upload progress directs clients to the authoritative upload view');
 $before=file_get_contents($statePath($id));$full=$new()->status($id);$compact=$new(true)->status($id);
 expect(file_get_contents($statePath($id))===$before&&isset($full['artifacts'],$full['offsets'],$full['options'])&&!isset($full['responseView']),'choosing response view does not mutate the journal or legacy API');
 $_GET=['view'=>'compact'];$native=Plugin::earlyImportRecovery('/zoer-connect/v1/imports/'.$id);
 expect($native===$compact,'authenticated native query explicitly selects compact response');
 $_GET=[];expect(Plugin::earlyImportRecovery('/zoer-connect/v1/imports/'.$id)===$full,'native default status remains fully detailed');
 $status=Plugin::earlyImportRecovery('/zoer-connect/v1/status');expect($status['capabilities']['compactImportResponses']===true,'native status advertises optional compact capability');
 $paused=$new(true)->pause($id);
 expect($paused['phase']==='paused'&&$paused['resumePhase']==='uploading'&&$paused['progress']['uploadedBytes']===null,'compact pause preserves resume phase without claiming upload completion');
 expect($new(true)->resume($id)['phase']==='uploading','compact resume advances the existing durable transition');
 $new(true)->chunk($id,0,0,'new verified style');$u=$new(true)->upload($id);
 expect($u['uploadedBytes']===$u['totalBytes']&&$u['cursor']['index']===1,'view=upload remains exact with a compact-capable importer');
 [$s]=drive(fn()=>$new(true),$id,['verification_required']);
 expect(file_get_contents($path)==='new verified style'&&is_file($base.'/private/write-fence.json'),'compact steps retain the writer fence and verified publication');
 $s=$new(true)->finish($id);$before=file_get_contents($statePath($id));$fenced=is_file($base.'/private/write-fence.json');
 expect($s['phase']==='complete'&&!$fenced&&$new(true)->finish($id)===$s&&file_get_contents($statePath($id))===$before,'compact finish is idempotent and never rewrites a complete journal');
 $_GET=['view'=>'compact'];$_SERVER['REQUEST_METHOD']='POST';
 expect(Plugin::earlyImportRecovery('/zoer-connect/v1/imports/'.$id.'/finish')===$s&&file_get_contents($statePath($id))===$before,'native compact finish replay acknowledges completion without journal mutation');
 $_GET=[];$_SERVER['REQUEST_METHOD']='GET';
 expect($new()->finish($id)['artifacts']===$full['artifacts'],'legacy finish on complete still returns all detailed artifacts');
 $s=rollbackAll(fn()=>$new(true),$id);
 expect($s['phase']==='rolled_back'&&file_get_contents($path)==='original style'&&!is_file($base.'/private/write-fence.json'),'compact rollback restores original bytes and releases the fence');
 $s=$new(true)->create($body(str_repeat('b',32)));$id=$s['id'];$new(true)->chunk($id,0,0,'new verified style');[$s]=drive(fn()=>$new(true),$id,['verification_required']);$new(true)->finish($id);
 file_put_contents($path,'later human edit');
 reject(fn()=>rollbackAll(fn()=>$new(true),$id),'compact rollback refuses later destination edits','Destination edited');
 $s=$new(true)->status($id);
 expect($s['phase']==='complete'&&$s['rollbackRefused']&&file_get_contents($path)==='later human edit'&&!is_file($base.'/private/write-fence.json'),'compact response preserves recovery refusal without changing later edits');
 // A large historical fixture makes the old response exceed the host's 1 MiB
 // control limit. No 100,000-file tree is created; compact must not stat artifacts.
 $state=$read($id);$state['artifacts']=[];
 for($i=0;$i<100000;$i++)$state['artifacts'][]=['kind'=>'file','path'=>'wp-content/themes/fixture/file-'.$i.'.css','bytes'=>1,'sha256'=>hash('sha256',(string)$i)];
 $state['samples']=array_fill(0,20,['table'=>'wp_posts','column'=>'post_content','before'=>str_repeat('before ',40),'after'=>str_repeat('after ',40)]);
 $state['error']=['code'=>'zoer_import_failed','message'=>'Destination changed since preparation.','phase'=>'reserving'];
 $state['tables']=[['name'=>'wp_posts','source'=>'wp_posts','rows'=>4641,'replacements'=>103,'created'=>false,'schemaReplaced'=>true]];
 file_put_contents($statePath($id),json_encode($state,JSON_THROW_ON_ERROR));$before=file_get_contents($statePath($id));
 $small=$new(true)->finish($id);$encoded=json_encode($small,JSON_THROW_ON_ERROR);
 expect(strlen($encoded)<16384&&$small['artifactCount']===100000&&$small['stats']['rows']===4641&&$small['stats']['replacements']===103&&$small['stats']['tableCount']===1,'compact acknowledgement stays below 16 KiB for 100,000 artifacts');
 expect($small['rollbackRefused']&&$small['error']===$state['error']&&$small['stats']['sampleCount']===20&&count($small['stats']['samples'])===3&&$small['stats']['samplesTruncated'],'compact response retains error, refusal and bounded sample coverage');
 $large=$new()->status($id);expect(strlen(json_encode($large,JSON_THROW_ON_ERROR))>1048576&&count($large['artifacts'])===100000&&count($large['stats']['samples'])===20,'legacy full response remains available above 1 MiB');
 expect(file_get_contents($statePath($id))===$before,'large compact and full reads leave the complete journal unchanged');
 // Escaped text expands JSON; even worst-case bounded excerpts and a maximum
 // accepted identity must remain small independently of artifact count.
 $state['samples']=array_fill(0,20,['table'=>str_repeat("\0",64),'column'=>str_repeat("\0",64),'before'=>str_repeat("\0",240),'after'=>str_repeat("\0",240)]);
 $state['error']=['code'=>str_repeat("\0",64),'message'=>str_repeat("\0",300),'phase'=>str_repeat("\0",64)];
 $state['sourceUrl']='https://source.example/'.str_repeat('/',2025);
 file_put_contents($statePath($id),json_encode($state,JSON_THROW_ON_ERROR));
 expect(strlen(json_encode($new(true)->status($id),JSON_THROW_ON_ERROR))<32768,'compact acknowledgement bounds escaped excerpts and maximum source identity');
 $tooLong=$body(str_repeat('c',32));$tooLong['sourceUrl']='https://source.example/'.str_repeat('a',2049);
 reject(fn()=>$new(true)->create($tooLong),'compact creation refuses oversized identity before job allocation','Use the detailed API');
 expect(!is_dir($base.'/private/import-'.str_repeat('c',32)),'compact identity refusal allocates no journal or artifacts');
 $state['sourceUrl']='https://source.example/'.str_repeat('a',2049);file_put_contents($statePath($id),json_encode($state,JSON_THROW_ON_ERROR));$before=file_get_contents($statePath($id));
 reject(fn()=>$new(true)->finish($id),'oversized legacy identity refuses compact before mutation','Use the detailed API');
 expect($new()->status($id)['sourceUrl']===$state['sourceUrl']&&file_get_contents($statePath($id))===$before,'oversized identities remain exact through the full legacy API');
 echo "PASS compact import transport, legacy detail, native capability, bounded acknowledgement and durable recovery\n";
}finally{removeTree($base);}
