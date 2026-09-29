<?php
// Execute only inside a newly created, disposable WordPress installation.
if(get_option('home')!=='https://zoer-connect-defaults.test')throw new RuntimeException('Disposable fixture URL required.');
if(get_option(\ZoerConnect\ConnectionKey::OPTION,null)!==null)throw new RuntimeException('Fixture must have no existing connection.');
use ZoerConnect\ConnectionKey;use ZoerConnect\ConnectionAdmin;use ZoerConnect\ImportAdmin;use ZoerConnect\Plugin;
function default_check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);
$_SERVER['HTTPS']='on';$_SERVER['DOCUMENT_ROOT']=rtrim(ABSPATH,'/');$_SERVER['REQUEST_METHOD']='POST';
function connection_form($action,$extra=[]){$_POST=['zoer_connection_action'=>$action,'_wpnonce'=>wp_create_nonce('zoer_connection_settings')]+$extra;$_REQUEST=$_POST;ob_start();try{ConnectionAdmin::render();return ob_get_contents();}finally{ob_end_clean();}}
$html=connection_form('rotate');$record=get_option(ConnectionKey::OPTION);
default_check($record['push']&&!$record['pull'],'installed plugin generates Push-enabled, Pull-disabled key');
$private=Plugin::storageRoot();default_check(ImportAdmin::mode($private,ABSPATH)==='shared-replacement','installed plugin automatically prepares shared migration');
default_check(!file_exists($private.'/write-fence.json'),'preparation leaves site serving normally');
default_check(str_contains($html,'Shared-hosting migration is ready'),'administrator receives success notice');
preg_match('/zc_[a-f0-9]{64}/',$html,$match);$key=$match[0]??'';default_check(ConnectionKey::matches($key,$record),'shown key matches stored hash');
$request=new WP_REST_Request('GET','/zoer-connect/v1/status');$request->set_header('x-zoer-connection',$key);$response=rest_do_request($request);$status=$response->get_data();
default_check($response->get_status()===200&&$status['migrationMode']==='shared-replacement'&&$status['capabilities']['publish']&&$status['permissions']['push']&&!$status['permissions']['pull'],'real WordPress REST dispatch reports ready Push destination');
$receipt=file_get_contents($private.'/import-readiness.json');
connection_form('permissions',['allow_pull'=>'on']);connection_form('rotate');$record=get_option(ConnectionKey::OPTION);
default_check(!$record['push']&&$record['pull'],'installed plugin preserves disabled Push during key reset');
default_check(!ConnectionKey::matches($key,$record),'reset invalidates earlier key');
connection_form('revoke');connection_form('rotate');$record=get_option(ConnectionKey::OPTION);default_check(!$record['push']&&!$record['pull'],'installed plugin preserves revocation policy');
default_check(file_get_contents($private.'/import-readiness.json')===$receipt,'permission changes preserve private setup receipt');
$home=get_option('home');update_option('home','https://different.test');connection_form('permissions',['allow_push'=>'on']);default_check(!ImportAdmin::ready($private,ABSPATH)&&file_get_contents($private.'/import-readiness.json')===$receipt,'stale destination setup is not silently renewed');update_option('home',$home);
echo "Disposable WordPress connection defaults qualification passed\n";
