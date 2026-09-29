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
 expect($new()->rollback($open['id'])['phase']==='cancelled','unstarted import cancelled');

 // F. Tables of another install whose prefix extends ours (wp_shop_) are never created, replaced or site-replaced.
 foreach(['options','posts'] as $t)$db->define(str_replace('`wp_widgets`','`wp_shop_'.$t.'`',WIDGETS_DDL));
 $src->define(str_replace('`wp_widgets`','`wp_shop_orders`',WIDGETS_DDL),[['id'=>'1','name'=>'order','config'=>'']]);
 $shop=snapshot($src,$base.'/shop.sql');
 $s=upload($new,['database'=>$shop]+$body(['options'=>['createTables'=>true,'fence'=>'activation']]),[file_get_contents($base.'/shop.sql')]);
 reject(fn()=>drive($new,$s['id'],['reserving']),'createTables refuses a table in another install\'s prefix','another WordPress installation');
 expect(!isset($db->tables['wp_shop_orders'])&&$new()->status($s['id'])['error']['phase']==='scanning_database'&&$new()->rollback($s['id'])['phase']==='cancelled','nothing created; failure recorded and cancellable');
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
 echo "PASS import fixes: legacy rules, siteurl mapping, runtime options, fence release, rollback pause, admin cleanup, other installs, case folding\n";
}finally{removeTree($base);}
