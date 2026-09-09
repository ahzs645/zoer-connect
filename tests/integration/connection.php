<?php
// Real WordPress form/REST dispatch test; CLI HTTPS flag is not a network TLS test.
if(parse_url(home_url(),PHP_URL_HOST)!=='zoer-connect-security-test.wp.k8s.ahmad.sh')throw new RuntimeException('Disposable site required.');
$option=\ZoerConnect\ConnectionKey::OPTION;$original=get_option($option,null);
$post=$_POST;$request=$_REQUEST;$server=$_SERVER;$uid=get_current_user_id();
function connectionAssert($value){if(!$value)throw new RuntimeException('Connection integration assertion failed.');}
function connectionForm($operation,$push=false){
 $_POST=['zoer_connection_action'=>$operation,'_wpnonce'=>wp_create_nonce('zoer_connection_settings')];
 if($push)$_POST['allow_push']='1';$_REQUEST=$_POST;
 ob_start();try{\ZoerConnect\ConnectionAdmin::render();return ob_get_contents();}finally{ob_end_clean();}
}
function connectionCall($key,$path){
 $r=new WP_REST_Request('GET','/zoer-connect/v1'.$path);$r->set_header('x-zoer-connection',$key);
 return rest_do_request($r)->get_status();
}
try{
 wp_set_current_user(get_user_by('login','admin')->ID);
 $_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/wp-admin/tools.php?page=zoer-connect';
 $html=connectionForm('rotate');
 connectionAssert(preg_match('/zc_[a-f0-9]{64}/',$html,$match)===1);$key=$match[0];
 connectionAssert(str_contains($html,'Copy connection info')&&str_contains($html,'Reset secret key'));
 connectionAssert(!str_contains(serialize(get_option($option)),$key));
 connectionAssert(connectionCall($key,'/status')===200);
 connectionAssert(connectionCall($key,'/jobs')===403);
 connectionForm('permissions',true);connectionAssert(connectionCall($key,'/jobs')===200);
 connectionForm('permissions');connectionAssert(connectionCall($key,'/jobs')===403);
 $html=connectionForm('rotate');connectionAssert(connectionCall($key,'/status')===401);
 preg_match('/zc_[a-f0-9]{64}/',$html,$match);$key=$match[0];
 $_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_REQUEST=[];
 ob_start();try{\ZoerConnect\ConnectionAdmin::render();$html=ob_get_contents();}finally{ob_end_clean();}
 connectionAssert(!str_contains($html,$key));
 $_SERVER['REQUEST_METHOD']='POST';connectionForm('revoke');connectionAssert(connectionCall($key,'/status')===401);
 echo "PASS WordPress connection form, one-time display, copy/reset controls, REST status, permission enforcement, rotation and revocation; original settings restored\n";
}finally{
 if($original===null)delete_option($option);else update_option($option,$original,false);
 $_POST=$post;$_REQUEST=$request;$_SERVER=$server;wp_set_current_user($uid);
}
