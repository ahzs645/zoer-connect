<?php
require __DIR__.'/../includes/PeerImport.php';
use ZoerConnect\PeerImport;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$header="-- Zoer Connect database snapshot\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
$create="CREATE TABLE `wp_posts` (\n  `ID` bigint NOT NULL,\n  `post_content` longtext,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4";
$schema=['wp_posts'=>str_replace('AUTO_INCREMENT=99','AUTO_INCREMENT=2',$create)];
$content="Robert'); DROP TABLE users; --\n";
$sql=$header."DROP TABLE IF EXISTS `wp_posts`;\n".$create.";\nINSERT INTO `wp_posts` (`ID`,`post_content`) VALUES (X'31',X'".bin2hex($content)."');\nSET FOREIGN_KEY_CHECKS=1;\n";
$rows=PeerImport::parseSnapshot($sql,$schema);
check($rows['wp_posts'][0]===['ID'=>'1','post_content'=>$content],'Hex values must remain inert data');
rejects(fn()=>PeerImport::parseSnapshot(substr($sql,0,-5),$schema),'Truncated snapshot accepted');
rejects(fn()=>PeerImport::parseSnapshot(str_replace("X'31'","1",$sql),$schema),'Arbitrary SQL cell accepted');
rejects(fn()=>PeerImport::parseSnapshot(str_replace('`ID`,`post_content`','`ID`,`ID`',$sql),$schema),'Duplicate columns accepted');
rejects(fn()=>PeerImport::parseSnapshot(str_replace('longtext','text',$sql),$schema),'Schema mismatch accepted');
rejects(fn()=>PeerImport::parseSnapshot(str_replace("SET FOREIGN_KEY_CHECKS=1;\n","DELETE FROM wp_posts;\nSET FOREIGN_KEY_CHECKS=1;\n",$sql),$schema),'Extra SQL accepted');
rejects(fn()=>PeerImport::parseSnapshot($sql,['wp_other'=>'CREATE TABLE `wp_other` ()']),'Missing selection accepted');
check(PeerImport::parseSnapshot($sql,[])===[],'Unselected source tables should be skipped');

$base=sys_get_temp_dir().'/zoer-peer-test-'.bin2hex(random_bytes(8));
mkdir($base,0700); mkdir($base.'/public',0700); mkdir($base.'/private',0700);
$db=new class {
    public $prefix='wp_'; public $options='wp_options'; public $last_error=''; public $url=PeerImport::DESTINATION; public $siteUrl=null;
    public function prepare($sql,...$args) { return $sql.' '.implode(',',$args); }
    public function get_var($sql) { return str_ends_with($sql,' siteurl') ? ($this->siteUrl ?? $this->url) : $this->url; }
}; $fence=true; $guard=function()use(&$fence){return $fence;};
$construct=fn($enabled,$url,$g=null)=>new PeerImport($db,$base.'/public',$base.'/private',$enabled,$url,$g);
try {
    rejects(fn()=>$construct(true,'https://sparklab.unbc.ca',$guard),'Production destination accepted');
    rejects(fn()=>$construct(false,PeerImport::DESTINATION,$guard),'Disabled destination accepted');
    $id=str_repeat('a',32);
    $manifest=['version'=>1,'target'=>PeerImport::DESTINATION,'files'=>[['path'=>'wp-content/themes/fixture/style.css','bytes'=>3,'sha256'=>hash('sha256','new')]]];
    file_put_contents($base.'/private/input','new');
    mkdir($base.'/public/wp-content/themes/fixture',0755,true);
    file_put_contents($base.'/public/wp-content/themes/fixture/style.css','old');
    $prepare=fn($p)=>$p->prepare($id,null,null,'wp_','https://source.example',[],$manifest,[$base.'/private/input']);
    rejects(fn()=>$prepare($construct(true,PeerImport::DESTINATION)),'Missing write fence accepted');
    $p=$construct(true,PeerImport::DESTINATION,$guard);
    $db->url='https://sparklab.unbc.ca';
    rejects(fn()=>$prepare($p),'Spoofed destination argument bypassed actual identity check');
    $db->url=PeerImport::DESTINATION; $db->siteUrl=PeerImport::DDEV_DESTINATION;
    rejects(fn()=>$prepare($p),'Mixed home/siteurl peer identities accepted');
    $db->siteUrl=null;
    $db->url=PeerImport::DDEV_DESTINATION;
    rejects(fn()=>$prepare($p),'Cross-paired peer identities accepted');
    $ddev=$construct(true,PeerImport::DDEV_DESTINATION,$guard);
    $ddevId=str_repeat('d',32);
    $ddevManifest=$manifest; $ddevManifest['target']=PeerImport::DDEV_DESTINATION;
    $ddev->prepare($ddevId,null,null,'wp_','https://source.example',[],$ddevManifest,[$base.'/private/input']);
    for($i=0;$i<10;$i++){ $state=$ddev->rollback($ddevId); if($state['phase']==='rolled_back')break; }
    $ddev->releaseRollback($ddevId);
    $db->url=PeerImport::DESTINATION;
    $failedId=str_repeat('c',32);
    file_put_contents($base.'/private/bad-input','bad');
    rejects(fn()=>$p->prepare($failedId,null,null,'wp_','https://source.example',[],$manifest,[$base.'/private/bad-input']),'Bad payload accepted');
    check($p->status($failedId)['phase']==='preparation_failed','Failed preparation not retained');
    check(file_get_contents($base.'/public/wp-content/themes/fixture/style.css')==='old','Failed preparation changed destination');
    $p=$construct(true,PeerImport::DESTINATION,$guard);
    check($p->discardPreparation($failedId)['phase']==='discarded','Failed preparation could not be abandoned');
    $prepare($p);
    rejects(fn()=>$p->discardPreparation($id),'Ready import incorrectly discarded');
    rejects(fn()=>$p->releaseRollback($id),'Unrestored import released');
    rejects(fn()=>$p->prepare(str_repeat('b',32),null,null,'wp_','https://source.example',[],$manifest,[$base.'/private/input']),'Concurrent job accepted');
    check(file_get_contents($base.'/public/wp-content/themes/fixture/style.css')==='old','Preparation changed destination');
    $fence=false; rejects(fn()=>$p->step($id),'Lost write fence accepted'); $fence=true;
    for($i=0;$i<10;$i++) { $state=$p->step($id); if(file_get_contents($base.'/public/wp-content/themes/fixture/style.css')==='new')break; }
    check(file_get_contents($base.'/public/wp-content/themes/fixture/style.css')==='new','File did not activate');
    // Lose the caller between ticks and recover through a fresh importer.
    $p=$construct(true,PeerImport::DESTINATION,$guard);
    for($i=0;$i<10;$i++){ $state=$p->rollback($id); if($state['phase']==='rolled_back')break; }
    check($state['phase']==='rolled_back','Rollback did not complete');
    check(file_get_contents($base.'/public/wp-content/themes/fixture/style.css')==='old','Original file not restored');
    check($p->status($id)['recovery']['phase']==='rolled_back','Recovery status not durable');
    $p->releaseRollback($id);
    check(!is_file($base.'/private/peer-active'),'Restored job reservation retained');
    echo "PASS strict snapshot parser, exact peer guard, mandatory writer fence, exclusive job, resumed file rollback\n";
    echo "NOT COVERED: real database import, WordPress login, OS process termination, hosting limits, public API write fence\n";
} finally {
    $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($walk as $entry) $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    rmdir($base);
}
