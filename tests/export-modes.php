<?php
// Profile resource modes, media date filter and database filter wiring (API A3).
foreach(['StageStore','Selection','ExportProfile','FileExporter','DatabaseExporter','RemoteExport','PagedExport'] as $class)require __DIR__.'/../includes/'.$class.'.php';
use ZoerConnect\ExportProfile as P;use ZoerConnect\FileExporter;use ZoerConnect\RemoteExport;use ZoerConnect\PagedExport;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function denied(callable $fn,$name,string $message=''){try{$fn();}catch(Throwable $e){check($message===''||$e->getMessage()===$message,$name);return;}throw new RuntimeException($name);}
$base=sys_get_temp_dir().'/zoer-modes-'.bin2hex(random_bytes(6));mkdir($base);$root=$base.'/public';
try{
 $files=['wp-content/themes/index.php','wp-content/themes/parent/style.css','wp-content/themes/child/style.css','wp-content/themes/other/style.css','wp-content/plugins/index.php','wp-content/plugins/alpha/alpha.php','wp-content/plugins/beta/beta.php','wp-content/plugins/hello.php','wp-content/plugins/zoer-connect/zoer-connect.php','wp-content/uploads/2019/old.jpg','wp-content/uploads/2026/new.jpg'];
 foreach($files as $file){if(!is_dir(dirname($root.'/'.$file)))mkdir(dirname($root.'/'.$file),0755,true);file_put_contents($root.'/'.$file,$file);}
 touch($root.'/wp-content/uploads/2019/old.jpg',gmmktime(12,0,0,6,1,2019));touch($root.'/wp-content/uploads/2026/new.jpg',gmmktime(0,0,0,1,1,2026));
 // Normalization keeps 0.3.14 bindings for absent or default values.
 $legacy=P::normalize(['name'=>'site','themes'=>true,'media'=>true]);
 check($legacy===P::normalize(['name'=>'site','themes'=>true,'media'=>true,'themesMode'=>'all','themesItems'=>['ignored'],'pluginsMode'=>'all','mediaSince'=>null]),'absent and all modes normalize identically');
 check(array_keys($legacy)===['name','themes','plugins','media','muplugins','core','excludes'],'legacy profile shape unchanged');
 $p=P::normalize(['name'=>'x','themesMode'=>'selected','themesItems'=>['b','a','b'],'pluginsMode'=>'active','pluginsItems'=>['x'],'mediaSince'=>'2024-02-29']);
 check($p['themesItems']===['a','b']&&$p['pluginsMode']==='active'&&$p['pluginsItems']===[]&&$p['mediaSince']==='2024-02-29','modes normalized: sorted unique items, active ignores items');
 foreach([['themesMode'=>'some'],['themesItems'=>['a/b']],['themesItems'=>['..']],['pluginsItems'=>'alpha'],['pluginsItems'=>[1]],['mediaSince'=>'2023-02-29'],['mediaSince'=>'01/02/2024'],['mediaSince'=>1700000000]] as $i=>$bad)denied(fn()=>P::normalize(['name'=>'x']+$bad),'invalid profile addition rejected #'.$i);
 check(P::mediaSince(['mediaSince'=>'2024-01-02'])===gmmktime(0,0,0,1,2,2024)&&P::mediaSince($legacy)===null,'media date is UTC midnight');
 $paths=fn(array $profile,array $active=[])=>array_column(FileExporter::plan($root,['name'=>'t']+$profile,$active)['files'],'path');
 check(FileExporter::roots($root,$legacy)===['wp-content/themes','wp-content/uploads'],'all mode traverses category roots as before');
 check($paths(['themes'=>true,'themesMode'=>'selected','themesItems'=>['other']])===['wp-content/themes/other/style.css'],'selected theme only');
 check($paths(['themes'=>true,'themesMode'=>'active'],['themes'=>['child','parent']])===['wp-content/themes/child/style.css','wp-content/themes/parent/style.css'],'active child theme and parent');
 check($paths(['themes'=>true,'themesMode'=>'except','themesItems'=>['parent','deleted']])===['wp-content/themes/child/style.css','wp-content/themes/index.php','wp-content/themes/other/style.css'],'all themes except listed; absent exceptions ignored');
 check($paths(['plugins'=>true,'pluginsMode'=>'selected','pluginsItems'=>['hello.php','alpha']])===['wp-content/plugins/alpha/alpha.php','wp-content/plugins/hello.php'],'selected directory and single-file plugins');
 check($paths(['plugins'=>true,'pluginsMode'=>'active'],['plugins'=>['beta','zoer-connect']])===['wp-content/plugins/beta/beta.php'],'active plugins; connector remains excluded');
 check($paths(['plugins'=>true,'pluginsMode'=>'selected'])===[],'empty plugin selection selects nothing');
 denied(fn()=>$paths(['plugins'=>true,'pluginsMode'=>'selected','pluginsItems'=>['missing']]),'selected plugin absent from source refused');
 check($paths(['media'=>true,'mediaSince'=>'2025-12-31'])===['wp-content/uploads/2026/new.jpg'],'media modified on or after date');
 check($paths(['media'=>true,'mediaSince'=>'2026-01-01'])===['wp-content/uploads/2026/new.jpg'],'media date boundary is inclusive');
 check(count($paths(['media'=>true]))===2,'media without date exports every upload');
 $profile=['name'=>'zip','themes'=>true,'themesMode'=>'active'];$active=['themes'=>['other']];$plan=FileExporter::plan($root,$profile,$active);
 FileExporter::zip($root,$plan,$base.'/active.zip',$active);$z=new ZipArchive();$z->open($base.'/active.zip');check($z->locateName('wordpress/wp-content/themes/other/style.css')!==false&&$z->numFiles===2,'download ZIP re-plans with the same active resources');$z->close();
 denied(fn()=>FileExporter::zip($root,$plan,$base.'/changed.zip',['themes'=>['parent']]),'changed active theme refused as changed source');
 // RemoteExport: filters reach the snapshot callback; table suffixes are reported in source.
 $store=new RemoteExport($base.'/private',[$root],$root,'owner-a');$seen=[];
 $db=function($path,$filters=[])use(&$seen){$seen[]=$filters;file_put_contents($path,'SQL');return ['options','posts'];};
 $id=str_repeat('a',32);$input=['clientId'=>$id,'profile'=>['name'=>'db'],'database'=>['tables'=>['posts','options'],'excludeRevisions'=>true]];
 $job=$store->create($input,['url'=>'https://source.example','abspath'=>'/srv/site'],$db);
 check($seen===[['tables'=>['options','posts'],'postTypes'=>null,'excludeRevisions'=>true,'excludeSpam'=>false,'excludeTransients'=>true]],'normalized filters passed to database snapshot');
 check($job['status']==='ready'&&$job['source']['tables']===['options','posts']&&$job['source']['abspath']==='/srv/site','source reports abspath and exported tables');
 check($store->create($input,['url'=>'https://source.example','abspath'=>'/srv/site'],$db)===$job&&count($seen)===1,'filtered create retry returns the same artifact');
 $input['database']=['tables'=>['posts']];denied(fn()=>$store->create($input,[],$db),'changed filters cannot reuse export ID');$store->cancel($id);
 $id=str_repeat('b',32);$legacyInput=['clientId'=>$id,'profile'=>['name'=>'db','themes'=>true],'database'=>true];$store->create($legacyInput,[],$db);
 $state=json_decode(file_get_contents($base.'/private/exports/'.$id.'/state.json'),true);
 check($state['requestHash']===hash('sha256',json_encode([P::normalize($legacyInput['profile']),true]))&&$seen[1]===[],'boolean selection keeps the 0.3.14 binding and unfiltered snapshot');$store->cancel($id);
 $id=str_repeat('c',32);$job=$store->create(['clientId'=>$id,'profile'=>['name'=>'files','plugins'=>true,'pluginsMode'=>'active'],'database'=>false],[],$db,['plugins'=>['alpha']]);
 check(count($seen)===2&&array_column($job['files'],'path')===['wp-content/plugins/alpha/alpha.php']&&$job['source']['tables']===[],'file-only export uses active plugins and reports no tables');$store->cancel($id);
 denied(fn()=>$store->create(['clientId'=>$id,'profile'=>['name'=>'x'],'database'=>'yes'],[],$db),'invalid database selection refused');
 denied(fn()=>$store->create(['clientId'=>$id,'profile'=>['name'=>'x']],[],$db),'missing database selection refused');
 // PagedExport resolves modes once at create and filters media while traversing.
 $paged=new PagedExport($base.'/private',[$root],$root,'owner-a');$id=str_repeat('d',32);
 $view=$paged->create(['clientId'=>$id,'profile'=>['name'=>'paged','themes'=>true,'themesMode'=>'except','themesItems'=>['other'],'media'=>true,'mediaSince'=>'2025-01-01'],'database'=>['excludeSpam'=>true]],['abspath'=>'/srv/site']);
 check($view['phase']==='database'&&$view['source']['tables']===[],'paged export starts with filtered database');
 $view=$paged->step($id,$db);check($seen[2]['excludeSpam']===true&&$view['source']['tables']===['options','posts'],'paged database step receives filters and reports tables');
 do{$view=$paged->step($id,$db);}while($view['status']!=='ready');
 $manifest=array_column($paged->manifest($id,0)['files'],'path');sort($manifest);
 check($manifest===['database.sql','wp-content/themes/child/style.css','wp-content/themes/index.php','wp-content/themes/parent/style.css','wp-content/uploads/2026/new.jpg'],'paged export applies theme exceptions and media date');
 $paged->cancel($id);
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);}
