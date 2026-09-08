<?php
require __DIR__.'/../includes/RecoveryCoordinator.php';
$f=new class {public $restored=false;function step(){return ['status'=>'verification_required'];}function rollbackStep(){$this->restored=true;return ['status'=>'rolled_back'];}};
$t=new class {public $active=false;public $restored=false;function activate(){$this->active=true;throw new RuntimeException('simulated lost reply after mutation');}function rollback(){$this->active=false;$this->restored=true;}};
$path=sys_get_temp_dir().'/zoer-coordinator-'.bin2hex(random_bytes(6));
try{
 $c=new \ZoerConnect\RecoveryCoordinator($path,$f,[$t]);$c->start();$c->tick();
 try{$c->tick();}catch(RuntimeException $e){}
 $c=new \ZoerConnect\RecoveryCoordinator($path,$f,[$t]);
 for($i=0;$i<3;$i++)$state=$c->tick();
 if($state['phase']!=='rolled_back'||!$t->restored||!$f->restored||$t->active)throw new RuntimeException('Recovery failed');
 echo "PASS recovery after mutation with lost reply, resumed coordinator and reverse rollback\n";
}finally{foreach([$path,$path.'.tmp',$path.'.lock'] as $f)if(is_file($f))unlink($f);}
