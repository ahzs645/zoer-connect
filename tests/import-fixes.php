<?php
// Regressions: legacy replacement parity, runtime options at cutover, activation
// mismatch without a stuck fence, rollback pause, admin cleanup after key rotation,
// other installs' table prefixes and lower_case_table_names folding.
require __DIR__.'/fixtures/import-site.php';
use ZoerConnect\TransferImport;
use ZoerConnect\TableStage;
use ZoerConnect\CachePurge;
use ZoerConnect\RewriteRefresh;
$base=fixtureBase();$target='https://dest.example';$owner=hash('sha256','fixes owner');
$db=destination();
$new=fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target,true);
$state=fn(string $id)=>json_decode(file_get_contents($base.'/private/import-'.$id.'/state.json'),true);
$fence=fn()=>is_file($base.'/private/write-fence.json');
try{
 $new()->installFence();
 // Source WordPress lives in /wp under its home; 0.3.14 clients send siteurl as an original URL.
 $src=source();$media='<img src="https://source.example/wp/wp-content/uploads/a.jpg"> <a href="https://source.example/about">';
 $src->insert('wp_posts',['ID'=>'13','post_author'=>'5','post_title'=>'Media','post_content'=>$media,'post_type'=>'post','post_status'=>'publish','guid'=>'https://source.example/?p=13']);
 $dump=snapshot($src,$base.'/wp.sql');$blob=[file_get_contents($base.'/wp.sql')];
 $body=fn(array $extra=[])=>['id'=>bin2hex(random_bytes(16)),'target'=>$target,'sourceUrl'=>'https://source.example','originalUrls'=>['https://source.example/wp'],'sourcePrefix'=>'wp_','wordpressOnlyWriters'=>true,'destinationAdminId'=>1,'database'=>$dump]+$extra;

 // A. No options: 0.3.14 sequential rules, byte-for-byte (home first, so /wp survives).
 $s=upload($new,$body(),$blob);[$s]=drive($new,$s['id'],['verification_required']);
 expect(row($db,'wp_posts','ID','13')['post_content']==='<img src="https://dest.example/wp/wp-content/uploads/a.jpg"> <a href="https://dest.example/about">','absent options reproduce 0.3.14 replacement output');
 $legacy=$new()->finish($s['id'])['id'];

 // B. Options present: source siteurl maps to destination siteurl in one longest-match pass,
 // and cron/maintenance markers written while staging live survive the activation swap.
 $s=upload($new,$body(['options'=>['fence'=>'activation','review'=>true]]),$blob);[$s]=drive($new,$s['id'],['review_required']);
 $cron=serialize(['1700000000'=>['wp_version_check'=>[]],'version'=>2]);
 $db->replace('wp_options',['option_name'=>'cron','option_value'=>$cron,'autoload'=>'yes']);
 $db->replace('wp_options',['option_name'=>CachePurge::OPTION,'option_value'=>'1','autoload'=>'no']);
 $db->replace('wp_options',['option_name'=>RewriteRefresh::OPTION,'option_value'=>'1','autoload'=>'no']);
 $new()->approve($s['id']);[$s]=drive($new,$s['id'],['verification_required']);
 expect(row($db,'wp_posts','ID','13')['post_content']==='<img src="https://dest.example/wp-content/uploads/a.jpg"> <a href="https://dest.example/about">','with options the source siteurl maps to the destination siteurl');
 expect(option($db,'cron')===$cron&&option($db,CachePurge::OPTION)==='1'&&option($db,RewriteRefresh::OPTION)==='1','cron and maintenance markers written after verification survive the swap');
 $optionsImport=$new()->finish($s['id'])['id'];
 $db->delete('wp_options',['option_name'=>CachePurge::OPTION]);$db->delete('wp_options',['option_name'=>RewriteRefresh::OPTION]);

 // C. A rollback in progress cannot be paused.
 $s=$new()->rollback($optionsImport);
 expect(str_starts_with($s['phase'],'rollback'),'rollback left in a rollback phase');
 reject(fn()=>$new()->pause($optionsImport),'pause refused during rollback','Import cannot pause');
 expect(rollbackAll($new,$optionsImport)['phase']==='rolled_back','rollback completes after the refused pause');
 $db->delete('wp_options',['option_name'=>RewriteRefresh::OPTION]);

 // D. A live edit after the unfenced pre-check is caught under the fence: the fence is
 // released and the import cancelled, never left paused.
 $s=upload($new,$body(['options'=>['fence'=>'activation']]),$blob);[$s]=drive($new,$s['id'],['reserving']);
 for($i=0;$i<50&&!($state($s['id'])['prechecked']??false);$i++)$new()->step($s['id']);
 expect(($state($s['id'])['prechecked']??false)===true&&!$fence(),'unfenced pre-check passes without pausing the site');
 $db->query("UPDATE `wp_posts` SET post_title='edited before the fence' WHERE ID=10");
 $s=$new()->step($s['id']);
 expect($s['phase']==='cancelled'&&$s['error']===['code'=>'zoer_import_failed','message'=>'Destination changed since preparation.','phase'=>'reserving']&&!$fence(),'fenced mismatch releases the fence and cancels with the error');
 expect(row($db,'wp_posts','ID','10')['post_title']==='edited before the fence'&&$new()->rollback($s['id'])['phase']==='cancelled','live edit kept; cancelled import stays cancelled');
 // An unfenced pre-check mismatch cancels too, and a live site is never reserved.
 $s=upload($new,$body(['options'=>['fence'=>'activation']]),$blob);[$s]=drive($new,$s['id'],['reserving']);
 $db->query("UPDATE `wp_posts` SET post_title='edited during staging' WHERE ID=10");
 [$s]=drive($new,$s['id'],['cancelled','preparing_files']);
 expect($s['phase']==='cancelled'&&$s['error']['phase']==='reserving'&&!$fence()&&!($state($s['id'])['prechecked']??false),'pre-check mismatch cancels without any fence');

 // E. Administrator cleanup works on journals of an earlier key generation; REST cleanup does not.
 $rotated=new TransferImport($db,$base.'/public',$base.'/private',hash('sha256','rotated key'),$target,true);
 reject(fn()=>$rotated->cleanup($legacy),'REST cleanup keeps the owner check','credential generation');
 $c=$rotated->adminCleanup($legacy);for($i=0;$i<20&&!$c['cleanedUp'];$i++)$c=$rotated->adminCleanup($legacy);
 expect($c['cleanedUp']===true&&$state($legacy)['owner']===$owner,'admin cleanup completes for an earlier key generation');
 reject(fn()=>(new TransferImport($db,$base.'/public',$base.'/private',$owner,'https://other.example',true))->adminCleanup($optionsImport),'admin cleanup keeps the destination check','destination changed');
 $open=$new()->create($body());
 reject(fn()=>$rotated->adminCleanup($open['id']),'admin cleanup keeps the terminal-phase check','Only complete');
 // An administrator hands the unfinished import to the new key; only that key can then act on it.
 reject(fn()=>$rotated->status($open['id']),'a new key cannot read an earlier key\'s import','credential generation');
 $adopted=$rotated->adminAdopt($open['id']);
 expect($adopted['id']===$open['id']&&$state($open['id'])['owner']===hash('sha256','rotated key')&&$state($open['id'])['previousOwners']===[$owner]&&isset($state($open['id'])['adoptedAt']),'administrator moves an unfinished import to the current key');
 reject(fn()=>$new()->status($open['id']),'the earlier key no longer controls the adopted import','credential generation');
 expect($rotated->adminAdopt($open['id'])['id']===$open['id']&&count($state($open['id'])['previousOwners'])===1,'adopting again is a no-op');
 expect($rotated->rollback($open['id'])['phase']==='cancelled','the current key recovers the adopted import');
 reject(fn()=>$rotated->adminAdopt($open['id']),'finished imports are not adopted','Only an unfinished import');

 // F. Tables of another install whose prefix extends ours (wp_shop_) are never created, replaced or site-replaced.
 foreach(['options','posts'] as $t)$db->define(str_replace('`wp_widgets`','`wp_shop_'.$t.'`',WIDGETS_DDL));
 $src->define(str_replace('`wp_widgets`','`wp_shop_orders`',WIDGETS_DDL),[['id'=>'1','name'=>'order','config'=>'']]);
 $shop=snapshot($src,$base.'/shop.sql');
 $s=upload($new,['database'=>$shop]+$body(['options'=>['createTables'=>true,'fence'=>'activation']]),[file_get_contents($base.'/shop.sql')]);
 reject(fn()=>drive($new,$s['id'],['reserving']),'createTables refuses a table in another install\'s prefix','another WordPress installation');
 expect(!isset($db->tables['wp_shop_orders'])&&$new()->status($s['id'])['error']['phase']==='scanning_database'&&$new()->rollback($s['id'])['phase']==='cancelled','nothing created; failure recorded and cancellable');
 // Without createTables, a destination table of the other install with a matching schema is still refused.
 $db->define(str_replace('`wp_widgets`','`wp_shop_orders`',WIDGETS_DDL),[['id'=>'9','name'=>'theirs','config'=>'']]);
 $s=upload($new,['database'=>$shop]+$body(['options'=>['fence'=>'activation','partialDatabase'=>true]]),[file_get_contents($base.'/shop.sql')]);
 reject(fn()=>drive($new,$s['id'],['reserving']),'matching schema without createTables still refuses another install\'s table','another WordPress installation');
 expect(array_column($db->tables['wp_shop_orders']['rows'],'name')===['theirs']&&$new()->rollback($s['id'])['phase']==='cancelled','other install\'s rows untouched');
 $replace=fn(array $o=[])=>['kind'=>'replace','id'=>bin2hex(random_bytes(16)),'target'=>$target,'wordpressOnlyWriters'=>true,'options'=>$o+['replacements'=>['custom'=>[['find'=>'Acme','replace'=>'Zenith']]],'review'=>true]];
 $s=$new()->create($replace());[$s]=drive($new,$s['id'],['review_required']);
 expect($s['stats']['tables']&&!array_filter(array_column($s['stats']['tables'],'name'),fn($n)=>str_starts_with($n,'wp_shop_')),'site replace skips another install\'s tables by default');
 expect($new()->rollback($s['id'])['phase']==='cancelled','reviewed replace cancelled');
 $s=$new()->create($replace(['tables'=>['shop_posts']]));
 reject(fn()=>drive($new,$s['id'],['review_required']),'site replace refuses an explicitly selected table of another install','another WordPress installation');
 expect($new()->rollback($s['id'])['phase']==='cancelled','refused replace cancelled');

 // G. lower_case_table_names=1 reports mixed-case names folded.
 $exists=fn(object $o,string $t)=>(new ReflectionMethod($o,'exists'))->invoke($o,$t);
 $db->lowerCaseTableNames='1';
 expect($exists(new TableStage($db,'wp_Posts',str_repeat('9',16)),'wp_Posts')===true&&$exists($new(),'wp_Posts')===true,'folded table names found when lower_case_table_names is set');
 $db->lowerCaseTableNames='0';
 expect($exists(new TableStage($db,'wp_Posts',str_repeat('9',16)),'wp_Posts')===false&&$exists($new(),'wp_Posts')===false&&$exists($new(),'wp_posts')===true,'exact names required when lower_case_table_names is 0');
 // H. Binary columns are never rewritten; JSON text columns stay valid JSON.
 $assets="CREATE TABLE `wp_assets` (\n  `id` bigint(20) unsigned NOT NULL,\n  `data` longblob,\n  `meta` longtext,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
 $bytes="\x89PNG\0https://source.example/x\0";$json='{"src":"https:\/\/source.example\/x","label":"Acme \"quoted\""}';
 $db->define($assets);$src=source();$src->define($assets,[['id'=>'1','data'=>$bytes,'meta'=>$json]]);
 $assetDump=snapshot($src,$base.'/assets.sql');
 $s=upload($new,['database'=>$assetDump]+$body(['options'=>['fence'=>'activation','replacements'=>['variants'=>true,'custom'=>[['find'=>'Acme','replace'=>'Zen "Co"']]]]]),[file_get_contents($base.'/assets.sql')]);
 [$s]=drive($new,$s['id'],['verification_required']);$asset=row($db,'wp_assets','id','1');
 expect($asset['data']===$bytes,'a longblob value is imported byte-for-byte despite matching a rule');
 expect(json_decode($asset['meta'],true)===['src'=>'https://dest.example/x','label'=>'Zen "Co" "quoted"']&&str_contains($asset['meta'],'https:\/\/dest.example\/x'),'JSON text replaced per string, valid and in its escaping style');
 expect(rollbackAll($new,$s['id'])['phase']==='rolled_back','binary/JSON import rolled back');
 // I. WordPress in its own folder at the destination (home https://dest.example, siteurl .../wp).
 $db->replace('wp_options',['option_name'=>'siteurl','option_value'=>'https://dest.example/wp','autoload'=>'yes']);
 $src=source();$src->insert('wp_posts',['ID'=>'14','post_author'=>'5','post_title'=>'Root','post_content'=>'<img src="https://source.example/wp-content/uploads/b.jpg"> <a href="https://source.example/about">','post_type'=>'post','post_status'=>'publish','guid'=>'https://source.example/?p=14']);
 $rootDump=snapshot($src,$base.'/root.sql');$rootBlob=[file_get_contents($base.'/root.sql')];
 $rootBody=fn(array $extra=[])=>['id'=>bin2hex(random_bytes(16)),'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>'wp_','wordpressOnlyWriters'=>true,'destinationAdminId'=>1,'database'=>$rootDump]+$extra;
 reject(fn()=>$new()->create($rootBody()),'a separate WordPress address needs a client that sends options','update Zoer');
 $s=upload($new,$rootBody(['options'=>['fence'=>'activation']]),$rootBlob);
 expect($state($s['id'])['destinationSiteurl']==='https://dest.example/wp','the job records the destination WordPress address');
 [$s]=drive($new,$s['id'],['verification_required']);
 expect(row($db,'wp_posts','ID','14')['post_content']==='<img src="https://dest.example/wp/wp-content/uploads/b.jpg"> <a href="https://dest.example/about">','content URLs map into the destination WordPress folder; pages stay at the site address');
 expect(option($db,'home')==='https://dest.example'&&option($db,'siteurl')==='https://dest.example/wp','destination home and siteurl kept');
 $db->replace('wp_options',['option_name'=>'siteurl','option_value'=>'https://dest.example/other','autoload'=>'yes']);
 reject(fn()=>$new()->finish($s['id']),'a WordPress address changed during the job is refused','Destination identity changed');
 $db->replace('wp_options',['option_name'=>'siteurl','option_value'=>'https://dest.example/wp','autoload'=>'yes']);
 expect($new()->finish($s['id'])['phase']==='complete','subdirectory destination import finishes once the address is back');
 foreach(['https://elsewhere.example','https://dest.example/wp/','https://dest.example/../x'] as $bad){
  $db->replace('wp_options',['option_name'=>'siteurl','option_value'=>$bad,'autoload'=>'yes']);
  reject(fn()=>$new()->create($rootBody(['options'=>[]])),'unsupported WordPress address refused: '.$bad,'Destination identity changed');
 }
 $db->replace('wp_options',['option_name'=>'siteurl','option_value'=>$target,'autoload'=>'yes']);
 echo "PASS import fixes: legacy rules, siteurl mapping, runtime options, fence release, rollback pause, admin cleanup, other installs, case folding\n";
}finally{removeTree($base);}
