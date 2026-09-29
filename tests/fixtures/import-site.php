<?php
// Two synthetic WordPress databases on MemoryDb plus helpers that exercise the real
// TransferImport journal (upload, step, rollback) against temporary directories.
require_once __DIR__.'/memory-db.php';
require_once __DIR__.'/../../includes/TransferImport.php';
use ZoerConnect\TransferImport;
use ZoerConnect\DatabaseExporter;
use ZoerConnect\StageStore;
if(!function_exists('wp_cache_flush')){function wp_cache_flush(){return true;}}
function expect($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function reject($fn,$label,$message=null){try{$fn();}catch(Throwable $e){if($message!==null&&!str_contains($e->getMessage(),$message))throw new RuntimeException("$label: ".$e->getMessage());echo "PASS $label\n";return $e;}throw new RuntimeException('Accepted: '.$label);}
const WIDGETS_DDL="CREATE TABLE `wp_widgets` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(191) NOT NULL DEFAULT '',\n  `config` longtext,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
function site(string $home,array $options,array $users,array $posts,array $comments,bool $widgets=false): MemoryDb {
 $db=new MemoryDb();$i=fn($c)=>"  `$c` bigint(20) unsigned NOT NULL";$t=fn($c)=>"  `$c` longtext";
 $table=function(string $name,array $cols,string $pk,array $rows=[],string $extra='')use($db){$db->define("CREATE TABLE `wp_$name` (\n".implode(",\n",$cols).",\n  PRIMARY KEY ($pk)$extra\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",$rows);};
 $opts=[];$n=1;foreach(['home'=>$home,'siteurl'=>$home]+$options as $k=>$v)$opts[]=['option_id'=>(string)$n++,'option_name'=>$k,'option_value'=>$v,'autoload'=>'yes'];
 $table('options',["  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT","  `option_name` varchar(191) NOT NULL DEFAULT ''",$t('option_value'),"  `autoload` varchar(20) NOT NULL DEFAULT 'yes'"],'`option_id`',$opts,",\n  UNIQUE KEY `option_name` (`option_name`)");
 $table('posts',["  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('post_author'),$t('post_title'),$t('post_content'),"  `post_type` varchar(20) NOT NULL DEFAULT 'post'","  `post_status` varchar(20) NOT NULL DEFAULT 'publish'","  `guid` varchar(255) NOT NULL DEFAULT ''"],'`ID`',$posts);
 $table('postmeta',["  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('post_id'),"  `meta_key` varchar(255) DEFAULT NULL",$t('meta_value')],'`meta_id`');
 $table('comments',["  `comment_ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('comment_post_ID'),$i('user_id'),$t('comment_content')],'`comment_ID`',$comments);
 $table('commentmeta',["  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('comment_id'),"  `meta_key` varchar(255) DEFAULT NULL",$t('meta_value')],'`meta_id`');
 $table('terms',["  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT","  `name` varchar(200) NOT NULL DEFAULT ''"],'`term_id`',[['term_id'=>'1','name'=>'Uncategorized']]);
 $table('termmeta',["  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('term_id'),"  `meta_key` varchar(255) DEFAULT NULL",$t('meta_value')],'`meta_id`');
 $table('term_taxonomy',["  `term_taxonomy_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('term_id'),"  `taxonomy` varchar(32) NOT NULL DEFAULT ''"],'`term_taxonomy_id`',[['term_taxonomy_id'=>'1','term_id'=>'1','taxonomy'=>'category']]);
 $table('term_relationships',[$i('object_id'),$i('term_taxonomy_id')],'`object_id`,`term_taxonomy_id`',array_map(fn($p)=>['object_id'=>$p['ID'],'term_taxonomy_id'=>'1'],$posts));
 $table('users',["  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT","  `user_login` varchar(60) NOT NULL DEFAULT ''","  `user_email` varchar(100) NOT NULL DEFAULT ''"],'`ID`',array_map(fn($u)=>['ID'=>$u[0],'user_login'=>$u[1],'user_email'=>$u[2]],$users));
 $table('usermeta',["  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT",$i('user_id'),"  `meta_key` varchar(255) DEFAULT NULL",$t('meta_value')],'`umeta_id`',array_map(fn($u)=>['user_id'=>$u[0],'meta_key'=>'wp_capabilities','meta_value'=>serialize([$u[3]=>true])],$users));
 if($widgets)$db->define(WIDGETS_DDL,[['id'=>'1','name'=>'Acme links','config'=>serialize(['href'=>'https://source.example/links'])]]);
 return $db;
}
function destination(): MemoryDb {
 return site('https://dest.example',['blogname'=>'Destination','active_plugins'=>serialize(['dest-plugin/plugin.php']),'template'=>'dest-theme','stylesheet'=>'dest-theme'],
  [['1','admin','admin@dest.example','administrator'],['7','editor','editor@dest.example','editor']],
  [['ID'=>'1','post_author'=>'1','post_title'=>'Destination home','post_content'=>'Original destination content','post_type'=>'page','post_status'=>'publish','guid'=>'https://dest.example/?page_id=1']],
  [['comment_ID'=>'1','comment_post_ID'=>'1','user_id'=>'1','comment_content'=>'Destination comment']]);
}
function source(bool $widgets=false): MemoryDb {
 $content='Visit https://source.example/about and http://source.example/old and //source.example/cdn.js and {"u":"https:\/\/source.example\/x"} and https%3A%2F%2Fsource.example%2Fenc path /var/www/source/wp-content/uploads Acme';
 return site('https://source.example',['blogname'=>'Acme Blog','active_plugins'=>serialize(['akismet/akismet.php']),'template'=>'source-theme','stylesheet'=>'source-theme','widget_text'=>serialize(['url'=>'https://source.example/w'])],
  [['5','editor','editor@source.example','author'],['6','sourceadmin','admin@dest.example','administrator'],['9','ghost','ghost@source.example','author']],
  [['ID'=>'10','post_author'=>'5','post_title'=>'Acme post','post_content'=>$content,'post_type'=>'post','post_status'=>'publish','guid'=>'https://source.example/?p=10'],
   ['ID'=>'11','post_author'=>'6','post_title'=>'Second','post_content'=>'https://source.example/2','post_type'=>'post','post_status'=>'publish','guid'=>'https://source.example/?p=11'],
   ['ID'=>'12','post_author'=>'9','post_title'=>'Ghost','post_content'=>'none','post_type'=>'post','post_status'=>'publish','guid'=>'https://source.example/?p=12']],
  [['comment_ID'=>'1','comment_post_ID'=>'10','user_id'=>'5','comment_content'=>'by editor'],['comment_ID'=>'2','comment_post_ID'=>'10','user_id'=>'0','comment_content'=>'anonymous'],['comment_ID'=>'3','comment_post_ID'=>'10','user_id'=>'9','comment_content'=>'by ghost']],$widgets);
}
function snapshot($db,string $path): array {
 DatabaseExporter::write($db,$path);
 return ['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'chunkSha256'=>array_map(fn($c)=>hash('sha256',$c),str_split(file_get_contents($path),StageStore::CHUNK))];
}
function fixtureBase(): string {$base=sys_get_temp_dir().'/zoer-options-'.bin2hex(random_bytes(6));mkdir($base.'/private',0700,true);mkdir($base.'/public/wp-content/themes/fixture',0755,true);return $base;}
function removeTree(string $base): void {if(!is_dir($base))return;$walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($walk as $e)$e->isDir()&&!$e->isLink()?rmdir($e->getPathname()):unlink($e->getPathname());rmdir($base);}
function upload(callable $new,array $body,array $blobs): array {
 $s=$new()->create($body);
 foreach($blobs as $index=>$data)for($offset=0;$offset<strlen($data);$offset+=StageStore::CHUNK)$new()->chunk($s['id'],$index,$offset,substr($data,$offset,StageStore::CHUNK));
 return $new()->status($s['id']);
}
/** Step until one of $phases; returns [summary, every phase observed]. */
function drive(callable $new,string $id,array $phases,int $limit=800): array {
 $seen=[];
 for($i=0;$i<$limit;$i++){$s=$new()->step($id);$seen[]=$s['phase'];if(in_array($s['phase'],$phases,true))return [$s,array_values(array_unique($seen))];}
 throw new RuntimeException('Import never reached '.implode('|',$phases).'; last '.$s['phase']);
}
function rollbackAll(callable $new,string $id): array {for($i=0;$i<800;$i++){$s=$new()->rollback($id);if(in_array($s['phase'],['rolled_back','cancelled'],true))return $s;}throw new RuntimeException('Rollback never finished.');}
function option(MemoryDb $db,string $name){return $db->get_var($db->prepare("SELECT option_value FROM `wp_options` WHERE option_name=%s",$name));}
function row(MemoryDb $db,string $table,string $pk,string $id): ?array {foreach($db->dump($table) as $r)if($r[$pk]===$id)return $r;return null;}
