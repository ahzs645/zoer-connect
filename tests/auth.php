<?php
// WordPress boundary unit tests; not a substitute for a real WP installation test.
class WP_Error { public function __construct(public $code,public $message, public $data) {} }
class WP_REST_Response { public function __construct(public $data,public $status,public $headers) {} }
$hooks=[]; $routes=[]; $ssl=true; $admin=true; $multi=false;
$connection=null;$ownerAdmin=true;
function get_option($key,$default=null){global $connection;return $connection??$default;}
function user_can($id,$cap){global $ownerAdmin;return $id===1&&$ownerAdmin;}
function add_action($name,$fn) { global $hooks; $hooks[$name]=$fn; }
function register_rest_route($ns,$path,$args) { global $routes; $routes[$path][$args['methods']]=$args; }
function is_ssl() { global $ssl; return $ssl; }
function current_user_can($c) { global $admin; return $admin; }
function is_multisite() { global $multi; return $multi; }
function __return_true() { return true; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function assert_ok($x) { if (!$x) throw new RuntimeException('Boundary assertion failed'); }
require __DIR__.'/../includes/Plugin.php';
require __DIR__.'/../includes/ConnectionKey.php';
\ZoerConnect\Plugin::boot();
assert_ok(\ZoerConnect\Plugin::authorize() instanceof WP_Error);
$hooks['application_password_did_authenticate']();
assert_ok(\ZoerConnect\Plugin::authorize()===true);
$ssl=false; assert_ok(\ZoerConnect\Plugin::authorize() instanceof WP_Error); $ssl=true;
$admin=false; assert_ok(\ZoerConnect\Plugin::authorize() instanceof WP_Error); $admin=true;
$multi=true; assert_ok(\ZoerConnect\Plugin::authorize() instanceof WP_Error); $multi=false;
\ZoerConnect\Plugin::routes();
foreach($routes as $methods) foreach($methods as $route) assert_ok(is_callable($route['permission_callback']));
$request=new class { function get_body() { return ''; } };
$result=$routes['/jobs/(?P<id>[a-f0-9]{32})/publish']['POST']['callback']($request);
assert_ok($result instanceof WP_Error && $result->data['status']===501);
echo "Authentication boundary and publication refusal passed\n";

[$key,$connection]=\ZoerConnect\ConnectionKey::create(1,null,true);
assert_ok(!in_array($key,$connection,true));
$native=new class($key){public $body=null;function __construct(public $key){}function get_header($name){return $this->key;}function get_json_params(){return $this->body;}};
assert_ok(\ZoerConnect\Plugin::authorize($native)===true);
assert_ok($routes['/status']['GET']['permission_callback']($native)===true);
assert_ok($routes['/jobs']['POST']['permission_callback']($native)===true);
assert_ok(!\ZoerConnect\ConnectionKey::permits($connection,'pull'));
$connection['push']=false;
assert_ok($routes['/jobs']['POST']['permission_callback']($native)->data['status']===403);
$connection['push']=true;
assert_ok($routes['/jobs']['POST']['permission_callback']($native)===true);
$ownerAdmin=false;assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);$ownerAdmin=true;
$ssl=false;assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);$ssl=true;
$native->key='zc_'.str_repeat('0',64);assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);
$native->key=$key;
[$replacement,$connection]=\ZoerConnect\ConnectionKey::create(1,null,true);
assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);
$native->key=$replacement;assert_ok(\ZoerConnect\Plugin::authorize($native)===true);
$connection=['push'=>false];assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);
assert_ok(!\ZoerConnect\ConnectionKey::permits(['push'=>true],'pull'));
echo "Native key: hash-only storage, status access, push gate, HTTPS, owner demotion, invalid key, rotation, revocation and unavailable pull passed\n";

// Every export route must enforce the same permission throughout a resumed download.
[$key,$connection]=\ZoerConnect\ConnectionKey::create(1);$native->key=$key;
$exportRoutes=[];foreach($routes as $path=>$methods)if(str_starts_with($path,'/exports'))foreach($methods as $method=>$route)$exportRoutes[$method.' '.$path]=$route['permission_callback'];
assert_ok(count($exportRoutes)===10);
foreach($exportRoutes as $label=>$permission){
 $connection['pull']=false;assert_ok($permission($native)->data['status']===403);
 $connection['push']=true;assert_ok($permission($native)->data['status']===403);
 $connection['pull']=true;assert_ok($permission($native)===true);
 $ownerAdmin=false;assert_ok($permission($native)->data['status']===401);$ownerAdmin=true;
 $saved=$connection;[$replacement,$connection]=\ZoerConnect\ConnectionKey::create(1);$connection['pull']=true;assert_ok($permission($native)->data['status']===401);$connection=$saved;
 $connection=[];assert_ok($permission($native)->data['status']===401);$connection=$saved;
 $legacy=new class{function get_header($name){return '';}};
 $connection['pull']=false;assert_ok($permission($legacy)->data['status']===403);
 $connection['pull']=true;assert_ok($permission($legacy)===true);
 $connection=null;assert_ok($permission($legacy)->data['status']===403);$connection=$saved;
 echo "PASS export permissions, rotation, revocation, owner demotion and application-password gate: $label\n";
}

$comparePermission=$routes['/files/compare']['POST']['permission_callback'];
[$key,$connection]=\ZoerConnect\ConnectionKey::create(1,null,true);$native->key=$key;
$connection['push']=false;assert_ok($comparePermission($native)->data['status']===403);
$connection['push']=true;assert_ok($comparePermission($native)===true);
$ownerAdmin=false;assert_ok($comparePermission($native)->data['status']===401);$ownerAdmin=true;
[$replacement,$connection]=\ZoerConnect\ConnectionKey::create(1);assert_ok($comparePermission($native)->data['status']===401);
echo "PASS comparison requires Push permission and current administrator credential\n";

// Code scope (0.10.0): plugin and theme files need `code` on top of Push; uploads stay under Push.
use ZoerConnect\ConnectionKey;
[$key,$connection]=ConnectionKey::create(1);$native->key=$key;
assert_ok($connection['push']===false&&$connection['pull']===false&&$connection['code']===false);
assert_ok($routes['/jobs']['POST']['permission_callback']($native)->data['status']===403);
[$key,$connection]=ConnectionKey::create(1,null,true);$native->key=$key;$jobs=$routes['/jobs']['POST']['permission_callback'];
$native->body=['files'=>[['path'=>'wp-content/uploads/2026/10/photo.jpg']]];assert_ok($jobs($native)===true);
foreach(['wp-content/plugins/evil/evil.php','wp-content/themes/twentytwentyfive/functions.php'] as $codePath){
 $native->body=['files'=>[['path'=>'wp-content/uploads/a.jpg'],['path'=>$codePath]]];
 $denied=$jobs($native);assert_ok($denied instanceof WP_Error&&$denied->data['status']===403&&str_contains($denied->message,'Code'));
}
$connection['code']=true;assert_ok($jobs($native)===true);
$connection['push']=false;assert_ok($jobs($native)->data['status']===403);$connection['push']=true;
// A key stored before 0.10.0 (no `code` field) keeps code access with Push and is flagged as legacy.
$legacyRecord=$connection;unset($legacyRecord['code']);$connection=$legacyRecord;
assert_ok(ConnectionKey::legacyCode($connection)&&ConnectionKey::permits($connection,'code')&&$jobs($native)===true);
$connection['push']=false;assert_ok(!ConnectionKey::permits($connection,'code')&&!ConnectionKey::legacyCode($connection));$connection['push']=true;
$connection['code']=false;assert_ok(!ConnectionKey::legacyCode($connection)&&$jobs($native)->data['status']===403);
// Application-password administrators are not limited by key scopes.
$legacy=new class{public $body=['files'=>[['path'=>'wp-content/plugins/x/x.php']]];function get_header($name){return '';}function get_json_params(){return $this->body;}};
$connection=null;assert_ok($jobs($legacy)===true);
// Import requests: files or a plugin/theme resource selection need Code.
assert_ok(!ConnectionKey::importTouchesCode(['files'=>[['path'=>'wp-content/uploads/a.jpg']],'database'=>[]]));
assert_ok(ConnectionKey::importTouchesCode(['files'=>[['path'=>'wp-content/plugins/a/a.php']]]));
assert_ok(ConnectionKey::importTouchesCode(['resources'=>['plugins'=>true]])&&ConnectionKey::importTouchesCode(['resources'=>['themes'=>true]]));
assert_ok(!ConnectionKey::importTouchesCode(['resources'=>['plugins'=>false,'themes'=>'yes']])&&!ConnectionKey::importTouchesCode(['kind'=>'replace']));
assert_ok(!ConnectionKey::codePath('wp-content/uploads/plugins/a.php')&&!ConnectionKey::codePath('wp-content/pluginsx/a.php'));
// The import routes authenticate in a real permission_callback, not __return_true.
foreach($routes as $path=>$methods)if(str_starts_with($path,'/imports'))foreach($methods as $route)assert_ok($route['permission_callback']!=='__return_true');
echo "PASS code scope: off by default, Push-only keys stage uploads but not plugins or themes, legacy keys keep code and are flagged, application passwords unaffected\n";
