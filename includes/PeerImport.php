<?php
namespace ZoerConnect;

require_once __DIR__.'/TableStage.php';
require_once __DIR__.'/FilePublication.php';
require_once __DIR__.'/StageStore.php';
require_once __DIR__.'/RecoveryCoordinator.php';
require_once __DIR__.'/Replacement.php';

/** Bounded development importer. Deliberately not a general SQL restore endpoint.
 * The caller must fence ALL destination writers for the entire job, including cron,
 * CLI and external DB clients. A maintenance banner alone is not such a fence.
 * No public routes are supplied here. Existing destination settings/identities stay intact.
 */
final class PeerImport {
    public const DESTINATION = 'https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh';
    public const DDEV_DESTINATION = 'http://zoer-connect-transfer-peer.ddev.site:8080';
    public const MAX_SQL_BYTES = 8388608;
    private const CONTENT = ['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships'];
    private $db;
    private string $root;
    private string $private;
    private $guard;
    private string $destinationUrl;

    public function __construct($db, string $destinationRoot, string $privateRoot, bool $enabled, string $destinationUrl, ?callable $quiescenceGuard = null) {
        if (!$enabled || !in_array($destinationUrl,[self::DESTINATION,self::DDEV_DESTINATION],true)) throw new \RuntimeException('Import is restricted to the explicitly enabled transfer peer.');
        $this->root = realpath($destinationRoot) ?: throw new \RuntimeException('Destination missing.');
        $this->private = realpath($privateRoot) ?: throw new \RuntimeException('Private storage missing.');
        if (is_link($privateRoot) || $this->private === $this->root || str_starts_with($this->private, $this->root.'/')) throw new \RuntimeException('Import storage must be private.');
        $this->db = $db; $this->guard = $quiescenceGuard; $this->destinationUrl = $destinationUrl;
    }
    private function fenced(): void {
        if (!$this->guard || ($this->guard)() !== true) throw new \RuntimeException('An exclusive destination write fence is required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$this->db->options)) throw new \RuntimeException('Invalid destination options table.');
        foreach (['home','siteurl'] as $key) {
            $actual=$this->db->get_var($this->db->prepare("SELECT option_value FROM `{$this->db->options}` WHERE option_name=%s",$key));
            if ($this->db->last_error || $actual!==$this->destinationUrl) throw new \RuntimeException('Actual destination identity is not the transfer peer.');
        }
    }
    private function directory(string $id): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid import ID.');
        return $this->private.'/peer-'.$id;
    }
    private function locked(callable $fn) {
        $h = fopen($this->private.'/peer-import.lock', 'c');
        if (!$h || !flock($h, LOCK_EX)) throw new \RuntimeException('Cannot lock peer import.');
        try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
    }
    private function save(string $dir, array $state): void {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (file_put_contents($dir.'/import.tmp', $json) !== strlen($json) || !rename($dir.'/import.tmp', $dir.'/import.json')) throw new \RuntimeException('Cannot save import journal.');
    }
    private function read(string $id): array {
        return json_decode(file_get_contents($this->directory($id).'/import.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function schema(string $sql, string $table): string {
        $start = 'CREATE TABLE `'.$table.'` ';
        if (!str_starts_with($sql, $start)) throw new \InvalidArgumentException('Unexpected schema header.');
        // SHOW CREATE emits an engine suffix; differences other than its next ID fail closed.
        return preg_replace('/ AUTO_INCREMENT=[0-9]+(?= |$)/', '', substr($sql, strlen($start)));
    }
    /** Parse data, never execute imported SQL. Every statement and the completion footer is required.
     * $schemas maps selected source table names to destination SHOW CREATE text with source name.
     */
    public static function parseSnapshot(string $sql, array $schemas): array {
        if (strlen($sql) > self::MAX_SQL_BYTES) throw new \InvalidArgumentException('Snapshot exceeds development import limit.');
        $header = "-- Zoer Connect database snapshot\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $footer = "SET FOREIGN_KEY_CHECKS=1;\n";
        if (!str_starts_with($sql, $header) || !str_ends_with($sql, $footer)) throw new \InvalidArgumentException('Incomplete or unsupported snapshot.');
        $body = substr($sql, strlen($header), -strlen($footer));
        $rows = []; $seen = []; $total = 0; $current = null;
        while ($body !== '') {
            if (preg_match('/\ADROP TABLE IF EXISTS `([A-Za-z0-9_]+)`;\n/', $body, $m)) {
                $current = $m[1];
                if (isset($seen[$current]) || count($seen) >= 500) throw new \InvalidArgumentException('Duplicate or excessive tables.');
                $seen[$current] = true; $body = substr($body, strlen($m[0]));
                $end = strpos($body, ";\n");
                if ($end === false) throw new \InvalidArgumentException('Incomplete schema.');
                $create = substr($body, 0, $end);
                self::schema($create, $current);
                if (isset($schemas[$current])) {
                    if (self::schema($create, $current) !== self::schema($schemas[$current], $current)) throw new \InvalidArgumentException('Source and destination schemas differ.');
                    $rows[$current] = [];
                }
                $body = substr($body, $end + 2);
                continue;
            }
            if (!preg_match('/\AINSERT INTO `([A-Za-z0-9_]+)` \((`[A-Za-z0-9_]+`(?:,`[A-Za-z0-9_]+`)*)\) VALUES \(((?:NULL|X\x27(?:[a-f0-9]{2})*\x27)(?:,(?:NULL|X\x27(?:[a-f0-9]{2})*\x27))*)\);\n/', $body, $m) || $m[1] !== $current) throw new \InvalidArgumentException('Unsupported snapshot statement.');
            if (++$total > 20000) throw new \InvalidArgumentException('Snapshot row limit exceeded.');
            $columns = array_map(static fn($c) => trim($c, '`'), explode(',', $m[2]));
            $values = array_map(static fn($v) => $v === 'NULL' ? null : hex2bin(substr($v, 2, -1)), explode(',', $m[3]));
            if (count($columns) !== count($values) || count(array_unique($columns)) !== count($columns)) throw new \InvalidArgumentException('Invalid snapshot columns.');
            if (isset($schemas[$current])) $rows[$current][] = array_combine($columns, $values);
            $body = substr($body, strlen($m[0]));
        }
        if (!$seen || array_diff(array_keys($schemas), array_keys($rows))) throw new \InvalidArgumentException('Selected tables missing from snapshot.');
        return $rows;
    }
    public function prepare(string $id, ?string $databasePath, ?string $sha256, string $sourcePrefix, string $sourceUrl, array $selectedTables, array $manifest, array $stagedFiles, int $destinationAdminId = 0): array {
        return $this->locked(function() use ($id,$databasePath,$sha256,$sourcePrefix,$sourceUrl,$selectedTables,$manifest,$stagedFiles,$destinationAdminId) {
            $this->fenced(); $dir = $this->directory($id);
            if (file_exists($this->private.'/peer-active') || file_exists($dir)) throw new \RuntimeException('An import already exists; recover it first.');
            if (!array_is_list($selectedTables) || count(array_unique($selectedTables)) !== count($selectedTables) || array_diff($selectedTables, self::CONTENT)) throw new \InvalidArgumentException('Only core content tables are supported; identities and settings are preserved.');
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $sourcePrefix) || !preg_match('/^[A-Za-z0-9_]+$/D', $this->db->prefix)) throw new \InvalidArgumentException('Invalid prefix.');
            if (!filter_var($sourceUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($sourceUrl, PHP_URL_SCHEME), ['http','https'], true)) throw new \InvalidArgumentException('Invalid source URL.');
            if ($selectedTables && array_diff(self::CONTENT,$selectedTables)) throw new \InvalidArgumentException('Import all core content tables together to preserve relationships.');
            $hasFiles = !empty($manifest['files']);
            if (!$hasFiles && !$selectedTables) throw new \InvalidArgumentException('Nothing selected.');
            $data = []; $tables = [];
            if ($selectedTables) {
                $path = $databasePath === null ? false : realpath($databasePath);
                if (!$path || is_link($databasePath) || !str_starts_with($path, $this->private.'/') || !is_file($path) || filesize($path) > self::MAX_SQL_BYTES || !preg_match('/^[a-f0-9]{64}$/D', $sha256 ?? '') || !hash_equals($sha256, hash_file('sha256',$path))) throw new \InvalidArgumentException('Snapshot path, size or digest invalid.');
                $schemas = [];
                foreach ($selectedTables as $suffix) {
                    $table = $this->db->prefix.$suffix;
                    $create = $this->db->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
                    if (!is_array($create) || !isset($create[1])) throw new \RuntimeException('Destination table missing.');
                    $schemas[$sourcePrefix.$suffix] = 'CREATE TABLE `'.$sourcePrefix.$suffix.'` '.self::schema($create[1], $table);
                }
                $data = self::parseSnapshot(file_get_contents($path), $schemas);
                if (in_array('posts',$selectedTables,true)) {
                    $caps = $this->db->get_var($this->db->prepare("SELECT meta_value FROM `{$this->db->usermeta}` WHERE user_id=%d AND meta_key=%s", $destinationAdminId, $this->db->prefix.'capabilities'));
                    $caps = is_string($caps) ? @unserialize($caps,['allowed_classes'=>false]) : null;
                    if (!is_array($caps) || ($caps['administrator'] ?? false) !== true || !$this->db->get_var($this->db->prepare("SELECT ID FROM `{$this->db->users}` WHERE ID=%d",$destinationAdminId))) throw new \InvalidArgumentException('A destination administrator is required for author remapping.');
                }
                foreach ($data as $table => &$rows) foreach ($rows as &$row) {
                    foreach ($row as &$value) if (is_string($value)) $value = Replacement::apply($value,[['mode'=>'literal','find'=>rtrim($sourceUrl,'/'),'replace'=>$this->destinationUrl]]);
                    unset($value);
                    if ($table === $sourcePrefix.'posts') $row['post_author'] = (string)$destinationAdminId;
                    if ($table === $sourcePrefix.'comments') $row['user_id'] = '0';
                }
                unset($rows,$row);
            }
            if ($hasFiles) {
                StageStore::validateManifest($manifest, $this->destinationUrl);
                if (count($manifest['files']) !== count($stagedFiles)) throw new \InvalidArgumentException('Staged file count mismatch.');
            }
            // Reserve before any preparation. A process death retains ownership and all staging.
            if (!mkdir($dir,0700)) throw new \RuntimeException('Cannot create import journal.');
            $state = ['id'=>$id,'phase'=>'preparing','tables'=>[],'files'=>$hasFiles]; $this->save($dir,$state);
            if (file_put_contents($this->private.'/peer-active.tmp',$id) !== strlen($id) || !rename($this->private.'/peer-active.tmp',$this->private.'/peer-active')) throw new \RuntimeException('Cannot reserve import.');
            try {
                if ($hasFiles) {
                    $copies = [];
                    foreach ($manifest['files'] as $i=>$file) {
                        $source = isset($stagedFiles[$i]) ? realpath($stagedFiles[$i]) : false;
                        if (!$source || is_link($stagedFiles[$i]) || !str_starts_with($source,$this->private.'/') || !is_file($source)) throw new \InvalidArgumentException('Invalid staged file.');
                        if (filesize($source) !== $file['bytes'] || hash_file('sha256',$source) !== $file['sha256']) throw new \InvalidArgumentException('Staged file digest mismatch.');
                        $copy = $dir.'/payload-'.$i;
                        if (!copy($source,$copy) || !chmod($copy,0600)) throw new \RuntimeException('Cannot copy staged file.');
                        $copies[] = $copy;
                    }
                    (new FilePublication($this->root,$dir))->prepare($manifest,$copies);
                }
                foreach ($selectedTables as $index=>$suffix) {
                    $entry = ['name'=>$this->db->prefix.$suffix,'id'=>substr(hash('sha256',$id.':'.$index),0,16)];
                    $state['tables'][]=$entry; $this->save($dir,$state);
                    $table = new TableStage($this->db,$entry['name'],$entry['id']); $table->prepare();
                    $rows = $data[$sourcePrefix.$suffix]; $sequence = 0;
                    // One row per batch bounds retries and rejects oversized individual values.
                    foreach ($rows as $row) $table->chunk($sequence++,[$row]);
                    $table->verify(count($rows),$sequence);
                }
                $this->coordinator($dir,$state)->start();
                $state['phase']='ready'; $this->save($dir,$state); return $state;
            } catch (\Throwable $e) {
                $state['phase']='preparation_failed'; $this->save($dir,$state);
                throw new \RuntimeException('Preparation failed; private journal retained; destination was not activated.',0,$e);
            }
        });
    }
    private function coordinator(string $dir, array $state): RecoveryCoordinator {
        $files = $state['files'] ? new FilePublication($this->root,$dir) : new class {
            public function step(): array { return ['status'=>'verification_required']; }
            public function rollbackStep(): array { return ['status'=>'rolled_back']; }
        };
        $tables = array_map(fn($t)=>new TableStage($this->db,$t['name'],$t['id']),$state['tables']);
        return new RecoveryCoordinator($dir.'/recovery.json',$files,$tables);
    }
    public function status(string $id): array {
        return $this->locked(function() use($id) {
            $state=$this->read($id); $path=$this->directory($id).'/recovery.json';
            if (is_file($path)) $state['recovery']=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            return $state;
        });
    }
    /** Abandon an interrupted PREPARATION only. No live tables/files were activated.
     * Keeps the private audit journal; a fresh ID can then restart from verified input.
     */
    public function discardPreparation(string $id): array {
        return $this->locked(function()use($id) {
            $this->fenced(); $state=$this->read($id); $dir=$this->directory($id);
            if (file_get_contents($this->private.'/peer-active')!==$id || !in_array($state['phase'],['preparing','preparation_failed','discarded'],true)) throw new \RuntimeException('Only an unactivated preparation can be discarded.');
            foreach ($state['tables'] as $entry) {
                if (!preg_match('/^[a-f0-9]{16}$/D',$entry['id'])) throw new \RuntimeException('Invalid stage journal.');
                $stage='zoer_s_'.$entry['id']; $ledger='zoer_l_'.$entry['id']; $backup='zoer_b_'.$entry['id'];
                $exists=function($table) {
                    $result=$this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s',$this->db->esc_like($table)));
                    if ($this->db->last_error) throw new \RuntimeException('Cannot inspect preparation.');
                    return $result===$table;
                };
                if ($exists($backup)) throw new \RuntimeException('Backup exists; discard refused.');
                if ($exists($ledger)) {
                    $phase=$this->db->get_var("SELECT phase FROM `$ledger` WHERE sequence_id=-1");
                    if ($this->db->last_error || ($phase!==null && !in_array($phase,['uploading','verified'],true))) throw new \RuntimeException('Stage may have activated; discard refused.');
                }
                // Exact generated names only; DROP never uses source snapshot identifiers.
                if ($this->db->query("DROP TABLE IF EXISTS `$stage`, `$ledger`")===false) throw new \RuntimeException('Cannot discard preparation.');
            }
            $state['phase']='discarded'; $this->save($dir,$state);
            if (!unlink($this->private.'/peer-active')) throw new \RuntimeException('Cannot release import reservation.');
            return $state;
        });
    }
    /** Release a fully restored job while retaining its audit and private artifacts. */
    public function releaseRollback(string $id): void {
        $this->locked(function()use($id) {
            $this->fenced(); $state=$this->read($id);
            if (file_get_contents($this->private.'/peer-active')!==$id) throw new \RuntimeException('Import does not own the destination.');
            $recovery=json_decode(file_get_contents($this->directory($id).'/recovery.json'),true,512,JSON_THROW_ON_ERROR);
            if (($recovery['phase']??null)!=='rolled_back') throw new \RuntimeException('Rollback must complete before release.');
            $state['phase']='rolled_back'; $this->save($this->directory($id),$state);
            if (!unlink($this->private.'/peer-active')) throw new \RuntimeException('Cannot release import reservation.');
        });
    }
    public function step(string $id): array { return $this->advance($id,false); }
    public function rollback(string $id): array { return $this->advance($id,true); }
    private function advance(string $id, bool $rollback): array {
        return $this->locked(function()use($id,$rollback) {
            $this->fenced(); $state=$this->read($id); $dir=$this->directory($id);
            if (file_get_contents($this->private.'/peer-active')!==$id || $state['phase']!=='ready') throw new \RuntimeException('Import is not ready; preparation requires inspection.');
            $coordinator=$this->coordinator($dir,$state);
            if ($rollback) $coordinator->requestRollback();
            return $coordinator->tick();
        });
    }
}
