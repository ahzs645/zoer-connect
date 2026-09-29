<?php
// Rollback removes directories that a publication created (and only those), so a
// rolled-back new theme/plugin does not leave an empty "broken theme" folder behind.
// Found by the local Docker end-to-end run (tests/e2e-docker).
require __DIR__.'/../includes/StageStore.php';
require __DIR__.'/../includes/FilePublication.php';
require __DIR__.'/../includes/ChunkedFilePublication.php';
use ZoerConnect\ChunkedFilePublication;use ZoerConnect\FilePublication;use ZoerConnect\StageStore;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$base=sys_get_temp_dir().'/zoer-dirs-'.bin2hex(random_bytes(6));
$public=$base.'/public';mkdir($public.'/wp-content/themes',0755,true);mkdir($public.'/wp-content/plugins',0755,true);
$n=0;$private=function()use($base,&$n){$d=$base.'/private-'.(++$n);mkdir($d,0700);return $d;};
$legacy=function(string $path,string $bytes)use($public,$private){
    $p=$private();file_put_contents($p.'/src',$bytes);
    $pub=new FilePublication($public,$p);$pub->prepare(['version'=>1,'target'=>'t','files'=>[['path'=>$path,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)]]],[$p.'/src']);
    for($i=0;$i<10&&$pub->step()['status']!=='verification_required';$i++);
    return $pub;
};
$blocks=function(string $path,string $bytes,bool $activate=true)use($public,$private){
    $p=$private();file_put_contents($p.'/src',$bytes);
    $a=['path'=>$path,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'chunkSha256'=>[hash('sha256',$bytes)]];
    $pub=new ChunkedFilePublication($public,$p);$pub->prepare(['version'=>1,'target'=>'t','files'=>[$a]],[$p.'/src']);
    for($i=0;$i<50;$i++){$s=$pub->step();if($s['status']==='verification_required'||(!$activate&&$s['phase']==='new_check'))break;}
    return $pub;
};
$rollback=function($pub){if($pub instanceof ChunkedFilePublication){while(!$pub->rollbackPreflightStep()){}}else $pub->rollbackPreflight();for($i=0;$i<50;$i++)if($pub->rollbackStep()['status']==='rolled_back')return;throw new RuntimeException('rollback did not finish');};
try{
    // Legacy (32 MiB) path: nested new theme directories are created and removed again.
    $pub=$legacy('wp-content/themes/newtheme/assets/app.css','body{}');
    check(is_file($public.'/wp-content/themes/newtheme/assets/app.css'),'legacy publication creates nested directories');
    $rollback($pub);
    check(!file_exists($public.'/wp-content/themes/newtheme'),'legacy rollback removes the directories it created');

    // Block path, two files in one new plugin: rollback in reverse order removes the folder.
    $first=$blocks('wp-content/plugins/newplugin/newplugin.php','<?php // one');
    $second=$blocks('wp-content/plugins/newplugin/inc/two.php','<?php // two');
    check(is_file($public.'/wp-content/plugins/newplugin/inc/two.php'),'block publication creates nested directories');
    $rollback($second);$rollback($first);
    check(!file_exists($public.'/wp-content/plugins/newplugin'),'block rollback (reverse order) removes every created directory');

    // Later content in a created directory keeps it; pre-existing empty directories are never removed.
    mkdir($public.'/wp-content/uploads/2026/09',0755,true);
    $pub=$blocks('wp-content/uploads/2026/09/new.png','png');
    $other=$blocks('wp-content/themes/kept/style.css','/* kept */');
    file_put_contents($public.'/wp-content/themes/kept/later.txt','added after publication');
    $rollback($other);$rollback($pub);
    check(!file_exists($public.'/wp-content/themes/kept/style.css')&&is_file($public.'/wp-content/themes/kept/later.txt'),'directory with later content is kept');
    check(is_dir($public.'/wp-content/uploads/2026/09')&&!file_exists($public.'/wp-content/uploads/2026/09/new.png'),'pre-existing empty directory is not removed');

    // Rollback before activation (file never renamed into place) also removes the created folder.
    $pub=$blocks('wp-content/themes/early/style.css','/* early */',false);
    check(is_dir($public.'/wp-content/themes/early')&&!file_exists($public.'/wp-content/themes/early/style.css'),'unactivated publication created only its folder');
    for($i=0;$i<10;$i++)if($pub->rollbackStep()['status']==='rolled_back')break;
    check(!file_exists($public.'/wp-content/themes/early'),'unactivated rollback removes the created folder and temporary file');
    check(FilePublication::missingDirectories($public,'wp-content/uploads/x/y/z.png')===['wp-content/uploads/x/y','wp-content/uploads/x'],'missing directories listed deepest first');
    FilePublication::removeCreatedDirectories($public,['wp-content','wp-content/../outside','/etc']);
    check(is_dir($public.'/wp-content'),'directories outside themes/plugins/uploads are never removed');
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);
}
