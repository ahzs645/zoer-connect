<?php
namespace ZoerConnect;
foreach(['ImportError','StageStore','FileComparison','WriteFence','SnapshotStream','TableStage','FilePublication','ChunkedFilePublication','Replacement','RewriteRefresh','CachePurge','DatabaseExporter','BatchUpload'] as $dependency) require_once __DIR__.'/'.$dependency.'.php';

/** Authenticated callers supply the current native credential generation. All
 * mutable job inputs are private and bound to that generation and destination.
 * Public callers must independently enforce Push authorization and completed
 * ImportAdmin worker-drain readiness before setting the explicit enable flag.
 * Absent options reproduce the 0.3.14 pipeline; fence 'activation' stages tables
 * while the site stays live and pauses only for file apply and table swaps.
 */
final class TransferImport {
    private const TABLES=['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options'];
    private const TERMINAL=['complete','rolled_back','cancelled'];
    private const GENERIC='Import could not advance. Retry or roll back using the same connection.';
    private $db;
    private string $root;
    private string $abspath;
    private string $private;
    private string $owner;
    private string $target;
    private WriteFence $fence;
    public function __construct($db,string $root,string $private,string $owner,string $target,bool $enabled=false) {
        if (!$enabled) throw new \RuntimeException('Public import requires explicit readiness and authorization.');
        if (!filter_var($target,FILTER_VALIDATE_URL)||!in_array(parse_url($target,PHP_URL_SCHEME),['http','https'],true)||parse_url($target,PHP_URL_USER)!==null||parse_url($target,PHP_URL_PASS)!==null||parse_url($target,PHP_URL_QUERY)!==null||parse_url($target,PHP_URL_FRAGMENT)!==null) throw new \InvalidArgumentException('Invalid destination URL.');
        new StageStore($private,[$root]);
        if (!preg_match('/^[a-f0-9]{64}$/D',$owner)||!preg_match('/^[A-Za-z0-9_]+$/D',$db->prefix)) throw new \InvalidArgumentException('Invalid import identity.');
        $this->db=$db;$this->root=realpath($root);$this->abspath=rtrim($root,'/');$this->private=realpath($private);$this->owner=$owner;$this->target=$target;$this->fence=new WriteFence($private,$root);
    }
    public function installFence(): void {$this->fence->install();}
    private function dir(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid import ID.');return $this->private.'/import-'.$id;}
    /** Failures inside the lock persist a safe copy as the import's last error. */
    private function locked(callable $fn,?string $id=null) {
        $h=fopen($this->private.'/transfer-import.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new \RuntimeException('Import is busy.');}
        try{return $fn();}catch(\Throwable $e){if($id!==null)$this->recordError($id,$e);throw $e;}finally{flock($h,LOCK_UN);fclose($h);}
    }
    private function persist(array $s): void {$dir=$this->dir($s['id']);$j=json_encode($s,JSON_THROW_ON_ERROR);if(file_put_contents($dir.'/state.tmp',$j)!==strlen($j)||!rename($dir.'/state.tmp',$dir.'/state.json'))throw new \RuntimeException('Cannot persist import state.');}
    /** Progress supersedes the last recorded failure. */
    private function save(array &$s): void {
        unset($s['error']);$s['updatedAt']=gmdate('c');
        if(in_array($s['phase'],self::TERMINAL,true)&&($s['finishedPhase']??null)!==$s['phase']){$s['finishedAt']=$s['updatedAt'];$s['finishedPhase']=$s['phase'];}
        $this->persist($s);
    }
    /** $anyOwner is only for WordPress administrator cleanup after key rotation. */
    private function read(string $id,bool $anyOwner=false): array {
        // Missing journals must not emit PHP warnings into the REST response on
        // development/shared hosts with display_errors enabled. Keep the native
        // failure generic, including when the journal contains a JSON scalar.
        $raw=@file_get_contents($this->dir($id).'/state.json');
        if($raw===false)throw new \UnexpectedValueException('Import state unavailable.');
        $s=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($s))throw new \UnexpectedValueException('Invalid import state.');
        if(($s['target']??null)!==$this->target||(!$anyOwner&&!hash_equals($this->owner,$s['owner']??'')))throw new \RuntimeException('Import credential generation or destination changed.');
        return $s;
    }
    private function recordError(string $id,\Throwable $e): void {
        try{$s=$this->read($id);$s['error']=self::safeError($e,$s['phase']==='paused'?($s['resumePhase']??'paused'):$s['phase']);$s['updatedAt']=gmdate('c');$this->persist($s);}catch(\Throwable $ignored){}
    }
    /** Plugin-authored: this plugin's own exception classes, or exactly \RuntimeException /
     * \InvalidArgumentException, never subclasses such as mysqli_sql_exception. */
    public static function authored(\Throwable $e): bool {$class=get_class($e);return str_starts_with($class,__NAMESPACE__.'\\')||in_array($class,[\RuntimeException::class,\InvalidArgumentException::class],true);}
    /** Client-visible failure text: only plugin-authored messages, never paths. A path
     * may contain spaces, so everything from its first separator to a quote or the end
     * of the message is redacted. */
    public static function safeError(\Throwable $e,?string $phase=null): array {
        $message=self::authored($e)?$e->getMessage():self::GENERIC;
        $message=preg_replace(['~[A-Za-z]:\\\\[^\'"]*~','~(?<![\w:/.\-])/[^\'"]*~'],'[path]',$message)??self::GENERIC;
        $message=json_decode(json_encode(substr($message,0,300),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),true);
        return ['code'=>'zoer_import_failed','message'=>$message===''?self::GENERIC:$message,'phase'=>$phase];
    }
    public function phase(string $id): ?string {try{$s=$this->read($id);return $s['phase']==='paused'?($s['resumePhase']??'paused'):$s['phase'];}catch(\Throwable $e){return null;}}
    private function destination(): void {
        foreach(['home','siteurl'] as $key)if($this->db->get_var($this->db->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s",$key))!==$this->target||$this->db->last_error)throw new \RuntimeException('Destination identity changed.');
    }
    private function exists(string $table): bool {
        $name=$this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s',$this->db->esc_like($table)));
        // lower_case_table_names 1/2 may report a mixed-case name folded.
        return $name===$table||(is_string($name)&&strcasecmp($name,$table)===0&&(string)$this->db->get_var('SELECT @@lower_case_table_names')!=='0');
    }
    /** Longer table prefixes of other WordPress installs sharing this database (an
     * options and a posts table each). A name starting with one belongs to that install. */
    private function otherPrefixes(): array {
        $names=$this->db->get_col($this->db->prepare('SHOW TABLES LIKE %s','%'.$this->db->esc_like('options')));
        if($this->db->last_error||!is_array($names))throw new \RuntimeException('Cannot inspect database.');
        $out=[];$folded=(int)$this->db->get_var('SELECT @@lower_case_table_names')!==0;
        foreach($names as $name){
            if(!is_string($name)||!str_ends_with($name,'options'))continue;$prefix=substr($name,0,-7);
            if($folded&&strncasecmp($prefix,$this->db->prefix,strlen($this->db->prefix))===0)$prefix=$this->db->prefix.substr($prefix,strlen($this->db->prefix));
            if(strlen($prefix)>strlen($this->db->prefix)&&str_starts_with($prefix,$this->db->prefix)&&$this->exists($prefix.'posts'))$out[]=$prefix;
        }
        return $out;
    }
    private static function claimed(string $table,array $prefixes): bool {foreach($prefixes as $prefix)if(str_starts_with($table,$prefix))return true;return false;}
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
    /** Normalized options. Every key is optional; defaults are the 0.3.14 policy.
     * A replace import forces automatic rules off, implies a partial database and
     * requires at least one custom row.
     */
    public static function options($raw,bool $replace=false): array {
        $raw??=[];
        if(!is_array($raw)||($raw&&array_is_list($raw))||array_diff(array_keys($raw),['replacements','replaceGuids','keepActivePlugins','keepActiveTheme','authorMapping','createTables','partialDatabase','fence','review','purgeCaches',...($replace?['tables']:[])]))throw new \InvalidArgumentException('Invalid import options.');
        $flag=static function(string $key,bool $default)use($raw):bool{$v=$raw[$key]??$default;if(!is_bool($v))throw new \InvalidArgumentException('Invalid import option: '.$key.'.');return $v;};
        $keep=static function(string $key)use($raw):?bool{$v=$raw[$key]??null;if($v!==null&&!is_bool($v))throw new \InvalidArgumentException('Invalid import option: '.$key.'.');return $v;};
        $r=$raw['replacements']??[];
        if(!is_array($r)||($r&&array_is_list($r))||array_diff(array_keys($r),['automatic','variants','paths','custom']))throw new \InvalidArgumentException('Invalid replacement options.');
        foreach(['automatic','variants','paths'] as $key)if(isset($r[$key])&&!is_bool($r[$key]))throw new \InvalidArgumentException('Invalid replacement options.');
        $o=['replacements'=>['automatic'=>$r['automatic']??true,'variants'=>$r['variants']??false,'paths'=>$r['paths']??false,'custom'=>Replacement::custom($r['custom']??null)],
            'replaceGuids'=>$flag('replaceGuids',true),'keepActivePlugins'=>$keep('keepActivePlugins'),'keepActiveTheme'=>$keep('keepActiveTheme'),
            'authorMapping'=>$raw['authorMapping']??'administrator','createTables'=>$flag('createTables',false),'partialDatabase'=>$flag('partialDatabase',false),
            'fence'=>$raw['fence']??($replace?'activation':'early'),'review'=>$flag('review',false),'purgeCaches'=>$flag('purgeCaches',false)];
        if(!in_array($o['authorMapping'],['administrator','match'],true))throw new \InvalidArgumentException('Invalid author mapping.');
        if(!in_array($o['fence'],['early','activation'],true))throw new \InvalidArgumentException('Invalid fence mode.');
        if($o['review']&&$o['fence']!=='activation')throw new \InvalidArgumentException('Review requires the activation fence.');
        if($replace){
            if(!$o['replacements']['custom'])throw new \InvalidArgumentException('Replace requires at least one custom replacement.');
            $o['replacements']=['automatic'=>false,'variants'=>false,'paths'=>false,'custom'=>$o['replacements']['custom']];
            $o['authorMapping']='administrator';$o['createTables']=false;$o['partialDatabase']=true;
            $tables=$raw['tables']??null;
            if($tables!==null){
                if(!is_array($tables)||!array_is_list($tables)||!$tables||count($tables)>500)throw new \InvalidArgumentException('Invalid table selection.');
                foreach($tables as $suffix)if(!is_string($suffix)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$suffix)||in_array($suffix,['users','usermeta'],true))throw new \InvalidArgumentException('Invalid table selection.');
                $tables=array_values(array_unique($tables));
            }
            $o['tables']=$tables;
        }
        return $o;
    }
    private function opt(array $s): array {return $s['options']??self::options(null);}
    private function activation(array $s): bool {return $this->opt($s)['fence']==='activation';}
    private function replacing(array $s): bool {return ($s['kind']??'transfer')==='replace';}
    public function create(array $body): array {
        return $this->locked(function()use($body){
            $this->destination();
            $kind=$body['kind']??'transfer';if(!in_array($kind,['transfer','replace'],true))throw new \InvalidArgumentException('Invalid import kind.');$replace=$kind==='replace';
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
            $options=self::options($body['options']??null,$replace);
            if($replace){
                foreach(['database','files','reuseImportId','originalUrls','sourceUrl','sourcePrefix','sourcePath','resources'] as $key)if(array_key_exists($key,$body))throw new \InvalidArgumentException('A replace import snapshots this site; omit transfer artifacts.');
            }elseif(!filter_var($body['sourceUrl']??'',FILTER_VALIDATE_URL)||!in_array(parse_url($body['sourceUrl'],PHP_URL_SCHEME),['http','https'],true)||$body['sourceUrl']===$this->target||!preg_match('/^[A-Za-z0-9_]+$/D',$body['sourcePrefix']??''))throw new \InvalidArgumentException('Invalid source identity.');
            $sourcePath=null;
            if(isset($body['sourcePath'])){
                if(!is_string($body['sourcePath'])||!preg_match('~^/[^\x00-\x1f\x7f]{1,1023}$~D',$body['sourcePath'])||strlen(rtrim($body['sourcePath'],'/'))<2)throw new \InvalidArgumentException('Invalid source path.');
                $sourcePath=rtrim($body['sourcePath'],'/');
            }
            foreach(glob($this->private.'/import-*/state.json')?:[] as $path){$old=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!in_array($old['phase'],self::TERMINAL,true))throw new \RuntimeException('Recover the existing import first.');}
            $files=$replace?[]:($body['files']??[]);if(!is_array($files)||!array_is_list($files))throw new \InvalidArgumentException('Invalid selected files.');
            if($files)StageStore::validateManifest(['version'=>1,'target'=>$this->target,'files'=>$files],$this->target);
            $artifacts=[];$hasDb=$replace||isset($body['database']);
            // A replace snapshot is written and digested by this plugin in snapshotting.
            if($replace)$artifacts[]=['bytes'=>0,'sha256'=>str_repeat('0',64),'chunkSha256'=>[],'kind'=>'database'];
            elseif($hasDb)$artifacts[]=array_merge($this->artifact($body['database'],true),['kind'=>'database']);
            foreach($files as $file)$artifacts[]=array_merge($this->artifact($file,false),['kind'=>'file']);
            if(!$artifacts)throw new \InvalidArgumentException('Nothing selected.');
            $reuse=null;
            if(isset($body['reuseImportId'])){
                $previous=$this->read($body['reuseImportId']);
                if(!in_array($previous['phase'],['complete','rolled_back'],true)||$previous['artifacts']!==$artifacts||($previous['cleanedUp']??false))throw new \InvalidArgumentException('Reusable upload must be terminal and match every artifact.');
                $reuse=$previous['id'];
            }
            $admin=$body['destinationAdminId']??0;
            if($hasDb&&!$replace){
                $caps=$this->db->get_var($this->db->prepare("SELECT meta_value FROM `{$this->db->usermeta}` WHERE user_id=%d AND meta_key=%s",$admin,$this->db->prefix.'capabilities'));
                $caps=is_string($caps)?@unserialize($caps,['allowed_classes'=>false]):null;
                if(!is_int($admin)||$admin<1||!is_array($caps)||($caps['administrator']??false)!==true||!$this->db->get_var($this->db->prepare("SELECT ID FROM `{$this->db->users}` WHERE ID=%d",$admin)))throw new \InvalidArgumentException('Select a retained destination administrator for source author mapping.');
            }
            if(disk_free_space($this->private)<array_sum(array_column($artifacts,'bytes'))*2+67108864)throw new \RuntimeException('Insufficient private backup space.');
            $id=$body['id']??bin2hex(random_bytes(16));$final=$this->dir($id);$dir=$this->private.'/.new-import-'.$id.'-'.bin2hex(random_bytes(8));if(!mkdir($dir,0700))throw new \RuntimeException('Cannot create import.');
            foreach($artifacts as $i=>$a){if(!mkdir($dir.'/artifact-'.$i,0700))throw new \RuntimeException('Cannot create artifact.');if(!$replace)file_put_contents($dir.'/artifact-'.$i.'/data','');}
            $s=['id'=>$id,'phase'=>$replace?'snapshotting':'uploading','migrationMode'=>$shared?'shared-replacement':'verified-workers','target'=>$this->target,'owner'=>$this->owner,'sourceUrl'=>$replace?$this->target:$body['sourceUrl'],'sourcePrefix'=>$replace?$this->db->prefix:$body['sourcePrefix'],'originalUrls'=>$replace?[]:array_values(array_unique([$body['sourceUrl'],...($body['originalUrls']??[])])),'admin'=>$admin,'artifacts'=>$artifacts,'hasDb'=>$hasDb,'hasFiles'=>(bool)$files,'selectedPlugins'=>isset($body['resources']['plugins'])?$body['resources']['plugins']===true:(bool)array_filter($files,fn($f)=>str_starts_with($f['path'],'wp-content/plugins/')),'selectedThemes'=>isset($body['resources']['themes'])?$body['resources']['themes']===true:(bool)array_filter($files,fn($f)=>str_starts_with($f['path'],'wp-content/themes/')),'cursor'=>0,'tables'=>[],'createdAt'=>gmdate('c')];
            // A client that sends no options gets the 0.3.14 replacement rules byte-for-byte.
            $s+=['kind'=>$kind,'options'=>$options,'legacyReplacements'=>!isset($body['options']),'sourcePath'=>$sourcePath,'samples'=>[],'authors'=>null,'cleanedUp'=>false,'updatedAt'=>$s['createdAt'],'finishedAt'=>null];
            $s['binding']=hash('sha256',json_encode([$this->owner,$this->target,$body],JSON_THROW_ON_ERROR));
            if($reuse!==null){$s['reuseImportId']=$reuse;$s['phase']='reusing_artifacts';}
            if(!$replace)self::writeIndexes($dir,$artifacts);
            $json=json_encode($s,JSON_THROW_ON_ERROR);if(file_put_contents($dir.'/state.json',$json)!==strlen($json)||!rename($dir,$final))throw new \RuntimeException('Cannot publish initialized import journal.');
            return $this->summary($s);
        });
    }
    /** $light (list entries) omits per-artifact arrays and only stats files while uploading. */
    private function summary(array $s,bool $light=false): array {
        $offsets=[];
        if($light&&!in_array($s['phase'],['uploading','reusing_artifacts','snapshotting','paused'],true))$offsets=array_column($s['artifacts'],'bytes');
        else foreach($s['artifacts'] as $i=>$a){$path=$this->dir($s['id']).'/artifact-'.$i.'/data';clearstatcache(true,$path);$offsets[]=is_file($path)?filesize($path):(($s['cleanedUp']??false)?$a['bytes']:0);}
        $o=$this->opt($s);$tables=$s['tables'];$phase=$s['phase']==='paused'?($s['resumePhase']??''):$s['phase'];
        $tableIndex=count($tables);
        if(in_array($phase,['uploading','reusing_artifacts','checking_artifacts','scanning_database','mapping_authors','snapshotting'],true))$tableIndex=0;
        elseif(in_array($phase,['preparing_tables','verifying_tables','activating_tables','rollback_preflight_tables','rollback_tables'],true)||($phase==='reserving'&&$this->activation($s)))$tableIndex=max(0,min($tableIndex,(int)$s['cursor']));
        elseif($phase==='reading_database'){$k=array_search($s['reader']['current']??null,array_column($tables,'source'),true);$tableIndex=$k===false?0:$k;}
        $policy=$this->replacing($s)?'authors and comment users unchanged':($o['authorMapping']==='match'?'source authors matched to destination users by login, then email; unmatched posts assigned to retained destination administrator; unmatched comments unlinked':'source posts assigned to retained destination administrator; comments unlinked from source users');
        $out=['id'=>$s['id'],'migrationMode'=>$s['migrationMode']??'verified-workers','phase'=>$s['phase'],'target'=>$s['target'],'sourceUrl'=>$s['sourceUrl'],'createdAt'=>$s['createdAt'],'offsets'=>$offsets,'cursor'=>$s['cursor'],'tableRows'=>array_map(static fn($t)=>$t['rows'],$tables),'maxChunkBytes'=>StageStore::CHUNK,'rollbackRefused'=>$s['rollbackRefused']??false,'authorPolicy'=>$policy,'artifacts'=>array_map(static fn($a)=>array_intersect_key($a,array_flip(['kind','path','bytes','sha256'])),$s['artifacts']),
            'kind'=>$s['kind']??'transfer','options'=>$o,'fence'=>$o['fence'],'resumePhase'=>$s['phase']==='paused'?($s['resumePhase']??null):null,
            'stats'=>['replacements'=>array_sum(array_map(static fn($t)=>$t['replacements']??0,$tables)),'tables'=>array_map(static fn($t)=>['name'=>$t['name'],'rows'=>$t['rows'],'replacements'=>$t['replacements']??0,'created'=>$t['created']??false,'schemaReplaced'=>$t['schemaReplaced']??false],$tables),'samples'=>$s['samples']??[]],
            'progress'=>['uploadedBytes'=>array_sum($offsets),'totalBytes'=>array_sum(array_column($s['artifacts'],'bytes')),'tableIndex'=>$tableIndex,'tableCount'=>count($tables),'rowsRead'=>array_sum(array_column($tables,'rows'))],
            'authors'=>$s['authors']??null,'error'=>$s['error']??null,'cleanedUp'=>$s['cleanedUp']??false,'updatedAt'=>$s['updatedAt']??$s['createdAt'],'finishedAt'=>$s['finishedAt']??null];
        if($light){unset($out['artifacts'],$out['offsets'],$out['tableRows']);$out['artifactCount']=count($s['artifacts']);}
        return $out;
    }
    public function status(string $id): array {return $this->locked(fn()=>$this->summary($this->read($id)));}
    /** Journals written atomically are safe to read without the import lock, so a
     * running step never makes the list unavailable. Other generations are hidden.
     * Entries are light summaries: per-artifact arrays are replaced by artifactCount. */
    public function list(): array {
        $all=[];
        foreach(glob($this->private.'/import-*/state.json')?:[] as $path){
            if(!preg_match('~/import-([a-f0-9]{32})/state\.json$~D',$path,$m))continue;
            try{$s=$this->read($m[1]);}catch(\Throwable $e){continue;}
            $all[]=[[$s['createdAt'],$s['updatedAt']??'',$s['id']],$this->summary($s,true)];unset($s);
        }
        usort($all,static fn($a,$b)=>$b[0]<=>$a[0]);
        return ['imports'=>array_column(array_slice($all,0,50),1)];
    }
    public function chunk(string $id,int $index,int $offset,string $data): array {
        return $this->locked(function()use($id,$index,$offset,$data){
            $s=$this->read($id);$a=$s['artifacts'][$index]??null;
            if($s['phase']!=='uploading'||!$a||$offset<0||$offset%StageStore::CHUNK!==0||strlen($data)!==min(StageStore::CHUNK,$a['bytes']-$offset)||$data==='')throw new \InvalidArgumentException('Invalid artifact chunk.');
            try{$this->appendBlock($this->dir($id).'/artifact-'.$index.'/data',$offset,$data,isset($a['chunkSha256'])?($a['chunkSha256'][intdiv($offset,StageStore::CHUNK)]??''):null);}
            catch(BatchRejected $r){throw new \InvalidArgumentException(['digest_mismatch'=>'Database chunk digest mismatch.','gap'=>'Upload offset mismatch.'][$r->reason]??'Conflicting chunk retry.');}
            return $this->summary($s);
        });
    }
    /** Shared by /chunks and /batch, so a client may switch mid-upload. The block's
     * SHA-256 is checked before any write; then a block inside the staged bytes is
     * skipped (a verified block that differs on disk is rewritten), one overlapping
     * the end is written at its offset (repairing a torn tail), and one beyond the end
     * is a gap. Without a digest (legacy whole-file artifacts, /chunks only) staged
     * bytes must match. Returns how many bytes the artifact grew. */
    private function appendBlock(string $path,int $offset,string $data,?string $digest): int {
        if($digest!==null&&!hash_equals($digest,hash('sha256',$data)))throw new BatchRejected('digest_mismatch');
        clearstatcache(true,$path);$size=@filesize($path);if($size===false)throw new \RuntimeException('Cannot open artifact.');
        if($offset>$size)throw new BatchRejected('gap');
        $h=fopen($path,'c+b');if(!$h)throw new \RuntimeException('Cannot open artifact.');
        try{
            $n=strlen($data);
            if($offset<$size){fseek($h,$offset);$existing=(string)fread($h,$n);if($existing===$data)return 0;if($digest===null&&!hash_equals($existing,substr($data,0,strlen($existing))))throw new BatchRejected('conflict');}
            fseek($h,$offset);if(fwrite($h,$data)!==$n||!fflush($h))throw new \RuntimeException($offset<$size?'Upload retry failed.':'Upload failed.');
            return max(0,$offset+$n-$size);
        }finally{fclose($h);}
    }
    /** Phase and binding from the journal's leading keys (id, phase, migrationMode,
     * target and owner are written first), so an upload request never decodes every
     * artifact's block digests. Any other layout falls back to a full read. */
    private function head(string $id): string {
        $lead=@file_get_contents($this->dir($id).'/state.json',false,null,0,4096);
        if(is_string($lead)&&preg_match('~^\{"id":"([a-f0-9]{32})","phase":"([a-z_]+)","migrationMode":"[a-z-]+","target":("(?:[^"\\\\]|\\\\.)*"),"owner":"([a-f0-9]{64})",~',$lead,$m)&&$m[1]===$id){
            if(json_decode($m[3])!==$this->target||!hash_equals($this->owner,$m[4]))throw new \RuntimeException('Import credential generation or destination changed.');
            return $m[2];
        }
        return $this->read($id)['phase'];
    }
    private static function atomic(string $path,string $data): void {
        $h=fopen($path.'.tmp','wb');if(!$h)throw new \RuntimeException('Cannot persist upload index.');
        try{$ok=fwrite($h,$data)===strlen($data)&&fflush($h);}finally{fclose($h);}
        if(!$ok||!rename($path.'.tmp',$path))throw new \RuntimeException('Cannot persist upload index.');
    }
    /** Fixed-width upload indexes: artifacts.idx holds (uint64 bytes, uint32 first block,
     * uint32 verified) per artifact, blocks.idx 32 raw SHA-256 bytes per block; upload.json
     * (the cursor) is written last, so its presence implies complete indexes. */
    private static function writeIndexes(string $dir,array $artifacts): void {
        $a='';$b='';$blocks=0;
        foreach($artifacts as $x){$verified=isset($x['chunkSha256']);$a.=pack('JNN',$x['bytes'],$verified?$blocks:0xFFFFFFFF,$verified?1:0);if($verified)foreach($x['chunkSha256'] as $hash){$b.=hex2bin($hash);$blocks++;}}
        self::atomic($dir.'/artifacts.idx',$a);self::atomic($dir.'/blocks.idx',$b);
        self::atomic($dir.'/upload.json',json_encode(['cursor'=>['index'=>0,'offset'=>0],'uploadedBytes'=>0,'totalBytes'=>array_sum(array_column($artifacts,'bytes')),'done'=>0,'ahead'=>[],'artifacts'=>count($artifacts),'blocks'=>$blocks],JSON_THROW_ON_ERROR));
    }
    /** The upload cursor and its indexes. Journals created before 0.4.0 batching get
     * them built once from the full state, only while uploads are still possible. */
    private function uploadIndex(string $id,bool $build=true): ?array {
        $dir=$this->dir($id);$u=json_decode((string)@file_get_contents($dir.'/upload.json'),true);
        clearstatcache();
        if(is_array($u)&&is_int($u['artifacts']??null)&&is_int($u['blocks']??null)&&is_int($u['done']??null)&&is_array($u['ahead']??null)&&@filesize($dir.'/artifacts.idx')===16*$u['artifacts']&&@filesize($dir.'/blocks.idx')===32*$u['blocks'])return $u;
        if(!$build)return null;
        $s=$this->read($id);$phase=$s['phase']==='paused'?($s['resumePhase']??''):$s['phase'];
        if(($s['kind']??'transfer')==='replace'||!in_array($phase,['uploading','reusing_artifacts'],true))return null;
        self::writeIndexes($dir,$s['artifacts']);return $this->uploadIndex($id,false);
    }
    private static function entry($h,int $i): array {
        fseek($h,16*$i);$raw=fread($h,16);if(!is_string($raw)||strlen($raw)!==16)throw new \RuntimeException('Cannot read upload index.');
        return unpack('Jbytes/Nfirst/Nverified',$raw);
    }
    private static function resumeOffset(string $path): int {clearstatcache(true,$path);return intdiv((int)@filesize($path),StageStore::CHUNK)*StageStore::CHUNK;}
    /** Moves the cursor past complete artifacts (staged sizes only grow) and recomputes
     * uploadedBytes; the cursor offset is block-aligned, so a torn tail is resent. */
    private function advanceCursor(string $id,array &$u,$h): void {
        $dir=$this->dir($id);$i=$u['cursor']['index'];$size=0;
        while($i<$u['artifacts']){
            $bytes=self::entry($h,$i)['bytes'];$path=$dir.'/artifact-'.$i.'/data';clearstatcache(true,$path);$size=is_file($path)?filesize($path):$bytes;
            if($size<$bytes)break;
            $u['done']+=$bytes;unset($u['ahead'][$i]);$i++;$size=0;
        }
        unset($u['ahead'][$i]);
        $u['cursor']=['index'=>$i,'offset'=>intdiv($size,StageStore::CHUNK)*StageStore::CHUNK];$u['uploadedBytes']=$u['done']+$size+array_sum($u['ahead']);
    }
    /** GET /imports/{id}?view=upload: {cursor, uploadedBytes, totalBytes, phase} without
     * the import lock or a full journal decode. */
    public function upload(string $id): array {
        $phase=$this->head($id);
        $u=$this->uploadIndex($id,false)??$this->locked(fn()=>$this->uploadIndex($id));
        if($u===null){
            $s=$this->read($id);$p=$this->summary($s);$cursor=['index'=>count($s['artifacts']),'offset'=>0];
            if($s['phase']!=='snapshotting')foreach($p['offsets'] as $i=>$o)if($o<$s['artifacts'][$i]['bytes']){$cursor=['index'=>$i,'offset'=>intdiv($o,StageStore::CHUNK)*StageStore::CHUNK];break;}
            return ['cursor'=>$s['phase']==='snapshotting'?['index'=>0,'offset'=>0]:$cursor,'uploadedBytes'=>$p['progress']['uploadedBytes'],'totalBytes'=>$p['progress']['totalBytes'],'phase'=>$s['phase']];
        }
        $h=fopen($this->dir($id).'/artifacts.idx','rb');if(!$h)throw new \RuntimeException('Cannot read upload index.');
        try{$this->advanceCursor($id,$u,$h);}finally{fclose($h);}
        return ['cursor'=>$u['cursor'],'uploadedBytes'=>$u['uploadedBytes'],'totalBytes'=>$u['totalBytes'],'phase'=>$phase];
    }
    /** POST /imports/{id}/batch: ordered spans of 256 KiB blocks, each authenticated by
     * its manifest SHA-256 before it is written to private staging. Stops at the first
     * rejected span, or between blocks once $budget seconds have passed since $started
     * (at least one block is always attempted). Reads only the index files. */
    public function batch(string $id,BatchUpload $body,?float $started=null,float $budget=2.0): array {
        $started??=microtime(true);
        return $this->locked(function()use($id,$body,$started,$budget){
            if($this->head($id)!=='uploading')throw new \RuntimeException('Import is not accepting uploads.');
            $u=$this->uploadIndex($id);if($u===null)throw new \RuntimeException('Import is not accepting uploads.');
            $dir=$this->dir($id);$ah=fopen($dir.'/artifacts.idx','rb');$bh=fopen($dir.'/blocks.idx','rb');
            $deadline=$started+$budget;$accepted=0;$appended=0;$blocks=0;$hit=false;$rejected=null;
            try{
                if(!$ah||!$bh)throw new \RuntimeException('Cannot read upload index.');
                foreach($body->spanList() as $k=>[$i,$o,$len]){
                    if($blocks>0&&microtime(true)>=$deadline){$hit=true;break;}
                    $e=$i<$u['artifacts']?self::entry($ah,$i):null;$path=$dir.'/artifact-'.$i.'/data';
                    try{
                        $body->next();
                        if(!$e)throw new BatchRejected('bounds');
                        if(!$e['verified'])throw new BatchRejected('unverifiable');
                        if($o%StageStore::CHUNK||$o+$len>$e['bytes']||($len%StageStore::CHUNK&&$o+$len!==$e['bytes']))throw new BatchRejected('bounds');
                        for($off=$o;$off<$o+$len;$off+=StageStore::CHUNK){
                            if($blocks>0&&microtime(true)>=$deadline){$hit=true;break 2;}
                            $data=$body->read(min(StageStore::CHUNK,$o+$len-$off));
                            fseek($bh,32*($e['first']+intdiv($off,StageStore::CHUNK)));$digest=fread($bh,32);
                            if(!is_string($digest)||strlen($digest)!==32)throw new \RuntimeException('Cannot read upload index.');
                            $grow=$this->appendBlock($path,$off,$data,bin2hex($digest));$blocks++;
                            if($grow){$appended+=$grow;if($i!==$u['cursor']['index'])$u['ahead'][$i]=$off+strlen($data);}
                        }
                        $body->end();$accepted++;
                    }catch(BatchRejected $r){$rejected=['span'=>$k,'code'=>$r->reason,'expectedOffset'=>$e?self::resumeOffset($path):null];break;}
                }
                $this->advanceCursor($id,$u,$ah);
            }finally{if($ah)fclose($ah);if($bh)fclose($bh);}
            self::atomic($dir.'/upload.json',json_encode($u,JSON_THROW_ON_ERROR));
            return ['v'=>1,'phase'=>'uploading','acceptedSpans'=>$accepted,'appendedBytes'=>$appended,'cursor'=>$u['cursor'],'uploadedBytes'=>$u['uploadedBytes'],'totalBytes'=>$u['totalBytes'],'complete'=>$u['cursor']['index']>=$u['artifacts'],'deadlineHit'=>$hit,'serverMs'=>(int)round((microtime(true)-$started)*1000),'rejected'=>$rejected];
        });
    }
    private function table(array $t): TableStage {return new TableStage($this->db,$t['name'],$t['id'],true,($t['created']??false)||($t['schemaReplaced']??false)?['name'=>$t['source'],'schema'=>$t['schema']]:null);}
    private function publication(array $s,int $index): FilePublication|ChunkedFilePublication {$class=isset($s['artifacts'][$index]['chunkSha256'])?ChunkedFilePublication::class:FilePublication::class;return new $class($this->root,$this->dir($s['id']).'/artifact-'.$index);}
    /** Repeatable invalidation occurs while requests remain paused. A failed
     * cache backend leaves recovery available and never silently reopens. */
    private function reopen(array $s): void {
        $this->fence->release($s['id'],$s['binding'],static function()use($s){
            if (($s['hasDb']||$s['selectedThemes']||$s['selectedPlugins']) && function_exists('wp_cache_flush') && wp_cache_flush()===false)
                throw new \RuntimeException('Object cache flush failed; retry finish or recovery.');
        });
    }
    private function queueMaintenance(array $s): void {RewriteRefresh::queue($this->db,$s);CachePurge::queue($this->db,$s);}
    private function afterScan(array $s): string {return $this->activation($s)?'preparing_tables':'reserving';}
    private function writeJson(string $path,array $data): void {$j=json_encode($data,JSON_THROW_ON_ERROR);if(file_put_contents($path.'.tmp',$j)!==strlen($j)||!rename($path.'.tmp',$path))throw new \RuntimeException('Cannot persist import state.');}
    /** Source user ID => destination user ID, with its own durable snapshot cursor. */
    private function authorMap(array $s): array {
        $path=$this->dir($s['id']).'/authors.json';
        return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['cursor'=>SnapshotStream::initial(),'map'=>[],'fallback'=>0];
    }
    /** Without client options: 0.3.14 sequential rules. With options: one longest-match
     * pass where each source URL maps to its destination counterpart. The source
     * siteurl (WordPress in a subdirectory of the source home) maps to the destination
     * siteurl; the source home and every other original URL map to the destination home. */
    private function rules(array $s): array {
        if(!isset($s['options'])||($s['legacyReplacements']??false))return Replacement::legacy($s['originalUrls'],$this->target);
        $siteurl=rtrim((string)$this->db->get_var($this->db->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s",'siteurl')),'/');
        $home=rtrim($s['sourceUrl'],'/');$map=[];
        foreach($s['originalUrls'] as $url){$url=rtrim($url,'/');$map[$url]??=$siteurl!==''&&str_starts_with($url,$home.'/')?$siteurl:$this->target;}
        return Replacement::rules($map,$this->target,$this->opt($s)['replacements'],$s['sourcePath']??null,$this->abspath);
    }
    private function sample(array &$s,string $table,string $column,string $before,string $after): void {
        if(count($s['samples']??[])>=20)return;
        $max=min(strlen($before),strlen($after));$p=strspn(substr($before,0,$max)^substr($after,0,$max),"\0");$start=max(0,$p-80);
        $clip=static fn($v)=>json_decode(json_encode(substr($v,$start,240),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),true);
        $s['samples'][]=['table'=>$table,'column'=>$column,'before'=>$clip($before),'after'=>$clip($after)];
    }
    /** Replace kind: stage (and fingerprint) every selected live table BEFORE the
     * snapshot is taken, so any write after that fingerprint aborts activation
     * instead of being silently overwritten by an older snapshot. */
    private function snapshot(array &$s): void {
        $this->destination();$path=$this->dir($s['id']).'/artifact-0/data';$deadline=microtime(true)+2;
        if(!($s['snapshotTables']??false)){
            $prefix=$this->db->prefix;$wanted=$this->opt($s)['tables'];
            $names=$this->db->get_col($this->db->prepare('SHOW TABLES LIKE %s',$this->db->esc_like($prefix).'%'));
            if($this->db->last_error||!is_array($names))throw new \RuntimeException('Cannot inspect database.');
            $selected=[];$others=$this->otherPrefixes();$folded=(int)$this->db->get_var('SELECT @@lower_case_table_names')!==0;
            foreach($names as $name){
                if(is_string($name)&&$folded&&strncasecmp($name,$prefix,strlen($prefix))===0)$name=$prefix.substr($name,strlen($prefix));
                if(!is_string($name)||!str_starts_with($name,$prefix)||!preg_match('/^[A-Za-z0-9_]+$/D',$name))continue;
                $suffix=substr($name,strlen($prefix));
                if($suffix===''||in_array($suffix,['users','usermeta'],true)||($wanted!==null&&!in_array($suffix,$wanted,true)))continue;
                if(self::claimed($name,$others)){if($wanted!==null)throw new \InvalidArgumentException('The table '.$suffix.' belongs to another WordPress installation in this database.');continue;}
                if(strlen($name)>48){if($wanted!==null)throw new \InvalidArgumentException('Selected table name is too long to stage: '.$suffix.'.');continue;}
                $selected[]=$name;
            }
            if($wanted!==null&&count($selected)!==count($wanted))throw new \InvalidArgumentException('A selected table does not exist.');
            if(!$selected||count($selected)>500)throw new \InvalidArgumentException('No replaceable tables selected.');
            sort($selected);$s['tables']=[];
            foreach($selected as $index=>$name)$s['tables'][]=['name'=>$name,'source'=>$name,'schema'=>null,'id'=>substr(hash('sha256',$s['id'].':'.$index),0,16),'rows'=>0,'chunks'=>0,'replacements'=>0,'created'=>false,'schemaReplaced'=>false];
            $s['snapshotTables']=true;$s['cursor']=0;$this->save($s);
        }
        while($s['cursor']<count($s['tables'])&&microtime(true)<$deadline){if($this->table($s['tables'][$s['cursor']])->prepareStep()){$s['cursor']++;}$this->save($s);}
        if($s['cursor']<count($s['tables']))return;
        clearstatcache(true,$path);
        if(!is_file($path)){
            // A hard kill can leave the exporter's private partial file behind.
            if(file_exists($path.'.partial')&&!unlink($path.'.partial'))throw new \RuntimeException('Cannot reset database snapshot.');
            // Export only the staged tables; users/usermeta are never replaced.
            DatabaseExporter::write($this->db,$path,null,40,['tables'=>array_map(fn($t)=>substr($t['name'],strlen($this->db->prefix)),$s['tables']),'excludeTransients'=>true],true);$this->save($s);return;
        }
        $h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Cannot verify database snapshot.');
        $ctx=hash_init('sha256');$chunks=[];$bytes=0;
        try{while(($data=fread($h,StageStore::CHUNK))!==false&&$data!==''){hash_update($ctx,$data);$chunks[]=hash('sha256',$data);$bytes+=strlen($data);}}finally{fclose($h);}
        clearstatcache(true,$path);if($bytes!==filesize($path))throw new \RuntimeException('Cannot verify database snapshot.');
        $s['artifacts'][0]=['bytes'=>$bytes,'sha256'=>hash_final($ctx),'chunkSha256'=>$chunks,'kind'=>'database'];
        $s['phase']='checking_artifacts';$s['cursor']=0;$this->save($s);
    }
    public function step(string $id): array {
        return $this->locked(function()use($id){
            $s=$this->read($id);
            if(in_array($s['phase'],['finishing','rollback_refusal_release'],true)){$this->queueMaintenance($s);$this->reopen($s);$s['phase']='complete';$this->save($s);}
            if(in_array($s['phase'],['complete','rolled_back','cancelled','verification_required','paused','review_required'],true))return $this->summary($s);
            if($s['phase']==='snapshotting'){$this->snapshot($s);return $this->summary($s);}
            if($s['phase']==='reusing_artifacts'){
                $previous=$this->read($s['reuseImportId']);
                if(!in_array($previous['phase'],['complete','rolled_back'],true)||$previous['artifacts']!==$s['artifacts']||($previous['cleanedUp']??false))throw new \RuntimeException('Reusable upload changed.');
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
                $s['phase']=$s['hasDb']?'scanning_database':($this->opt($s)['review']?'review_required':'reserving');$s['cursor']=0;$s['scan']=SnapshotStream::initial();$this->save($s);return $this->summary($s);
            }
            if($s['phase']==='scanning_database'){
                $o=$this->opt($s);$replace=$this->replacing($s);$deadline=microtime(true)+2;
                $others=$o['createTables']&&!$replace?$this->otherPrefixes():[];
                do{
                $batch=SnapshotStream::read($this->dir($id).'/artifact-0/data',[],$s['scan'],100,true);
                foreach($batch['records'] as $record){
                    $source=$record['table'];if(!str_starts_with($source,$s['sourcePrefix']))throw new \InvalidArgumentException('Snapshot contains an unrelated table.');
                    $suffix=substr($source,strlen($s['sourcePrefix']));
                    if(in_array($suffix,['users','usermeta'],true)){if($suffix==='users')$s['usersSchema']=$record['schema'];continue;}
                    $table=$this->db->prefix.$suffix;
                    if($replace){
                        // Only tables staged before the snapshot are replaced.
                        $k=array_search($source,array_column($s['tables'],'source'),true);if($k===false)continue;
                        $create=$this->db->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
                        if(!isset($create[1])||SnapshotStream::schema($record['schema'],$source)!==SnapshotStream::schema($create[1],$table,(int)$this->db->get_var('SELECT @@lower_case_table_names')!==0))throw new \RuntimeException('A table schema changed during the replacement snapshot.');
                        $s['tables'][$k]['schema']=$record['schema'];continue;
                    }
                    if(self::claimed($table,$others))throw new \InvalidArgumentException('The table '.$suffix.' would belong to another WordPress installation in this database; refusing to create or replace it.');
                    $create=!$o['createTables']||$this->exists($table)?$this->db->get_row("SHOW CREATE TABLE `$table`",ARRAY_N):null;
                    $created=false;$replaced=false;
                    if(!isset($create[1])||SnapshotStream::schema($record['schema'],$source)!==SnapshotStream::schema($create[1],$table,(int)$this->db->get_var('SELECT @@lower_case_table_names')!==0)){
                        if(!$o['createTables'])throw new \RuntimeException('Every imported core or plugin table requires an existing matching destination schema.');
                        try{TableStage::sourceSchema($record['schema'],$source);}catch(\InvalidArgumentException $e){throw new \InvalidArgumentException('The source schema for '.$suffix.' cannot be created safely.');}
                        if(strlen($table)>48)throw new \InvalidArgumentException('The table name '.$suffix.' is too long to stage.');
                        $created=!isset($create[1]);$replaced=!$created;
                    }
                    $index=count($s['tables']);$s['tables'][]=['name'=>$table,'source'=>$source,'schema'=>$record['schema'],'id'=>substr(hash('sha256',$id.':'.$index),0,16),'rows'=>0,'chunks'=>0,'replacements'=>0,'created'=>$created,'schemaReplaced'=>$replaced];
                }
                $s['scan']=$batch['cursor'];
                }while(!$s['scan']['done']&&microtime(true)<$deadline);
                if($s['scan']['done']){
                    if(!$o['partialDatabase']){
                        $required=array_map(fn($suffix)=>$s['sourcePrefix'].$suffix,self::TABLES);
                        if(array_diff($required,array_column($s['tables'],'source')))throw new \InvalidArgumentException('Snapshot omits required WordPress content or settings tables.');
                    }
                    if($replace&&in_array(null,array_column($s['tables'],'schema'),true))throw new \RuntimeException('The replacement snapshot omits a selected table.');
                    if(!$s['tables'])throw new \InvalidArgumentException('Snapshot contains no importable tables.');
                    $s['phase']=$o['authorMapping']==='match'&&!$replace?'mapping_authors':$this->afterScan($s);$s['cursor']=0;
                }
                $this->save($s);return $this->summary($s);
            }
            if($s['phase']==='mapping_authors'){
                $users=$s['sourcePrefix'].'users';$a=$this->authorMap($s);$deadline=microtime(true)+2;
                if(!isset($s['usersSchema']))$a['cursor']['done']=true;
                while(!$a['cursor']['done']&&microtime(true)<$deadline){
                    $batch=SnapshotStream::read($this->dir($id).'/artifact-0/data',[$users=>$s['usersSchema']],$a['cursor'],250);
                    foreach($batch['records'] as $record)if($record['type']==='row'){
                        $row=$record['row'];if(!isset($row['ID'],$row['user_login']))throw new \RuntimeException('Unsupported source users table.');
                        $match=$this->db->get_var($this->db->prepare("SELECT ID FROM `{$this->db->users}` WHERE user_login=%s",$row['user_login']));
                        if(!$match&&($row['user_email']??'')!=='')$match=$this->db->get_var($this->db->prepare("SELECT ID FROM `{$this->db->users}` WHERE user_email=%s ORDER BY ID LIMIT 1",$row['user_email']));
                        if($this->db->last_error)throw new \RuntimeException('Cannot match source authors.');
                        if($match)$a['map'][(string)$row['ID']]=(int)$match;else{unset($a['map'][(string)$row['ID']]);$a['fallback']++;}
                    }
                    $a['cursor']=$batch['cursor'];
                    // Snapshot tables are ordered; stop once the users table has passed.
                    if(isset($a['cursor']['seen'][$users])&&$a['cursor']['current']!==$users)$a['cursor']['done']=true;
                    $this->writeJson($this->dir($id).'/authors.json',$a);
                }
                if($a['cursor']['done']){$s['authors']=['matched'=>count($a['map']),'fallback'=>$a['fallback']];$s['phase']=$this->afterScan($s);$s['cursor']=0;}
                $this->save($s);return $this->summary($s);
            }
            // Activation fence: staging touches only private tables while WordPress runs.
            if($this->activation($s)&&in_array($s['phase'],['preparing_tables','reading_database','verifying_tables'],true)){$this->destination();$this->advance($s);return $this->summary($s);}
            if($s['phase']==='reserving'){
                if($this->activation($s)){
                    // Nothing live has changed yet. Check the live tables first without a
                    // fence so a mismatch cancels without ever pausing the site, then
                    // check again from scratch under the fence before any file changes.
                    if(!($s['prechecked']??false)){
                        $this->destination();
                        try{if(!$this->checkTables($s))return $this->summary($s);}catch(StageChanged $e){return $this->cancelChanged($s,$e);}
                        foreach($s['tables'] as $t)$this->table($t)->resetActivationCheck();
                        $s['prechecked']=true;$s['cursor']=0;$this->save($s);return $this->summary($s);
                    }
                    $this->fence->reserve($id,$s['binding']);
                    try{return $this->fence->exclusive($id,$s['binding'],function()use(&$s){$this->destination();if($this->checkTables($s)){$s['phase']='preparing_files';$s['cursor']=0;$this->save($s);}return $this->summary($s);});}
                    catch(StageChanged $e){$this->reopen($s);return $this->cancelChanged($s,$e);}
                }
                $this->fence->reserve($id,$s['binding']);
                $s['phase']='preparing_tables';$s['cursor']=0;$this->save($s);
            }
            return $this->fence->exclusive($id,$s['binding'],function()use(&$s){$this->destination();$this->advance($s);return $this->summary($s);});
        },$id);
    }
    /** Activation fence: prove every live table is unchanged since staging before
     * any file is replaced. activateStep reuses the fenced, completed fingerprints.
     * Returns true once every table has been checked. */
    private function checkTables(array &$s): bool {
        $deadline=microtime(true)+2;
        while($s['cursor']<count($s['tables'])){
            if($this->table($s['tables'][$s['cursor']])->activationCheckStep())$s['cursor']++;
            $this->save($s);if(microtime(true)>=$deadline)return $s['cursor']>=count($s['tables']);
        }
        return true;
    }
    /** A live table changed while staging: nothing live was replaced, so end the
     * import (no fence held) and keep the reason as its last error. */
    private function cancelChanged(array &$s,StageChanged $e): array {
        $s['phase']='cancelled';$this->save($s);$s['error']=self::safeError($e,'reserving');$this->persist($s);return $this->summary($s);
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
            $o=$this->opt($s);$rules=$this->rules($s);$replace=$this->replacing($s);
            $map=$o['authorMapping']==='match'&&!$replace?$this->authorMap($s)['map']:[];
            $keepPlugins=$o['keepActivePlugins']??!$s['selectedPlugins'];$keepTheme=$o['keepActiveTheme']??!$s['selectedThemes'];
            // A single row per cursor is independently committed and replayable.
            $deadline=microtime(true)+2.0;
            for($batchIndex=0;$batchIndex<100;$batchIndex++){
            $batch=SnapshotStream::read($dir.'/artifact-0/data',$schemas,$s['reader'],1);
            foreach($batch['records'] as $record)if($record['type']==='row'){
                foreach($s['tables'] as &$t)if($t['source']===$record['table']){
                    $row=$record['row'];$posts=$t['name']===$this->db->prefix.'posts';
                    foreach($row as $column=>&$v)if(is_string($v)&&($o['replaceGuids']||!$posts||$column!=='guid')){
                        $before=$v;$v=Replacement::apply($v,$rules,$count);
                        if($count){$t['replacements']=($t['replacements']??0)+$count;$this->sample($s,$t['name'],$column,$before,$v);}
                    }unset($v);
                    if(!$replace&&$posts)$row['post_author']=(string)($map[(string)$row['post_author']]??$s['admin']);
                    if(!$replace&&$t['name']===$this->db->prefix.'comments')$row['user_id']=(string)($map[(string)$row['user_id']]??0);
                    if($t['name']===$this->db->options&&((($row['option_name']??'')==='active_plugins'&&$keepPlugins)||(in_array($row['option_name']??'',['template','stylesheet'],true)&&$keepTheme))){
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
            if($i>=count($s['tables'])){$s['phase']=$this->activation($s)?($this->opt($s)['review']?'review_required':'reserving'):'preparing_files';$s['cursor']=0;return;}
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
            if($s['cleanedUp']??false)throw new \RuntimeException('Import data was cleaned up; rollback is unavailable.');
            // Nothing live has changed before the fence: cancel. Staged private
            // tables remain until cleanup.
            $cancel=['uploading','reusing_artifacts','checking_artifacts','scanning_database','mapping_authors','snapshotting'];
            if($this->activation($s))array_push($cancel,'preparing_tables','reading_database','verifying_tables','review_required',...(($s['prechecked']??false)?[]:['reserving']));
            if(in_array($s['phase'],$cancel,true)){$s['phase']='cancelled';$this->save($s);return $this->summary($s);}
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
            if($s['phase']==='rollback_ready'){$this->queueMaintenance($s);$this->reopen($s);$s['phase']='rolled_back';$this->save($s);}
            return $this->summary($s);
        },$id);
    }
    public function pause(string $id): array {
        return $this->locked(function()use($id){$s=$this->read($id);if($s['phase']==='paused')return $this->summary($s);if(in_array($s['phase'],['complete','rolled_back','cancelled','finishing'],true)||str_starts_with($s['phase'],'rollback'))throw new \RuntimeException('Import cannot pause.');$s['resumePhase']=$s['phase'];$s['phase']='paused';$this->save($s);return $this->summary($s);});
    }
    public function resume(string $id): array {
        return $this->locked(function()use($id){$s=$this->read($id);if($s['phase']==='paused'){$s['phase']=$s['resumePhase'];unset($s['resumePhase']);$this->save($s);}return $this->summary($s);});
    }
    /** review_required -> reserving. A retried approval after a lost response is a no-op. */
    public function approve(string $id): array {
        return $this->locked(function()use($id){
            $s=$this->read($id);
            if($s['phase']==='review_required'){$s['phase']='reserving';$s['cursor']=0;$s['approvedAt']=gmdate('c');$this->save($s);return $this->summary($s);}
            if(isset($s['approvedAt']))return $this->summary($s);
            throw new \RuntimeException('Import is not waiting for review.');
        });
    }
    public function finish(string $id): array {
        return $this->locked(function()use($id){$s=$this->read($id);if($s['phase']==='complete')return $this->summary($s);if(!in_array($s['phase'],['verification_required','finishing'],true))throw new \RuntimeException('Import is not ready for final verification.');if($s['phase']==='verification_required'){$this->fence->exclusive($id,$s['binding'],fn()=>$this->destination());$s['phase']='finishing';$this->save($s);}$this->queueMaintenance($s);$this->reopen($s);$s['phase']='complete';$this->save($s);return $this->summary($s);},$id);
    }
    /** Terminal imports only: drop this import's private tables, then artifact data
     * and file backups. state.json remains (cleanedUp) and rollback is refused. */
    public function cleanup(string $id): array {return $this->cleanupImport($id,false);}
    /** WordPress administrator (manage_options) cleanup: any credential generation,
     * still bound to this destination and still limited to terminal imports. */
    public function adminCleanup(string $id): array {return $this->cleanupImport($id,true);}
    private function cleanupImport(string $id,bool $anyOwner): array {
        return $this->locked(function()use($id,$anyOwner){
            $s=$this->read($id,$anyOwner);
            if($s['cleanedUp']??false)return $this->summary($s);
            if(!in_array($s['phase'],self::TERMINAL,true))throw new \RuntimeException('Only complete, rolled back or cancelled imports can be cleaned up.');
            foreach(glob($this->private.'/import-*/state.json')?:[] as $path){$other=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(($other['reuseImportId']??null)===$id&&!in_array($other['phase'],self::TERMINAL,true))throw new \RuntimeException('Another import is reusing these uploads.');}
            $deadline=microtime(true)+2;
            while(($s['cleanupCursor']??0)<count($s['tables'])){
                $this->table($s['tables'][$s['cleanupCursor']??0])->cleanup();$s['cleanupCursor']=($s['cleanupCursor']??0)+1;$this->save($s);
                if(microtime(true)>=$deadline)return $this->summary($s);
            }
            $dir=$this->dir($id);
            foreach([...glob($dir.'/artifact-*',GLOB_ONLYDIR)?:[],$dir.'/authors.json',...array_merge(...array_map(fn($f)=>[$dir.'/'.$f,$dir.'/'.$f.'.tmp'],['artifacts.idx','blocks.idx','upload.json']))] as $path)self::remove($path);
            $s['cleanedUp']=true;unset($s['cleanupCursor']);$this->save($s);return $this->summary($s);
        },$id);
    }
    private static function remove(string $path): void {
        if(is_link($path)||is_file($path)){if(!unlink($path))throw new \RuntimeException('Cannot remove import data.');return;}
        if(!is_dir($path))return;
        $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($walk as $entry)if(!($entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname())))throw new \RuntimeException('Cannot remove import data.');
        if(!rmdir($path))throw new \RuntimeException('Cannot remove import data.');
    }
}
