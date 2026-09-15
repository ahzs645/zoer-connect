<?php
namespace ZoerConnect;
foreach(['StageStore','FileComparison','WriteFence','SnapshotStream','TableStage','FilePublication','ChunkedFilePublication','Replacement'] as $dependency) require_once __DIR__.'/'.$dependency.'.php';

/** Authenticated callers supply the current native credential generation. All
 * mutable job inputs are private and bound to that generation and destination.
 * Public callers must independently enforce Push authorization and completed
 * ImportAdmin worker-drain readiness before setting the explicit enable flag.
 */
final class TransferImport {
    private const TABLES=['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options'];
    private $db;
    private string $root;
    private string $private;
    private string $owner;
    private string $target;
    private WriteFence $fence;
    public function __construct($db,string $root,string $private,string $owner,string $target,bool $enabled=false) {
        if (!$enabled) throw new \RuntimeException('Public import requires explicit readiness and authorization.');
        if (!filter_var($target,FILTER_VALIDATE_URL)||!in_array(parse_url($target,PHP_URL_SCHEME),['http','https'],true)||parse_url($target,PHP_URL_USER)!==null||parse_url($target,PHP_URL_PASS)!==null||parse_url($target,PHP_URL_QUERY)!==null||parse_url($target,PHP_URL_FRAGMENT)!==null) throw new \InvalidArgumentException('Invalid destination URL.');
        new StageStore($private,[$root]);
        if (!preg_match('/^[a-f0-9]{64}$/D',$owner)||!preg_match('/^[A-Za-z0-9_]+$/D',$db->prefix)) throw new \InvalidArgumentException('Invalid import identity.');
        $this->db=$db;$this->root=realpath($root);$this->private=realpath($private);$this->owner=$owner;$this->target=$target;$this->fence=new WriteFence($private,$root);
    }
    public function installFence(): void {$this->fence->install();}
    private function dir(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid import ID.');return $this->private.'/import-'.$id;}
    private function locked(callable $fn) {$h=fopen($this->private.'/transfer-import.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new \RuntimeException('Import is busy.');}try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}}
    private function save(array $s): void {$dir=$this->dir($s['id']);$j=json_encode($s,JSON_THROW_ON_ERROR);if(file_put_contents($dir.'/state.tmp',$j)!==strlen($j)||!rename($dir.'/state.tmp',$dir.'/state.json'))throw new \RuntimeException('Cannot persist import state.');}
    private function read(string $id): array {
        $s=json_decode(file_get_contents($this->dir($id).'/state.json'),true,512,JSON_THROW_ON_ERROR);
        if(($s['target']??null)!==$this->target||!hash_equals($this->owner,$s['owner']??''))throw new \RuntimeException('Import credential generation or destination changed.');
        return $s;
    }
    private function destination(): void {
        foreach(['home','siteurl'] as $key)if($this->db->get_var($this->db->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s",$key))!==$this->target||$this->db->last_error)throw new \RuntimeException('Destination identity changed.');
    }
    private function artifact(array $a,bool $database): array {
        if(!is_int($a['bytes']??null)||$a['bytes']<0||$a['bytes']>($database||isset($a['chunkSha256'])?2147483648:33554432)||!preg_match('/^[a-f0-9]{64}$/D',$a['sha256']??''))throw new \InvalidArgumentException('Invalid artifact size or digest.');
        if(array_key_exists('expectedDestinationSha256',$a)&&$a['expectedDestinationSha256']!==null&&(!is_string($a['expectedDestinationSha256'])||!preg_match('/^[a-f0-9]{64}$/D',$a['expectedDestinationSha256'])))throw new \InvalidArgumentException('Invalid destination precondition.');
        if(!$database&&isset($a['chunkSha256']))ChunkedFilePublication::validate($a);
        if($database){
            $hashes=$a['chunkSha256']??null;
            if(!is_array($hashes)||!array_is_list($hashes)||count($hashes)!==(int)ceil($a['bytes']/StageStore::CHUNK))throw new \InvalidArgumentException('Database requires a SHA-256 digest for every fixed-size upload chunk.');
            foreach($hashes as $hash)if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new \InvalidArgumentException('Invalid database chunk digest.');
        }
        return $a;
    }
    public function create(array $body): array {
        return $this->locked(function()use($body){
            $this->destination();
            foreach($body['originalUrls']??[] as $url)if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true))throw new \InvalidArgumentException('Invalid original source URL.');
            if(isset($body['id'])&&is_file($this->dir($body['id']).'/state.json')){
                $old=$this->read($body['id']);$binding=hash('sha256',json_encode([$this->owner,$this->target,$body],JSON_THROW_ON_ERROR));
                if(!hash_equals($old['binding'],$binding))throw new \InvalidArgumentException('Conflicting import creation retry.');
                return $this->summary($old);
            }
            if(!$this->fence->installed())throw new \RuntimeException('Install the protected MU request fence first.');
            if(($body['target']??null)!==$this->target)throw new \InvalidArgumentException('Confirm destination.');
            $shared=($body['migrationMode']??null)==='shared-replacement';
            if($shared?($body['replacementAccepted']??false)!==true:($body['wordpressOnlyWriters']??false)!==true)throw new \InvalidArgumentException('Confirm the selected migration policy.');
            if(!filter_var($body['sourceUrl']??'',FILTER_VALIDATE_URL)||!in_array(parse_url($body['sourceUrl'],PHP_URL_SCHEME),['http','https'],true)||$body['sourceUrl']===$this->target||!preg_match('/^[A-Za-z0-9_]+$/D',$body['sourcePrefix']??''))throw new \InvalidArgumentException('Invalid source identity.');
            foreach(glob($this->private.'/import-*/state.json')?:[] as $path){$old=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!in_array($old['phase'],['complete','rolled_back','cancelled'],true))throw new \RuntimeException('Recover the existing import first.');}
            $files=$body['files']??[];if(!is_array($files)||!array_is_list($files))throw new \InvalidArgumentException('Invalid selected files.');
            if($files)StageStore::validateManifest(['version'=>1,'target'=>$this->target,'files'=>$files],$this->target);
            $artifacts=[];$hasDb=isset($body['database']);
            if($hasDb)$artifacts[]=array_merge($this->artifact($body['database'],true),['kind'=>'database']);
            foreach($files as $file)$artifacts[]=array_merge($this->artifact($file,false),['kind'=>'file']);
            if(!$artifacts)throw new \InvalidArgumentException('Nothing selected.');
            $reuse=null;
            if(isset($body['reuseImportId'])){
                $previous=$this->read($body['reuseImportId']);
                if(!in_array($previous['phase'],['complete','rolled_back'],true)||$previous['artifacts']!==$artifacts)throw new \InvalidArgumentException('Reusable upload must be terminal and match every artifact.');
                $reuse=$previous['id'];
            }
            $admin=$body['destinationAdminId']??0;
            if($hasDb){
                $caps=$this->db->get_var($this->db->prepare("SELECT meta_value FROM `{$this->db->usermeta}` WHERE user_id=%d AND meta_key=%s",$admin,$this->db->prefix.'capabilities'));
                $caps=is_string($caps)?@unserialize($caps,['allowed_classes'=>false]):null;
                if(!is_int($admin)||$admin<1||!is_array($caps)||($caps['administrator']??false)!==true||!$this->db->get_var($this->db->prepare("SELECT ID FROM `{$this->db->users}` WHERE ID=%d",$admin)))throw new \InvalidArgumentException('Select a retained destination administrator for source author mapping.');
            }
            if(disk_free_space($this->private)<array_sum(array_column($artifacts,'bytes'))*2+67108864)throw new \RuntimeException('Insufficient private backup space.');
            $id=$body['id']??bin2hex(random_bytes(16));$final=$this->dir($id);$dir=$this->private.'/.new-import-'.$id.'-'.bin2hex(random_bytes(8));if(!mkdir($dir,0700))throw new \RuntimeException('Cannot create import.');
            foreach($artifacts as $i=>$a){if(!mkdir($dir.'/artifact-'.$i,0700))throw new \RuntimeException('Cannot create artifact.');file_put_contents($dir.'/artifact-'.$i.'/data','');}
            $s=['id'=>$id,'phase'=>'uploading','migrationMode'=>$shared?'shared-replacement':'verified-workers','target'=>$this->target,'owner'=>$this->owner,'sourceUrl'=>$body['sourceUrl'],'sourcePrefix'=>$body['sourcePrefix'],'originalUrls'=>array_values(array_unique([$body['sourceUrl'],...($body['originalUrls']??[])])),'admin'=>$admin,'artifacts'=>$artifacts,'hasDb'=>$hasDb,'hasFiles'=>(bool)$files,'selectedPlugins'=>isset($body['resources']['plugins'])?$body['resources']['plugins']===true:(bool)array_filter($files,fn($f)=>str_starts_with($f['path'],'wp-content/plugins/')),'selectedThemes'=>isset($body['resources']['themes'])?$body['resources']['themes']===true:(bool)array_filter($files,fn($f)=>str_starts_with($f['path'],'wp-content/themes/')),'cursor'=>0,'tables'=>[],'createdAt'=>gmdate('c')];
            $s['binding']=hash('sha256',json_encode([$this->owner,$this->target,$body],JSON_THROW_ON_ERROR));
            if($reuse!==null){$s['reuseImportId']=$reuse;$s['phase']='reusing_artifacts';}
            $json=json_encode($s,JSON_THROW_ON_ERROR);if(file_put_contents($dir.'/state.json',$json)!==strlen($json)||!rename($dir,$final))throw new \RuntimeException('Cannot publish initialized import journal.');
            return $this->summary($s);
        });
    }
    private function summary(array $s): array {
        $offsets=[];foreach($s['artifacts'] as $i=>$a){$path=$this->dir($s['id']).'/artifact-'.$i.'/data';clearstatcache(true,$path);$offsets[]=filesize($path);}
        return ['id'=>$s['id'],'migrationMode'=>$s['migrationMode']??'verified-workers','phase'=>$s['phase'],'target'=>$s['target'],'sourceUrl'=>$s['sourceUrl'],'createdAt'=>$s['createdAt'],'offsets'=>$offsets,'cursor'=>$s['cursor'],'tableRows'=>array_map(static fn($t)=>$t['rows'],$s['tables']),'maxChunkBytes'=>StageStore::CHUNK,'rollbackRefused'=>$s['rollbackRefused']??false,'authorPolicy'=>'source posts assigned to retained destination administrator; comments unlinked from source users','artifacts'=>array_map(static fn($a)=>array_intersect_key($a,array_flip(['kind','path','bytes','sha256'])),$s['artifacts'])];
    }
    public function status(string $id): array {return $this->locked(fn()=>$this->summary($this->read($id)));}
    public function chunk(string $id,int $index,int $offset,string $data): array {
        return $this->locked(function()use($id,$index,$offset,$data){
            $s=$this->read($id);$a=$s['artifacts'][$index]??null;
            if($s['phase']!=='uploading'||!$a||$offset<0||$offset%StageStore::CHUNK!==0||strlen($data)!==min(StageStore::CHUNK,$a['bytes']-$offset)||$data==='')throw new \InvalidArgumentException('Invalid artifact chunk.');
            if(isset($a['chunkSha256'])&&!hash_equals($a['chunkSha256'][(int)($offset/StageStore::CHUNK)]??'',hash('sha256',$data)))throw new \InvalidArgumentException('Database chunk digest mismatch.');
            $path=$this->dir($id).'/artifact-'.$index.'/data';clearstatcache(true,$path);$size=filesize($path);
            $h=fopen($path,'c+b');if(!$h)throw new \RuntimeException('Cannot open artifact.');
            try{
                if($offset<$size){fseek($h,$offset);$existing=fread($h,strlen($data));if(!hash_equals($existing,substr($data,0,strlen($existing))))throw new \InvalidArgumentException('Conflicting chunk retry.');if(strlen($existing)<strlen($data)){fseek($h,$offset);if(fwrite($h,$data)!==strlen($data)||!fflush($h))throw new \RuntimeException('Upload retry failed.');}}
                else {if($offset!==$size)throw new \InvalidArgumentException('Upload offset mismatch.');fseek($h,$offset);if(fwrite($h,$data)!==strlen($data)||!fflush($h))throw new \RuntimeException('Upload failed.');}
            }finally{fclose($h);}
            return $this->summary($s);
        });
    }
    private function table(array $t): TableStage {return new TableStage($this->db,$t['name'],$t['id'],true);}
    private function publication(array $s,int $index): FilePublication|ChunkedFilePublication {$class=isset($s['artifacts'][$index]['chunkSha256'])?ChunkedFilePublication::class:FilePublication::class;return new $class($this->root,$this->dir($s['id']).'/artifact-'.$index);}
    /** Repeatable invalidation occurs while requests remain paused. A failed
     * cache backend leaves recovery available and never silently reopens. */
    private function reopen(array $s): void {
        $this->fence->release($s['id'],$s['binding'],static function()use($s){
            if (($s['hasDb']||$s['selectedThemes']||$s['selectedPlugins']) && function_exists('wp_cache_flush') && wp_cache_flush()===false)
                throw new \RuntimeException('Object cache flush failed; retry finish or recovery.');
        });
    }
    public function step(string $id): array {
        return $this->locked(function()use($id){
            $s=$this->read($id);
            if(in_array($s['phase'],['finishing','rollback_refusal_release'],true)){$this->reopen($s);$s['phase']='complete';$this->save($s);}
            if(in_array($s['phase'],['complete','rolled_back','cancelled','verification_required','paused'],true))return $this->summary($s);
            if($s['phase']==='reusing_artifacts'){
                $previous=$this->read($s['reuseImportId']);
                if(!in_array($previous['phase'],['complete','rolled_back'],true)||$previous['artifacts']!==$s['artifacts'])throw new \RuntimeException('Reusable upload changed.');
                $deadline=microtime(true)+2;
                for($n=0;$n<100&&isset($s['artifacts'][$s['cursor']]);$n++){
                    $i=$s['cursor'];$a=$s['artifacts'][$i];
                    // Database chunks are uploaded and authenticated again. File
                    // bytes are rehashed in checking_artifacts before any fence.
                    if($a['kind']==='file'){
                        $from=$this->dir($previous['id']).'/artifact-'.$i.'/data';$to=$this->dir($id).'/artifact-'.$i.'/data';
                        clearstatcache(true,$from);clearstatcache(true,$to);
                        if(is_link($from)||!is_file($from)||filesize($from)!==$a['bytes'])throw new \RuntimeException('Reusable artifact is incomplete.');
                        if(filesize($to)!==$a['bytes']&&function_exists('link')){
                            if(is_file($to.'.link'))unlink($to.'.link');
                            if(!link($from,$to.'.link')||!rename($to.'.link',$to))throw new \RuntimeException('Cannot reuse staged artifact.');
                        }
                    }
                    $s['cursor']++;$this->save($s);if(microtime(true)>=$deadline)break;
                }
                if(!isset($s['artifacts'][$s['cursor']])){$s['phase']='uploading';$s['cursor']=0;$this->save($s);}
                return $this->summary($s);
            }
            if($s['phase']==='uploading'){
                foreach($s['artifacts'] as $i=>$a){$p=$this->dir($id).'/artifact-'.$i.'/data';clearstatcache(true,$p);if(filesize($p)!==$a['bytes'])throw new \RuntimeException('Complete all artifact uploads first.');}
                $s['phase']='checking_artifacts';$s['cursor']=0;$this->save($s);return $this->summary($s);
            }
            if($s['phase']==='checking_artifacts'){
                $deadline=microtime(true)+2;$limit=($s['migrationMode']??'')==='shared-replacement'?100:1;
                for($n=0;$n<$limit&&isset($s['artifacts'][$s['cursor']]);$n++){
                    $i=$s['cursor'];$a=$s['artifacts'][$i];$path=$this->dir($id).'/artifact-'.$i.'/data';
                    if($a['kind']==='file'){
                        if(isset($a['chunkSha256'])){
                            $offset=$s['checkOffset']??0;$h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Cannot verify file.');
                            try{fseek($h,$offset);$length=min(StageStore::CHUNK,$a['bytes']-$offset);$data=$length?fread($h,$length):'';}finally{fclose($h);}
                            if(strlen($data)!==$length||($length&&!hash_equals($a['chunkSha256'][intdiv($offset,StageStore::CHUNK)]??'',hash('sha256',$data))))throw new \RuntimeException('File block mismatch.');
                            $s['checkOffset']=$offset+$length;$this->save($s);
                            if($s['checkOffset']<$a['bytes']){if(microtime(true)>=$deadline)break;continue;}unset($s['checkOffset']);
                        }elseif(!hash_equals($a['sha256'],hash_file('sha256',$path)))throw new \RuntimeException('File digest mismatch.');
                    }
                    $s['cursor']++;$this->save($s);if(microtime(true)>=$deadline)break;
                }
                if(isset($s['artifacts'][$s['cursor']]))return $this->summary($s);
                $s['phase']=$s['hasDb']?'scanning_database':'reserving';$s['cursor']=0;$s['scan']=SnapshotStream::initial();$this->save($s);return $this->summary($s);
            }
            if($s['phase']==='scanning_database'){
                $batch=SnapshotStream::read($this->dir($id).'/artifact-0/data',[],$s['scan'],100,true);
                foreach($batch['records'] as $record){
                    $source=$record['table'];if(!str_starts_with($source,$s['sourcePrefix']))throw new \InvalidArgumentException('Snapshot contains an unrelated table.');
                    $suffix=substr($source,strlen($s['sourcePrefix']));
                    if(in_array($suffix,['users','usermeta'],true))continue;
                    $table=$this->db->prefix.$suffix;
                    $create=$this->db->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
                    if(!isset($create[1])||SnapshotStream::schema($record['schema'],$source)!==SnapshotStream::schema($create[1],$table))throw new \RuntimeException('Every imported core or plugin table requires an existing matching destination schema.');
                    $index=count($s['tables']);$s['tables'][]=['name'=>$table,'source'=>$source,'schema'=>$record['schema'],'id'=>substr(hash('sha256',$id.':'.$index),0,16),'rows'=>0,'chunks'=>0];
                }
                $s['scan']=$batch['cursor'];
                if($s['scan']['done']){
                    $required=array_map(fn($suffix)=>$s['sourcePrefix'].$suffix,self::TABLES);
                    if(array_diff($required,array_column($s['tables'],'source')))throw new \InvalidArgumentException('Snapshot omits required WordPress content or settings tables.');
                    $s['phase']='reserving';
                }
                $this->save($s);return $this->summary($s);
            }
            if($s['phase']==='reserving'){$this->fence->reserve($id,$s['binding']);$s['phase']='preparing_tables';$s['cursor']=0;$this->save($s);}
            return $this->fence->exclusive($id,$s['binding'],function()use(&$s){$this->destination();$this->advance($s);return $this->summary($s);});
        });
    }
    /** Persist every file transition; bounded batches avoid thousands of round trips.
     * Table activation remains a single recoverable transition per request. */
    private function advance(array &$s): void {
        $phase=$s['phase'];$deadline=microtime(true)+2;
        $batch=($s['migrationMode']??'')==='shared-replacement'&&in_array($phase,['preparing_files','applying_files','rollback_reset_files','rollback_preflight_files','rollback_files'],true)?100:1;
        for($n=0;$n<$batch;$n++){
            $this->tick($s);$this->save($s);
            if($s['phase']!==$phase||($phase==='rollback_files'&&$s['cursor']<0)||microtime(true)>=$deadline)break;
        }
    }
    private function tick(array &$s): void {
        $i=$s['cursor'];$dir=$this->dir($s['id']);
        if($s['phase']==='preparing_tables'){
            if($i>=count($s['tables'])){$s['phase']=$s['hasDb']?'reading_database':'preparing_files';$s['cursor']=0;$s['reader']=SnapshotStream::initial();return;}
            if($this->table($s['tables'][$i])->prepareStep())$s['cursor']++;return;
        }
        if($s['phase']==='reading_database'){
            $schemas=[];foreach($s['tables'] as $t)$schemas[$t['source']]=$t['schema'];
            // A single row per cursor is independently committed and replayable.
            $deadline=microtime(true)+2.0;
            for($batchIndex=0;$batchIndex<100;$batchIndex++){
            $batch=SnapshotStream::read($dir.'/artifact-0/data',$schemas,$s['reader'],1);
            foreach($batch['records'] as $record)if($record['type']==='row'){
                foreach($s['tables'] as &$t)if($t['source']===$record['table']){
                    $row=$record['row'];foreach($row as &$v)if(is_string($v))$v=Replacement::apply($v,array_map(fn($url)=>['mode'=>'literal','find'=>rtrim($url,'/'),'replace'=>$this->target],$s['originalUrls']));unset($v);
                    if($t['name']===$this->db->prefix.'posts')$row['post_author']=(string)$s['admin'];
                    if($t['name']===$this->db->prefix.'comments')$row['user_id']='0';
                    if($t['name']===$this->db->options&&((($row['option_name']??'')==='active_plugins'&&!$s['selectedPlugins'])||(in_array($row['option_name']??'',['template','stylesheet'],true)&&!$s['selectedThemes']))){
                        $old=$this->db->get_var($this->db->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s",$row['option_name']));if($old!==null)$row['option_value']=$old;
                    }
                    $this->table($t)->chunk($t['chunks'],[$row]);$t['chunks']++;$t['rows']++;break;
                }unset($t);
            }
            $s['reader']=$batch['cursor'];if($s['reader']['done']){$s['phase']='verifying_tables';$s['cursor']=0;}
            $this->save($s);
            if($s['reader']['done']||microtime(true)>=$deadline)break;
            }return;
        }
        if($s['phase']==='verifying_tables'){
            if($i>=count($s['tables'])){$s['phase']='preparing_files';$s['cursor']=0;return;}
            $t=$s['tables'][$i];if($this->table($t)->verifyStep($t['rows'],$t['chunks']))$s['cursor']++;return;
        }
        if($s['phase']==='preparing_files'){
            if($i>=count($s['artifacts'])){$s['phase']='applying_files';$s['cursor']=0;return;}
            $a=$s['artifacts'][$i];
            if($a['kind']==='file'&&!isset($a['chunkSha256'])&&is_file($this->root.'/'.$a['path'])&&filesize($this->root.'/'.$a['path'])>33554432)throw new \RuntimeException('An existing destination file exceeds the bounded 32 MiB publication limit.');
            if($a['kind']==='file'&&array_key_exists('expectedDestinationSha256',$a)&&!is_file($dir.'/artifact-'.$i.'/publication.json')&&FileComparison::fingerprint($this->root,$a['path'])!==$a['expectedDestinationSha256'])throw new \RuntimeException('Destination changed since preview. Roll back and compare again.');
            if($a['kind']==='file'&&!is_file($dir.'/artifact-'.$i.'/publication.json'))$this->publication($s,$i)->prepare(['version'=>1,'target'=>$this->target,'files'=>[$a]],[$dir.'/artifact-'.$i.'/data']);
            $s['cursor']++;return;
        }
        if($s['phase']==='applying_files'){
            if($i>=count($s['artifacts'])){$s['phase']='activating_tables';$s['cursor']=0;return;}
            if($s['artifacts'][$i]['kind']==='database'||$this->publication($s,$i)->step()['status']==='verification_required')$s['cursor']++;return;
        }
        if($s['phase']==='activating_tables'){
            if($i>=count($s['tables'])){$s['phase']='verification_required';$s['cursor']=0;return;}
            if($this->table($s['tables'][$i])->activateStep())$s['cursor']++;return;
        }
        if($s['phase']==='rollback_reset'){
            if($i>=count($s['tables'])){$s['phase']='rollback_reset_files';$s['cursor']=0;return;}
            $this->table($s['tables'][$i])->resetRollbackPreflight();$s['cursor']++;return;
        }
        if($s['phase']==='rollback_reset_files'){
            if($i>=count($s['artifacts'])){$s['phase']='rollback_preflight_tables';$s['cursor']=0;return;}
            if(isset($s['artifacts'][$i]['chunkSha256'])&&$s['artifacts'][$i]['kind']==='file'&&is_file($dir.'/artifact-'.$i.'/publication.json'))$this->publication($s,$i)->resetRollbackPreflight();$s['cursor']++;return;
        }
        if($s['phase']==='rollback_preflight_tables'){
            if($i>=count($s['tables'])){$s['phase']='rollback_preflight_files';$s['cursor']=0;return;}
            if($this->table($s['tables'][$i])->rollbackPreflightStep())$s['cursor']++;return;
        }
        if($s['phase']==='rollback_preflight_files'){
            if($i>=count($s['artifacts'])){$s['phase']='rollback_tables';$s['cursor']=count($s['tables'])-1;return;}
            if(is_file($dir.'/artifact-'.$i.'/publication.json')){$p=$this->publication($s,$i);if($p instanceof ChunkedFilePublication){if(!$p->rollbackPreflightStep())return;}else $p->rollbackPreflight();}$s['cursor']++;return;
        }
        if($s['phase']==='rollback_tables'){
            if($i<0){$s['phase']='rollback_files';$s['cursor']=count($s['artifacts'])-1;return;}
            if($this->table($s['tables'][$i])->rollbackStep())$s['cursor']--;return;
        }
        if($s['phase']==='rollback_files'){
            if($i<0){$s['phase']='rollback_ready';return;}
            if(!is_file($dir.'/artifact-'.$i.'/publication.json')||$this->publication($s,$i)->rollbackStep()['status']==='rolled_back')$s['cursor']--;return;
        }
    }
    public function rollback(string $id): array {
        return $this->locked(function()use($id){
            $s=$this->read($id);
            if($s['phase']==='rollback_refusal_release'){$this->reopen($s);$s['phase']='complete';$this->save($s);}
            if($s['phase']==='paused'){$s['phase']=$s['resumePhase'];unset($s['resumePhase']);}
            if(in_array($s['phase'],['rolled_back','cancelled'],true))return $this->summary($s);
            if(in_array($s['phase'],['uploading','reusing_artifacts','checking_artifacts','scanning_database'],true)){$s['phase']='cancelled';$this->save($s);return $this->summary($s);}
            $this->fence->reserve($id,$s['binding']);
            if(!str_starts_with($s['phase'],'rollback')){$s['rollbackFromComplete']=in_array($s['phase'],['complete','finishing'],true);$s['phase']='rollback_reset';$s['cursor']=0;$this->save($s);}
            try{$this->fence->exclusive($id,$s['binding'],function()use(&$s){$this->advance($s);});}
            catch(\Throwable $e){
                if(($s['rollbackFromComplete']??false)&&in_array($s['phase'],['rollback_reset','rollback_reset_files','rollback_preflight_tables','rollback_preflight_files'],true)){
                    // No destination mutation occurred. Keep its later edits and reopen the site.
                    $s['phase']='rollback_refusal_release';$s['rollbackRefused']=true;$this->save($s);
                    $this->reopen($s);$s['phase']='complete';$this->save($s);
                }
                throw $e;
            }
            if($s['phase']==='rollback_ready'){$this->reopen($s);$s['phase']='rolled_back';$this->save($s);}
            return $this->summary($s);
        });
    }
    public function pause(string $id): array {
        return $this->locked(function()use($id){$s=$this->read($id);if($s['phase']==='paused')return $this->summary($s);if(in_array($s['phase'],['complete','rolled_back','cancelled','finishing'],true))throw new \RuntimeException('Import cannot pause.');$s['resumePhase']=$s['phase'];$s['phase']='paused';$this->save($s);return $this->summary($s);});
    }
    public function resume(string $id): array {
        return $this->locked(function()use($id){$s=$this->read($id);if($s['phase']==='paused'){$s['phase']=$s['resumePhase'];unset($s['resumePhase']);$this->save($s);}return $this->summary($s);});
    }
    public function finish(string $id): array {
        return $this->locked(function()use($id){$s=$this->read($id);if($s['phase']==='complete')return $this->summary($s);if(!in_array($s['phase'],['verification_required','finishing'],true))throw new \RuntimeException('Import is not ready for final verification.');if($s['phase']==='verification_required'){$this->fence->exclusive($id,$s['binding'],fn()=>$this->destination());$s['phase']='finishing';$this->save($s);}$this->reopen($s);$s['phase']='complete';$this->save($s);return $this->summary($s);});
    }
}
