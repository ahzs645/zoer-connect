<?php
foreach(['WriteFence','CopiedFence'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\CopiedFence;use ZoerConnect\WriteFence;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function rejects($fn,$message){try{$fn();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message);}
$base=realpath(sys_get_temp_dir()).'/zoer-copied-fence-'.bin2hex(random_bytes(6));
$site=function(string $name)use($base){$root=$base.'/'.$name.'/public';$private=$base.'/'.$name.'/private';mkdir($root.'/wp-content',0755,true);mkdir($private,0700,true);return [realpath($root),realpath($private)];};
mkdir($base,0700);
try{
 // The original site (its folder name has a quote, which var_export escapes) and its fence in both cache modes.
 [$rootA,$privateA]=$site("it's-original");$muA=$rootA.'/'.CopiedFence::MU;
 (new WriteFence($privateA,$rootA))->install();$plain=file_get_contents($muA);
 (new WriteFence($privateA,$rootA))->install(true);$shared=file_get_contents($muA);
 $plugin=$rootA.'/wp-content/plugins/zoer-connect/includes/';
 $legacy="<?php\n// Zoer Connect request fence: keep before ordinary plugins and MU plugins.\ndefined('ABSPATH') || exit;\nrequire_once ".var_export($plugin.'WriteFence.php',true).";\n\\ZoerConnect\\WriteFence::boot(".var_export($privateA,true).", ".var_export($rootA,true).", static function (string \$route) {\n require_once ".var_export($plugin.'Plugin.php',true).";\n return \\ZoerConnect\\Plugin::earlyImportRecovery(\$route);\n});\n";
 foreach(['current'=>$plain,'shared-cache'=>$shared,'0.5.x'=>$legacy] as $form=>$body)check(CopiedFence::paths($body)===['root'=>$rootA,'private'=>$privateA],"recognises the $form fence and its paths");
 foreach(["<?php // someone else's file\n",$plain."\n// changed\n",str_replace('earlyImportRecovery','other',$plain),''] as $body)check(CopiedFence::paths($body)===null,'anything but an exact Zoer Connect fence is not recognised');
 check(!CopiedFence::removeIfCopied($rootA,$privateA)&&file_get_contents($muA)===$shared,'this site\'s own fence is kept');

 // A copy of the site in another folder (a hosting backup restore) brings the original's fence along.
 [$rootB,$privateB]=$site('copy');$muB=$rootB.'/'.CopiedFence::MU;mkdir(dirname($muB));
 foreach(['current'=>$plain,'shared-cache'=>$shared,'0.5.x'=>$legacy] as $form=>$body){
  file_put_contents($muB,$body);
  rejects(fn()=>(new WriteFence($privateB,$rootB))->install(true),"WriteFence alone still refuses the copied $form fence");
  check(CopiedFence::removeIfCopied($rootB,$privateB)&&!file_exists($muB),"the copied $form fence is removed");
  (new WriteFence($privateB,$rootB))->install(true);check((new WriteFence($privateB,$rootB))->installed(),"this site's own fence installs after the copied $form fence");
  unlink($muB);
 }
 // The original's paused transfer does not hold the copy.
 file_put_contents($privateA.'/write-fence.json','{}');file_put_contents($muB,$plain);
 check(CopiedFence::removeIfCopied($rootB,$privateB),'a transfer paused on the original site does not hold its copy');unlink($privateA.'/write-fence.json');
 file_put_contents($muB,"<?php // someone else's file\n");check(!CopiedFence::removeIfCopied($rootB,$privateB)&&is_file($muB),'a foreign MU file is never removed');unlink($muB);
 symlink($muA,$muB);check(!CopiedFence::removeIfCopied($rootB,$privateB)&&is_link($muB),'a symlinked MU file is never removed');unlink($muB);

 // The same WordPress folder with an earlier private storage folder: replaced only while that folder holds no paused transfer.
 $earlier=$base.'/earlier-private';mkdir($earlier,0700);$earlier=realpath($earlier);
 (new WriteFence($earlier,$rootB))->install(true);
 file_put_contents($earlier.'/write-fence.json','{}');
 rejects(fn()=>CopiedFence::removeIfCopied($rootB,$privateB),'a transfer paused in the earlier storage folder keeps its fence');
 check(is_file($muB),'the held fence is left in place');unlink($earlier.'/write-fence.json');
 check(CopiedFence::removeIfCopied($rootB,$privateB)&&!file_exists($muB),'the fence of an earlier storage folder is replaced once nothing is paused');
}finally{
 $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($walk as $entry)$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($base);
}
