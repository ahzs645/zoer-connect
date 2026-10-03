<?php
// WordPress boundary tests for /status (API v2) and /diagnostics; not a substitute for a real WordPress installation.
class WP_Error { public function __construct(public $code,public $message,public $data=[]) {} }
class WP_REST_Response { public function __construct(public $data,public $status,public $headers) {} }
class FixtureTheme { public function __construct(private array $d) {} function get($k){return $this->d[$k];} function get_template(){return $this->d['Template'];} }
$base=sys_get_temp_dir().'/zoer-diagnostics-'.bin2hex(random_bytes(6));mkdir($base);mkdir($base.'/site');mkdir($base.'/private',0700);
define('ABSPATH',$base.'/site/');define('WP_CONTENT_DIR',$base.'/site/wp-content');define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
$_SERVER['DOCUMENT_ROOT']=$base.'/site';$wp_version='6.8.1';
$hooks=[];$routes=[];$connection=null;
$options=['home'=>'http://source.example','siteurl'=>'http://source.example/','blog_public'=>'0','permalink_structure'=>'/%postname%/','zoer_connect_storage_dir'=>$base.'/private','active_plugins'=>['wordfence/wordfence.php','litespeed-cache/litespeed-cache.php','zoer-connect/zoer-connect.php']];
function get_option($key,$default=null){global $options,$connection;return $key==='zoer_connect_connection'?($connection??$default):($options[$key]??$default);}
function add_action($name,$fn){global $hooks;$hooks[$name]=$fn;}
function register_rest_route($ns,$path,$args){global $routes;$routes[$path][$args['methods']]=$args;}
function is_ssl(){return true;} function is_multisite(){return false;} function current_user_can($c){return true;} function user_can($id,$cap){return $id===1;}
function __return_true(){return true;} function is_wp_error($x){return $x instanceof WP_Error;} function untrailingslashit($v){return rtrim($v,'/\\');} function home_url(){return get_option('home');}
function get_locale(){return 'en_CA';} function wp_upload_dir($time=null,$create=true){return ['basedir'=>WP_CONTENT_DIR.'/uploads'];}
function get_post_type_object($name){return $name==='post'?(object)['label'=>'Posts']:null;}
function get_stylesheet(){return 'child';} function get_template(){return 'parent';}
function wp_get_themes(){return ['child'=>new FixtureTheme(['Name'=>'Child','Version'=>'1.0','Template'=>'parent']),'parent'=>new FixtureTheme(['Name'=>'Parent','Version'=>'2.0','Template'=>'parent']),'spare'=>new FixtureTheme(['Name'=>'Spare','Version'=>'3.0','Template'=>'spare'])];}
function get_plugins(){return ['wordfence/wordfence.php'=>['Name'=>'Wordfence','Version'=>'8.0'],'litespeed-cache/litespeed-cache.php'=>['Name'=>'LiteSpeed Cache','Version'=>'7.0'],'hello.php'=>['Name'=>'Hello Dolly','Version'=>'1.7'],'zoer-connect/zoer-connect.php'=>['Name'=>'Zoer Connect','Version'=>'0.4.0']];}
function get_mu_plugins(){return ['000-zoer-connect-fence.php'=>['Name'=>'']];}
function get_dropins(){return ['advanced-cache.php'=>['Name'=>'Advanced caching plugin'],'object-cache.php'=>['Name'=>'External object cache']];}
function get_site_transient($key){return $key==='update_plugins'?(object)['response'=>['zoer-connect/zoer-connect.php'=>(object)['new_version'=>'0.5.1']],'no_update'=>[]]:false;}
$wpdb=new class {
 public $prefix='wp_';public $charset='utf8mb4';public $collate='utf8mb4_unicode_520_ci';public $last_error='';public array $queries=[];
 function get_var($sql){$this->queries[]=$sql;return $sql==='SELECT VERSION()'?'11.4.3-MariaDB-log':($sql==='SELECT @@lower_case_table_names'?'0':null);}
 function get_results($sql,$mode){$this->queries[]=$sql;
  if($sql==='SHOW TABLE STATUS')return [['Name'=>'wp_posts','Engine'=>'InnoDB','Rows'=>'12','Data_length'=>'16384','Index_length'=>'8192'],['Name'=>'wp_Mixed','Engine'=>'InnoDB','Rows'=>'0','Data_length'=>'0','Index_length'=>'0'],['Name'=>'wp_legacy','Engine'=>'MyISAM','Rows'=>'3','Data_length'=>'10','Index_length'=>'0'],['Name'=>'wp_orders','Engine'=>'InnoDB','Rows'=>'1','Data_length'=>'1','Index_length'=>'1'],['Name'=>'wp_audit','Engine'=>'InnoDB','Rows'=>'1','Data_length'=>'1','Index_length'=>'1'],['Name'=>'wp_view','Engine'=>null,'Comment'=>'VIEW'],['Name'=>'other_x','Engine'=>'MyISAM','Rows'=>'1','Data_length'=>'1','Index_length'=>'1']];
  if(str_contains($sql,"CONSTRAINT_TYPE='PRIMARY KEY'"))return [['TABLE_NAME'=>'wp_posts'],['TABLE_NAME'=>'wp_Mixed'],['TABLE_NAME'=>'wp_orders'],['TABLE_NAME'=>'wp_audit']];
  if(str_contains($sql,'REFERENTIAL_CONSTRAINTS'))return [['TABLE_NAME'=>'wp_orders','REFERENCED_TABLE_NAME'=>'wp_posts']];
  if(str_contains($sql,'TRIGGERS'))return [['EVENT_OBJECT_TABLE'=>'wp_audit']];
  if(str_contains($sql,'GROUP BY post_type'))return [['post_type'=>'post','total'=>'9'],['post_type'=>'custom_thing','total'=>'3']];
  return [];
 }
};
foreach(['StageStore','WriteFence','ConnectionKey','ImportAdmin','Plugin','Diagnostics'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\Diagnostics;use ZoerConnect\ConnectionKey;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
try{
 \ZoerConnect\Plugin::boot();\ZoerConnect\Plugin::routes();
 check(isset($routes['/diagnostics']['GET']),'diagnostics route registered');
 $native=new class{public $key='';function get_header($n){return $this->key;}function get_body(){return '';}};
 $permission=$routes['/diagnostics']['GET']['permission_callback'];
 [$native->key,$connection]=ConnectionKey::create(1);
 $connection['push']=true;$connection['pull']=false;check($permission($native)===true,'diagnostics allowed with Push only');
 $connection['push']=false;$connection['pull']=true;check($permission($native)===true,'diagnostics allowed with Pull only');
 $connection['pull']=false;$denied=$permission($native);check($denied instanceof WP_Error&&$denied->data['status']===403,'diagnostics refused without Push or Pull');
 check($routes['/exports']['POST']['permission_callback']($native)->data['status']===403&&$routes['/jobs']['POST']['permission_callback']($native)->data['status']===403,'push and pull scopes unchanged');
 [,$other]=ConnectionKey::create(1);$saved=$connection;$connection=$other+['pull'=>true];check($permission($native)->data['status']===401,'diagnostics require the current key');$connection=$saved;
 $connection['push']=true;
 $status=$routes['/status']['GET']['callback']($native)->data;
 check($status['version']===\ZoerConnect\Plugin::VERSION&&$status['apiVersion']===2,'status reports current version and API version 2');
 $flags=['replacementRules','replacementVariants','reviewPause','createTables','authorMapping','keepActivePlugins','lateFence','importPauseResume','importCleanup','importList','siteReplace','cachePurge','databaseFilters','resourceModes','mediaSince','diagnostics','safeErrors'];
 check(!array_diff($flags,array_keys(array_filter($status['capabilities'],fn($v)=>$v===true))),'status advertises every API v2 capability');
 check(!array_diff(['pagedExport','connectionKey','stageFiles','pull','publish','selectivePush','artifactReuse','chunkedFilePublication','databaseImport','rollback'],array_keys($status['capabilities'])),'existing capability flags retained');
 $response=$routes['/diagnostics']['GET']['callback']($native);$r=$response->data;
 check($response->status===200&&$response->headers['Cache-Control']==='no-store','diagnostics served uncached');
 check(array_keys($r)===['wordpress','php','database','postTypes','themes','plugins','muPlugins','dropins','warnings','pluginUpdate'],'diagnostics top-level shape');
 check(array_keys($r['wordpress'])===['version','prefix','home','siteurl','abspath','contentDir','uploadsDir','locale','permalinkStructure','blogPublic']&&$r['wordpress']['abspath']===$base.'/site'&&$r['wordpress']['siteurl']==='http://source.example'&&$r['wordpress']['blogPublic']===false&&$r['wordpress']['version']==='6.8.1','wordpress facts');
 check(array_keys($r['php'])===['version','sapi','openBasedirRestricted','displayErrors','displayStartupErrors','functions','memoryLimit','maxExecutionTime','postMaxSize','uploadMaxFilesize','extensions']&&array_keys($r['php']['extensions'])===['zip','openssl','curl','mysqli']&&is_int($r['php']['maxExecutionTime']),'php facts');
 $db=$r['database'];check($db['server']==='mariadb'&&$db['version']==='11.4.3'&&$db['lowerCaseTableNames']===0&&$db['charset']==='utf8mb4','database server facts');
 $tables=array_column($db['tables'],null,'name');
 check(array_keys($db['tables'][0])===['name','suffix','prefixed','engine','rows','bytes','primaryKey','foreignKeys','triggers'],'table entry shape');
 check($tables['wp_posts']===['name'=>'wp_posts','suffix'=>'posts','prefixed'=>true,'engine'=>'InnoDB','rows'=>12,'bytes'=>24576,'primaryKey'=>true,'foreignKeys'=>true,'triggers'=>false],'referenced table reports foreign keys');
 check($tables['other_x']['suffix']===null&&!$tables['other_x']['prefixed']&&$tables['wp_view']['engine']==='VIEW'&&!$tables['wp_legacy']['primaryKey']&&$tables['wp_audit']['triggers'],'unprefixed, view, missing key and trigger facts');
 check($r['postTypes']===[['name'=>'post','label'=>'Posts','count'=>9],['name'=>'custom_thing','label'=>'custom_thing','count'=>3]],'post types with labels and counts');
 check($r['themes']===[['slug'=>'child','name'=>'Child','version'=>'1.0','active'=>true,'parent'=>'parent'],['slug'=>'parent','name'=>'Parent','version'=>'2.0','active'=>true,'parent'=>null],['slug'=>'spare','name'=>'Spare','version'=>'3.0','active'=>false,'parent'=>null]],'themes with active child and parent');
 check($r['plugins'][2]===['slug'=>'hello.php','file'=>'hello.php','name'=>'Hello Dolly','version'=>'1.7','active'=>false]&&$r['plugins'][0]['slug']==='wordfence'&&$r['plugins'][0]['active'],'plugin slugs are directory or single-file names');
 check($r['muPlugins']===[['file'=>'000-zoer-connect-fence.php','name'=>'']]&&$r['dropins']===['advanced-cache.php','object-cache.php'],'MU plugins and drop-ins');
 check($r['pluginUpdate']===['current'=>\ZoerConnect\Plugin::VERSION,'latest'=>'0.5.1'],'plugin update from update_plugins transient');
 $codes=array_column($r['warnings'],'code');
 foreach(['firewall_plugin','page_cache_plugin','object_cache_dropin','mixed_case_tables','non_innodb','foreign_keys','triggers','blog_private','no_https'] as $code)check(in_array($code,$codes,true),'warning '.$code);
 $messages=implode("\n",array_column($r['warnings'],'message'));
 check(str_contains($messages,'Wordfence')&&str_contains($messages,'LiteSpeed Cache')&&str_contains($messages,'wp_Mixed')&&str_contains($messages,'wp_legacy')&&!str_contains($messages,'other_x'),'warnings name the detected plugins and prefixed tables only');
 check(!array_filter($wpdb->queries,fn($q)=>preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\b/',$q)),'diagnostics issue read-only queries');
 $clean=$r;$clean['php']['displayErrors']=$clean['php']['displayStartupErrors']=false;$clean['plugins']=[];$clean['dropins']=[];$clean['wordpress']['blogPublic']=true;$clean['wordpress']['home']=$clean['wordpress']['siteurl']='https://source.example';$clean['database']['tables']=[$tables['wp_posts']];$clean['database']['tables'][0]['foreignKeys']=false;
 check(Diagnostics::warnings($clean)===[],'healthy site has no warnings');
 check(is_bool($r['php']['openBasedirRestricted'])&&$r['php']['sapi']===PHP_SAPI&&$r['php']['functions']['inflate_init']===function_exists('inflate_init'),'bounded hosting facts without private configuration paths');
 $errors=$clean;$errors['php']['displayStartupErrors']=true;check(array_column(Diagnostics::warnings($errors),'code')===['php_display_errors'],'startup error display warning');
 check(array_column(Diagnostics::warnings($clean,'/srv/wordfence-waf.php'),'code')===['firewall_plugin'],'auto_prepend firewall detected');
 $options['active_plugins']=[];$clean['plugins']=[['slug'=>'breeze','active'=>false]];check(Diagnostics::warnings($clean)===[],'inactive cache plugin ignored');
 $clean['database']['lowerCaseTableNames']=1;$clean['wordpress']['prefix']='WP_';check(array_column(Diagnostics::warnings($clean),'code')===['mixed_case_tables'],'mixed-case prefix with lower_case_table_names warned');
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);}
