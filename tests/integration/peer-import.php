<?php
/** Run only with peer nginx/php-fpm stopped by the test supervisor.
 * ZOER_PEER_IMPORT_CONFIG names a private JSON file containing verified exported
 * databasePath, sha256, sourcePrefix, sourceUrl, files [{path,source,bytes,sha256}],
 * and expectedPostTitle. Input must come from the disposable source export probe.
 * The runner must coordinate every CLI/DB writer and restore services only after
 * successful rollback. This script never installs plugins or changes URLs/users.
 */
require_once __DIR__.'/../../includes/PeerImport.php';
use ZoerConnect\PeerImport;
global $wpdb;
$destination=get_option('home');
if (!in_array($destination,[PeerImport::DESTINATION,PeerImport::DDEV_DESTINATION],true) || get_option('siteurl')!==$destination) throw new RuntimeException('Exact transfer peer required.');
if (getenv('ZOER_PEER_EXCLUSIVE_WRITERS')!=='1') throw new RuntimeException('Runner must exclusively coordinate all peer writers.');
if (getenv('ZOER_PEER_KILL_AFTER_ACTIVATION')==='1' && !function_exists('posix_kill')) throw new RuntimeException('POSIX kill support required before starting interruption test.');
$config=json_decode(file_get_contents(getenv('ZOER_PEER_IMPORT_CONFIG')),true,512,JSON_THROW_ON_ERROR);
$lock=fopen('/tmp/zoer-transfer-peer-integration.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another peer integration owns the writer fence.');
$fence=static function(): bool {
    $status=shell_exec('supervisorctl status nginx php-fpm 2>/dev/null');
    return is_string($status) && preg_match('/^nginx\s+STOPPED/m',$status) && preg_match('/^php-fpm\s+STOPPED/m',$status);
};
if (!$fence()) throw new RuntimeException('Peer HTTP/PHP workers must be STOPPED.');
$busy=$wpdb->get_results('SHOW PROCESSLIST',ARRAY_A);
foreach($busy as $process) if (($process['Command']??'')!=='Sleep' && !str_contains((string)($process['Info']??''),'SHOW PROCESSLIST') && ($process['db']??null)===DB_NAME) throw new RuntimeException('Another database operation is active.');
function peerAssert($condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function peerHash($db,string $table):string {
    $keys=$db->get_results("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'",ARRAY_A);
    usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
    $names=array_column($keys,'Column_name');
    foreach($names as $name)if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new RuntimeException('Invalid primary key.');
    if(!$names)throw new RuntimeException('Missing primary key.');
    $rows=$db->get_results("SELECT * FROM `$table` ORDER BY `".implode('`,`',$names).'`',ARRAY_A);
    if($db->last_error || !is_array($rows))throw new RuntimeException('Cannot fingerprint table.');
    return hash('sha256',serialize($rows));
}
$suffixes=['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships'];
$tables=array_map(fn($s)=>$wpdb->prefix.$s,[...$suffixes,'options','users','usermeta']);
$before=[];foreach($tables as $table)$before[$table]=peerHash($wpdb,$table);
$admin=0;
foreach($wpdb->get_results($wpdb->prepare("SELECT user_id,meta_value FROM `{$wpdb->usermeta}` WHERE meta_key=%s",$wpdb->prefix.'capabilities'),ARRAY_A) as $candidate){
    $caps=@unserialize($candidate['meta_value'],['allowed_classes'=>false]);
    if(is_array($caps) && ($caps['administrator']??false)===true){$admin=(int)$candidate['user_id'];break;}
}
peerAssert($admin>0,'Destination administrator missing.');
$private='/tmp/zoer-peer-import-'.bin2hex(random_bytes(8));mkdir($private,0700);
peerAssert(copy($config['databasePath'],$private.'/database.sql'),'Cannot copy verified database input.');chmod($private.'/database.sql',0600);
$manifest=['version'=>1,'target'=>$destination,'files'=>[]];$sources=[];$oldFiles=[];
foreach($config['files']??[] as $i=>$file){
    $manifest['files'][]=array_intersect_key($file,array_flip(['path','bytes','sha256']));
    $source=$private.'/input-'.$i;peerAssert(copy($file['source'],$source),'Cannot copy verified file input.');chmod($source,0600);$sources[]=$source;
}
if($manifest['files'])\ZoerConnect\StageStore::validateManifest($manifest,$destination);
foreach($manifest['files'] as $file){$target=ABSPATH.$file['path'];$oldFiles[$file['path']]=is_file($target)?hash_file('sha256',$target):null;}
$records=[];
try {
    foreach(['full','interrupted'] as $scenario){
        $id=bin2hex(random_bytes(16));$import=new PeerImport($wpdb,ABSPATH,$private,true,$destination,$fence);
        $records[]=$id;
        $baseline=['id'=>$id,'destination'=>$destination,'before'=>$before,'oldFiles'=>$oldFiles,'manifest'=>$manifest,'scenario'=>$scenario];
        $json=json_encode($baseline,JSON_THROW_ON_ERROR);
        peerAssert(file_put_contents($private.'/baseline.tmp',$json)===strlen($json) && rename($private.'/baseline.tmp',$private.'/baseline.json'),'Cannot persist recovery baseline.');
        chmod($private.'/baseline.json',0600);
        $import->prepare($id,$private.'/database.sql',$config['sha256'],$config['sourcePrefix'],$config['sourceUrl'],$suffixes,$manifest,$sources,$admin);
        for($tick=0;$tick<1000;$tick++){
            $state=$import->step($id);
            if($scenario==='interrupted' && ($state['phase']??'')==='tables' && ($state['table']??0)>=1)break;
            if(($state['phase']??'')==='verification_required')break;
        }
        if($scenario==='full'){
            peerAssert($state['phase']==='verification_required','Import did not reach verification.');
            $post=$wpdb->get_row($wpdb->prepare("SELECT ID,post_author,post_title,post_content FROM `{$wpdb->posts}` WHERE post_title=%s",$config['expectedPostTitle']),ARRAY_A);
            peerAssert(is_array($post) && (int)$post['post_author']===$admin,'Exported record or destination author remapping missing.');
            if(!empty($config['expectUrlReplacement']))peerAssert(str_contains($post['post_content'],$destination) && !str_contains($post['post_content'],$config['sourceUrl']),'Post URL replacement missing.');
            if(!empty($config['expectedSerializedMetaKey'])){
                $meta=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s",(int)$post['ID'],$config['expectedSerializedMetaKey']));
                peerAssert(is_string($meta) && str_contains($meta,$destination) && !str_contains($meta,$config['sourceUrl']) && is_array(@unserialize($meta,['allowed_classes'=>false])),'Serialized metadata replacement invalid.');
            }
            foreach($manifest['files'] as $file)peerAssert(hash_file('sha256',ABSPATH.$file['path'])===$file['sha256'],'Imported file hash mismatch.');
        } else {
            peerAssert($state['phase']==='tables' && $state['table']>=1,'Interruption boundary not reached.');
            if(getenv('ZOER_PEER_KILL_AFTER_ACTIVATION')==='1'){
                echo 'PEER_IMPORT_KILL_CHECKPOINT='.$private."\n"; fflush(STDOUT);
                posix_kill(getmypid(),9);
                throw new RuntimeException('Expected SIGKILL did not terminate worker.');
            }
        }
        foreach([$wpdb->options,$wpdb->users,$wpdb->usermeta] as $table)peerAssert(peerHash($wpdb,$table)===$before[$table],'Destination settings or administrator identity changed.');
        // Simulate caller loss after a table activation; reconnect using the persisted journal.
        $import=new PeerImport($wpdb,ABSPATH,$private,true,$destination,$fence);
        for($tick=0;$tick<1000;$tick++){ $state=$import->rollback($id);if($state['phase']==='rolled_back')break; }
        peerAssert($state['phase']==='rolled_back','Rollback did not complete.');
        foreach($before as $table=>$hash)peerAssert(peerHash($wpdb,$table)===$hash,'Original database content was not restored.');
        foreach($oldFiles as $path=>$hash)peerAssert((is_file(ABSPATH.$path)?hash_file('sha256',ABSPATH.$path):null)===$hash,'Original file state was not restored.');
        $import->releaseRollback($id);
        echo 'PASS peer '.$scenario." database/files import and rollback; destination settings/users/admin hashes unchanged\n";
    }
    echo "PASS peer content import acceptance; HTTP login still requires post-restart verification\n";
    echo 'PRIVATE_RECOVERY_ROOT='.$private."\n";
} catch(Throwable $error) {
    // Retain all backups/journals. Attempt recovery, but never hide its failure.
    if(isset($import,$id))try{
        $status=$import->status($id);
        if(in_array($status['phase'],['preparing','preparation_failed'],true))$import->discardPreparation($id);
        elseif($status['phase']==='ready'){
            for($i=0;$i<1000;$i++){ $state=$import->rollback($id);if($state['phase']==='rolled_back')break; }
            peerAssert($state['phase']==='rolled_back','Emergency rollback incomplete.');
            $import->releaseRollback($id);
        }
    }catch(Throwable $recovery){throw new RuntimeException('RECOVERY REQUIRED; keep peer writers fenced; journal '.$private,0,$recovery);}
    throw $error;
} finally {flock($lock,LOCK_UN);fclose($lock);}
