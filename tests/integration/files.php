<?php
// wp eval-file on the disposable site only. Never run on production.
if (strpos(home_url(), 'zoer-connect-security-test.') === false) throw new RuntimeException('Test site required.');
require_once WP_PLUGIN_DIR . '/zoer-connect/includes/FilePublication.php';
$dir=ABSPATH.'wp-content/themes/zoer-integration-fixture';
if(file_exists($dir)) throw new RuntimeException('Fixture already exists.');
$private=sys_get_temp_dir().'/zoer-file-test-'.bin2hex(random_bytes(8));
mkdir($dir);mkdir($private,0700);
try {
 file_put_contents($dir.'/style.css','old-fixture');file_put_contents($private.'/0.part','new-fixture');
 $manifest=['version'=>1,'target'=>untrailingslashit(home_url()),'files'=>[['path'=>'wp-content/themes/zoer-integration-fixture/style.css','bytes'=>11,'sha256'=>hash('sha256','new-fixture')]]];
 $p=new \ZoerConnect\FilePublication(ABSPATH,$private);$p->prepare($manifest,[$private.'/0.part']);
 for($i=0;$i<4;$i++)$state=$p->step();
 if($state['status']!=='verification_required'||file_get_contents($dir.'/style.css')!=='new-fixture')throw new RuntimeException('Activation failed');
 $p=new \ZoerConnect\FilePublication(ABSPATH,$private);
 $p->rollbackStep();$state=$p->rollbackStep();
 if($state['status']!=='rolled_back'||file_get_contents($dir.'/style.css')!=='old-fixture')throw new RuntimeException('Rollback failed');
 echo "PASS installed plugin file engine activated and restored disposable WordPress fixture\n";
} finally {
 foreach(glob($private.'/*') as $file)unlink($file);rmdir($private);
 if(is_file($dir.'/style.css'))unlink($dir.'/style.css');rmdir($dir);
}
