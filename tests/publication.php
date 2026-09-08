<?php
require __DIR__.'/../includes/StageStore.php';
require __DIR__.'/../includes/FilePublication.php';
use ZoerConnect\FilePublication;
$base=sys_get_temp_dir().'/zoer-publish-'.bin2hex(random_bytes(6));
mkdir($base);mkdir($base.'/public');mkdir($base.'/private');mkdir($base.'/public/wp-content/themes/demo',0755,true);
function ok($x) { if(!$x)throw new RuntimeException('Assertion failed'); }
try {
 $live=$base.'/public/wp-content/themes/demo/style.css';file_put_contents($live,'old');
 $source=$base.'/private/0.part';file_put_contents($source,'new');
 $manifest=['version'=>1,'target'=>'example','files'=>[['path'=>'wp-content/themes/demo/style.css','bytes'=>3,'sha256'=>hash('sha256','new')]]];
 $p=new FilePublication($base.'/public',$base.'/private');$p->prepare($manifest,[$source]);
 $p->step();ok(file_get_contents($live)==='old');
 $p=new FilePublication($base.'/public',$base.'/private'); // Resume in a new process-equivalent instance.
 $p->step();$p->step();ok(file_get_contents($live)==='new');
 ok($p->step()['status']==='verification_required');
 file_put_contents($live,'edited');
 $refused=false;try{$p->rollbackStep();}catch(RuntimeException $e){$refused=true;}ok($refused);
 file_put_contents($live,'new');$p->rollbackStep();ok(file_get_contents($live)==='old');
 ok($p->rollbackStep()['status']==='rolled_back');
 echo "File backup, activation, resume, rollback and edit protection passed\n";
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $p){if($p->isDir()&&!$p->isLink())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($base);
}
