<?php
namespace ZoerConnect;

/** One authenticated artifact, bounded I/O and durable cursors across requests.
 * The caller holds the destination writer fence through activation/recovery. */
final class ChunkedFilePublication {
    public const LIMIT=2147483648;
    private string $root;
    private string $private;
    public function __construct(string $root,string $private){
        $this->root=realpath($root)?:throw new \RuntimeException('Missing destination.');
        $this->private=realpath($private)?:throw new \RuntimeException('Missing private storage.');
        if($this->private===$this->root||str_starts_with($this->private,$this->root.'/'))throw new \RuntimeException('Public backup storage.');
    }
    private function target(string $path):string{
        StageStore::validateManifest(['version'=>1,'target'=>'destination','files'=>[['path'=>$path,'bytes'=>0,'sha256'=>hash('sha256','')]]],'destination');
        $p=$this->root;foreach(explode('/',$path) as $part){$p.='/'.$part;if(is_link($p))throw new \RuntimeException('Symlink destination rejected.');}return $p;
    }
    private function read():array{return json_decode(file_get_contents($this->private.'/publication.json'),true,512,JSON_THROW_ON_ERROR);}
    private function save(array $s):void{$j=json_encode($s,JSON_THROW_ON_ERROR);if(file_put_contents($this->private.'/publication.tmp',$j)!==strlen($j)||!rename($this->private.'/publication.tmp',$this->private.'/publication.json'))throw new \RuntimeException('Journal write failed.');}
    private function locked(callable $fn){$h=fopen($this->private.'/publication.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB))throw new \RuntimeException('Publication busy.');try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}}
    private function size(string $p):?int{clearstatcache(true,$p);if(is_link($p))throw new \RuntimeException('Symlink file rejected.');if(!file_exists($p))return null;if(!is_file($p))throw new \RuntimeException('Expected regular file.');return filesize($p);}
    private function block(string $p,int $offset,int $bytes):string{
        if($this->size($p)!==$bytes)throw new \RuntimeException('File size changed.');$h=fopen($p,'rb');if(!$h)throw new \RuntimeException('Cannot read file.');
        try{if(fseek($h,$offset)!==0)throw new \RuntimeException('Cannot seek file.');$n=min(StageStore::CHUNK,$bytes-$offset);$v=$n?fread($h,$n):'';if(strlen($v)!==$n)throw new \RuntimeException('Interrupted file read.');return $v;}finally{fclose($h);}
    }
    public static function validate(array $a):void{
        if(!is_int($a['bytes']??null)||$a['bytes']<0||$a['bytes']>self::LIMIT||!is_array($a['chunkSha256']??null)||!array_is_list($a['chunkSha256'])||count($a['chunkSha256'])!==(int)ceil($a['bytes']/StageStore::CHUNK))throw new \InvalidArgumentException('Invalid file block manifest.');
        foreach($a['chunkSha256'] as $hash)if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new \InvalidArgumentException('Invalid block hash.');
    }
    public function prepare(array $manifest,array $sources):array{return $this->locked(function()use($manifest,$sources){
        if(is_file($this->private.'/publication.json'))throw new \RuntimeException('Publication exists.');StageStore::validateManifest($manifest,$manifest['target']);
        if(count($manifest['files'])!==1||count($sources)!==1)throw new \InvalidArgumentException('One artifact required.');$a=$manifest['files'][0];self::validate($a);
        $src=realpath($sources[0]);if(!$src||is_link($sources[0])||!str_starts_with($src,$this->private.'/')||$this->size($src)!==$a['bytes'])throw new \RuntimeException('Invalid staged file.');
        $target=$this->target($a['path']);$old=$this->size($target);if($old!==null&&$old>self::LIMIT)throw new \RuntimeException('Destination original exceeds 2 GiB.');
        if(disk_free_space($this->private)<($old??0)+67108864)throw new \RuntimeException('Insufficient backup space.');
        $s=['version'=>2,'status'=>'backing_up','phase'=>'source_check','offset'=>0,'file'=>$a,'source'=>$src,'oldBytes'=>$old,'oldBlocks'=>[],'mode'=>$old===null?0644:(fileperms($target)&0777),'token'=>bin2hex(random_bytes(12)),'activated'=>false];$this->save($s);return $s;
    });}
    private function check(string $path,int $bytes,array $hashes,int &$offset):bool{
        $data=$this->block($path,$offset,$bytes);if($offset<$bytes&&!hash_equals($hashes[intdiv($offset,StageStore::CHUNK)]??'',hash('sha256',$data)))throw new \RuntimeException('File block digest mismatch.');$offset+=strlen($data);return $offset===$bytes;
    }
    private function copyBlock(string $from,string $to,int $bytes,array $hashes,int &$offset):bool{
        $data=$this->block($from,$offset,$bytes);if($offset<$bytes&&!hash_equals($hashes[intdiv($offset,StageStore::CHUNK)]??'',hash('sha256',$data)))throw new \RuntimeException('Copy source changed.');
        if(is_link($to))throw new \RuntimeException('Symlink temporary file.');$h=fopen($to,'c+b');if(!$h)throw new \RuntimeException('Cannot open copy.');
        try{if(!ftruncate($h,$offset)||fseek($h,$offset)!==0||fwrite($h,$data)!==strlen($data)||!fflush($h))throw new \RuntimeException('Copy failed.');}finally{fclose($h);}
        $offset+=strlen($data);return $offset===$bytes;
    }
    private function next(array &$s,string $phase):void{$s['phase']=$phase;$s['offset']=0;}
    public function step():array{return $this->locked(function(){
        $s=$this->read();if(!in_array($s['status'],['backing_up','applying'],true))return $s;
        $a=$s['file'];$target=$this->target($a['path']);$backup=$this->private.'/backup';$tmp=$target.'.zoer-tmp-'.$s['token'];
        switch($s['phase']){
        case 'source_check':if($this->check($s['source'],$a['bytes'],$a['chunkSha256'],$s['offset']))$this->next($s,'old_scan');break;
        case 'old_scan':
            if($s['oldBytes']===null){if($this->size($target)!==null)throw new \RuntimeException('Destination appeared.');$this->next($s,'copy_new');$s['status']='applying';break;}
            $data=$this->block($target,$s['offset'],$s['oldBytes']);if($s['offset']<$s['oldBytes'])$s['oldBlocks'][]=hash('sha256',$data);$s['offset']+=strlen($data);if($s['offset']===$s['oldBytes'])$this->next($s,'backup_copy');break;
        case 'backup_copy':if($this->copyBlock($target,$backup,$s['oldBytes'],$s['oldBlocks'],$s['offset']))$this->next($s,'backup_check');break;
        case 'backup_check':if($this->check($backup,$s['oldBytes'],$s['oldBlocks'],$s['offset'])){$this->next($s,'copy_new');$s['status']='applying';}break;
        case 'copy_new':
            if(!is_dir(dirname($target))&&!mkdir(dirname($target),0755,true))throw new \RuntimeException('Cannot create folder.');
            if($s['offset']===0&&disk_free_space(dirname($target))<$a['bytes']+67108864)throw new \RuntimeException('Insufficient destination space.');
            if($this->copyBlock($s['source'],$tmp,$a['bytes'],$a['chunkSha256'],$s['offset']))$this->next($s,'new_check');break;
        case 'new_check':if($this->check($tmp,$a['bytes'],$a['chunkSha256'],$s['offset']))$this->next($s,'old_check');break;
        case 'old_check':
            if($s['oldBytes']===null){if($this->size($target)!==null)throw new \RuntimeException('Destination appeared.');$this->next($s,'rename');}
            elseif($this->check($target,$s['oldBytes'],$s['oldBlocks'],$s['offset']))$this->next($s,'rename');break;
        case 'rename':
            // Durable rename intent exists before the only public mutation. A
            // missing temporary file after a lost response requires verification.
            if($this->size($tmp)!==null){if(!chmod($tmp,$s['mode'])||!rename($tmp,$target))throw new \RuntimeException('Activation failed.');}
            $s['activated']=true;$this->next($s,'applied_check');break;
        case 'applied_check':if($this->check($target,$a['bytes'],$a['chunkSha256'],$s['offset'])){$s['status']='verification_required';$s['phase']='applied';}break;
        default:throw new \RuntimeException('Unknown publication phase.');
        }$this->save($s);return $s;
    });}
    /** Fresh per-attempt cursors prevent reuse of preflight from an earlier fence. */
    public function resetRollbackPreflight():void{$this->locked(function(){$s=$this->read();unset($s['preflight']);$this->save($s);});}
    public function rollbackPreflightStep():bool{return $this->locked(function(){
        $s=$this->read();$result=$this->preflight($s);$this->save($s);return $result;
    });}
    private function preflight(array &$s):bool{
        if(!$s['activated']&&!in_array($s['phase'],['rename','applied_check','applied','restore_copy','restore_check','restore_rename','restored_check'],true))return true;
        $p=$s['preflight']??['phase'=>'target','offset'=>0,'new'=>true,'old'=>true];$target=$this->target($s['file']['path']);
        if($p['phase']==='done')return true;
        if($p['phase']==='target'){
            $size=$this->size($target);$p['new']=$p['new']&&$size===$s['file']['bytes'];$p['old']=$p['old']&&$size===$s['oldBytes'];
            if($size!==null&&($p['new']||$p['old'])){$data=$this->block($target,$p['offset'],$size);$hash=hash('sha256',$data);if($p['offset']<$size){$i=intdiv($p['offset'],StageStore::CHUNK);$p['new']=$p['new']&&hash_equals($s['file']['chunkSha256'][$i]??'',$hash);$p['old']=$p['old']&&hash_equals($s['oldBlocks'][$i]??'',$hash);}$p['offset']+=strlen($data);}
            if(!$p['new']&&!$p['old']){unset($s['preflight']);$this->save($s);throw new \RuntimeException('Destination edited; refusing rollback.');}
            if($size===null||$p['offset']===$size){$p['phase']='backup';$p['offset']=0;}
        }else{
            if($s['oldBytes']===null||$this->check($this->private.'/backup',$s['oldBytes'],$s['oldBlocks'],$p['offset']))$p['phase']='done';
        }
        $s['preflight']=$p;return $p['phase']==='done';
    }
    public function rollbackStep():array{return $this->locked(function(){
        $s=$this->read();if($s['status']==='rolled_back')return $s;$target=$this->target($s['file']['path']);$tmp=$target.'.zoer-tmp-'.$s['token'];$restore=$target.'.zoer-restore-'.$s['token'];
        if($s['status']!=='rolling_back'){
            if(!$this->preflight($s)){$this->save($s);return $s;}
            if(!$s['activated']&&!in_array($s['phase'],['rename','applied_check','applied'],true)){$s['status']='rolled_back';if(is_file($tmp))unlink($tmp);$this->save($s);return $s;}
            $s['status']='rolling_back';$this->next($s,'restore_copy');$this->save($s);
        }
        switch($s['phase']){
        case 'restore_copy':
            if($s['oldBytes']===null){if(is_file($target)&&!unlink($target))throw new \RuntimeException('Cannot restore absence.');$s['status']='rolled_back';break;}
            if($s['offset']===0&&disk_free_space(dirname($target))<$s['oldBytes']+67108864)throw new \RuntimeException('Insufficient restore space.');
            if($this->copyBlock($this->private.'/backup',$restore,$s['oldBytes'],$s['oldBlocks'],$s['offset']))$this->next($s,'restore_check');break;
        case 'restore_check':if($this->check($restore,$s['oldBytes'],$s['oldBlocks'],$s['offset']))$this->next($s,'restore_rename');break;
        case 'restore_rename':if($this->size($restore)!==null&&(!chmod($restore,$s['mode'])||!rename($restore,$target)))throw new \RuntimeException('Restore failed.');$this->next($s,'restored_check');break;
        case 'restored_check':if($this->check($target,$s['oldBytes'],$s['oldBlocks'],$s['offset']))$s['status']='rolled_back';break;
        default:throw new \RuntimeException('Unknown restore phase.');
        }
        if($s['status']==='rolled_back'&&is_file($tmp))unlink($tmp);$this->save($s);return $s;
    });}
}
