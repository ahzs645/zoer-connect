<?php
// Pull database filters (API A3). Row selection runs the generated WHERE clauses against SQLite as a
// stand-in engine; real MariaDB/MySQL semantics still need integration qualification.
require __DIR__.'/../includes/DatabaseExporter.php';
define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
use ZoerConnect\DatabaseExporter;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function denied(callable $fn,$name,string $message=''){try{$fn();}catch(Throwable $e){check($message===''||$e->getMessage()===$message,$name);return;}throw new RuntimeException($name);}
if(!extension_loaded('pdo_sqlite')){echo "SKIP database filter semantics require pdo_sqlite\n";exit(0);}
class FilterDb {
 public $prefix='wp_';public $last_error='';public array $queries=[];public PDO $pdo;
 public array $engines=['wp_posts'=>'InnoDB','wp_postmeta'=>'InnoDB','wp_comments'=>'InnoDB','wp_commentmeta'=>'InnoDB','wp_term_relationships'=>'InnoDB','wp_term_taxonomy'=>'InnoDB','wp_options'=>'InnoDB','wp_legacy'=>'MyISAM','other_secret'=>'InnoDB'];
 const KEYS=['wp_posts'=>['ID'],'wp_postmeta'=>['meta_id'],'wp_comments'=>['comment_ID'],'wp_commentmeta'=>['meta_id'],'wp_term_relationships'=>['object_id','term_taxonomy_id'],'wp_term_taxonomy'=>['term_taxonomy_id'],'wp_options'=>['option_id'],'wp_legacy'=>['id'],'other_secret'=>['id']];
 function __construct(){
  $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  foreach(['CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT)','CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER)','CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY, comment_post_ID INTEGER, comment_approved TEXT)','CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY, comment_id INTEGER)','CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER, PRIMARY KEY(object_id,term_taxonomy_id))','CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, taxonomy TEXT)','CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT)','CREATE TABLE wp_legacy (id INTEGER PRIMARY KEY)','CREATE TABLE other_secret (id INTEGER PRIMARY KEY)',
   // 1 post, 2 page, 3 revision of 1, 4 attachment; 99 is a link ID (link_category) and 50 an orphan parent.
   "INSERT INTO wp_posts VALUES (1,'post'),(2,'page'),(3,'revision'),(4,'attachment')","INSERT INTO wp_postmeta VALUES (10,1),(11,2),(12,3),(13,4),(14,50)",
   "INSERT INTO wp_comments VALUES (20,1,'1'),(21,1,'spam'),(22,4,'1'),(23,0,'1')","INSERT INTO wp_commentmeta VALUES (30,20),(31,21),(32,22),(33,23)",
   "INSERT INTO wp_term_taxonomy VALUES (1,'category'),(2,'link_category')","INSERT INTO wp_term_relationships VALUES (1,1),(3,1),(4,1),(4,2),(99,2)",
   "INSERT INTO wp_options VALUES (1,'home'),(2,'_transient_feed'),(3,'_transient_timeout_feed'),(4,'_site_transient_update'),(5,'_site_transient_timeout_update'),(6,'_transientx'),(7,'atransient_b'),(8,'_site_transientx'),(9,'zoer_connect_connection'),(10,'wpmdb_settings'),(11,'-transient-y')",
   'INSERT INTO wp_legacy VALUES (1)','INSERT INTO other_secret VALUES (1)'] as $sql)$this->pdo->exec($sql);
 }
 function query($sql){$this->queries[]=$sql;return 1;}
 function get_results($sql,$mode){$this->queries[]=$sql;
  if($sql==='SHOW TABLE STATUS')return array_map(fn($n,$e)=>['Name'=>$n,'Engine'=>$e],array_keys($this->engines),$this->engines);
  if(preg_match('/^SHOW INDEX FROM `([a-z_]+)`/',$sql,$m))return array_map(fn($c,$i)=>['Seq_in_index'=>$i+1,'Column_name'=>$c],self::KEYS[$m[1]],array_keys(self::KEYS[$m[1]]));
  return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
 }
 function get_row($sql,$mode){preg_match('/`([a-z_]+)`/',$sql,$m);return [$m[1],'CREATE TABLE `'.$m[1].'` (fixture)'];}
}
// Rows exported per table: decode the first key column of each INSERT.
function exported(string $sql): array {$out=[];preg_match_all("/INSERT INTO `([a-z_]+)` \\([^)]*\\) VALUES \\(X'([0-9a-f]*)'(?:,X'([0-9a-f]*)')?/",$sql,$m,PREG_SET_ORDER);foreach($m as $row)$out[$row[1]][]=hex2bin($row[2]);return $out;}
$root=sys_get_temp_dir().'/zoer-filters-'.bin2hex(random_bytes(6));mkdir($root);$n=0;
$run=function(array $filters,?FilterDb $db=null)use($root,&$n){$db??=new FilterDb();unset($db->engines['wp_legacy']);$path=$root.'/'.(++$n).'.sql';$tables=DatabaseExporter::write($db,$path,null,40,$filters);return [$tables,exported(file_get_contents($path)),file_get_contents($path),$db];};
try{
 check(DatabaseExporter::selection(true)===true&&DatabaseExporter::selection(false)===false,'boolean database selection unchanged');
 check(DatabaseExporter::selection([])===['tables'=>null,'postTypes'=>null,'excludeRevisions'=>false,'excludeSpam'=>false,'excludeTransients'=>true],'empty filter object normalizes to 0.3.14 defaults');
 check(DatabaseExporter::filters(['postTypes'=>['post','page','post']])['postTypes']===['page','post'],'post types deduplicated and ordered for stable bindings');
 foreach([['postTypes'=>["post' OR 1=1"]],['tables'=>['posts`; DROP']],['tables'=>'posts'],['tables'=>['a'=>'posts']],['excludeSpam'=>'yes'],['sql'=>'SELECT 1'],['postTypes'=>[1]]] as $i=>$bad)denied(fn()=>DatabaseExporter::selection($bad),'invalid filter rejected #'.$i);
 denied(fn()=>DatabaseExporter::selection('yes'),'non-boolean, non-object selection rejected');
 [$tables,$rows,$sql,$db]=$run([]);
 check($tables===['commentmeta','comments','options','postmeta','posts','term_relationships','term_taxonomy'],'default export returns every prefixed table suffix');
 check(!str_contains($sql,'other_secret'),'unprefixed tables still excluded');
 // database:true (no filter object) keeps the 0.3.14 query byte-for-byte, including its unescaped LIKE wildcards.
 check(in_array("SELECT * FROM `wp_options` WHERE option_name NOT IN ('zoer_connect_connection','zoer_connect_profiles','zoer_connect_storage_dir') AND option_name NOT LIKE 'wpmdb%' AND option_name NOT LIKE '_transient_%' AND option_name NOT LIKE '_site_transient_%' ORDER BY `option_id` LIMIT 200 OFFSET 0",$db->queries,true)&&$rows['wp_options']===['1'],'database:true options query identical to 0.3.14');
 [,$rows]=$run(DatabaseExporter::selection([]));
 check($rows['wp_options']===['1','6','7','8','11'],'filter object excludes exactly real transient families, connector state and wpmdb options');
 check(count($rows['wp_posts'])===4&&count($rows['wp_postmeta'])===5&&count($rows['wp_comments'])===4&&count($rows['wp_term_relationships'])===5,'default export keeps every content row');
 $db=new FilterDb();denied(fn()=>DatabaseExporter::write($db,$root.'/legacy.sql'),'unselected legacy engine still blocks a full export','Pull requires InnoDB tables.');
 $db=new FilterDb();[$tables,$rows]=[DatabaseExporter::write($db,$root.'/subset.sql',null,40,['tables'=>['posts','options']]),exported(file_get_contents($root.'/subset.sql'))];
 check($tables===['options','posts']&&array_keys($rows)===['wp_options','wp_posts'],'table subset exports only selected suffixes and skips an unselected MyISAM table');
 check(!array_filter($db->queries,fn($q)=>str_contains($q,'wp_legacy')&&!str_starts_with($q,'SHOW TABLE STATUS')),'unselected tables are never read');
 $db=new FilterDb();denied(fn()=>DatabaseExporter::write($db,$root.'/missing.sql',null,40,['tables'=>['posts','missing']]),'unknown selected table refused','Selected database table is unavailable.');
 check(!file_exists($root.'/missing.sql')&&!file_exists($root.'/missing.sql.partial'),'refused subset publishes nothing');
 $db=new FilterDb();denied(fn()=>DatabaseExporter::write($db,$root.'/legacy2.sql',null,40,['tables'=>['legacy']]),'selected MyISAM table still refused','Pull requires InnoDB tables.');
 [,$rows]=$run(['excludeRevisions'=>true]);
 check($rows['wp_posts']===['1','2','4']&&$rows['wp_postmeta']===['10','11','13','14'],'revisions and their meta excluded; orphaned meta retained');
 check($rows['wp_term_relationships']===['1','4','4','99'],'revision term relationships excluded');
 [,$rows]=$run(['postTypes'=>['post','page']]);
 check($rows['wp_posts']===['1','2'],'post type inclusion list');
 check($rows['wp_postmeta']===['10','11','14'],'meta of excluded post types dropped');
 check($rows['wp_comments']===['20','21','23'],'comments on excluded posts dropped; comments without a post kept');
 check($rows['wp_commentmeta']===['30','31','33'],'comment meta follows excluded comments');
 check($rows['wp_term_relationships']===['1','4','99'],'relationships of excluded posts dropped; link-category rows (link IDs) kept');
 [,$rows]=$run(['postTypes'=>[]]);check(!isset($rows['wp_posts'])&&$rows['wp_postmeta']===['14'],'empty post type list selects no posts');
 [,$rows,,$db]=$run(['excludeSpam'=>true]);
 check($rows['wp_comments']===['20','22','23']&&$rows['wp_commentmeta']===['30','32','33'],'spam comments and their meta excluded');
 check(count($rows['wp_postmeta'])===5&&!array_filter($db->queries,fn($q)=>str_starts_with($q,'SELECT * FROM `wp_postmeta` WHERE')),'spam filter leaves post tables unfiltered');
 [,$rows]=$run(['excludeTransients'=>false]);
 check($rows['wp_options']===['1','2','3','4','5','6','7','8','11'],'transients included on request; connector and wpmdb exclusions remain');
 [$tables,$rows]=$run(['tables'=>['postmeta'],'postTypes'=>['page']]);
 check($tables===['postmeta']&&$rows['wp_postmeta']===['11','14'],'dependent table filtered by parent posts even when posts are not exported');
}finally{foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
