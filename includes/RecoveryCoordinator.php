<?php
namespace ZoerConnect;

/** Internal orchestration, one operation per tick. Requires externally quiesced writes.
 * Application maintenance and a public authenticated recovery channel are not supplied here.
 */
final class RecoveryCoordinator {
    private string $journal;
    private $files;
    private array $tables;
    public function __construct(string $journal, $files, array $tables) {
        $this->journal=$journal; $this->files=$files; $this->tables=$tables;
    }
    private function save(array $s): void {
        $json=json_encode($s,JSON_THROW_ON_ERROR);$tmp=$this->journal.'.tmp';
        if(file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$this->journal))throw new \RuntimeException('Cannot persist recovery state.');
    }
    private function lock(callable $fn) {
        $h=fopen($this->journal.'.lock','c');
        if(!$h||!flock($h,LOCK_EX))throw new \RuntimeException('Recovery is busy.');
        try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}
    }
    public function start(): void {
        $this->lock(function(){if(file_exists($this->journal))throw new \RuntimeException('Recovery journal exists.');$this->save(['phase'=>'files','table'=>0,'attempted'=>-1]);});
    }
    public function tick(): array {
        return $this->lock(function(){
            $s=json_decode(file_get_contents($this->journal),true,512,JSON_THROW_ON_ERROR);
            try {
                if($s['phase']==='files') {
                    $f=$this->files->step();
                    if($f['status']==='verification_required')$s['phase']='tables';
                } elseif($s['phase']==='tables') {
                    if($s['table']>=count($this->tables))$s['phase']='verification_required';
                    else {
                        $s['attempted']=$s['table'];$this->save($s);
                        $this->tables[$s['table']]->activate();$s['table']++;
                    }
                } elseif($s['phase']==='rollback_tables') {
                    if($s['attempted']<0)$s['phase']='rollback_files';
                    else {$this->tables[$s['attempted']]->rollback();$s['attempted']--;}
                } elseif($s['phase']==='rollback_files') {
                    $f=$this->files->rollbackStep();if($f['status']==='rolled_back')$s['phase']='rolled_back';
                }
            } catch(\Throwable $e) {
                // Retain rollback progress on a recovery failure; do not discard backups.
                if(!str_starts_with($s['phase'],'rollback'))$s['phase']='rollback_tables';
                $s['needsAttention']=true;
                $this->save($s);
                throw new \RuntimeException('Operation failed; recovery journal retained.',0,$e);
            }
            unset($s['needsAttention']);$this->save($s);return $s;
        });
    }
    public function requestRollback(): void {
        $this->lock(function(){
            $s=json_decode(file_get_contents($this->journal),true,512,JSON_THROW_ON_ERROR);
            if($s['phase']==='rolled_back')return;
            if(!str_starts_with($s['phase'],'rollback'))$s['phase']='rollback_tables';
            $this->save($s);
        });
    }
}
