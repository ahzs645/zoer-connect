<?php
// WordPress boundary unit tests; not a substitute for a real WP installation test.
class WP_Error { public function __construct(public $code,public $message, public $data) {} }
class WP_REST_Response { public function __construct(public $data,public $status,public $headers) {} }
$hooks=[]; $routes=[]; $ssl=true; $admin=true; $multi=false;
function add_action($name,$fn) { global $hooks; $hooks[$name]=$fn; }
function register_rest_route($ns,$path,$args) { global $routes; $routes[$path][$args['methods']]=$args; }
function is_ssl() { global $ssl; return $ssl; }
function current_user_can($c) { global $admin; return $admin; }
function is_multisite() { global $multi; return $multi; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function assert_ok($x) { if (!$x) throw new RuntimeException('Boundary assertion failed'); }
require __DIR__.'/../includes/Plugin.php';
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
