<?php
require __DIR__ . '/../includes/StageStore.php';
use ZoerConnect\StageStore;
$n = 0;
function check($ok, $name) { global $n; if (!$ok) throw new RuntimeException($name); $n++; }
function rejects($fn, $name) { try { $fn(); } catch (Throwable $e) { check(true, $name); return; } throw new RuntimeException('Accepted: ' . $name); }
$base = sys_get_temp_dir() . '/zoer-test-' . bin2hex(random_bytes(6));
mkdir($base); mkdir($base . '/public');
$manifest = ['version'=>1,'target'=>'https://example.org','files'=>[['path'=>'wp-content/themes/demo/style.css','bytes'=>3,'sha256'=>hash('sha256','abc')]]];
try {
    rejects(fn()=>new StageStore($base . '/public/stage', [$base . '/public']), 'public storage');
    symlink($base . '/public', $base . '/linked');
    rejects(fn()=>new StageStore($base . '/linked', [$base . '/public']), 'symlink storage');
    $s = new StageStore($base . '/private', [$base . '/public']);
    rejects(fn()=>StageStore::validateManifest($manifest,'https://wrong.org'),'target');
    foreach (['wp-content/themes/../wp-config.php','wp-content/plugins/zoer-connect/main.php','wp-content/uploads/x.php.jpg','wp-content/uploads/.htaccess','/etc/passwd','wp-content/themes/x//a','wp-content/themes/x/./a'] as $path) {
        $bad = $manifest; $bad['files'][0]['path'] = $path;
        rejects(fn()=>StageStore::validateManifest($bad,$manifest['target']),$path);
    }
    $bad = $manifest; $bad['files'][]=$bad['files'][0];
    rejects(fn()=>StageStore::validateManifest($bad,$manifest['target']),'duplicate');
    $bad = $manifest; $bad['files'][]=['path'=>'wp-content/themes/demo','bytes'=>0,'sha256'=>hash('sha256','')];
    rejects(fn()=>StageStore::validateManifest($bad,$manifest['target']),'parent collision');
    $job=$s->create($manifest,$manifest['target']); $id=$job['id'];
    rejects(fn()=>$s->create($manifest,$manifest['target']),'single job quota');
    rejects(fn()=>$s->chunk($id,0,1,'a'),'gap');
    check($s->chunk($id,0,0,'a')['offset']===1,'first chunk');
    check($s->chunk($id,0,0,'a')['offset']===1,'idempotent retry');
    rejects(fn()=>$s->chunk($id,0,0,'b'),'conflicting retry');
    check($s->status($id)['offsets']===[1],'resume status');
    rejects(fn()=>$s->verify($id),'incomplete');
    rejects(fn()=>$s->chunk($id,0,1,'bcd'),'oversized');
    $s->chunk($id,0,1,'bx');
    rejects(fn()=>$s->verify($id),'checksum');
    check($s->cancel($id)['status']==='cancelled','cancel');
    $id=$s->create($manifest,$manifest['target'])['id'];
    $s->chunk($id,0,0,'abc');
    check($s->verify($id)['status']==='staged','verify');
    check($s->verify($id)['status']==='staged','repeat verify');
    rejects(fn()=>$s->chunk($id,0,0,'abc'),'immutable verified job');
    check(count(glob($base . '/public/*'))===0,'no live mutation');
    $s->cancel($id);
    $empty=$manifest; $empty['files'][0]['bytes']=0; $empty['files'][0]['sha256']=hash('sha256','');
    $id=$s->create($empty,$manifest['target'])['id']; $s->chunk($id,0,0,'');
    check($s->verify($id)['status']==='staged','empty file'); $s->cancel($id);
    rejects(fn()=>$s->status('../public'),'invalid job path');
    $id=$s->create($manifest,$manifest['target'])['id'];
    check($s->jobs()[0]['id']===$id,'job discovery');
    check($s->expire(time())===[],'keep recent jobs');
    check($s->expire(time()+86401)===[$id],'expire old staging');
    check($s->jobs()===[],'expiry releases reservation');
    echo "$n assertions passed\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p) { if($p->isDir() && !$p->isLink()) rmdir($p->getPathname()); else unlink($p->getPathname()); }
    rmdir($base);
}
