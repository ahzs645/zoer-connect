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

[$key,$connection]=\ZoerConnect\ConnectionKey::create(1);
assert_ok(!in_array($key,$connection,true));
$native=new class($key){function __construct(public $key){}function get_header($name){return $this->key;}};
assert_ok(\ZoerConnect\Plugin::authorize($native)===true);
assert_ok($routes['/status']['GET']['permission_callback']($native)===true);
assert_ok($routes['/jobs']['POST']['permission_callback']($native)->data['status']===403);
$connection['push']=true;
assert_ok($routes['/jobs']['POST']['permission_callback']($native)===true);
$ownerAdmin=false;assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);$ownerAdmin=true;
$ssl=false;assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);$ssl=true;
$native->key='zc_'.str_repeat('0',64);assert_ok(\ZoerConnect\Plugin::authorize($native)->data['status']===401);
$native->key=$key;
[$replacement,$connection]=\ZoerConnect\ConnectionKey::create(1);
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
