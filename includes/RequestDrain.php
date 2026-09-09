<?php
namespace ZoerConnect;

/** One-time adoption of the MU fence, without restarting or signalling workers.
 * A single-threaded PHP worker that has entered this generation's lease cannot
 * still be executing an earlier unprotected request. Unenrolled PHP processes
 * must exit or enter the lease. No elapsed-time assumption is used.
 */
final class RequestDrain {
    private const SOURCE_HASH='32377c03f6f791b70686dc69da0eb6bea2b5f65ad4a256f0e98b17ae77c4e869';
    public static function runtimeVerified(): bool {
        $source=@file_get_contents(__FILE__);
        if($source===false)return false;
        $normalized=preg_replace("/private const SOURCE_HASH='[a-f0-9]{64}';/", "private const SOURCE_HASH='".str_repeat('0',64)."';",$source,1);
        return is_string($normalized)&&hash_equals(self::SOURCE_HASH,hash('sha256',$normalized));
    }

    private string $private;
    public function __construct(string $private) {$this->private=rtrim($private,'/');}
    private static function read(string $path): string {
        $value=@file_get_contents($path);
        if($value===false)throw new \RuntimeException('Automatic request verification is unavailable: PHP cannot inspect local worker information. Imports remain disabled.');
        return $value;
    }
    /** Kept separate for deterministic parser tests; start time defeats PID reuse. */
    public static function process(string $status,string $stat,string $command,string $executable=''): array {
        if(!preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m',$status,$u)||!preg_match('/^Name:\s+(.+)$/m',$status,$n)||!preg_match('/^Threads:\s+(\d+)/m',$status,$threads)||!preg_match('/^State:\s+(\S)/m',$status,$state))throw new \RuntimeException('Incomplete worker information; imports remain disabled.');
        // comm (field 2) may itself contain spaces and parentheses.
        $end=strrpos($stat,')');$fields=$end===false?[]:preg_split('/\s+/',trim(substr($stat,$end+1)));
        $start=$fields[19]??null;
        if(!is_string($start)||!ctype_digit($start))throw new \RuntimeException('Cannot identify worker lifetime.');
        $name=trim($n[1]);$php=(bool)preg_match('/^(?:php(?:[0-9.]+)?|php-fpm(?:[0-9.]+)?|php-cgi(?:[0-9.]+)?|lsphp(?:[0-9.]+)?)$/D',$name);
        $binary=preg_replace('/ \(deleted\)$/','',basename($executable));
        $php=$php||(bool)preg_match('/^(?:php(?:[0-9.]+)?|php-fpm(?:[0-9.]+)?|php-cgi(?:[0-9.]+)?|lsphp(?:[0-9.]+)?)$/D',$binary);
        $master=str_starts_with(str_replace("\0",' ',$command),'php-fpm: master process ');
        $kind=preg_match('/(?:php-fpm|php-cgi|lsphp)/',$name.' '.$binary)?'request':'cli';
        return ['kind'=>$kind,'uid'=>$u[2],'name'=>$name,'start'=>$start,'php'=>$php,'master'=>$master,'threads'=>(int)$threads[1],'dead'=>in_array($state[1],['Z','X'],true)];
    }
    /** Only Linux, single-threaded PHP runtimes are supported by this proof. */
    public static function workers(): array {
        if(PHP_OS_FAMILY!=='Linux'||!in_array(PHP_SAPI,['cli','fpm-fcgi','cgi-fcgi','litespeed'],true))throw new \RuntimeException('Automatic request verification requires a supported single-threaded Linux PHP runtime. Imports remain disabled.');
        $boot=trim(self::read('/proc/sys/kernel/random/boot_id'));
        if(!preg_match('/^[a-f0-9-]{36}$/D',$boot))throw new \RuntimeException('Cannot verify server identity.');
        $self=self::process(self::read('/proc/self/status'),self::read('/proc/self/stat'),self::read('/proc/self/cmdline'),(string)@readlink('/proc/self/exe'));
        if(!$self['php']||$self['threads']!==1)throw new \RuntimeException('Cannot verify this PHP worker safely.');
        $dirs=glob('/proc/[0-9]*',GLOB_ONLYDIR);
        if(!$dirs)throw new \RuntimeException('Local worker inventory is unavailable.');
        $workers=[];$kinds=[];
        foreach($dirs as $dir){
            $status=@file_get_contents($dir.'/status');
            if($status===false){clearstatcache(true,$dir);if(!is_dir($dir))continue;throw new \RuntimeException('Worker inventory is incomplete; imports remain disabled.');}
            if(!preg_match('/^Uid:\s+\d+\s+(\d+)/m',$status,$uid))throw new \RuntimeException('Cannot verify worker ownership.');
            if($uid[1]!==$self['uid'])continue;
            $stat=@file_get_contents($dir.'/stat');$cmd=@file_get_contents($dir.'/cmdline');
            if($stat===false||$cmd===false){clearstatcache(true,$dir);if(!is_dir($dir))continue;throw new \RuntimeException('Cannot inspect a local worker; imports remain disabled.');}
            $exe=@readlink($dir.'/exe');
            $p=self::process($status,$stat,$cmd,$exe===false?'':$exe);
            // FPM/LSAPI worker names remain visible even when Linux denies exe
            // symlink inspection (nondumpable workers). Custom/embedded PHP
            // runtimes and renamed CLI writers are outside this supported model.
            if(!$p['php']||$p['master']||$p['dead'])continue;
            if($p['threads']!==1)throw new \RuntimeException('A multithreaded PHP process prevents automatic verification.');
            $workers[basename($dir)]=$p['start'];$kinds[basename($dir)]=$p['kind'];
            if(count($workers)>128)throw new \RuntimeException('Too many account workers for bounded automatic verification.');
        }
        $pid=(string)getmypid();
        if(($workers[$pid]??null)!==$self['start'])throw new \RuntimeException('Local worker inventory did not include this request.');
        return ['boot'=>$boot,'kinds'=>$kinds,'workers'=>$workers,'pid'=>$pid,'start'=>$self['start']];
    }
    private function pending(): ?array {
        $path=$this->private.'/import-setup-pending.json';
        if(!is_file($path))return null;
        if(is_link($path)||filesize($path)>8192)throw new \RuntimeException('Invalid request verification journal.');
        $s=json_decode(self::read($path),true,32,JSON_THROW_ON_ERROR);
        if(($s['version']??null)!==2)return null;
        if(!preg_match('/^[a-f0-9]{32}$/D',$s['setupId']??''))throw new \RuntimeException('Invalid request verification generation.');
        return $s;
    }
    /** Called only after WriteFence::enter has retained its shared process lease.
     * Failure to record proof must not break normal WordPress; it only prevents
     * setup from declaring readiness. This also tolerates old v0.3 MU setups.
     */
    public function enrolled(): void {
        try {
            $s=$this->pending();if(!$s||!self::runtimeVerified()||!WriteFence::runtimeVerified())return;
            $ready=$this->private.'/import-readiness.json';
            if(!is_link($ready)&&is_file($ready)&&filesize($ready)<=8192){$r=json_decode(self::read($ready),true);if(($r['setupId']??null)===$s['setupId']&&($r['workersVerified']??false)===true)return;}
            $self=self::process(self::read('/proc/self/status'),self::read('/proc/self/stat'),self::read('/proc/self/cmdline'),(string)@readlink('/proc/self/exe'));
            if(!$self['php']||$self['threads']!==1)return;
            $boot=trim(self::read('/proc/sys/kernel/random/boot_id'));
            $dir=$this->private.'/drain-'.$s['setupId'];
            if(is_link($dir))return;
            if(!is_dir($dir)&&!@mkdir($dir,0700)&&!is_dir($dir))return;
            $path=$dir.'/'.getmypid().'.json';
            $data=json_encode(['boot'=>$boot,'start'=>$self['start'],'setupId'=>$s['setupId'],'fenceSha256'=>hash_file('sha256',__DIR__.'/WriteFence.php'),'drainSha256'=>hash_file('sha256',__FILE__)],JSON_THROW_ON_ERROR);
            if(is_link($path))return;
            if(is_file($path)&&@file_get_contents($path)===$data)return;
            $tmp=$dir.'/.'.bin2hex(random_bytes(8)).'.tmp';$h=@fopen($tmp,'x');if(!$h)return;
            try {if(!chmod($tmp,0600)||fwrite($h,$data)!==strlen($data)||!fflush($h)||(function_exists('fsync')&&!fsync($h)))return;fclose($h);$h=null;rename($tmp,$path);}finally{if(is_resource($h))fclose($h);if(is_file($tmp))unlink($tmp);}
        }catch(\Throwable $e){/* No proof means setup remains closed, not a public outage. */}
    }
    /** Every live PHP process owned by this account must be accounted for, even
     * an idle worker for another site or a long-lived CLI job. Never kill them.
     */
    public function check(array $pending,?array $inventory=null): array {
        $inventory??=self::workers();$remaining=0;
        if(is_link($this->private.'/drain-'.$pending['setupId']))throw new \RuntimeException('Unsafe worker enrollment directory.');
        $baseline=$pending['workerBaseline']??$inventory;
        if(($baseline['boot']??null)!==$inventory['boot'])throw new \RuntimeException('Server identity changed. Repeat request protection setup.');
        foreach($inventory['workers'] as $pid=>$start){
            // Fresh request workers created after installation cannot retain an
            // earlier request. CLI/background processes always need a lease.
            // Forking unprotected background writers are explicitly unsupported.
            if(($inventory['kinds'][$pid]??'cli')==='request'&&($baseline['workers'][$pid]??null)!==$start)continue;
            if(!ctype_digit((string)$pid)||!ctype_digit((string)$start))throw new \RuntimeException('Invalid worker identity.');
            $path=$this->private.'/drain-'.$pending['setupId'].'/'.$pid.'.json';
            $ticket=!is_link($path)&&is_file($path)&&filesize($path)<=2048?json_decode(self::read($path),true):null;
            foreach(['boot'=>$inventory['boot'],'start'=>$start,'setupId'=>$pending['setupId'],'fenceSha256'=>$pending['fenceSha256'],'drainSha256'=>$pending['drainSha256']] as $key=>$expected){
                if(!is_array($ticket)||($ticket[$key]??null)!==$expected){$remaining++;break;}
            }
        }
        if(count($inventory['workers'])===0)throw new \RuntimeException('Empty worker inventory cannot establish readiness.');
        return ['remaining'=>$remaining,'checked'=>count($inventory['workers']),'boot'=>$inventory['boot']];
    }
}
