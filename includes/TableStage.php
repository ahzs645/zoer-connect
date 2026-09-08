<?php
namespace ZoerConnect;

/** Experimental internal row staging for an explicitly selected existing InnoDB table.
 * No arbitrary SQL ingestion, public route, options/users migration, or live-site orchestration.
 */
final class TableStage {
    private $db;
    private string $live;
    private string $stage;
    private string $backup;
    private string $ledger;
    private string $lock;
    public function __construct($db, string $table, string $id) {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id) || !preg_match('/^[A-Za-z0-9_]{1,48}$/D', $table) || !str_starts_with($table, $db->prefix)) throw new \InvalidArgumentException('Invalid staging identity.');
        if (in_array($table, [$db->options, $db->users, $db->usermeta], true)) throw new \InvalidArgumentException('Identity tables require a preservation policy.');
        $this->db=$db; $this->live=$table;
        $this->stage='zoer_s_'.$id; $this->backup='zoer_b_'.$id; $this->ledger='zoer_l_'.$id;
        $this->lock='zoer:'.$db->dbname.':'.$table;
    }
    private function q(string $sql) { $r=$this->db->query($sql); if($r===false)throw new \RuntimeException('Database operation failed.'); return $r; }
    private function exists(string $table): bool { return $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)))===$table; }
    private function locked(callable $fn) {
        if((string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, 1)', $this->lock))!=='1')throw new \RuntimeException('Table is busy.');
        try{return $fn();}finally{$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$this->lock));}
    }
    public function prepare(): void {
        $this->locked(function() {
            if(!$this->exists($this->live)||$this->exists($this->stage)||$this->exists($this->backup)||$this->exists($this->ledger))throw new \RuntimeException('Unexpected table state.');
            $engine=$this->db->get_var($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$this->live));
            if($engine!=='InnoDB')throw new \RuntimeException('InnoDB is required.');
            // LIKE does not preserve triggers or foreign keys: explicitly refuse them.
            $constraints=$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME=%s OR REFERENCED_TABLE_NAME=%s) AND REFERENCED_TABLE_NAME IS NOT NULL",$this->live,$this->live));
            $triggers=$this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=%s',$this->live));
            if((int)$constraints||(int)$triggers)throw new \RuntimeException('Foreign keys and triggers are unsupported.');
            $this->q("CREATE TABLE `{$this->stage}` LIKE `{$this->live}`");
            $this->q("CREATE TABLE `{$this->ledger}` (sequence_id BIGINT PRIMARY KEY, digest CHAR(64) NOT NULL, row_count BIGINT NOT NULL, phase VARCHAR(24) NOT NULL) ENGINE=InnoDB");
            $this->q("INSERT INTO `{$this->ledger}` VALUES (-1, '', 0, 'uploading')");
        });
    }
    public function chunk(int $sequence, array $rows): void {
        if($sequence<0||count($rows)>250||!array_is_list($rows)||!$rows||strlen(json_encode($rows,JSON_THROW_ON_ERROR))>262144)throw new \InvalidArgumentException('Invalid row batch.');
        $this->locked(function()use($sequence,$rows){
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase!=='uploading')throw new \RuntimeException('Stage is not writable.');
            $columns=$this->db->get_col("SHOW COLUMNS FROM `{$this->stage}`");
            foreach($rows as $row){
                if(!is_array($row)||array_diff(array_keys($row),$columns)||count($row)!==count($columns))throw new \InvalidArgumentException('Column mismatch.');
                foreach($row as $value)if(!is_null($value)&&!is_string($value)&&!is_int($value)&&!is_float($value))throw new \InvalidArgumentException('Unsupported cell value.');
            }
            $digest=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
            $old=$this->db->get_var($this->db->prepare("SELECT digest FROM `{$this->ledger}` WHERE sequence_id=%d",$sequence));
            if($old!==null){if(!hash_equals($old,$digest))throw new \InvalidArgumentException('Conflicting retry.');return;}
            $next=(int)$this->db->get_var("SELECT COALESCE(MAX(sequence_id),-1)+1 FROM `{$this->ledger}`");
            if($sequence!==$next)throw new \InvalidArgumentException('Sequence gap.');
            $this->q('START TRANSACTION');
            try{
                foreach($rows as $row)if($this->db->insert($this->stage,$row)===false)throw new \RuntimeException('Row insertion failed.');
                $this->q($this->db->prepare("INSERT INTO `{$this->ledger}` VALUES (%d,%s,%d,'chunk')",$sequence,$digest,count($rows)));
                $this->q('COMMIT');
            }catch(\Throwable $e){$this->q('ROLLBACK');throw $e;}
        });
    }
    public function verify(int $rows, int $chunks): void {
        $this->locked(function()use($rows,$chunks){
            if($rows<0||$chunks<0)throw new \InvalidArgumentException('Invalid expected counts.');
            if($this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1")!=='uploading')throw new \RuntimeException('Unexpected phase.');
            $count=(int)$this->db->get_var("SELECT COUNT(*) FROM `{$this->stage}`");
            $batches=(int)$this->db->get_var("SELECT COUNT(*) FROM `{$this->ledger}` WHERE sequence_id>=0");
            if($count!==$rows||$batches!==$chunks)throw new \RuntimeException('Count verification failed.');
            $this->q("UPDATE `{$this->ledger}` SET phase='verified' WHERE sequence_id=-1");
        });
    }
    /** Caller must quiesce application writes before invoking this internal primitive. */
    public function activate(): void {
        $this->locked(function(){
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase==='activated')return;
            if(!in_array($phase,['verified','swapping'],true))throw new \RuntimeException('Not verified.');
            $this->q("UPDATE `{$this->ledger}` SET phase='swapping' WHERE sequence_id=-1");
            if($this->exists($this->stage)&&!$this->exists($this->backup))$this->q("RENAME TABLE `{$this->live}` TO `{$this->backup}`, `{$this->stage}` TO `{$this->live}`");
            elseif(!$this->exists($this->backup)||$this->exists($this->stage)||!$this->exists($this->live))throw new \RuntimeException('Ambiguous cutover.');
            $this->q("UPDATE `{$this->ledger}` SET phase='activated' WHERE sequence_id=-1");
        });
    }
    /** Internal rollback. Caller must prevent and account for post-cutover writes. */
    public function rollback(): void {
        $this->locked(function(){
            $phase=$this->db->get_var("SELECT phase FROM `{$this->ledger}` WHERE sequence_id=-1");
            if($phase==='restored')return;
            if(!in_array($phase,['activated','restoring'],true))throw new \RuntimeException('Not activated.');
            $this->q("UPDATE `{$this->ledger}` SET phase='restoring' WHERE sequence_id=-1");
            if($this->exists($this->backup)&&!$this->exists($this->stage))$this->q("RENAME TABLE `{$this->live}` TO `{$this->stage}`, `{$this->backup}` TO `{$this->live}`");
            elseif($this->exists($this->backup)||!$this->exists($this->stage)||!$this->exists($this->live))throw new \RuntimeException('Ambiguous restore.');
            $this->q("UPDATE `{$this->ledger}` SET phase='restored' WHERE sequence_id=-1");
        });
    }
}
