<?php
namespace ZoerConnect;

/** Bounded traversal and paginated manifests for sites with many small files. */
final class PagedExport {
    private string $root;
    public function __construct(string $root,array $publicRoots,private string $sourceRoot,private string $owner){
        new StageStore($root,$publicRoots);
        $this->root=realpath($root).'/paged-exports';
        if(is_link($this->root)||(!is_dir($this->root)&&!mkdir($this->root,0700)))throw new \RuntimeException('Private export storage unavailable.');
    }
    private function dir(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid export ID.');$d=$this->root.'/'.$id;if(is_link($d))throw new \RuntimeException('Unsafe export.');return $d;}
    private function locked(callable $fn){$h=fopen($this->root.'/lock','c');if(!$h||!flock($h,LOCK_EX))throw new \RuntimeException('Export busy.');try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}}
    private function read(string $id,bool $expired=false):array{$raw=@file_get_contents($this->dir($id).'/state.json');$j=$raw===false?null:json_decode($raw,true);if(!is_array($j)||!hash_equals($this->owner,$j['owner']??''))throw new \RuntimeException('Export unavailable.');if(!$expired&&$j['expiresAt']<=time())throw new \RuntimeException('Export expired. Start a new pull.');return $j;}
    private function save(array $j):void{$d=$this->dir($j['id']);$bytes=json_encode($j,JSON_THROW_ON_ERROR);if(file_put_contents($d.'/state.tmp',$bytes)!==strlen($bytes)||!rename($d.'/state.tmp',$d.'/state.json'))throw new \RuntimeException('Cannot save export.');chmod($d.'/state.json',0600);}
    private function touch(array &$j):void{$j['expiresAt']=min($j['createdAt']+86400,time()+3600);}
    private function view(array $j):array{return ['id'=>$j['id'],'status'=>$j['status'],'phase'=>$j['phase'],'fileCount'=>count($j['files']),'bytes'=>$j['bytes'],'expiresAt'=>$j['expiresAt'],'source'=>$j['source'],'maxChunkBytes'=>262144,'paged'=>true];}
    public function create(array $input,array $source,array $active=[]):array{return $this->locked(function()use($input,$source,$active){
        if(array_diff(array_keys($input),['clientId','profile','database'])||!array_key_exists('database',$input)||!is_array($input['profile']??null))throw new \InvalidArgumentException('Export selection required.');
        $selection=DatabaseExporter::selection($input['database']);$wantsDatabase=$selection!==false;
        $id=$input['clientId']??'';$d=$this->dir($id);$profile=ExportProfile::normalize($input['profile']);$hash=hash('sha256',json_encode([$profile,$selection],JSON_THROW_ON_ERROR));
        if(is_dir($d)){$j=$this->read($id);if(!hash_equals($hash,$j['requestHash']))throw new \InvalidArgumentException('Export selections changed.');return $this->view($j);}
        foreach(glob($this->root.'/*',GLOB_ONLYDIR)?:[] as $old){if(is_link($old)||!preg_match('/^[a-f0-9]{32}$/D',basename($old)))continue;$j=json_decode((string)@file_get_contents($old.'/state.json'),true);if(($j['expiresAt']??filemtime($old)+3600)<=time())$this->remove(basename($old));}
        if(count(glob($this->root.'/*',GLOB_ONLYDIR)?:[])>=2)throw new \RuntimeException('Cancel an existing export before starting another.');
        if($profile['core'])throw new \InvalidArgumentException('Paged exports currently support content resources only.');
        // Theme/plugin modes resolve to item roots once, so later steps traverse a fixed selection.
        $queue=FileExporter::roots($this->sourceRoot,$profile,$active);
        if(!$queue&&!$wantsDatabase)throw new \InvalidArgumentException('Select at least one resource.');
        if(!mkdir($d,0700))throw new \RuntimeException('Cannot reserve export.');
        $j=['id'=>$id,'owner'=>$this->owner,'requestHash'=>$hash,'source'=>$source+['tables'=>[]],'profile'=>$profile,'database'=>$selection,'status'=>'preparing','phase'=>$wantsDatabase?'database':'files','queue'=>$queue,'files'=>[],'bytes'=>0,'createdAt'=>time(),'expiresAt'=>time()+3600];$this->save($j);return $this->view($j);
    });}
    public function step(string $id,callable $database):array{return $this->locked(function()use($id,$database){
        $j=$this->read($id);if($j['status']==='ready')return $this->view($j);$d=$this->dir($id);
        if($j['phase']==='database'){
            foreach(['0.bin','0.bin.partial'] as $name){$p=$d.'/'.$name;if(is_link($p))throw new \RuntimeException('Unsafe snapshot.');if(is_file($p))unlink($p);}
            $tables=$database($d.'/0.bin',is_array($j['database'])?$j['database']:[]);clearstatcache();$size=filesize($d.'/0.bin');if(is_array($tables))$j['source']['tables']=array_values($tables);if($size<1||$size>DatabaseExporter::MAX_BYTES)throw new \RuntimeException('Invalid database snapshot.');
            $j['files'][]=['path'=>'database.sql','bytes'=>$size,'sha256'=>hash_file('sha256',$d.'/0.bin')];$j['bytes']=$size;$j['phase']='files';$this->touch($j);$this->save($j);return $this->view($j);
        }
        $start=microtime(true);$root=realpath($this->sourceRoot);$count=0;$since=ExportProfile::mediaSince($j['profile']);
        while($j['queue']&&$count++<250&&microtime(true)-$start<4){
            $relative=array_pop($j['queue']);Selection::path($relative);
            if($relative==='wp-content/plugins/zoer-connect'||str_starts_with($relative,'wp-content/plugins/zoer-connect/'))continue;
            if(Selection::excluded($relative,['**/.git/','**/node_modules/','**/.env','**/.env.*','**/*.log',...$j['profile']['excludes']]))continue;
            $path=$root.'/'.$relative;if(!file_exists($path)&&!is_link($path)){if(in_array($relative,['wp-content/themes','wp-content/plugins','wp-content/uploads','wp-content/mu-plugins'],true))continue;throw new \RuntimeException('Source changed during pull.');}
            $real=realpath($path);if(!$real||!str_starts_with($real,$root.'/')||is_link($path))throw new \RuntimeException('Unsafe source file.');
            for($parent=dirname($path);$parent!==$root;$parent=dirname($parent))if(is_link($parent))throw new \RuntimeException('Symlink source rejected.');
            if(is_dir($path)){$children=scandir($path);if($children===false||count($children)+count($j['queue'])>120000)throw new \RuntimeException('Too many selected files.');foreach(array_reverse($children) as $name)if($name!=='.'&&$name!=='..')$j['queue'][]=$relative.'/'.$name;continue;}
            if(!is_file($path))throw new \RuntimeException('Unsafe source file.');
            if($since!==null&&str_starts_with($relative,'wp-content/uploads/')&&filemtime($path)<$since)continue;
            $size=filesize($path);if($size>33554432)throw new \RuntimeException('A selected file exceeds the current 32 MiB export limit.');
            if(count($j['files'])>=100000||$j['bytes']+$size>4*1073741824)throw new \RuntimeException('Paged export exceeds its file or byte limit.');
            if(disk_free_space($d)<$size+67108864)throw new \RuntimeException('Insufficient private export storage.');
            $index=count($j['files']);$tmp=$d.'/'.$index.'.tmp';if(is_link($tmp)||is_link($d.'/'.$index.'.bin'))throw new \RuntimeException('Unsafe snapshot.');
            if(!copy($path,$tmp))throw new \RuntimeException('Cannot snapshot file.');chmod($tmp,0600);$hash=hash_file('sha256',$tmp);clearstatcache(true,$path);
            if(filesize($tmp)!==$size||filesize($path)!==$size||$hash!==hash_file('sha256',$path))throw new \RuntimeException('Source changed during pull.');
            if(!rename($tmp,$d.'/'.$index.'.bin'))throw new \RuntimeException('Cannot finalize snapshot.');
            $j['files'][]=['path'=>$relative,'bytes'=>$size,'sha256'=>$hash];$j['bytes']+=$size;
        }
        if(!$j['queue']){$j['status']='ready';$j['phase']='ready';}$this->touch($j);$this->save($j);return $this->view($j);
    });}
    public function manifest(string $id,int $offset):array{return $this->locked(function()use($id,$offset){$j=$this->read($id);if($j['status']!=='ready'||$offset<0||$offset>count($j['files']))throw new \InvalidArgumentException('Invalid manifest page.');$this->touch($j);$this->save($j);return ['id'=>$id,'offset'=>$offset,'total'=>count($j['files']),'files'=>array_slice($j['files'],$offset,500)];});}
    public function batch(string $id,int $index,int $offset):array{return $this->locked(function()use($id,$index,$offset){
        $j=$this->read($id);if($j['status']!=='ready'||$index<0||!isset($j['files'][$index])||$offset<0)throw new \InvalidArgumentException('Invalid export range.');$chunks=[];$total=0;
        while(isset($j['files'][$index])&&count($chunks)<32&&$total<1048576){$f=$j['files'][$index];if($offset>$f['bytes'])throw new \InvalidArgumentException('Invalid export offset.');$p=$this->dir($id).'/'.$index.'.bin';if(is_link($p)||!is_file($p)||filesize($p)!==$f['bytes'])throw new \RuntimeException('Snapshot unavailable.');
            $h=fopen($p,'rb');try{if(!$h||fseek($h,$offset)!==0)throw new \RuntimeException('Cannot read snapshot.');$data=fread($h,min(262144,1048576-$total));if($data===false)throw new \RuntimeException('Cannot read snapshot.');}finally{if(is_resource($h))fclose($h);}
            $n=strlen($data);$chunks[]=['index'=>$index,'offset'=>$offset,'bytes'=>$n,'data'=>base64_encode($data),'sha256'=>hash('sha256',$data)];$total+=$n;$offset+=$n;if($offset===$f['bytes']){$index++;$offset=0;}elseif(!$n)throw new \RuntimeException('Snapshot truncated.');
        }$this->touch($j);$this->save($j);return ['id'=>$id,'chunks'=>$chunks];
    });}
    private function remove(string $id):void{$d=$this->dir($id);foreach(glob($d.'/*')?:[] as $p){if(is_dir($p)||is_link($p))throw new \RuntimeException('Unsafe cleanup.');if(!unlink($p))throw new \RuntimeException('Cannot clean export.');}if(is_dir($d)&&!rmdir($d))throw new \RuntimeException('Cannot clean export.');}
    public function cancel(string $id):array{return $this->locked(function()use($id){if(is_dir($this->dir($id))){$this->read($id,true);$this->remove($id);}return ['id'=>$id,'status'=>'cancelled'];});}
}
