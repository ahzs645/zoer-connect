<?php
/** Run only after the backend peer pairing is disconnected. Peer-only fixture
 * cleanup; restores the saved pre-test option, then removes the temporary key.
 * The private original-option backup and transfer audit journals are retained.
 */
if (!in_array('--backend-disconnected', $argv ?? [], true)) throw new RuntimeException('Disconnect the backend peer pairing first, then pass --backend-disconnected.');
define('SHORTINIT',true);
require '/var/www/html/wp-load.php';
$private='/var/www/.zoer-connect';
foreach(['home','siteurl'] as $name) {
    $value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$wpdb->options` WHERE option_name=%s",$name));
    if($value!=='http://zoer-connect-transfer-peer.ddev.site:8080')throw new RuntimeException('Cleanup is restricted to the transfer peer.');
}
if(is_file($private.'/write-fence.json'))throw new RuntimeException('An active write fence prevents credential cleanup.');
foreach(glob($private.'/import-*/state.json')?:[] as $path) {
    $job=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!in_array($job['phase']??null,['rolled_back','cancelled'],true))throw new RuntimeException('Every acceptance import must be rolled back or cancelled before restoring credentials.');
}
$backup=$private.'/acceptance-key-backup.json';
$original=json_decode(file_get_contents($backup),true,512,JSON_THROW_ON_ERROR);
if($original!==null&&!is_array($original))throw new RuntimeException('Unexpected original connection option backup.');
$name='zoer_connect_connection';
if($original===null) {
    if($wpdb->delete($wpdb->options,['option_name'=>$name])===false)throw new RuntimeException('Cannot remove the temporary connection record.');
    if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$wpdb->options` WHERE option_name=%s",$name))!==0)throw new RuntimeException('Connection record still exists.');
} else {
    $encoded=serialize($original);
    if($wpdb->replace($wpdb->options,['option_name'=>$name,'option_value'=>$encoded,'autoload'=>'off'])===false)throw new RuntimeException('Cannot restore the original connection record.');
    if($wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$wpdb->options` WHERE option_name=%s",$name))!==$encoded)throw new RuntimeException('Original connection record verification failed.');
}
$key=$private.'/acceptance-native-key';
if(is_file($key)&&!unlink($key))throw new RuntimeException('Option restored, but temporary key file removal failed.');
echo "Original peer connection option restored; temporary acceptance key removed; private audit backup retained\n";
