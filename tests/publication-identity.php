<?php
define('ARRAY_A','ARRAY_A');
require __DIR__.'/../includes/CachePurge.php';
require __DIR__.'/../includes/Publication.php';
use ZoerConnect\Publication;
class WP_Post{public $ID;public $post_modified_gmt;public $post_content;public $post_password='';function __construct($id,$m,$c){$this->ID=$id;$this->post_modified_gmt=$m;$this->post_content=$c;}}
$options=['show_on_front'=>'page','page_on_front'=>5];$queried=null;$singular=false;$deleted=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function wp_cache_delete($key,$group){$GLOBALS['deleted'][]=$key;return true;}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function is_singular(){return $GLOBALS['singular'];}
function get_queried_object(){return $GLOBALS['queried'];}
function home_url($p='/'){return 'https://example.org/blog'.$p;}
function get_permalink($id){return $id===7?'https://example.org/blog/about/':($id===8?'https://other.example/x/':'https://example.org/blog/?p='.$id);}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new class{public $options='wp_options';public $posts='wp_posts';public array $rows=[];
  public function replace($t,$row){$this->rows[$row['option_name']]=$row['option_value'];$GLOBALS['options'][$row['option_name']]=$row['option_value'];return 1;}
  public function prepare($q,...$a){return [$q,$a];}
  public function get_row($q,$f){return ['ID'=>5,'post_modified_gmt'=>'2026-10-16 10:00:00','post_content'=>'Home v2','post_type'=>'page'];}
  public function get_results($q,$f){return [['ID'=>7,'post_modified_gmt'=>'2026-10-16 09:00:00','post_content'=>'About','post_type'=>'page'],['ID'=>8,'post_modified_gmt'=>'2026-10-15 09:00:00','post_content'=>'Elsewhere','post_type'=>'post'],['ID'=>9,'post_modified_gmt'=>'2026-10-14 09:00:00','post_content'=>'Plain','post_type'=>'post']];}
};
check(Publication::current()===null,'No identity before the first import.');
$first=Publication::commit($db,['id'=>str_repeat('a',32)],'import');
check(preg_match('/^[a-f0-9]{32}$/',$first['id'])&&$first['importId']===str_repeat('a',32)&&$first['kind']==='import','Commit must bind an opaque id to the import.');
check($first['id']!==$first['importId'],'The public identifier must not reveal the import ID.');
check(in_array('zoer_connect_publication',$deleted,true)&&in_array('alloptions',$deleted,true),'Commit must drop cached option copies.');
$second=Publication::commit($db,['id'=>str_repeat('b',32)],'rollback');
check($second['id']!==$first['id']&&Publication::current()['id']===$second['id']&&$second['kind']==='rollback','A rollback is a new publication.');
ob_start();Publication::meta();$html=ob_get_clean();
check($html==='<meta name="zoer-publication" content="'.$second['id'].'">'."\n",'Home page meta is wrong: '.$html);
$singular=true;$queried=new WP_Post(5,'2026-10-16 10:00:00','Home v1');
ob_start();Publication::meta();$html=ob_get_clean();
$stale=Publication::revision(5,'2026-10-16 10:00:00','Home v1');$fresh=Publication::revision(5,'2026-10-16 10:00:00','Home v2');
check(str_contains($html,'name="zoer-revision" content="'.$stale.'"')&&$stale!==$fresh,'A stale cached post must render a different revision.');
$queried->post_password='secret';ob_start();Publication::meta();check(!str_contains(ob_get_clean(),'zoer-revision'),'Protected posts must not expose a revision.');
$expected=Publication::expected($db);
check($expected['home']==='https://example.org/blog/'&&$expected['publication']['id']===$second['id'],'Expected home or identity is wrong.');
check($expected['pages'][0]===['path'=>'/','kind'=>'front-page','revision'=>$fresh],'Front page must expect the database revision.');
check(count($expected['pages'])===2&&$expected['pages'][1]['path']==='/about/','Only pages under the home URL without queries are listed: '.json_encode($expected['pages']));
echo "PASS publication identity: opaque per import/rollback, public meta, stale revisions detectable, expected pages\n";
