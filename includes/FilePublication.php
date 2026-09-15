<?php
namespace ZoerConnect;

/** Recoverable selected-file replacement. Never accepts paths from unauthenticated users. */
final class FilePublication {
    private string $root;
    private string $private;
    public function __construct(string $root, string $private) {
        $this->root = realpath($root) ?: throw new \RuntimeException('Missing destination.');
        $this->private = realpath($private) ?: throw new \RuntimeException('Missing private storage.');
        if ($this->private === $this->root || str_starts_with($this->private, $this->root . '/')) throw new \RuntimeException('Backup storage is public.');
    }
    private function target(string $relative): string {
        StageStore::validateManifest(['version'=>1,'target'=>'destination','files'=>[['path'=>$relative,'bytes'=>0,'sha256'=>hash('sha256','')]]], 'destination');
        $cursor=$this->root;
        foreach(explode('/',$relative) as $segment) {
            $cursor .= '/'.$segment;
            if(is_link($cursor)) throw new \RuntimeException('Symlink destination rejected.');
        }
        return $cursor;
    }
    private function write(array $state): void {
        $json=json_encode($state,JSON_THROW_ON_ERROR);
        $tmp=$this->private.'/publication.tmp';
        if(file_put_contents($tmp,$json)!==strlen($json) || !rename($tmp,$this->private.'/publication.json')) throw new \RuntimeException('Journal write failed.');
    }
    private function read(): array {
        return json_decode(file_get_contents($this->private.'/publication.json'),true,512,JSON_THROW_ON_ERROR);
    }
    private function locked(callable $fn) {
        $h=fopen($this->private.'/publication.lock','c');
        if(!$h || !flock($h,LOCK_EX)) throw new \RuntimeException('Publication is locked.');
        try { return $fn(); } finally { flock($h,LOCK_UN); fclose($h); }
    }
    public function prepare(array $manifest, array $staged): array {
        return $this->locked(function() use($manifest,$staged) {
            if(is_file($this->private.'/publication.json')) throw new \RuntimeException('Publication already exists.');
            $requested=$manifest['files'];
            $manifest=StageStore::validateManifest($manifest,$manifest['target']);
            if(count($manifest['files'])!==count($staged)) throw new \InvalidArgumentException('Staged file count mismatch.');
            $entries=[];
            foreach($manifest['files'] as $i=>$file) {
                $target=$this->target($file['path']);
                $source=realpath($staged[$i]);
                if(!$source || is_link($staged[$i]) || !str_starts_with($source,$this->private.'/') || !is_file($source)) throw new \RuntimeException('Invalid staged source.');
                if(filesize($source)!==$file['bytes'] || hash_file('sha256',$source)!==$file['sha256']) throw new \RuntimeException('Staged hash mismatch.');
                if(file_exists($target) && !is_file($target)) throw new \RuntimeException('Target is not a regular file.');
                $oldHash=is_file($target)?hash_file('sha256',$target):null;
                if(array_key_exists('expectedDestinationSha256',$requested[$i])&&$oldHash!==$requested[$i]['expectedDestinationSha256'])throw new \RuntimeException('Destination changed since preview.');
                $entries[]=['path'=>$file['path'],'source'=>$source,'sha256'=>$file['sha256'],'oldHash'=>$oldHash,'mode'=>is_file($target)?(fileperms($target)&0777):0644,'phase'=>'pending'];
            }
            $state=['status'=>'backing_up','cursor'=>0,'entries'=>$entries]; $this->write($state); return $state;
        });
    }
    /** One file per request. Backup completes for every selected file before activation. */
    public function step(): array {
        return $this->locked(function() {
            $s=$this->read(); $i=$s['cursor'];
            if(!in_array($s['status'],['backing_up','applying'],true)) return $s;
            if($i>=count($s['entries'])) {
                $s['status']=$s['status']==='backing_up'?'applying':'verification_required'; $s['cursor']=0; $this->write($s); return $s;
            }
            $e=$s['entries'][$i]; $target=$this->target($e['path']); $backup=$this->private.'/backup-'.$i;
            if($s['status']==='backing_up') {
                if($e['oldHash']!==null) {
                    if(!is_file($target) || hash_file('sha256',$target)!==$e['oldHash']) throw new \RuntimeException('Destination changed during backup.');
                    if(!copy($target,$backup) || hash_file('sha256',$backup)!==$e['oldHash']) throw new \RuntimeException('Backup verification failed.');
                } elseif(file_exists($target)) throw new \RuntimeException('Destination changed during backup.');
                $s['entries'][$i]['phase']='backed_up';
            } else {
                // Journal before mutation lets a retry distinguish intended output from an unrelated edit.
                $actual=is_file($target)?hash_file('sha256',$target):null;
                if($actual!==$e['oldHash'] && !($e['phase']==='applying' && $actual===$e['sha256'])) throw new \RuntimeException('Destination changed; refusing overwrite.');
                if(hash_file('sha256',$e['source'])!==$e['sha256']) throw new \RuntimeException('Staged source changed.');
                $s['entries'][$i]['phase']='applying'; $this->write($s);
                if(!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true)) throw new \RuntimeException('Cannot create destination folder.');
                $tmp=$target.'.zoer-tmp-'.bin2hex(random_bytes(8));
                try {
                    if(!copy($e['source'],$tmp) || hash_file('sha256',$tmp)!==$e['sha256'] || !chmod($tmp,$e['mode']??0644) || !rename($tmp,$target)) throw new \RuntimeException('Activation failed.');
                } finally { if(is_file($tmp)) unlink($tmp); }
                $s['entries'][$i]['phase']='applied';
            }
            $s['cursor']++; $this->write($s); return $s;
        });
    }
    /** Read-only check used across every file before a coordinated rollback
     * starts changing any table or file. Caller retains its writer fence. */
    public function rollbackPreflight(): void {
        $this->locked(function() {
            $s=$this->read();
            foreach($s['entries'] as $i=>$e) {
                if(!in_array($e['phase'],['applying','applied'],true))continue;
                $target=$this->target($e['path']);$actual=is_file($target)?hash_file('sha256',$target):null;
                if($actual!==$e['sha256'] && $actual!==$e['oldHash'])throw new \RuntimeException('Destination edited after publication; refusing rollback overwrite.');
                if($e['oldHash']!==null){$backup=$this->private.'/backup-'.$i;if(!is_file($backup)||hash_file('sha256',$backup)!==$e['oldHash'])throw new \RuntimeException('Backup corrupt.');}
            }
        });
    }
    public function rollbackStep(): array {
        return $this->locked(function() {
            $s=$this->read();
            if($s['status']==='rolled_back') return $s;
            if($s['status']!=='rolling_back') { $s['status']='rolling_back'; $s['cursor']=count($s['entries'])-1; $this->write($s); }
            $i=$s['cursor'];
            if($i<0) { $s['status']='rolled_back'; $this->write($s); return $s; }
            $e=$s['entries'][$i]; $target=$this->target($e['path']);
            if(in_array($e['phase'],['applying','applied'],true)) {
                $actual=is_file($target)?hash_file('sha256',$target):null;
                if($actual!==$e['sha256'] && $actual!==$e['oldHash']) throw new \RuntimeException('Destination edited after publication; refusing rollback overwrite.');
                if($e['oldHash']===null) { if(is_file($target) && !unlink($target)) throw new \RuntimeException('Cannot restore absent file.'); }
                else {
                    $backup=$this->private.'/backup-'.$i;
                    if(!is_file($backup) || hash_file('sha256',$backup)!==$e['oldHash']) throw new \RuntimeException('Backup corrupt.');
                    $tmp=$target.'.zoer-restore-'.bin2hex(random_bytes(8));
                    try { if(!copy($backup,$tmp) || hash_file('sha256',$tmp)!==$e['oldHash'] || !chmod($tmp,$e['mode']??0644) || !rename($tmp,$target)) throw new \RuntimeException('Restore failed.'); }
                    finally { if(is_file($tmp)) unlink($tmp); }
                }
            }
            $s['entries'][$i]['phase']='restored'; $s['cursor']--; $this->write($s); return $s;
        });
    }
}
