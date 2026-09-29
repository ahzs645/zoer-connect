<?php
namespace ZoerConnect;
require_once __DIR__.'/RewriteRefresh.php';
require_once __DIR__.'/CachePurge.php';

/** Row staging for an explicitly selected existing InnoDB table.
 * Step methods require the caller to fence writers across requests. Synchronous
 * compatibility methods are for internal CLI use, never an HTTP orchestration loop.
 * With a validated source schema the stage uses that schema (schema replacement),
 * or creates a table missing from the destination (ledger marker -4 'created').
 */
final class TableStage {
    private $db;
    private string $live;
    private string $stage;
    private string $backup;
    private string $ledger;
    private string $lock;
    private ?string $create=null;
    public function __construct($db, string $table, string $id, bool $preserveOptions = false, ?array $source = null) {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id) || !preg_match('/^[A-Za-z0-9_]{1,48}$/D', $table) || !str_starts_with($table, $db->prefix)) throw new \InvalidArgumentException('Invalid staging identity.');
        if (in_array($table, [$db->users, $db->usermeta], true)) throw new \InvalidArgumentException('Identity tables require a preservation policy.');
        if ($table === $db->options && !$preserveOptions) throw new \InvalidArgumentException('Options preservation must be explicit.');
        $this->db=$db; $this->live=$table;
        $this->stage='zoer_s_'.$id; $this->backup='zoer_b_'.$id; $this->ledger='zoer_l_'.$id;
        $this->lock='zoer:'.$db->dbname.':'.$table;
        if($source!==null){
            if(!is_string($source['name']??null)||!is_string($source['schema']??null))throw new \InvalidArgumentException('Invalid source schema.');
            $sql=self::sourceSchema($source['schema'],$source['name']);
            $this->create='CREATE TABLE `'.$this->stage.'` ('.substr($sql,strlen('CREATE TABLE `'.$source['name'].'` ('));
        }
    }
    /** Strict single-statement CREATE validation. The source DDL is executed only
     * after this check and with the table name rewritten to the private stage.
     */
    public static function sourceSchema(string $sql, string $table): string {
        $head='CREATE TABLE `'.$table.'` (';
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)||strlen($sql)>65536||!str_starts_with($sql,$head)||preg_match('/[\x00-\x09\x0b-\x1f\x7f]/',$sql))throw new \InvalidArgumentException('Unsupported source table schema.');
        foreach([';','\\`','--','/*','#'] as $bad)if(str_contains($sql,$bad))throw new \InvalidArgumentException('Unsupported source table schema.');
        // Keywords are checked outside quoted identifiers and string literals so
        // that quoting cannot hide clauses; an unterminated quote is refused.
        $code='';$n=strlen($sql);
        for($i=0;$i<$n;$i++){
            $c=$sql[$i];
            if($c!=='`'&&$c!=="'"&&$c!=='"'){$code.=$c;continue;}
            for($i++;;$i++){
                if($i>=$n)throw new \InvalidArgumentException('Unsupported source table schema.');
                if($sql[$i]==='\\'&&$c!=='`'){$i++;continue;}
                if($sql[$i]===$c){if(($sql[$i+1]??'')===$c){$i++;continue;}break;}
            }
            $code.=$c.$c;
        }
        // Exactly one column list, closed by InnoDB and only known table options.
        if(substr_count($code,') ENGINE=')!==1||!preg_match("/\\) ENGINE=InnoDB(?: (?:DEFAULT )?(?:AUTO_INCREMENT|CHARSET|CHARACTER SET|COLLATE|ROW_FORMAT|COMMENT|PAGE_CHECKSUM|STATS_PERSISTENT|STATS_AUTO_RECALC|STATS_SAMPLE_PAGES|KEY_BLOCK_SIZE|PACK_KEYS|CHECKSUM|DELAY_KEY_WRITE)=(?:\\w+|''))*$/D",$code)||!preg_match('/\bPRIMARY KEY \(/i',$code))throw new \InvalidArgumentException('Source table schema requires InnoDB and a primary key.');
        if(preg_match('/\b(?:FOREIGN\s+KEY|REFERENCES|DATA\s+DIRECTORY|INDEX\s+DIRECTORY|CONNECTION|UNION|PARTITION|SELECT|LOAD_FILE|SLEEP|BENCHMARK|TABLESPACE|ENCRYPTION|GENERATED|VIRTUAL|STORED|INSERT_METHOD|TEMPORARY)\b|\bAS\s*\(|\bDEFAULT\s*\(|@/i',$code))throw new \InvalidArgumentException('Source table schema contains an unsupported clause.');
        return $sql;
    }
    private function created(): bool { return $this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-4")==='created'; }
    private function q(string $sql) { $r=$this->db->query($sql); if($r===false)throw new \RuntimeException('Database operation failed.'); return $r; }
    private function exists(string $table): bool { return $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)))===$table; }
    /** Regenerated caches and WordPress's untouched editor placeholder are not
     * published content. Saved drafts, autosaves, and any authored field remain
     * guarded. Post metadata is independently verified by the global preflight.
     */
    private function ephemeral(array $row): bool {
        if($this->live===$this->db->options)return in_array($row['option_name']??null,['cron','rewrite_rules',RewriteRefresh::OPTION,CachePurge::OPTION],true) || str_starts_with((string)($row['option_name']??''),'_transient_') || str_starts_with((string)($row['option_name']??''),'_site_transient_');
        if($this->live!==$this->db->prefix.'posts' || ($row['post_type']??null)!=='post' || ($row['post_status']??null)!=='auto-draft' || !in_array($row['post_title']??null,['','Auto Draft'],true))return false;
        foreach(['post_content','post_excerpt','post_content_filtered','post_name','post_password','post_mime_type','to_ping','pinged'] as $field)if(($row[$field]??null)!=='')return false;
        foreach(['post_parent','menu_order','comment_count'] as $field)if(!in_array($row[$field]??null,[0,'0'],true))return false;
        return true;
    }
    /** One durable keyset page. Caller holds the writer fence across every step.
     * A chain hash is serializable across PHP requests; PHP hash contexts are not.
     */
    private function fingerprintStep(string $table, int $task): ?array {
        $state=$this->db->get_row($this->db->prepare("SELECT digest,row_count,progress_json FROM `{$this->ledger}` WHERE sequence_id=%d",$task),ARRAY_A);
        if(!$state){
            $state=['digest'=>str_repeat('0',64),'row_count'=>0,'progress_json'=>null];
            $this->q($this->db->prepare("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase,progress_json) VALUES (%d,%s,0,'fingerprint',NULL)",$task,$state['digest']));
        }
        $cursor=$state['progress_json']===null?null:json_decode($state['progress_json'],true,512,JSON_THROW_ON_ERROR);
        if($cursor && ($cursor['done']??false))return ['digest'=>$state['digest'],'rows'=>(int)$state['row_count']];
        $keys=$this->db->get_results("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'",ARRAY_A);
        if(!$keys)throw new \RuntimeException('A primary key is required for content verification.');
        usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $columns=[];
        foreach($keys as $key){
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$key['Column_name']))throw new \RuntimeException('Unsupported primary key.');
            $columns[]=$key['Column_name'];
        }
        $where='';
        if($cursor){
            $terms=[];$equal=[];
            foreach($columns as $column){
                $value=base64_decode($cursor['keys'][$column]??'',true);
                if($value===false)throw new \RuntimeException('Invalid verification cursor.');
                $terms[]=implode(' AND ',[...$equal,$this->db->prepare("`$column` > %s",$value)]);
                $equal[]=$this->db->prepare("`$column` = %s",$value);
            }
            $where=' WHERE ('.implode(') OR (',$terms).')';
        }
        $order='`'.implode('`,`',$columns).'`';
        // Bound bytes as well as rows before transferring payloads into PHP.
        // Total table size is unlimited; an individual imported row is already
        // constrained by the 4 MiB staging-batch limit.
        $fields=$this->db->get_col("SHOW COLUMNS FROM `$table`");
        if(!$fields)throw new \RuntimeException('Content columns are unavailable.');
        $sizes=[];
        foreach($fields as $field){
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$field))throw new \RuntimeException('Unsupported content column.');
            $sizes[]="COALESCE(OCTET_LENGTH(`$field`),0)";
        }
        $lengths=$this->db->get_col("SELECT (".implode('+',$sizes).") FROM `$table`$where ORDER BY $order LIMIT 50");
        if($this->db->last_error || !is_array($lengths))throw new \RuntimeException('Content size verification failed.');
        $pageLimit=0;$pageBytes=0;
        foreach($lengths as $length){
            if((int)$length>4194304)throw new \RuntimeException('A destination row exceeds the 4 MiB verification limit.');
            if($pageBytes+(int)$length>4194304)break;
            $pageBytes+=(int)$length;$pageLimit++;
        }
        $pageLimit=max(1,$pageLimit);
        // Never OFFSET into a large table or scan the entire table in one PHP request.
        $rows=$this->db->get_results("SELECT * FROM `$table`$where ORDER BY $order LIMIT $pageLimit",ARRAY_A);
        if($this->db->last_error || !is_array($rows))throw new \RuntimeException('Content verification failed.');
        $digest=$state['digest'];
        foreach($rows as $row){
            // Still count every row so upload row-count validation remains exact.
            if($this->ephemeral($row))continue;
            $encoded=serialize($row);$digest=hash('sha256',$digest.strlen($encoded).':'.$encoded);
        }
        $next=['done'=>count($rows)<$pageLimit || count($lengths)<50 && count($rows)===count($lengths),'keys'=>$cursor['keys']??[]];
        if($rows)foreach($columns as $column)$next['keys'][$column]=base64_encode((string)$rows[count($rows)-1][$column]);
        $count=(int)$state['row_count']+count($rows);
        $this->q($this->db->prepare("UPDATE `{$this->ledger}` SET digest=%s,row_count=%d,progress_json=%s WHERE sequence_id=%d",$digest,$count,json_encode($next,JSON_THROW_ON_ERROR),$task));
        return $next['done']?['digest'=>$digest,'rows'=>$count]:null;
    }
    private function clearFingerprints(array $tasks): void {
        foreach($tasks as $task)$this->q($this->db->prepare("DELETE FROM `{$this->ledger}` WHERE sequence_id=%d",$task));
    }
    private function locked(callable $fn) {
        if((string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, 1)', $this->lock))!=='1')throw new \RuntimeException('Table is busy.');
        try{return $fn();}finally{$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$this->lock));}
    }
    public function prepare(): void { while(!$this->prepareStep()){} }
    public function prepareStep(): bool {
        return $this->locked(function() {
            if($this->exists($this->ledger)){
                $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
                if($phase===null && !$this->exists($this->stage) && !$this->exists($this->backup)) {
                    $missing=$this->create!==null&&!$this->exists($this->live);
                    $this->q("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-1, '', 0, 'preparing')".($missing?",(-4, '', 0, 'created')":''));
                    $phase='preparing';
                }
                if(in_array($phase,['uploading','verifying','preserved','verified','swapping','activated'],true))return true;
                if($phase!=='preparing')throw new \RuntimeException('Unexpected preparation phase.');
            } elseif($this->create!==null&&!$this->exists($this->live)) {
            if($this->exists($this->stage)||$this->exists($this->backup))throw new \RuntimeException('Unexpected table state.');
            // Missing destination table: the marker and lifecycle row commit together.
            $this->q("CREATE TABLE `{$this->ledger}` (sequence_id BIGINT PRIMARY KEY, digest CHAR(64) NOT NULL, row_count BIGINT NOT NULL, phase VARCHAR(24) NOT NULL, progress_json LONGTEXT NULL) ENGINE=InnoDB");
            $this->q("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-1, '', 0, 'preparing'),(-4, '', 0, 'created')");
            } else {
            if(!$this->exists($this->live)||$this->exists($this->stage)||$this->exists($this->backup)||$this->exists($this->ledger))throw new \RuntimeException('Unexpected table state.');
            $engine=$this->db->get_var($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$this->live));
            if($engine!=='InnoDB')throw new \RuntimeException('InnoDB is required.');
            // LIKE does not preserve triggers or foreign keys: explicitly refuse them.
            $constraints=$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME=%s OR REFERENCED_TABLE_NAME=%s) AND REFERENCED_TABLE_NAME IS NOT NULL",$this->live,$this->live));
            $triggers=$this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=%s',$this->live));
            if((int)$constraints||(int)$triggers)throw new \RuntimeException('Foreign keys and triggers are unsupported.');
            $this->q("CREATE TABLE `{$this->ledger}` (sequence_id BIGINT PRIMARY KEY, digest CHAR(64) NOT NULL, row_count BIGINT NOT NULL, phase VARCHAR(24) NOT NULL, progress_json LONGTEXT NULL) ENGINE=InnoDB");
            $this->q("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-1, '', 0, 'preparing')");
            }
            if(!$this->exists($this->stage))$this->q($this->create??"CREATE TABLE `{$this->stage}` LIKE `{$this->live}`");
            if($this->created()){
                if($this->exists($this->live))throw new \RuntimeException('Destination changed since preparation.');
                $this->q("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-2, '', 0, 'absent') ON DUPLICATE KEY UPDATE phase=VALUES(phase)");
                $this->q("UPDATE `{$this->ledger}` SET phase='uploading' WHERE sequence_id=-1");
                return true;
            }
            $original=$this->fingerprintStep($this->live,-10);
            if($original===null)return false;
            $this->q($this->db->prepare("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-2, %s, %d, 'original') ON DUPLICATE KEY UPDATE digest=VALUES(digest),row_count=VALUES(row_count)",$original['digest'],$original['rows']));
            $this->q("UPDATE `{$this->ledger}` SET phase='uploading' WHERE sequence_id=-1");
            return true;
        });
    }
    public function chunk(int $sequence, array $rows): void {
        if($sequence<0||count($rows)>250||!array_is_list($rows)||!$rows||strlen(serialize($rows))>4194304)throw new \InvalidArgumentException('Invalid row batch.');
        $this->locked(function()use($sequence,$rows){
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase!=='uploading')throw new \RuntimeException('Stage is not writable.');
            $columns=$this->db->get_col("SHOW COLUMNS FROM `{$this->stage}`");
            foreach($rows as $row){
                if(!is_array($row)||array_diff(array_keys($row),$columns)||count($row)!==count($columns))throw new \InvalidArgumentException('Column mismatch.');
                foreach($row as $value)if(!is_null($value)&&!is_string($value)&&!is_int($value)&&!is_float($value))throw new \InvalidArgumentException('Unsupported cell value.');
            }
            $digest=hash('sha256',serialize($rows));
            $old=$this->db->get_var($this->db->prepare("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=%d",$sequence));
            if($old!==null){if(!hash_equals($old,$digest))throw new \InvalidArgumentException('Conflicting retry.');return;}
            $next=(int)$this->db->get_var("SELECT COALESCE(MAX(sequence_id),-1)+1 FROM `{$this->ledger}`");
            if($sequence!==$next)throw new \InvalidArgumentException('Sequence gap.');
            // Snapshot SQL is parsed, not executed. Preserve its zero-valued
            // AUTO_INCREMENT keys explicitly instead of silently allocating IDs.
            $sqlMode=$this->db->get_var('SELECT @@SESSION.sql_mode');
            if(!is_string($sqlMode))throw new \RuntimeException('Cannot verify database SQL mode.');
            $changeMode=!in_array('NO_AUTO_VALUE_ON_ZERO',explode(',',$sqlMode),true);
            if($changeMode)$this->q($this->db->prepare('SET SESSION sql_mode=%s',ltrim($sqlMode.',NO_AUTO_VALUE_ON_ZERO',',')));
            try{
                $this->q('START TRANSACTION');
                try{
                    foreach($rows as $row)if($this->db->insert($this->stage,$row)===false)throw new \RuntimeException('Row insertion failed.');
                    $this->q($this->db->prepare("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (%d,%s,%d,'chunk')",$sequence,$digest,count($rows)));
                    $this->q('COMMIT');
                }catch(\Throwable $e){$this->q('ROLLBACK');throw $e;}
            }finally{if($changeMode)$this->q($this->db->prepare('SET SESSION sql_mode=%s',$sqlMode));}
        });
    }
    public function verify(int $rows, int $chunks): void { while(!$this->verifyStep($rows,$chunks)){} }
    public function verifyStep(int $rows, int $chunks): bool {
        return $this->locked(function()use($rows,$chunks){
            if($rows<0||$chunks<0)throw new \InvalidArgumentException('Invalid expected counts.');
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase==='verified')return true;
            if(!in_array($phase,['uploading','verifying','preserved'],true))throw new \RuntimeException('Unexpected phase.');
            $expected=hash('sha256',"$rows:$chunks");
            if($phase==='uploading'){
                $counts=$this->db->get_row("SELECT COUNT(*) AS batches,COALESCE(SUM(row_count),0) AS rows_total FROM `{$this->ledger}` WHERE sequence_id>=0",ARRAY_A);
                if((int)$counts['batches']!==$chunks || (int)$counts['rows_total']!==$rows)throw new \RuntimeException('Count verification failed.');
                $this->q('START TRANSACTION');
                try{
                    $this->q($this->db->prepare("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-3,%s,%d,'expected')",$expected,$rows));
                    $this->q("UPDATE `{$this->ledger}` SET phase='verifying' WHERE sequence_id=-1");
                    $this->q('COMMIT');
                }catch(\Throwable $e){$this->q('ROLLBACK');throw $e;}
                $phase='verifying';
            }
            if($this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-3")!==$expected)throw new \RuntimeException('Expected counts changed.');
            if($phase==='verifying'){
                $content=$this->fingerprintStep($this->stage,-11);
                if($content===null)return false;
                if($content['rows']!==$rows){$this->clearFingerprints([-11]);throw new \RuntimeException('Count verification failed.');}
                if($this->live!==$this->db->options){
                    $this->q($this->db->prepare("UPDATE `{$this->ledger}` SET phase='verified',digest=%s WHERE sequence_id=-1",$content['digest']));
                    return true;
                }
                // Preservation and its durable phase marker commit together so a
                // lost response cannot apply it twice (auto-increment IDs matter).
                $this->q('START TRANSACTION');
                try{
                    require_once __DIR__.'/RewriteRefresh.php';
                    require_once __DIR__.'/SettingsPreservation.php';
                    SettingsPreservation::apply($this->db,$this->stage);
                    $this->q("UPDATE `{$this->ledger}` SET phase='preserved' WHERE sequence_id=-1");
                    $this->q('COMMIT');
                }catch(\Throwable $e){$this->q('ROLLBACK');throw $e;}
                return false;
            }
            $content=$this->fingerprintStep($this->stage,-16);
            if($content===null)return false;
            $this->q($this->db->prepare("UPDATE `{$this->ledger}` SET phase='verified',digest=%s WHERE sequence_id=-1",$content['digest']));
            return true;
        });
    }
    /** Under the caller's writer fence, prove the stage and the live table still
     * match their staging fingerprints before any file is replaced. activateStep
     * reuses these completed fingerprints under the same fence.
     */
    public function activationCheckStep(): bool {
        return $this->locked(function(){
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase==='activated')return true;
            if($phase!=='verified')throw new \RuntimeException('Not verified.');
            $content=$this->fingerprintStep($this->stage,-12);
            if($content===null)return false;
            if($content['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-1")){$this->clearFingerprints([-12,-13]);throw new \RuntimeException('Verified stage changed.');}
            if($this->created()){if($this->exists($this->live))throw new \RuntimeException('Destination changed since preparation.');return true;}
            $original=$this->fingerprintStep($this->live,-13);
            if($original===null)return false;
            if($original['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-2")){$this->clearFingerprints([-12,-13]);throw new \RuntimeException('Destination changed since preparation.');}
            return true;
        });
    }
    /** Caller must quiesce application writes before invoking this internal primitive. */
    public function activate(): void { while(!$this->activateStep()){} }
    public function activateStep(): bool {
        return $this->locked(function(){
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase==='activated')return true;
            if(!in_array($phase,['verified','swapping'],true))throw new \RuntimeException('Not verified.');
            if($this->created()){
                if($this->exists($this->stage)&&!$this->exists($this->live)){
                    $content=$this->fingerprintStep($this->stage,-12);
                    if($content===null)return false;
                    if($content['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-1")){$this->clearFingerprints([-12]);throw new \RuntimeException('Verified stage changed.');}
                    $this->q("UPDATE `{$this->ledger}` SET phase='swapping' WHERE sequence_id=-1");
                    $this->q("RENAME TABLE `{$this->stage}` TO `{$this->live}`");
                }elseif($phase!=='swapping'||$this->exists($this->stage)||!$this->exists($this->live))throw new \RuntimeException($this->exists($this->live)?'Destination changed since preparation.':'Ambiguous cutover.');
                $this->q("UPDATE `{$this->ledger}` SET phase='activated' WHERE sequence_id=-1");
                return true;
            }
            if($this->exists($this->stage)&&!$this->exists($this->backup)){
                $content=$this->fingerprintStep($this->stage,-12);
                if($content===null)return false;
                if($content['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-1")){$this->clearFingerprints([-12,-13]);throw new \RuntimeException('Verified stage changed.');}
                $original=$this->fingerprintStep($this->live,-13);
                if($original===null)return false;
                if($original['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-2")){$this->clearFingerprints([-12,-13]);throw new \RuntimeException('Destination changed since preparation.');}
            }
            $this->q("UPDATE `{$this->ledger}` SET phase='swapping' WHERE sequence_id=-1");
            if($this->exists($this->stage)&&!$this->exists($this->backup))$this->q("RENAME TABLE `{$this->live}` TO `{$this->backup}`, `{$this->stage}` TO `{$this->live}`");
            elseif(!$this->exists($this->backup)||$this->exists($this->stage)||!$this->exists($this->live))throw new \RuntimeException('Ambiguous cutover.');
            $this->q("UPDATE `{$this->ledger}` SET phase='activated' WHERE sequence_id=-1");
            return true;
        });
    }
    /** Reset when starting a new rollback fence/preflight attempt. Never between
     * a successful global preflight and its swaps under the same writer fence.
     */
    public function resetRollbackPreflight(): void {
        $this->locked(function(){if($this->exists($this->ledger))$this->clearFingerprints([-14,-15]);});
    }
    /** No table rename or lifecycle phase mutation: orchestrators must check all
     * tables/files before restoring the first one. Fingerprint cursors are durable.
     */
    public function rollbackCheckStep(): bool {return $this->locked(fn()=>$this->rollbackReadyStep());}
    public function rollbackPreflightStep(): bool {return $this->rollbackCheckStep();}
    private function rollbackReadyStep(): bool {
        if(!$this->exists($this->ledger)){
            if($this->exists($this->backup))throw new \RuntimeException('Ambiguous unrecorded cutover.');
            return true;
        }
        $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
        if(($phase===null || in_array($phase,['preparing','uploading','verifying','preserved','verified'],true)) && !$this->exists($this->backup))return true;
        if($phase==='restored')return true;
        if($phase==='swapping' && $this->exists($this->stage) && !$this->exists($this->backup))return true;
        if(!in_array($phase,['activated','swapping','restoring'],true))throw new \RuntimeException('Not activated.');
        if($this->created()){
            // A created table is removed from service by renaming it back to its
            // private stage name, only when nobody edited it after publication.
            if($this->exists($this->live)&&!$this->exists($this->stage)&&!$this->exists($this->backup)){
                $content=$this->fingerprintStep($this->live,-14);
                if($content===null)return false;
                if($content['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-1")){$this->clearFingerprints([-14]);throw new \RuntimeException('Destination edited after publication; refusing rollback overwrite.');}
                return true;
            }
            if($phase==='restoring'&&!$this->exists($this->live)&&$this->exists($this->stage))return true;
            throw new \RuntimeException('Ambiguous restore.');
        }
        if($this->exists($this->backup)&&!$this->exists($this->stage)&&$this->exists($this->live)){
            $content=$this->fingerprintStep($this->live,-14);
            if($content===null)return false;
            if($content['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-1")){$this->clearFingerprints([-14,-15]);throw new \RuntimeException('Destination edited after publication; refusing rollback overwrite.');}
            $original=$this->fingerprintStep($this->backup,-15);
            if($original===null)return false;
            if($original['digest']!==$this->db->get_var("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=-2")){$this->clearFingerprints([-14,-15]);throw new \RuntimeException('Backup content changed.');}
            return true;
        }
        if($phase==='restoring' && !$this->exists($this->backup)&&$this->exists($this->stage)&&$this->exists($this->live))return true;
        throw new \RuntimeException('Ambiguous restore.');
    }
    /** Internal rollback. Caller must prevent and account for post-cutover writes. */
    public function rollback(): void { while(!$this->rollbackStep()){} }
    public function rollbackStep(): bool {
        return $this->locked(function(){
            if(!$this->rollbackReadyStep())return false;
            // Orchestration records table ownership before the first prepare
            // request, so rollback must also accept a crash before ledger creation.
            if(!$this->exists($this->ledger)){
                if($this->exists($this->backup))throw new \RuntimeException('Ambiguous unrecorded cutover.');
                return true;
            }
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase===null && !$this->exists($this->backup)){
                $this->q("INSERT INTO `{$this->ledger}` (sequence_id,digest,row_count,phase) VALUES (-1,'',0,'restored')");
                return true;
            }
            if($phase==='restored')return true;
            if(in_array($phase,['preparing','uploading','verifying','preserved','verified'],true) || ($phase==='swapping' && $this->exists($this->stage) && !$this->exists($this->backup))) {
                $this->q("UPDATE `{$this->ledger}` SET phase='restored' WHERE sequence_id=-1"); return true;
            }
            if($phase==='swapping' && $this->exists($this->backup) && !$this->exists($this->stage))$phase='activated';
            if($this->created()){
                if($phase==='swapping' && !$this->exists($this->stage) && $this->exists($this->live))$phase='activated';
                if(!in_array($phase,['activated','restoring'],true))throw new \RuntimeException('Not activated.');
                $this->q("UPDATE `{$this->ledger}` SET phase='restoring' WHERE sequence_id=-1");
                if($this->exists($this->live)&&!$this->exists($this->stage))$this->q("RENAME TABLE `{$this->live}` TO `{$this->stage}`");
                elseif($this->exists($this->live)||!$this->exists($this->stage))throw new \RuntimeException('Ambiguous restore.');
                $this->q("UPDATE `{$this->ledger}` SET phase='restored' WHERE sequence_id=-1");
                return true;
            }
            if(!in_array($phase,['activated','restoring'],true))throw new \RuntimeException('Not activated.');
            $this->q("UPDATE `{$this->ledger}` SET phase='restoring' WHERE sequence_id=-1");
            if($this->exists($this->backup)&&!$this->exists($this->stage)){
                if($this->live===$this->db->options){
                    // Cron is destination runtime state, not migrated content.
                    // Preserve the CURRENT queue, including absence, in the old
                    // table immediately before restore. Repeating after a crash
                    // before RENAME copies the same fenced queue safely.
                    $this->q('START TRANSACTION');
                    try{
                        $this->q("DELETE FROM `{$this->backup}` WHERE option_name='cron'");
                        $this->q("INSERT INTO `{$this->backup}` (option_name,option_value,autoload) SELECT option_name,option_value,autoload FROM `{$this->live}` WHERE option_name='cron'");
                        $this->q('COMMIT');
                    }catch(\Throwable $e){$this->q('ROLLBACK');throw $e;}
                }
                $this->q("RENAME TABLE `{$this->live}` TO `{$this->stage}`, `{$this->backup}` TO `{$this->live}`");
            }
            elseif($this->exists($this->backup)||!$this->exists($this->stage)||!$this->exists($this->live))throw new \RuntimeException('Ambiguous restore.');
            $this->q("UPDATE `{$this->ledger}` SET phase='restored' WHERE sequence_id=-1");
            return true;
        });
    }
    /** Drop this stage's private tables once the lifecycle is terminal: activated
     * (caller finished the import), restored, or never activated. Never the live
     * table. The ledger is dropped last so an interrupted cleanup can repeat.
     */
    public function cleanup(): void {
        $this->locked(function(){
            if($this->exists($this->ledger)){
                $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
                $never=($phase===null||in_array($phase,['preparing','uploading','verifying','preserved','verified'],true))&&!$this->exists($this->backup);
                if(!$never&&!in_array($phase,['activated','restored'],true))throw new \RuntimeException('Table cleanup requires a finished or restored import.');
                if($phase==='activated'&&$this->exists($this->stage))throw new \RuntimeException('Ambiguous cleanup.');
            }elseif($this->exists($this->backup))throw new \RuntimeException('Ambiguous unrecorded cutover.');
            $this->q("DROP TABLE IF EXISTS `{$this->stage}`");
            $this->q("DROP TABLE IF EXISTS `{$this->backup}`");
            $this->q("DROP TABLE IF EXISTS `{$this->ledger}`");
        });
    }
}
