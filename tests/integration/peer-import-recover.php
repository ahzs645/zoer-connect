<?php
/** Separate PHP process recovery after opt-in SIGKILL in peer-import.php.
 * Keep peer web/PHP workers stopped; pass ZOER_PEER_EXCLUSIVE_WRITERS=1 and
 * ZOER_PEER_RECOVERY_ROOT=<PEER_IMPORT_KILL_CHECKPOINT path>.
 */
require_once __DIR__.'/../../includes/PeerImport.php';
use ZoerConnect\PeerImport;
global $wpdb;
$private=getenv('ZOER_PEER_RECOVERY_ROOT');
if(!is_string($private) || !preg_match('~^/tmp/zoer-peer-import-[a-f0-9]{16}$~D',$private) || is_link($private) || realpath($private)!==$private)throw new RuntimeException('Invalid private recovery root.');
if(getenv('ZOER_PEER_EXCLUSIVE_WRITERS')!=='1')throw new RuntimeException('Exclusive peer writer coordination required.');
$baseline=json_decode(file_get_contents($private.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
$destination=get_option('home');
if(!in_array($destination,[PeerImport::DESTINATION,PeerImport::DDEV_DESTINATION],true) || get_option('siteurl')!==$destination || $baseline['destination']!==$destination || $baseline['scenario']!=='interrupted')throw new RuntimeException('Exact interrupted transfer peer required.');
$lock=fopen('/tmp/zoer-transfer-peer-integration.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Peer writer fence still owned by another process.');
$fence=static function():bool {
    $status=shell_exec('supervisorctl status nginx php-fpm 2>/dev/null');
    return is_string($status) && preg_match('/^nginx\s+STOPPED/m',$status) && preg_match('/^php-fpm\s+STOPPED/m',$status);
};
if(!$fence())throw new RuntimeException('Peer web/PHP workers must remain STOPPED during recovery.');
foreach($wpdb->get_results('SHOW PROCESSLIST',ARRAY_A) as $process)if(($process['Command']??'')!=='Sleep' && !str_contains((string)($process['Info']??''),'SHOW PROCESSLIST') && ($process['db']??null)===DB_NAME)throw new RuntimeException('Another database operation is active.');
function recoverHash($db,string $table):string {
    $keys=$db->get_results("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'",ARRAY_A);
    usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
    $names=array_column($keys,'Column_name');
    foreach($names as $name)if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new RuntimeException('Invalid primary key.');
    if(!$names)throw new RuntimeException('Missing primary key.');
    $rows=$db->get_results("SELECT * FROM `$table` ORDER BY `".implode('`,`',$names).'`',ARRAY_A);
    if($db->last_error || !is_array($rows))throw new RuntimeException('Cannot fingerprint restored table.');
    return hash('sha256',serialize($rows));
}
try {
    $id=$baseline['id'];
    $import=new PeerImport($wpdb,ABSPATH,$private,true,$destination,$fence);
    $initial=$import->status($id);
    if(($initial['recovery']['phase']??'')!=='tables' || ($initial['recovery']['table']??0)<1)throw new RuntimeException('Expected post-activation kill checkpoint missing.');
    for($tick=0;$tick<1000;$tick++){$state=$import->rollback($id);if($state['phase']==='rolled_back')break;}
    if($state['phase']!=='rolled_back')throw new RuntimeException('Rollback incomplete; keep peer fenced.');
    $tables=array_map(fn($suffix)=>$wpdb->prefix.$suffix,['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options','users','usermeta']);
    if(array_keys($baseline['before'])!==$tables)throw new RuntimeException('Unexpected recovery table baseline.');
    foreach($tables as $table)if(recoverHash($wpdb,$table)!==$baseline['before'][$table])throw new RuntimeException('Original database/settings/administrator hash mismatch; retain fence.');
    if($baseline['manifest']['files'])\ZoerConnect\StageStore::validateManifest($baseline['manifest'],$destination);
    if(array_column($baseline['manifest']['files'],'path')!==array_keys($baseline['oldFiles']))throw new RuntimeException('Unexpected recovery file baseline.');
    foreach($baseline['oldFiles'] as $path=>$hash)if((is_file(ABSPATH.$path)?hash_file('sha256',ABSPATH.$path):null)!==$hash)throw new RuntimeException('Original file hash mismatch; retain fence.');
    $import->releaseRollback($id);
    echo "PASS actual SIGKILL followed by separate PHP process rollback; all original database/settings/users and files restored\n";
    echo "HTTP_LOGIN_VERIFICATION_REQUIRED\n";
} finally {flock($lock,LOCK_UN);fclose($lock);}
