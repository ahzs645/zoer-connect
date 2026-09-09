<?php
if(strpos(home_url(),'zoer-connect-security-test.')===false)throw new RuntimeException('Disposable site required.');
require_once WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;
$id=bin2hex(random_bytes(8));$p=new \ZoerConnect\TableStage($wpdb,$wpdb->options,$id,true);
$original=$wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='home'");
$originalProfiles=$wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='zoer_connect_profiles'");
$userHash=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `$wpdb->users`",ARRAY_A)));
$swapped=false;
try{
 $p->prepare();$rows=$wpdb->get_results("SELECT * FROM `$wpdb->options` ORDER BY option_id",ARRAY_A);
 foreach($rows as &$row){if(in_array($row['option_name'],['home','siteurl'],true))$row['option_value']='https://incorrect.example';if($row['option_name']==='active_plugins')$row['option_value']=serialize([]);}unset($row);
 $chunks=array_chunk($rows,20);foreach($chunks as $i=>$chunk)$p->chunk($i,$chunk);
 $wpdb->replace('zoer_s_'.$id,['option_name'=>'zoer_connect_profiles','option_value'=>serialize(['foreign-profile'=>['name'=>'Must not replace destination profiles']]),'autoload'=>'off']);
 $p->verify((int)$wpdb->get_var("SELECT COUNT(*) FROM `zoer_s_$id`"),count($chunks));$p->activate();$swapped=true;
 if($wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='home'")!==$original)throw new RuntimeException('URL preservation failed');
 $active=unserialize($wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='active_plugins'"),['allowed_classes'=>false]);
 if(!in_array('zoer-connect/zoer-connect.php',$active,true))throw new RuntimeException('Connector lost');
 if($wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='zoer_connect_profiles'")!==$originalProfiles)throw new RuntimeException('Destination profiles changed');
 if(hash('sha256',json_encode($wpdb->get_results("SELECT * FROM `$wpdb->users`",ARRAY_A)))!==$userHash)throw new RuntimeException('User data changed');
 $p->rollback();$swapped=false;
 echo "PASS options cutover preserved destination URL, connector activation, saved profiles and user records; restored original options\n";
}finally{
 if($swapped)$p->rollback();
 foreach(['zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name)$wpdb->query("DROP TABLE IF EXISTS `$name`");
 wp_cache_flush();
}
