<?php
foreach(['Selection','ExportProfile','FileExporter'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\FileExporter;use ZoerConnect\ExportProfile;
$root=sys_get_temp_dir().'/zoer-export-test-'.bin2hex(random_bytes(6));mkdir($root);
function echeck($x,$name){if(!$x)throw new RuntimeException($name);echo "PASS $name\n";}
try{
 foreach(['wp-content/themes/demo/a.txt','wp-content/plugins/demo/a.txt','wp-content/plugins/zoer-connect/main.php','wp-content/mu-plugins/demo.php','wp-includes/a.php','wp-admin/a.php','wp-config.php','index.php'] as $file){if(!is_dir(dirname($root.'/'.$file)))mkdir(dirname($root.'/'.$file),0755,true);file_put_contents($root.'/'.$file,'fixture');}
 foreach(['wp-content/plugins/demo/.gitattributes','wp-content/plugins/demo/.DS_Store'] as $file)file_put_contents($root.'/'.$file,'metadata');
 $profile=ExportProfile::normalize(['name'=>'test','core'=>true,'muplugins'=>true,'plugins'=>true]);$plan=FileExporter::plan($root,$profile);$paths=array_column($plan['files'],'path');
 echeck(!in_array('wp-content/plugins/demo/.gitattributes',$paths,true),'repository metadata excluded by default');
 echeck(!in_array('wp-content/plugins/demo/.DS_Store',$paths,true),'Finder metadata excluded by default');
 echeck(in_array('wp-content/mu-plugins/demo.php',$paths),'mu-plugin export selection');echeck(in_array('wp-admin/a.php',$paths)&&in_array('index.php',$paths),'core export selection');
 echeck(!in_array('wp-config.php',$paths),'configuration excluded');echeck(!in_array('wp-content/plugins/zoer-connect/main.php',$paths),'connector excluded');echeck(!in_array('wp-content/themes/demo/a.txt',$paths),'unselected category excluded');
 FileExporter::zip($root,$plan,$root.'/test.zip');$z=new ZipArchive();$z->open($root.'/test.zip');echeck($z->getFromName('wordpress/wp-content/mu-plugins/demo.php')==='fixture','ZIP file bytes');echeck($z->locateName('zoer-manifest.json')!==false,'ZIP manifest');$z->close();
 file_put_contents($root.'/index.php','changed');$blocked=false;try{FileExporter::zip($root,$plan,$root.'/changed.zip');}catch(Throwable $e){$blocked=true;}echeck($blocked,'changed source refused');
 $blocked=false;try{ExportProfile::normalize(['name'=>'x','password'=>'secret']);}catch(Throwable $e){$blocked=true;}echeck($blocked,'credential fields rejected');
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);}
