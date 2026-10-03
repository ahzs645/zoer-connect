<?php
namespace ZoerConnect;
require_once __DIR__.'/ExportBlocks.php';
require_once __DIR__.'/PagedDatabase.php';
require_once __DIR__.'/WriteFence.php';
require_once __DIR__.'/ImportAdmin.php';

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
    private function view(array $j):array{return ['id'=>$j['id'],'status'=>$j['status'],'phase'=>$j['phase'],'fileCount'=>count($j['files']),'bytes'=>$j['bytes'],'expiresAt'=>$j['expiresAt'],'source'=>$j['source'],'maxChunkBytes'=>262144,'paged'=>true,'sourcePaused'=>($j['snapshotMode']??null)==='maintenance'&&$this->fence()->owns($j['id'],$this->binding($j)),'snapshotMode'=>$j['snapshotMode']??'transaction','checkpoint'=>isset($j['db'])?['table'=>$j['db']['table']??0,'rowsPart'=>count($j['db']['parts']??[]),'rows'=>$j['db']['rows']??0,'tables'=>count($j['db']['tables']??[]),'bytes'=>$j['db']['bytes']??0]:(isset($j['copy'])?['bytes'=>$j['bytes']+$j['copy']['offset'],'fileBytes'=>$j['copy']['bytes'],'fileOffset'=>$j['copy']['offset']]:null)];}
    public function create(array $input,array $source,array $active=[]):array{return $this->locked(function()use($input,$source,$active){
        if(array_diff(array_keys($input),['clientId','profile','database','largeTransfer','snapshotMode','sourceQuiescenceAccepted'])||!array_key_exists('database',$input)||!is_array($input['profile']??null))throw new \InvalidArgumentException('Export selection required.');
        $large=($input['largeTransfer']??false);if(!is_bool($large))throw new \InvalidArgumentException('Invalid large-transfer selection.');
        $mode=$input['snapshotMode']??'transaction';if(!in_array($mode,['transaction','maintenance'],true)||($mode==='maintenance'&&!$large))throw new \InvalidArgumentException('Invalid snapshot mode.');
        if($mode==='maintenance'&&(($input['sourceQuiescenceAccepted']??null)!==true||!ImportAdmin::ready(dirname($this->root),$this->sourceRoot)))throw new \InvalidArgumentException('Confirm source writers are stopped and complete request protection setup first.');
        $selection=DatabaseExporter::selection($input['database']);$wantsDatabase=$selection!==false;
        $id=$input['clientId']??'';$d=$this->dir($id);$profile=ExportProfile::normalize($input['profile']);$hash=hash('sha256',json_encode(!$large&&$mode==='transaction'?[$profile,$selection]:[$profile,$selection,$large,$mode],JSON_THROW_ON_ERROR));
        if(is_dir($d)){$j=$this->read($id);if(!hash_equals($hash,$j['requestHash']))throw new \InvalidArgumentException('Export selections changed.');return $this->view($j);}
        foreach(glob($this->root.'/*',GLOB_ONLYDIR)?:[] as $old){if(is_link($old)||!preg_match('/^[a-f0-9]{32}$/D',basename($old)))continue;$j=json_decode((string)@file_get_contents($old.'/state.json'),true);if(($j['expiresAt']??filemtime($old)+3600)<=time())$this->remove(basename($old));}
        if(count(glob($this->root.'/*',GLOB_ONLYDIR)?:[])>=2)throw new \RuntimeException('Cancel an existing export before starting another.');
        if($profile['core'])throw new \InvalidArgumentException('Paged exports currently support content resources only.');
        // Theme/plugin modes resolve to item roots once, so later steps traverse a fixed selection.
        $queue=FileExporter::roots($this->sourceRoot,$profile,$active);
        if($mode==='maintenance'&&!$wantsDatabase)throw new \InvalidArgumentException('Source pause requires a database export.');
        if(!$queue&&!$wantsDatabase)throw new \InvalidArgumentException('Select at least one resource.');
        if(!mkdir($d,0700))throw new \RuntimeException('Cannot reserve export.');
        $j=['largeTransfer'=>$large,'snapshotMode'=>$mode,'id'=>$id,'owner'=>$this->owner,'requestHash'=>$hash,'source'=>$source+['tables'=>[]],'profile'=>$profile,'database'=>$selection,'status'=>'preparing','phase'=>$wantsDatabase?'database':'files','queue'=>$queue,'files'=>[],'bytes'=>0,'createdAt'=>time(),'expiresAt'=>time()+3600];$this->save($j);return $this->view($j);
    });}
    private function fence():WriteFence{return new WriteFence(dirname($this->root),$this->sourceRoot);}
    private function binding(array $j):string{return hash('sha256',"export\0".$this->owner."\0".$j['id']."\0".$j['requestHash']);}
    public function step(string $id,callable $database,$db=null):array{return $this->locked(function()use($id,$database,$db){
        $j=$this->read($id);if($j['status']==='ready')return $this->view($j);$d=$this->dir($id);
        if(($j['snapshotMode']??null)==='maintenance'&&in_array($j['phase'],['database','verify_parts','seal_database'],true))return $this->databaseStep($j,$db);
        if($j['phase']==='database'){
            foreach(['0.bin','0.bin.partial'] as $name){$p=$d.'/'.$name;if(is_link($p))throw new \RuntimeException('Unsafe snapshot.');if(is_file($p))unlink($p);}
            $tables=$database($d.'/0.bin',is_array($j['database'])?$j['database']:[]);clearstatcache();$size=filesize($d.'/0.bin');if(is_array($tables))$j['source']['tables']=array_values($tables);if($size<1||$size>DatabaseExporter::MAX_BYTES)throw new \RuntimeException('Invalid database snapshot.');
            $j['files'][]=['path'=>'database.sql','bytes'=>$size,'sha256'=>hash_file('sha256',$d.'/0.bin')];$j['bytes']=$size;$j['phase']='files';$this->touch($j);$this->save($j);return $this->view($j);
        }
        if($j['phase']==='release_source'){$f=$this->fence();$f->release($j['id'],$this->binding($j));$j['phase']='files';$this->touch($j);$this->save($j);return $this->view($j);}
        if(isset($j['copy']))return $this->copyStep($j);
        $start=microtime(true);$root=realpath($this->sourceRoot);$count=0;$smallBytes=0;$since=ExportProfile::mediaSince($j['profile']);
        while($j['queue']&&$count++<250&&microtime(true)-$start<4){
            $relative=array_pop($j['queue']);Selection::path($relative);
            // A hidden directory must be checked as a parent, not as a leaf filename.
            $portable=is_dir($root.'/'.$relative)?$relative.'/__entry__':$relative;
            if(!FileExporter::portableHiddenPath($portable))continue;
            if($relative==='wp-content/plugins/zoer-connect'||str_starts_with($relative,'wp-content/plugins/zoer-connect/'))continue;
            if(Selection::excluded($relative,['**/.git/','**/node_modules/','**/.env','**/.env.*','**/*.log',...$j['profile']['excludes']]))continue;
            $path=$root.'/'.$relative;if(!file_exists($path)&&!is_link($path)){if(in_array($relative,['wp-content/themes','wp-content/plugins','wp-content/uploads','wp-content/mu-plugins'],true))continue;throw new \RuntimeException('Source changed during pull.');}
            $real=realpath($path);if(!$real||!str_starts_with($real,$root.'/')||is_link($path))throw new \RuntimeException('Unsafe source file.');
            for($parent=dirname($path);$parent!==$root;$parent=dirname($parent))if(is_link($parent))throw new \RuntimeException('Symlink source rejected.');
            if(is_dir($path)){$children=scandir($path);if($children===false||count($children)+count($j['queue'])>120000)throw new \RuntimeException('Too many selected files.');foreach(array_reverse($children) as $name)if($name!=='.'&&$name!=='..')$j['queue'][]=$relative.'/'.$name;continue;}
            if(!is_file($path))throw new \RuntimeException('Unsafe source file.');
            if($since!==null&&str_starts_with($relative,'wp-content/uploads/')&&filemtime($path)<$since)continue;
            $size=filesize($path);if(!($j['largeTransfer']??false)&&$size>33554432)throw new \RuntimeException('A selected file exceeds the current 32 MiB export limit.');
            if($j['largeTransfer']??false)TransferStorage::size($size,$j['bytes']+$size);
            if(count($j['files'])>=100000||(!($j['largeTransfer']??false)&&$j['bytes']+$size>4*1073741824))throw new \RuntimeException('Paged export exceeds its file or byte limit.');
            if(disk_free_space($d)<$size+67108864)throw new \RuntimeException('Insufficient private export storage.');
            $index=count($j['files']);
            if($j['largeTransfer']??false){
                if($size<=262144&&$smallBytes+$size<=4194304){
                    // Batch small files within the existing entry/time bounds and a 4 MiB budget.
                    // A lost response replays from the journal and truncates uncommitted snapshots.
                    $identity=self::identity($path);$target=$d.'/'.$index.'.bin';
                    if(ExportBlocks::append($path,$target,0,$size)!==$size||ExportBlocks::verifySource($path,$target,0,$size)!==$size||self::identity($path)!==$identity)throw new \RuntimeException('Source changed during pull.');
                    $j['files'][]=ExportBlocks::entry($target,$relative,$size);$j['bytes']+=$size;$smallBytes+=$size;continue;
                }
                $j['copy']=['path'=>$relative,'bytes'=>$size,'offset'=>0,'stat'=>self::identity($path)];$this->touch($j);$this->save($j);return $this->view($j);
            }
            $tmp=$d.'/'.$index.'.tmp';if(is_link($tmp)||is_link($d.'/'.$index.'.bin'))throw new \RuntimeException('Unsafe snapshot.');
            if(!copy($path,$tmp))throw new \RuntimeException('Cannot snapshot file.');chmod($tmp,0600);$hash=hash_file('sha256',$tmp);clearstatcache(true,$path);
            if(filesize($tmp)!==$size||filesize($path)!==$size||$hash!==hash_file('sha256',$path))throw new \RuntimeException('Source changed during pull.');
            if(!rename($tmp,$d.'/'.$index.'.bin'))throw new \RuntimeException('Cannot finalize snapshot.');
            $j['files'][]=['path'=>$relative,'bytes'=>$size,'sha256'=>$hash];$j['bytes']+=$size;
        }
        if(!$j['queue']){$j['status']='ready';$j['phase']='ready';}$this->touch($j);$this->save($j);return $this->view($j);
    });}
    private function databaseStep(array $j,$db):array {
        if(!$db)throw new \RuntimeException('Source database unavailable.');$f=$this->fence();$binding=$this->binding($j);
        if(!isset($j['db'])){
            if($j['phase']!=='database')throw new \RuntimeException('Source checkpoint unavailable.');
            $f->reserveExport($j['id'],$binding);$j['db']=['starting'=>true];$this->touch($j);$this->save($j);return $this->view($j);
        }
        // An expired/released fence can never silently resume the old snapshot.
        if(!$f->owns($j['id'],$binding))throw new \RuntimeException('Source pause expired. Cancel and start a fresh export.');
        $f->reserveExport($j['id'],$binding);
        return $f->exclusive($j['id'],$binding,function()use($j,$db,$f,$binding){
            $path=$this->dir($j['id']).'/0.bin';
            if(isset($j['db']['starting']))$j['db']=PagedDatabase::start($db,is_array($j['database'])?$j['database']:[]);
            if($j['phase']==='database'){$j['db']=PagedDatabase::step($db,$path,$j['db']);if($j['db']['complete']){$j['phase']='verify_parts';$j['verifyPart']=0;$j['sealOffset']=0;$j['sealTail']='';}}
            elseif($j['phase']==='verify_parts'){
                if(isset($j['db']['parts'][$j['verifyPart']])){$sealed=ExportBlocks::sealPart($path,$j['db']['parts'][$j['verifyPart']],$j['sealOffset'],base64_decode($j['sealTail'],true),$j['verifyPart']===count($j['db']['parts'])-1);$j['sealOffset']=$sealed['offset'];$j['sealTail']=$sealed['tail'];$j['verifyPart']++;}
                if($j['verifyPart']===count($j['db']['parts'])){$j['phase']='seal_database';}
            }else {
                if($j['sealTail']!=='')throw new \RuntimeException('Snapshot part tail incomplete.');
                if($j['sealOffset']===$j['db']['bytes']){
                    $j['files'][]=ExportBlocks::entry($path,'database.sql',$j['db']['bytes']);$j['bytes']=$j['db']['bytes'];$j['source']['tables']=array_map(static fn($t)=>substr($t,strlen($j['db']['prefix'])),$j['db']['tables']);
                    $j['source']['snapshotConsistency']='source-maintenance';$j['phase']='release_source';
                }
            }
            $this->touch($j);$this->save($j);return $this->view($j);
        });
    }
    private static function identity(string $path):array {clearstatcache(true,$path);$s=lstat($path);if(!$s||($s['mode']&0170000)!==0100000)throw new \RuntimeException('Unsafe source file.');return array_intersect_key($s,array_flip(['dev','ino','size','mtime','ctime']));}
    private function copyStep(array $j):array {
        $c=$j['copy'];$root=realpath($this->sourceRoot);$path=$root.'/'.$c['path'];$real=realpath($path);
        if(!$real||$real!==$path)throw new \RuntimeException('Source changed during pull.');
        for($p=$path;$p!==$root;$p=dirname($p))if(is_link($p))throw new \RuntimeException('Symlink source rejected.');
        if(self::identity($path)!==$c['stat'])throw new \RuntimeException('Source changed during pull.');
        $target=$this->dir($j['id']).'/'.count($j['files']).'.bin';
        // Empty files still need durable snapshot and block-seal files before entry().
        $c['offset']=($c['offset']<$c['bytes']||$c['bytes']===0)?ExportBlocks::append($path,$target,$c['offset'],$c['bytes']):$c['offset'];
        if($c['offset']===$c['bytes'])$c['verified']=ExportBlocks::verifySource($path,$target,$c['verified']??0,$c['bytes']);
        if(self::identity($path)!==$c['stat'])throw new \RuntimeException('Source changed during pull.');
        if($c['offset']===$c['bytes']&&($c['verified']??0)===$c['bytes']){$j['files'][]=ExportBlocks::entry($target,$c['path'],$c['bytes']);$j['bytes']+=$c['bytes'];unset($j['copy']);}
        else $j['copy']=$c;
        if(!$j['queue']&&!isset($j['copy'])){$j['status']='ready';$j['phase']='ready';}
        $this->touch($j);$this->save($j);return $this->view($j);
    }
    public function status(string $id,bool $expired=false):array{return $this->locked(fn()=>$this->view($this->read($id,$expired)));}
    public function manifest(string $id,int $offset):array{return $this->locked(function()use($id,$offset){$j=$this->read($id);if($j['status']!=='ready'||$offset<0||$offset>count($j['files']))throw new \InvalidArgumentException('Invalid manifest page.');$this->touch($j);$this->save($j);return ['id'=>$id,'offset'=>$offset,'total'=>count($j['files']),'files'=>array_slice($j['files'],$offset,500)];});}
    public function batch(string $id,int $index,int $offset):array{return $this->locked(function()use($id,$index,$offset){
        $j=$this->read($id);if($j['status']!=='ready'||$index<0||!isset($j['files'][$index])||$offset<0)throw new \InvalidArgumentException('Invalid export range.');$chunks=[];$total=0;$batchBytes=($j['largeTransfer']??false)?4194304:1048576;
        while(isset($j['files'][$index])&&count($chunks)<32&&$total<$batchBytes){$f=$j['files'][$index];if($offset>$f['bytes'])throw new \InvalidArgumentException('Invalid export offset.');$p=$this->dir($id).'/'.$index.'.bin';if(is_link($p)||!is_file($p)||filesize($p)!==$f['bytes'])throw new \RuntimeException('Snapshot unavailable.');
            // The next request must start on a sealed block boundary. Earlier small
            // files can leave less than one full block in this batch's byte budget.
            if(($f['digestFormat']??null)===ExportBlocks::FORMAT&&min(262144,$f['bytes']-$offset)>$batchBytes-$total)break;
            $h=fopen($p,'rb');try{if(!$h||fseek($h,$offset)!==0)throw new \RuntimeException('Cannot read snapshot.');$data=fread($h,min(262144,$batchBytes-$total));if($data===false)throw new \RuntimeException('Cannot read snapshot.');}finally{if(is_resource($h))fclose($h);}
            $n=strlen($data);if(($f['digestFormat']??null)===ExportBlocks::FORMAT&&$n)ExportBlocks::verify($p,$offset,$data);$chunks[]=['index'=>$index,'offset'=>$offset,'bytes'=>$n,'data'=>base64_encode($data),'sha256'=>hash('sha256',$data)];$total+=$n;$offset+=$n;if($offset===$f['bytes']){$index++;$offset=0;}elseif(!$n)throw new \RuntimeException('Snapshot truncated.');
        }$this->touch($j);$this->save($j);return ['id'=>$id,'chunks'=>$chunks];
    });}
    private function remove(string $id):void{$d=$this->dir($id);$raw=@file_get_contents($d.'/state.json');$j=$raw===false?null:json_decode($raw,true);if(is_array($j)&&($j['snapshotMode']??null)==='maintenance'){$f=$this->fence();if($f->owns($id,$this->binding($j)))$f->release($id,$this->binding($j));}
        foreach(glob($d.'/*')?:[] as $p){if(is_dir($p)||is_link($p))throw new \RuntimeException('Unsafe cleanup.');if(!unlink($p))throw new \RuntimeException('Cannot clean export.');}if(is_dir($d)&&!rmdir($d))throw new \RuntimeException('Cannot clean export.');}
    public function cancel(string $id):array{return $this->locked(function()use($id){if(is_dir($this->dir($id))){$this->read($id,true);$this->remove($id);}return ['id'=>$id,'status'=>'cancelled'];});}
}
