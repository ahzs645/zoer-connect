<?php
require __DIR__.'/fixtures/admin-wordpress.php';
require __DIR__.'/../includes/TransferAdmin.php';
require __DIR__.'/../includes/TransferImport.php';
use ZoerConnect\TransferImport;use ZoerConnect\TransferRetention;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
function reject($fn,$why){try{$fn();}catch(Throwable $e){echo "PASS $why\n";return;}throw new RuntimeException($why);}
$base=sys_get_temp_dir().'/zoer-retention-'.bin2hex(random_bytes(6));mkdir($base);mkdir($base.'/site');mkdir($base.'/private',0700);define('ABSPATH',$base.'/site/');
$GLOBALS['fixtureOptions']['zoer_connect_storage_dir']=$base.'/private';$GLOBALS['wpdb']=(object)['prefix'=>'wp_'];
$new=fn()=>new TransferImport($GLOBALS['wpdb'],$base.'/site',$base.'/private',str_repeat('a',64),'https://destination.example',true);
$journal=function($id,$phase,$date)use($base){$dir=$base.'/private/import-'.$id;mkdir($dir,0700);file_put_contents($dir.'/state.json',json_encode(['id'=>$id,'phase'=>$phase,'createdAt'=>$date,'finishedAt'=>$date,'target'=>'https://destination.example','owner'=>str_repeat('f',64),'sourceUrl'=>'https://source.example','cursor'=>0,'tables'=>[],'artifacts'=>[]]));mkdir($dir.'/artifact-0');file_put_contents($dir.'/artifact-0/original','keep these bytes');return $dir;};
try{
 $old=str_repeat('a',32);$latest=str_repeat('b',32);$active=str_repeat('c',32);$recent=str_repeat('d',32);
 $d1=$journal($old,'complete','2026-01-01T00:00:00Z');$d2=$journal($latest,'complete','2026-06-01T00:00:00Z');
 check(TransferRetention::policy()===['enabled'=>false,'days'=>30],'cleanup is opt-in with 30 day default');
 $before=file_get_contents($d1.'/state.json');TransferRetention::run();check(file_get_contents($d1.'/state.json')===$before,'disabled worker does not mutate imports');
 check(!($new()->retentionCleanup($latest,strtotime('2026-10-01'))['cleanedUp']??false),'newest successful backup survives regardless of age');
 $d3=$journal($active,'uploading','2026-07-01T00:00:00Z');check(!($new()->retentionCleanup($old,strtotime('2026-10-01'))['cleanedUp']??false),'an active import blocks scheduled cleanup');
 $s=json_decode(file_get_contents($d3.'/state.json'),true);$s['phase']='cancelled';file_put_contents($d3.'/state.json',json_encode($s));
 $d4=$journal($recent,'cancelled','2026-10-15T00:00:00Z');check(!($new()->retentionCleanup($recent,strtotime('2026-10-01'))['cleanedUp']??false),'recent finished imports retain their grace period');
 $measurement=TransferRetention::measureImport($base.'/private',$old,$GLOBALS['wpdb'],microtime(true)+2);
 check($measurement['complete']&&$measurement['bytes']===filesize($d1.'/state.json')+strlen('keep these bytes'),'size includes private journal and retained files');
 $partial=TransferRetention::measureImport($base.'/private',$old,$GLOBALS['wpdb'],microtime(true)-1);check(!$partial['complete'],'bounded measurements are marked incomplete');
 $GLOBALS['fixtureOptions'][TransferRetention::OPTION]=['enabled'=>true,'days'=>1];
 check($new()->retentionCleanup($old,strtotime('2026-10-01'))['cleanedUp']===true,'old completed import cleaned through existing engine');
 check(!is_dir($d1.'/artifact-0')&&is_file($d1.'/state.json')&&is_file($d2.'/artifact-0/original'),'cleanup retains history and newest backup bytes');
 check(TransferRetention::measureImport($base.'/private',$old,$GLOBALS['wpdb'],microtime(true)+2)['bytes']===0,'cleaned imports report zero retained storage');
 $_POST=['_wpnonce'=>'bad','days'=>'30'];reject(fn()=>TransferRetention::save(),'retention changes require a nonce');
 $GLOBALS['fixtureAdmin']=false;reject(fn()=>TransferRetention::measure(),'size reads require administrator access');$GLOBALS['fixtureAdmin']=true;
 $_POST=['_wpnonce'=>'fixture:zoer_import_retention','days'=>'0'];reject(fn()=>TransferRetention::save(),'zero day cleanup is refused');
 TransferRetention::run();$last=get_option('zoer_connect_import_retention_last');check(is_array($last)&&isset($last['checkedAt'])&&$last['protected']>=1,'scheduled run records an honest summary while keeping the newest backup');
 echo "PASS retention safeguards and bounded storage measurements\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);}
