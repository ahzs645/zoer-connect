<?php
require __DIR__.'/../includes/StageStore.php';
use ZoerConnect\StageStore;
$check=static function(string $path): bool {
    try{StageStore::validateManifest(['version'=>1,'target'=>'t','files'=>[['path'=>$path,'bytes'=>0,'sha256'=>hash('sha256','')]]],'t');return true;}
    catch(\InvalidArgumentException $e){return false;}
};
// Ordinary WordPress file names: Retina assets ship in core plugins such as Akismet.
foreach(['wp-content/plugins/akismet/_inc/img/akismet-refresh-logo@2x.png','wp-content/uploads/2026/09/c++ guide [draft] #2 (final)!.pdf','wp-content/themes/t/assets/a~b&c=d\'e.svg','wp-content/uploads/2026/09/Écran café.png'] as $path){
    if(!$check($path)){fwrite(STDERR,"FAIL accepted path: $path\n");exit(1);}
}
foreach(['wp-content/plugins/a/%2e%2e/x.php','wp-content/plugins/a\\b.php','wp-content/plugins/a:b.php','wp-content/plugins/a*b.php','wp-content/plugins/a?b.php','wp-content/plugins/a"b.php','wp-content/plugins/a<b>.php','wp-content/plugins/a|b.php',"wp-content/plugins/a\nb.php",'wp-content/plugins/../wp-config.php','wp-content/plugins/.hidden/x.php','wp-content/uploads/x@2x.php','wp-content/mu-plugins/a@2x.png'] as $path){
    if($check($path)){fwrite(STDERR,"FAIL rejected path: $path\n");exit(1);}
}
echo "PASS path character allowlist accepts ordinary names and still rejects unsafe paths\n";
