<?php
require __DIR__.'/fixtures/admin-wordpress.php';
use ZoerConnect\ConnectionAdmin;use ZoerConnect\ConnectionKey;use ZoerConnect\ImportAdmin;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function render(array $post=[]): string {$_POST=$post;$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';ob_start();try{ConnectionAdmin::render();return ob_get_contents();}finally{ob_end_clean();}}
function rejected(callable $fn): bool {try{$fn();return false;}catch(Throwable $e){return true;}}
$base=sys_get_temp_dir().'/zoer-connection-admin-'.bin2hex(random_bytes(6));mkdir($base);$root=$base.'/wordpress';mkdir($root);mkdir($root.'/wp-content');define('ABSPATH',$root.'/');$_SERVER['DOCUMENT_ROOT']=$root;
$private=$base.'/.zoer-connect';$post=['zoer_connection_action'=>'rotate','_wpnonce'=>'fixture:zoer_connection_settings'];
try{
 $html=render();check(!get_option(ConnectionKey::OPTION)&&!file_exists($private),'GET never creates a key or setup');
 check(str_contains($html,'automatically prepare shared-hosting migration'),'new defaults are explained before generating a key');
 check(rejected(fn()=>render(['zoer_connection_action'=>'rotate','_wpnonce'=>'invalid'])),'bad nonce rejects key generation');
 $GLOBALS['fixtureSsl']=false;check(rejected(fn()=>render($post)),'HTTP rejects key generation');$GLOBALS['fixtureSsl']=true;
 $GLOBALS['fixtureAdmin']=false;check(render($post)===''&&!get_option(ConnectionKey::OPTION),'non-admin cannot create a connection');$GLOBALS['fixtureAdmin']=true;
 file_put_contents($root.'/wp-content/db.php','<?php');$html=render($post);$first=get_option(ConnectionKey::OPTION);
 check($first['push']&&!$first['pull']&&str_contains($html,'id="zoer-connection-info"'),'new key shown once with Push enabled and Pull disabled');
 check(str_contains($html,'could not be prepared')&&!ImportAdmin::ready($private,$root),'setup failure leaves key available and explains problem');
 unlink($root.'/wp-content/db.php');$html=render(['zoer_connection_action'=>'permissions','_wpnonce'=>$post['_wpnonce'],'allow_push'=>'on']);
 check(ImportAdmin::mode($private,$root)==='shared-replacement','saving Push retries fresh setup successfully');
 check(!file_exists($private.'/write-fence.json'),'setup alone does not start maintenance or publish');
 $html=render();check(!str_contains($html,'id="zoer-connection-info"'),'GET does not redisplay secret');
 render(['zoer_connection_action'=>'permissions','_wpnonce'=>$post['_wpnonce'],'allow_pull'=>'on']);render($post);$rotated=get_option(ConnectionKey::OPTION);
 check(!$rotated['push']&&$rotated['pull']&&$rotated['hash']!==$first['hash'],'reset preserves disabled Push and enabled Pull');
 render(['zoer_connection_action'=>'revoke','_wpnonce'=>$post['_wpnonce']]);render($post);$regenerated=get_option(ConnectionKey::OPTION);
 check(!$regenerated['push']&&!$regenerated['pull'],'key regeneration respects revoked permissions');
 // Start a fresh fixture to exercise the one-click happy path.
 update_option(ConnectionKey::OPTION,[]);unlink($private.'/import-readiness.json');unlink($root.'/wp-content/mu-plugins/000-zoer-connect-fence.php');
 $html=render($post);check(ImportAdmin::ready($private,$root)&&str_contains($html,'Shared-hosting migration is ready'),'first key generation readies destination in the same POST');
 $receipt=file_get_contents($private.'/import-readiness.json');render($post);check(file_get_contents($private.'/import-readiness.json')===$receipt,'repeated key submissions preserve installed setup');
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($base);}
