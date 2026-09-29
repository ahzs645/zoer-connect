<?php
require __DIR__.'/fixtures/import-site.php';
use ZoerConnect\TransferImport;
$base=fixtureBase();$target='https://dest.example';$owner=hash('sha256','replace owner');
$db=destination();
$db->query("UPDATE `wp_posts` SET post_content='Old Brand at https://dest.example and old brand' WHERE ID=1");
$db->insert('wp_options',['option_name'=>'tagline','option_value'=>serialize(['text'=>'Old Brand forever']),'autoload'=>'yes']);
$new=fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target,true);
$body=fn(array $options=[],array $extra=[])=>['kind'=>'replace','id'=>bin2hex(random_bytes(16)),'target'=>$target,'wordpressOnlyWriters'=>true,'destinationAdminId'=>1,'options'=>$options+['replacements'=>['custom'=>[['find'=>'Old Brand','replace'=>'New Brand','caseSensitive'=>false]]],'review'=>true]]+$extra;
try{
 $new()->installFence();
 foreach(['no custom rows'=>[$body(['replacements'=>['custom'=>[]]])],'transfer database artifact'=>[$body([],['database'=>['bytes'=>1]])],'source identity'=>[$body([],['sourceUrl'=>'https://source.example'])],
  'identity table selection'=>[$body(['tables'=>['users']])],'review with early fence'=>[$body(['fence'=>'early'])]] as $label=>[$b])reject(fn()=>$new()->create($b),"replace with $label refused");
 $users=$db->dump('wp_users');$comments=$db->dump('wp_comments');
 $s=$new()->create($body(['tables'=>['posts','options','comments'],'purgeCaches'=>true]));
 expect($s['kind']==='replace'&&$s['phase']==='snapshotting'&&$s['fence']==='activation'&&$s['options']['partialDatabase']===true&&$s['options']['replacements']['automatic']===false&&$s['options']['tables']===['posts','options','comments'],'replace defaults: activation fence, partial database, custom rows only');
 [$s,$seen]=drive($new,$s['id'],['review_required']);
 expect(array_slice($seen,0,3)===['snapshotting','checking_artifacts','scanning_database']&&!in_array('mapping_authors',$seen,true)&&!is_file($base.'/private/write-fence.json'),'site snapshot and staging ran while live');
 expect(array_column($s['stats']['tables'],'name')===['wp_comments','wp_options','wp_posts']&&$s['artifacts'][0]['bytes']>0&&$s['progress']['uploadedBytes']===$s['artifacts'][0]['bytes'],'only selected tables staged from a self-digested snapshot');
 expect($s['stats']['replacements']===3&&array_column($s['stats']['tables'],'replacements','name')===['wp_comments'=>0,'wp_options'=>1,'wp_posts'=>2],'replacement counts reviewed before any change');
 expect(str_contains(row($db,'wp_posts','ID','1')['post_content'],'Old Brand'),'live content untouched during review');
 $new()->approve($s['id']);
 [$s]=drive($new,$s['id'],['verification_required']);
 expect(row($db,'wp_posts','ID','1')['post_content']==='New Brand at https://dest.example and New Brand'&&unserialize(option($db,'tagline'))['text']==='New Brand forever','custom case-insensitive replacement applied in place, serialized lengths updated');
 expect($db->dump('wp_users')===$users&&$db->dump('wp_comments')===$comments&&row($db,'wp_posts','ID','1')['post_author']==='1'&&option($db,'home')===$target,'identity tables, authors and comment users unchanged');
 $s=$new()->finish($s['id']);expect($s['phase']==='complete'&&option($db,\ZoerConnect\CachePurge::OPTION)==='1','replace finishes and queues a cache purge');
 $s=rollbackAll($new,$s['id']);
 expect($s['phase']==='rolled_back'&&str_contains(row($db,'wp_posts','ID','1')['post_content'],'Old Brand'),'replace rolls back to the pre-replacement content');
 $c=$new()->cleanup($s['id']);expect($c['cleanedUp']===true,'replace cleanup');
 // An edit after the pre-snapshot fingerprint must abort, never be overwritten by the snapshot.
 $s=$new()->create($body(['review'=>false]));
 [$s]=drive($new,$s['id'],['checking_artifacts']);
 $db->query("UPDATE `wp_posts` SET post_title='edited after snapshot' WHERE ID=1");
 reject(fn()=>drive($new,$s['id'],['verification_required']),'edit after the replacement snapshot aborts activation','Destination changed since preparation');
 $s=rollbackAll($new,$s['id']);
 expect($s['phase']==='rolled_back'&&row($db,'wp_posts','ID','1')['post_title']==='edited after snapshot','concurrent edit preserved');
 $s=$new()->create($body(['tables'=>['missing_table']]));
 $e=reject(fn()=>drive($new,$s['id'],['review_required']),'unknown selected table refused during snapshotting','A selected table does not exist');
 expect($new()->status($s['id'])['error']['phase']==='snapshotting'&&$new()->rollback($s['id'])['phase']==='cancelled','snapshot failure recorded and cancellable');
 echo "PASS site-local replace snapshots, stages, reviews, applies, rolls back and detects concurrent edits\n";
}finally{removeTree($base);}
