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
