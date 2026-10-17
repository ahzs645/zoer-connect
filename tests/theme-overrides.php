<?php
define('ARRAY_A','ARRAY_A');
require __DIR__.'/../includes/ThemeOverrides.php';
use ZoerConnect\ThemeOverrides;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/zc-theme-'.bin2hex(random_bytes(4));mkdir($dir.'/parts',0777,true);file_put_contents($dir.'/parts/footer.html','x');file_put_contents($dir.'/theme.json','{}');
$db=new class{public $posts='wp_posts';public $terms='wp_terms';public $term_taxonomy='wp_term_taxonomy';public $term_relationships='wp_term_relationships';public array $queries=[];public string $styles='{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"text":"#111"}}}';
  public function prepare($q,...$a){$this->queries[]=[$q,$a];return $q;}
  public function get_results($q,$f){return [['ID'=>3,'post_type'=>'wp_template_part','post_name'=>'footer','post_title'=>'Footer','post_modified_gmt'=>'2026-10-17 10:00:00'],['ID'=>4,'post_type'=>'wp_template','post_name'=>'single-event','post_title'=>'Single event','post_modified_gmt'=>'2026-10-17 11:00:00'],['ID'=>5,'post_type'=>'wp_template','post_name'=>'../evil','post_title'=>'x','post_modified_gmt'=>'']];}
  public function get_row($q,$f){return ['post_content'=>$this->styles,'post_modified_gmt'=>'2026-10-17 12:00:00'];}
};
$summary=ThemeOverrides::summary($db,'acme','acme',$dir);
check($summary['active']&&$summary['installed']&&!$summary['truncated'],'Theme state is wrong.');
check(array_column($summary['items'],'path')===['parts/footer.html','templates/single-event.html','theme.json'],'Unexpected items: '.json_encode($summary['items']));
check($summary['items'][0]['hasThemeFile']&&!$summary['items'][1]['hasThemeFile']&&$summary['items'][2]['type']==='styles','File presence or styles are wrong.');
check(!str_contains(json_encode($summary),'#111')&&!array_key_exists('ID',$summary['items'][0]),'No content or record IDs may be returned.');
check($db->queries[0][1]===['acme']&&str_contains($db->queries[0][0],"tt.taxonomy='wp_theme'"),'The query must filter by the wp_theme term.');
$db->styles='{"version":3,"isGlobalStylesUserThemeJSON":true}';
check(count(ThemeOverrides::summary($db,'acme','other',null)['items'])===2,'Empty user styles are not an override.');
check(ThemeOverrides::theme(null,'active-theme')==='active-theme','Defaults to the active theme.');
foreach(['../x','A b',str_repeat('a',81)] as $bad){try{ThemeOverrides::theme($bad,'x');check(false,'Accepted '.$bad);}catch(InvalidArgumentException $e){}}
array_map('unlink',[$dir.'/parts/footer.html',$dir.'/theme.json']);rmdir($dir.'/parts');rmdir($dir);
echo "PASS theme overrides: per-theme Site Editor copies, names and dates only, theme validated\n";
