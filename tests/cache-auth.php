<?php
ob_start();
require __DIR__.'/../includes/Plugin.php';
require __DIR__.'/../includes/ConnectionKey.php';
function is_ssl(){return true;}function is_multisite(){return false;}
function get_option(...$args){throw new RuntimeException('Recovery read cached authentication');}
[$token,$record]=ZoerConnect\ConnectionKey::create(7);$record['push']=true;
$wpdb=new class {
 public $options='wp_options',$usermeta='wp_usermeta',$users='wp_users',$prefix='wp_';
 public $record; public $administrator=true;
 function prepare($query,...$args){return [$query,$args];}
 function get_var($q){if(str_contains($q[0],'option_value'))return $this->record===null?null:serialize($this->record);if(str_contains($q[0],'meta_value'))return serialize(['administrator'=>$this->administrator]);return 7;}
};
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
$_SERVER['HTTP_X_ZOER_CONNECTION']=$token;$wpdb->record=null;
$r=ZoerConnect\Plugin::earlyImportRecovery('/zoer-connect/v1/imports/invalid');check(http_response_code()===401,'revoked database key denied despite cached key');
$wpdb->record=$record;$wpdb->record['push']=false;
$r=ZoerConnect\Plugin::earlyImportRecovery('/zoer-connect/v1/imports/invalid');check(http_response_code()===403,'current push permission enforced');
$wpdb->record=$record;$wpdb->administrator=false;
$r=ZoerConnect\Plugin::earlyImportRecovery('/zoer-connect/v1/imports/invalid');check(http_response_code()===401,'current administrator role enforced');
$wpdb->administrator=true;
$r=ZoerConnect\Plugin::earlyImportRecovery('/zoer-connect/v1/imports/invalid');check(http_response_code()===404,'valid database key passes authentication without object cache');
// The REST fallback for the import routes authenticates in its permission_callback with the same uncached checks.
class WP_Error { public function __construct(public $code,public $message,public $data) {} }
$routes=[];function register_rest_route($ns,$path,$args){global $routes;$routes[$path]=$args;}function add_action(...$args){}
ZoerConnect\Plugin::routes();$permission=$routes['/imports/(?P<id>[a-f0-9]{32})/(?P<action>chunks|batch|step|rollback|finish|pause|resume|approve|cleanup)']['permission_callback'];
$request=new class{function get_route(){return '/zoer-connect/v1/imports/'.str_repeat('a',32).'/step';}};
check(is_callable($permission)&&$permission!=='__return_true'&&$permission($request)===true,'REST import route permission_callback accepts the current key');
$wpdb->record['push']=false;$denied=$permission($request);check($denied instanceof WP_Error&&$denied->data['status']===403,'REST import route permission_callback enforces Push');$wpdb->record['push']=true;
$wpdb->administrator=false;$denied=$permission($request);check($denied instanceof WP_Error&&$denied->data['status']===401,'REST import route permission_callback enforces the owner role');$wpdb->administrator=true;
$_SERVER['HTTP_X_ZOER_CONNECTION']='zc_'.str_repeat('0',64);$denied=$permission($request);check($denied instanceof WP_Error&&$denied->data['status']===401,'REST import route permission_callback rejects other keys');
