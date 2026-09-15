<?php
require_once __DIR__.'/../includes/FileComparison.php';
use ZoerConnect\FileComparison;
$base=sys_get_temp_dir().'/zoer-compare-'.bin2hex(random_bytes(6));mkdir($base.'/wp-content/themes/demo',0700,true);
function ck($v){if(!$v)throw new RuntimeException('Comparison check failed');}
try{
 $p='wp-content/themes/demo/style.css';file_put_contents($base.'/'.$p,'old');
 ck(FileComparison::fingerprint($base,$p)===hash('sha256','old'));
 ck(FileComparison::fingerprint($base,'wp-content/themes/demo/new.css')===null);
 foreach(['../secret','wp-content/plugins/zoer-connect/key.php','wp-content/uploads/run.php','wp-content/themes/demo/.env'] as $bad)ck(FileComparison::compare($base,[['path'=>$bad]])['files'][0]['blocked']===true);
 symlink('/tmp',$base.'/wp-content/themes/linked');ck(FileComparison::compare($base,[['path'=>'wp-content/themes/linked/test']])['files'][0]['blocked']===true);unlink($base.'/wp-content/themes/linked');
 $h=fopen($base.'/wp-content/themes/demo/large','w');ftruncate($h,33554433);fclose($h);ck(FileComparison::compare($base,[['path'=>'wp-content/themes/demo/large']])['files'][0]['blocked']===true);
 echo "PASS read-only hashes, missing files, traversal/protected paths, symlinks and bounded comparison\n";
}finally{foreach(glob($base.'/wp-content/themes/demo/*') as $p)unlink($p);rmdir($base.'/wp-content/themes/demo');rmdir($base.'/wp-content/themes');rmdir($base.'/wp-content');rmdir($base);}
