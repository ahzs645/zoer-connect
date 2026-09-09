<?php
require __DIR__.'/../includes/RequestDrain.php';require __DIR__.'/../includes/WriteFence.php';
use ZoerConnect\RequestDrain;use ZoerConnect\WriteFence;
function check($ok,$m){if(!$ok)throw new RuntimeException($m);echo "PASS $m\n";}
$base=sys_get_temp_dir().'/zoer-drain-'.bin2hex(random_bytes(8));mkdir($base);mkdir($base.'/private',0700);mkdir($base.'/public/wp-content',0755,true);
$private=$base.'/private';$pending=['version'=>2,'setupId'=>bin2hex(random_bytes(16)),'fenceSha256'=>hash_file('sha256',__DIR__.'/../includes/WriteFence.php'),'drainSha256'=>hash_file('sha256',__DIR__.'/../includes/RequestDrain.php')];file_put_contents($private.'/import-setup-pending.json',json_encode($pending));
$drain=new RequestDrain($private);$pipes=[];$child=null;
try{
 check(RequestDrain::runtimeVerified()&&WriteFence::runtimeVerified(),'compiled proof code matches sealed source');
 $clone=$base.'/sealed.php';copy(__DIR__.'/../includes/RequestDrain.php',$clone);
 $scriptCode='require '.var_export($clone,true).';$before=\\ZoerConnect\\RequestDrain::runtimeVerified();file_put_contents('.var_export($clone,true).',"\\n// changed on disk",FILE_APPEND);echo json_encode([$before,\\ZoerConnect\\RequestDrain::runtimeVerified()]);';
 $out=[];$h=proc_open([PHP_BINARY,'-r',$scriptCode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$out);fclose($out[0]);$result=stream_get_contents($out[1]);$err=stream_get_contents($out[2]);fclose($out[1]);fclose($out[2]);check(proc_close($h)===0&&$err===''&&$result==='[true,false]','already-loaded old code cannot prove adoption after its file changes');
 $restricted='require '.var_export(realpath(__DIR__.'/../includes/RequestDrain.php'),true).';ini_set("open_basedir",'.var_export($base,true).');try{\\ZoerConnect\\RequestDrain::workers();exit(1);}catch(\\RuntimeException $e){echo "closed";}';
 $io=[];$h=proc_open([PHP_BINARY,'-r',$restricted],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$io);fclose($io[0]);$text=stream_get_contents($io[1]);fclose($io[1]);fclose($io[2]);check(proc_close($h)===0&&$text==='closed','hidden process information fails closed without timeout fallback');
 $status="Name:\tphp-fpm8.3\nState:\tS (sleeping)\nUid:\t1000\t1000\t1000\t1000\nThreads:\t1\n";
 $stat='55 (comm with ) parentheses) '.implode(' ',array_merge(['S'],array_fill(0,18,'0'),['99999999999999']));
 check(RequestDrain::process($status,$stat,'php-fpm: pool www')['start']==='99999999999999','start-time parser tolerates spaces/parentheses and preserves exact integer');
 check(RequestDrain::process($status,$stat,'php-fpm: master process (/etc/fpm.conf)')['master'],'pool master is distinguished from request workers');
 $fake=['boot'=>'testboot','workers'=>['55'=>'99999999999999']];
 check($drain->check($pending,$fake)['remaining']===1,'missing enrollment blocks readiness');
 $old=$pending;$old['workerBaseline']=['boot'=>'testboot','workers'=>['55'=>'99999999999999']];$fresh=['boot'=>'testboot','workers'=>['56'=>'100000000000000'],'kinds'=>['56'=>'request']];check($drain->check($old,$fresh)['remaining']===0,'new request worker after installation has no earlier request to drain');$fresh['kinds']['56']='cli';check($drain->check($old,$fresh)['remaining']===1,'new CLI writer still requires an actual lease');

 $dir=$private.'/drain-'.$pending['setupId'];mkdir($dir,0700);$ticket=['boot'=>'testboot','start'=>'99999999999999','setupId'=>$pending['setupId'],'fenceSha256'=>$pending['fenceSha256'],'drainSha256'=>$pending['drainSha256']];file_put_contents($dir.'/55.json',json_encode($ticket));
 check($drain->check($pending,$fake)['remaining']===0,'matching generation and lifetime are accepted');
 foreach(['boot','start','setupId','fenceSha256','drainSha256'] as $field){$bad=$ticket;$bad[$field]='changed';file_put_contents($dir.'/55.json',json_encode($bad));check($drain->check($pending,$fake)['remaining']===1,'changed '.$field.' invalidates enrollment');}
 unlink($dir.'/55.json');symlink($private.'/import-setup-pending.json',$dir.'/55.json');check($drain->check($pending,$fake)['remaining']===1,'symlink ticket cannot establish readiness');unlink($dir.'/55.json');
 $f=new WriteFence($private,$base.'/public');$f->install();$f->enter();
 $script=$base.'/old-request.php';file_put_contents($script,'<?php require '.var_export(realpath(__DIR__.'/../includes/WriteFence.php'),true).';echo "OLD\\n";fflush(STDOUT);fgets(STDIN);$f=new \\ZoerConnect\\WriteFence('.var_export($private,true).','.var_export($base.'/public',true).');$f->enter();echo "ENROLLED\\n";fflush(STDOUT);fgets(STDIN);');
 $child=proc_open([PHP_BINARY,$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($child)&&trim(fgets($pipes[1]))==='OLD','real earlier unprotected PHP process started');
 check($drain->check($pending)['remaining']>=1,'real unprotected process prevents readiness despite current request lease');
 fwrite($pipes[0],"adopt\n");fflush($pipes[0]);check(trim(fgets($pipes[1]))==='ENROLLED','same process acquires protection without restart');
 check($drain->check($pending)['remaining']===0,'all real PHP processes have adopted current request protection');
 check((fileperms($dir.'/'.getmypid().'.json')&0777)===0600,'enrollment receipt has private permissions');
 fwrite($pipes[0],"exit\n");fclose($pipes[0]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);check(proc_close($child)===0&&$err==='','enrolled worker exits normally');$child=null;
 check($drain->check($pending)['remaining']===0,'exited process and stale ticket do not block readiness');
}finally{if(is_resource($child))proc_terminate($child);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);}
