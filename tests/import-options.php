<?php
require __DIR__.'/fixtures/import-site.php';
use ZoerConnect\TransferImport;
use ZoerConnect\CachePurge;
use ZoerConnect\RewriteRefresh;
$base=fixtureBase();$target='https://dest.example';$owner=hash('sha256','options owner');
$db=destination();
$new=fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target,true);
try{
 $new()->installFence();
 $legacyDump=snapshot(source(),$base.'/legacy.sql');$fullDump=snapshot(source(true),$base.'/full.sql');
 $body=fn(array $database,array $extra=[])=>['id'=>bin2hex(random_bytes(16)),'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>'wp_','wordpressOnlyWriters'=>true,'destinationAdminId'=>1,'database'=>$database]+$extra;
 // Option validation happens before any journal exists.
 foreach(['unknown key'=>['options'=>['everything'=>true]],'review without activation fence'=>['options'=>['review'=>true]],'unknown author mapping'=>['options'=>['authorMapping'=>'everyone']],
  'unknown fence'=>['options'=>['fence'=>'late']],'non-boolean keep flag'=>['options'=>['keepActivePlugins'=>'yes']],'invalid custom regex'=>['options'=>['replacements'=>['custom'=>[['find'=>'/(/','replace'=>'x','regex'=>true]]]]],
  'unknown replacement key'=>['options'=>['replacements'=>['sql'=>true]]],'list options'=>['options'=>[true]],'relative source path'=>['sourcePath'=>'var/www'],'root source path'=>['sourcePath'=>'/'],
  'replace-only table selection on a transfer'=>['options'=>['tables'=>['posts']]]] as $label=>$extra)reject(fn()=>$new()->create($body($legacyDump,$extra)),"$label refused");
 expect(!glob($base.'/private/import-*'),'refused options create no journal');

 // A. No options: the 0.3.14 pipeline (early fence, administrator authors, destination plugins/theme).
 $s=upload($new,$body($legacyDump),[file_get_contents($base.'/legacy.sql')]);
 expect($s['kind']==='transfer'&&$s['fence']==='early'&&$s['options']===TransferImport::options(null)&&$s['authors']===null&&$s['error']===null&&$s['cleanedUp']===false&&$s['finishedAt']===null,'absent options normalize to 0.3.14 defaults');
 [$s,$seen]=drive($new,$s['id'],['preparing_tables']);
 expect(is_file($base.'/private/write-fence.json')&&!in_array('mapping_authors',$seen,true),'default fence pauses the site before table preparation');
 [$s,$seen]=drive($new,$s['id'],['verification_required']);
 $post=row($db,'wp_posts','ID','10');
 expect($post['post_author']==='1'&&row($db,'wp_posts','ID','11')['post_author']==='1'&&array_column($db->dump('wp_comments'),'user_id')===['0','0','0'],'default policy assigns every post to the retained administrator and unlinks comments');
 expect(str_contains($post['post_content'],'https://dest.example/about')&&str_contains($post['post_content'],'http://source.example/old')&&str_contains($post['post_content'],'//source.example/cdn.js')&&$post['guid']==='https://dest.example/?p=10','default rules replace the source URL only, including guid');
 expect(unserialize(option($db,'active_plugins'))===['dest-plugin/plugin.php','zoer-connect/zoer-connect.php']&&option($db,'template')==='dest-theme','default keeps destination plugins and theme');
 $s=$new()->finish($s['id']);
 expect($s['phase']==='complete'&&$s['finishedAt']!==null&&option($db,RewriteRefresh::OPTION)==='1'&&option($db,CachePurge::OPTION)===null&&!is_file($base.'/private/write-fence.json'),'default finish queues permalinks but no cache purge');
 $legacy=$s['id'];
 $db->delete('wp_options',['option_name'=>RewriteRefresh::OPTION]);

 // B. Every option: late fence, review, created table, matched authors, variants, paths, custom rows.
 $options=['replacements'=>['variants'=>true,'paths'=>true,'custom'=>[['find'=>'Acme','replace'=>'Zenith']]],'replaceGuids'=>false,'keepActivePlugins'=>false,'keepActiveTheme'=>true,'authorMapping'=>'match','createTables'=>true,'fence'=>'activation','review'=>true,'purgeCaches'=>true];
 $before=$db->dump('wp_posts');
 $s=upload($new,$body($fullDump,['options'=>$options,'sourcePath'=>'/var/www/source/']),[file_get_contents($base.'/full.sql')]);
 expect($s['options']['fence']==='activation'&&$s['options']['replacements']['custom']===[['find'=>'Acme','replace'=>'Zenith','regex'=>false,'caseSensitive'=>true]]&&$s['progress']['uploadedBytes']===$s['progress']['totalBytes'],'options normalized and upload progress reported');
 [$s,$seen]=drive($new,$s['id'],['review_required']);
 expect(array_slice($seen,array_search('scanning_database',$seen,true))===['scanning_database','mapping_authors','preparing_tables','reading_database','verifying_tables','review_required'],'activation fence stages tables before any reservation');
 expect(!is_file($base.'/private/write-fence.json')&&$db->dump('wp_posts')===$before&&!isset($db->tables['wp_widgets']),'site stays live and untouched while staging for review');
 $tables=array_column($s['stats']['tables'],null,'name');
 expect($s['authors']===['matched'=>2,'fallback'=>1]&&$tables['wp_widgets']['created']===true&&$tables['wp_posts']['created']===false&&$s['stats']['replacements']>=8&&$s['stats']['replacements']===array_sum(array_column($s['stats']['tables'],'replacements')),'review summary reports authors, created tables and replacement counts');
 $sample=$s['stats']['samples'][0];
 expect(count($s['stats']['samples'])<=20&&isset($sample['table'],$sample['column'],$sample['before'],$sample['after'])&&strlen($sample['before'])<=240&&$sample['before']!==$sample['after'],'bounded replacement samples');
 expect($s['progress']['tableCount']===count($s['stats']['tables'])&&$s['progress']['tableIndex']===$s['progress']['tableCount']&&$s['progress']['rowsRead']===array_sum($s['tableRows']),'table progress reported');
 expect($new()->step($s['id'])['phase']==='review_required','step waits at review');
 $p=$new()->pause($s['id']);expect($p['phase']==='paused'&&$p['resumePhase']==='review_required'&&$new()->step($s['id'])['phase']==='paused','pause holds a reviewed import');
 expect($new()->resume($s['id'])['phase']==='review_required','resume returns to review');
 reject(fn()=>$new()->approve($legacy),'approve refused for an import not in review','not waiting for review');
 $a=$new()->approve($s['id']);expect($a['phase']==='reserving'&&$new()->approve($s['id'])['phase']==='reserving','approve moves to reserving and retries idempotently');
 [$s,$seen]=drive($new,$s['id'],['verification_required']);
 expect(array_values(array_intersect($seen,['preparing_tables','reading_database','verifying_tables','preparing_files','applying_files','activating_tables']))===['preparing_files','applying_files','activating_tables'],'approved import only pauses for files and table swaps');
 $post=row($db,'wp_posts','ID','10');
 foreach(['https://dest.example/about','https://dest.example/old','//dest.example/cdn.js','https:\/\/dest.example\/x','https%3A%2F%2Fdest.example%2Fenc','path '.rtrim($base.'/public','/').'/wp-content/uploads','Zenith'] as $needle)if(!str_contains($post['post_content'],$needle))throw new RuntimeException("Missing $needle in ".$post['post_content']);
 expect(!str_contains($post['post_content'],'source.example')&&$post['guid']==='https://source.example/?p=10'&&$post['post_title']==='Zenith post','variants, paths and custom rows applied; guid preserved');
 expect(array_column($db->dump('wp_posts'),'post_author','ID')===['10'=>'7','11'=>'1','12'=>'1']&&array_column($db->dump('wp_comments'),'user_id','comment_ID')===['1'=>'7','2'=>'0','3'=>'0'],'authors matched by login then email; unmatched fall back');
 expect(unserialize(option($db,'active_plugins'))===['akismet/akismet.php','zoer-connect/zoer-connect.php']&&option($db,'template')==='dest-theme'&&unserialize(option($db,'widget_text'))['url']==='https://dest.example/w'&&option($db,'blogname')==='Zenith Blog'&&option($db,'home')===$target,'plugin/theme policies and serialized replacements applied; identity preserved');
 expect(unserialize(row($db,'wp_widgets','id','1')['config'])['href']==='https://dest.example/links'&&row($db,'wp_widgets','id','1')['name']==='Zenith links','missing plugin table created with replaced rows');
 $s=$new()->finish($s['id']);$full=$s['id'];
 expect($s['phase']==='complete'&&option($db,CachePurge::OPTION)==='1'&&!is_file($base.'/private/write-fence.json'),'finish queues a cache purge when requested');
 $db->delete('wp_options',['option_name'=>CachePurge::OPTION]);
 $s=rollbackAll($new,$full);
 expect($s['phase']==='rolled_back'&&!isset($db->tables['wp_widgets'])&&$db->dump('wp_posts')===$before&&row($db,'wp_posts','ID','10')['post_author']==='1'&&option($db,CachePurge::OPTION)==='1','rollback removes the created table from service and restores content');
 $db->delete('wp_options',['option_name'=>CachePurge::OPTION]);

 // C. A live edit during staging aborts activation before any file changes.
 $path='wp-content/themes/fixture/style.css';file_put_contents($base.'/public/'.$path,'original');$css='new theme bytes';
 $s=upload($new,$body($legacyDump,['options'=>['fence'=>'activation'],'files'=>[['path'=>$path,'bytes'=>strlen($css),'sha256'=>hash('sha256',$css),'expectedDestinationSha256'=>hash('sha256','original')]]]),[file_get_contents($base.'/legacy.sql'),$css]);
 [$s]=drive($new,$s['id'],['reserving']);
 expect(!is_file($base.'/private/write-fence.json'),'staging completed without reserving the site');
 $db->query("UPDATE `wp_posts` SET post_title='edited while staging' WHERE ID=10");
 reject(fn()=>drive($new,$s['id'],['verification_required']),'live edit during staging aborts activation','Destination changed since preparation');
 $s=$new()->status($s['id']);
 expect($s['error']===['code'=>'zoer_import_failed','message'=>'Destination changed since preparation.','phase'=>'reserving']&&file_get_contents($base.'/public/'.$path)==='original','safe last error persisted; files untouched');
 $s=rollbackAll($new,$s['id']);
 expect($s['phase']==='rolled_back'&&$s['error']===null&&row($db,'wp_posts','ID','10')['post_title']==='edited while staging'&&!is_file($base.'/private/write-fence.json'),'rollback keeps the live edit, clears the error and reopens');
 $aborted=$s['id'];

 // Cleanup and listing.
 $list=$new()->list()['imports'];
 expect(count($list)===3&&!array_diff([$legacy,$full,$aborted],array_column($list,'id'))&&isset($list[0]['stats'],$list[0]['progress'],$list[0]['kind'],$list[0]['artifactCount'])&&!isset($list[0]['artifacts']),'list returns every import summary');
 expect((new TransferImport($db,$base.'/public',$base.'/private',hash('sha256','rotated'),$target,true))->list()===['imports'=>[]],'list hides other credential generations');
 $zoer=fn()=>array_values(array_filter(array_keys($db->tables),fn($t)=>str_starts_with($t,'zoer_')));
 expect($zoer()!==[],'terminal imports retain private tables until cleanup');
 foreach([$legacy,$full,$aborted] as $id){$c=$new()->cleanup($id);for($i=0;$i<20&&!$c['cleanedUp'];$i++)$c=$new()->cleanup($id);expect($c['cleanedUp']===true&&$new()->cleanup($id)['cleanedUp']===true,'cleanup completes and repeats safely');}
 expect($zoer()===[]&&!glob($base.'/private/import-*/artifact-*')&&count(glob($base.'/private/import-*/state.json'))===3,'cleanup drops private tables and artifacts, keeps journals');
 reject(fn()=>$new()->rollback($legacy),'rollback of a completed import refused after cleanup','cleaned up');expect($new()->rollback($full)['phase']==='rolled_back','rolled-back import stays idempotent after cleanup');
 reject(fn()=>$new()->create($body($legacyDump,['reuseImportId'=>$legacy])),'cleaned-up uploads cannot be reused');
 $s=upload($new,$body($legacyDump),[file_get_contents($base.'/legacy.sql')]);
 reject(fn()=>$new()->cleanup($s['id']),'cleanup refused before a terminal phase','Only complete');
 expect($new()->rollback($s['id'])['phase']==='cancelled','unstarted import cancels');
 // Safe errors never carry filesystem paths or raw PHP errors.
 $e=TransferImport::safeError(new RuntimeException('Cannot open /var/private/.zoer/import-x/state.json now'),'uploading');
 expect($e===['code'=>'zoer_import_failed','message'=>'Cannot open [path] now','phase'=>'uploading'],'filesystem paths stripped from safe errors');
 expect(TransferImport::safeError(new TypeError('secret internals'))['message']==='Import could not advance. Retry or roll back using the same connection.'&&strlen(TransferImport::safeError(new RuntimeException(str_repeat('x',900)))['message'])===300,'non-domain errors generic; messages capped');
 echo "PASS import options, activation fence, review, created tables, author mapping, cleanup and safe errors\n";
}finally{removeTree($base);}
