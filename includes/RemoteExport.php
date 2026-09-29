<?php
namespace ZoerConnect;

/** Private immutable artifacts, scoped to a credential generation, with resumable reads. */
final class RemoteExport {
    public const CHUNK=262144;
    public const TTL=3600;
    private string $root;
    public function __construct(string $root,array $publicRoots,private string $sourceRoot,private string $owner){
        new StageStore($root,$publicRoots);
        $this->root=realpath($root).'/exports';
        if(is_link($this->root))throw new \RuntimeException('Unsafe export storage.');
        if(!is_dir($this->root)&&!mkdir($this->root,0700))throw new \RuntimeException('Cannot create export storage.');
    }
    private function locked(callable $fn){$h=fopen($this->root.'/lock','c');if(!$h||!flock($h,LOCK_EX))throw new \RuntimeException('Export busy.');try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}}
    private function directory(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid export ID.');$dir=$this->root.'/'.$id;if(is_link($dir))throw new \RuntimeException('Unsafe export.');return $dir;}
    private function read(string $id,bool $allowExpired=false):array{$dir=$this->directory($id);$raw=@file_get_contents($dir.'/state.json');$job=$raw===false?null:json_decode($raw,true);if(!is_array($job)||!is_string($job['owner']??null)||!hash_equals($job['owner'],$this->owner))throw new \RuntimeException('Export unavailable.');if(!$allowExpired&&$job['expiresAt']<=time())throw new \RuntimeException('Export expired. Start a new pull.');return $job;}
    private function save(array $job):void{$dir=$this->directory($job['id']);$tmp=$dir.'/state.tmp';$data=json_encode($job,JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$data)!==strlen($data))throw new \RuntimeException('Cannot save export.');chmod($tmp,0600);if(!rename($tmp,$dir.'/state.json'))throw new \RuntimeException('Cannot save export.');}
    private function publicJob(array $job):array{unset($job['owner'],$job['requestHash'],$job['databasePending']);return $job;}
    /** $database(string $path, array $filters): ?array receives normalized filters and may return exported table suffixes. */
    public function create(array $input,array $source,callable $database,array $active=[]):array{return $this->locked(function()use($input,$source,$database,$active){
        if(array_diff(array_keys($input),['clientId','profile','database'])||!array_key_exists('database',$input)||!is_array($input['profile']??null))throw new \InvalidArgumentException('Profile and database selection required.');
        $selection=DatabaseExporter::selection($input['database']);$wantsDatabase=$selection!==false;
        $id=$input['clientId']??'';$dir=$this->directory($id);$profile=ExportProfile::normalize($input['profile']);
        // Binding is unchanged for boolean selections, so older clients retry against 0.3.14 artifacts.
        $requestHash=hash('sha256',json_encode([$profile,$selection],JSON_THROW_ON_ERROR));
        if(is_dir($dir)){$job=$this->read($id);if(!hash_equals($job['requestHash'],$requestHash))throw new \InvalidArgumentException('Export ID already has different selections.');if($job['databasePending']??false)$job=$this->prepareDatabase($job,$database);return $this->publicJob($job);}
        foreach(glob($this->root.'/*',GLOB_ONLYDIR)?:[] as $oldDir){
            if(is_link($oldDir)||!preg_match('/^[a-f0-9]{32}$/D',basename($oldDir)))continue;
            $old=json_decode((string)@file_get_contents($oldDir.'/state.json'),true);
            if((is_array($old)&&($old['expiresAt']??PHP_INT_MAX)<=time())||(!is_array($old)&&filemtime($oldDir)+self::TTL<=time()))$this->remove(basename($oldDir));
        }

        if(count(glob($this->root.'/*',GLOB_ONLYDIR)?:[])>=2)throw new \RuntimeException('Cancel an existing export before starting another.');
        $plan=FileExporter::plan($this->sourceRoot,$profile,$active);
        if($plan['bytes']>1073741824)throw new \InvalidArgumentException('File export exceeds 1 GiB.');
        if(!$plan['files']&&!$wantsDatabase)throw new \InvalidArgumentException('Select at least one resource.');
        if(disk_free_space($this->root)<$plan['bytes']+DatabaseExporter::MAX_BYTES+67108864)throw new \RuntimeException('Insufficient private export storage.');
        if(!mkdir($dir,0700))throw new \RuntimeException('Cannot reserve export.');
        try{
            $files=$plan['files'];
            $job=['id'=>$id,'owner'=>$this->owner,'requestHash'=>$requestHash,'status'=>'preparing','profile'=>$profile,'database'=>$selection,'databasePending'=>$wantsDatabase,'source'=>$source+['tables'=>[]],'files'=>$files,'nextIndex'=>0,'expiresAt'=>time()+self::TTL,'maxChunkBytes'=>self::CHUNK];
            // Persist ownership before expensive work so request death is recoverable by the same create.
            $this->save($job);
            if($wantsDatabase)$job=$this->prepareDatabase($job,$database);
            return $this->publicJob($job);
        }catch(\Throwable $e){$this->remove($id);throw $e;}
    });}
    private function prepareDatabase(array $job,callable $database):array{
        $dir=$this->directory($job['id']);
        foreach(['0.bin','0.bin.partial'] as $name){$path=$dir.'/'.$name;if(is_link($path))throw new \RuntimeException('Unsafe snapshot.');if(is_file($path))unlink($path);}
        $tables=$database($dir.'/0.bin',is_array($job['database'])?$job['database']:[]);clearstatcache();
        if(is_array($tables))$job['source']['tables']=array_values($tables);
        $path=$dir.'/0.bin';$bytes=is_file($path)?filesize($path):false;
        if(is_link($path)||$bytes===false||$bytes<1||$bytes>DatabaseExporter::MAX_BYTES)throw new \RuntimeException('Invalid database snapshot.');
        array_unshift($job['files'],['path'=>'database.sql','bytes'=>$bytes,'sha256'=>hash_file('sha256',$path)]);
        $job['databasePending']=false;$job['nextIndex']=1;
        $job['status']=count($job['files'])===1?'ready':'preparing';
        $this->save($job);return $job;
    }
    public function status(string $id):array{return $this->locked(fn()=>$this->publicJob($this->read($id)));}
    public function step(string $id):array{return $this->locked(function()use($id){
        $job=$this->read($id);if($job['status']==='ready')return $this->publicJob($job);
        if($job['databasePending']??false)throw new \RuntimeException('Database preparation interrupted. Retry export creation with the same selections.');
        $i=$job['nextIndex'];$file=$job['files'][$i];$root=realpath($this->sourceRoot);$path=$root.'/'.$file['path'];
        $real=realpath($path);
        if(!$real||!str_starts_with($real,$root.'/')||is_link($path))throw new \RuntimeException('Source path changed.');
        $parent=dirname($path);while($parent!==$root){if(is_link($parent))throw new \RuntimeException('Source symlink rejected.');$parent=dirname($parent);}
        $tmp=$this->directory($id).'/'.$i.'.tmp';
        if(!copy($real,$tmp))throw new \RuntimeException('Cannot snapshot file.');chmod($tmp,0600);
        if(filesize($tmp)!==$file['bytes']||!hash_equals($file['sha256'],hash_file('sha256',$tmp))){unlink($tmp);throw new \RuntimeException('Source changed during pull. Start a new export.');}
        if(!rename($tmp,$this->directory($id).'/'.$i.'.bin'))throw new \RuntimeException('Cannot finalize file snapshot.');
        $job['nextIndex']++;
        if($job['nextIndex']===count($job['files']))$job['status']='ready';
        $this->save($job);return $this->publicJob($job);
    });}
    public function chunk(string $id,int $index,int $offset):array{return $this->locked(function()use($id,$index,$offset){
        $job=$this->read($id);
        if($job['status']!=='ready'||$index<0||!isset($job['files'][$index])||$offset<0||$offset>$job['files'][$index]['bytes'])throw new \InvalidArgumentException('Invalid export range or export not ready.');
        $path=$this->directory($id).'/'.$index.'.bin';clearstatcache(true,$path);
        if(is_link($path)||!is_file($path)||filesize($path)!==$job['files'][$index]['bytes']||!hash_equals($job['files'][$index]['sha256'],hash_file('sha256',$path)))throw new \RuntimeException('Snapshot integrity check failed. Start a new export.');
        $h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Snapshot missing.');
        try{if(fseek($h,$offset)!==0)throw new \RuntimeException('Cannot seek snapshot.');$data=fread($h,self::CHUNK);if($data===false)throw new \RuntimeException('Cannot read snapshot.');}finally{fclose($h);}
        return ['index'=>$index,'offset'=>$offset,'data'=>base64_encode($data),'bytes'=>strlen($data),'sha256'=>hash('sha256',$data),'eof'=>$offset+strlen($data)===$job['files'][$index]['bytes']];
    });}
    private function remove(string $id):void{$dir=$this->directory($id);foreach(glob($dir.'/*')?:[] as $file){if(is_dir($file)||is_link($file))throw new \RuntimeException('Unsafe export cleanup.');if(!unlink($file))throw new \RuntimeException('Cannot clean export.');}if(is_dir($dir)&&!rmdir($dir))throw new \RuntimeException('Cannot clean export.');}
    public function cancel(string $id):array{return $this->locked(function()use($id){if(is_dir($this->directory($id))){$this->read($id,true);$this->remove($id);}return ['id'=>$id,'status'=>'cancelled'];});}
}
