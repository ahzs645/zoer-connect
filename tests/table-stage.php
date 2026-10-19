<?php
require_once __DIR__.'/../includes/TableStage.php';
// Minimal transactional ledger seam: payloads must reach wpdb intact, including
// invalid UTF-8 bytes that cannot round-trip through json_encode.
final class StageChunkDb {
 public string $prefix='wp_',$users='wp_users',$usermeta='wp_usermeta',$options='wp_options',$dbname='test';
 public array $digests=[],$inserted=[];public int $packet=67108864;
 public function get_var(string $sql){
  if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return '1';
  if($sql==='SELECT @@SESSION.sql_mode')return 'STRICT_TRANS_TABLES';
  if($sql==='SELECT @@SESSION.max_allowed_packet')return (string)$this->packet;
  if(str_contains($sql,'SELECT phase'))return 'uploading';
  if(str_contains($sql,'MAX(sequence_id)'))return count($this->digests);
  if(preg_match('/SELECT digest .*sequence_id=(\d+)/',$sql,$m))return $this->digests[(int)$m[1]]??null;
  throw new RuntimeException('Unexpected read');
 }
 public function get_col(string $sql){return ['id','payload'];}
 public function prepare(string $sql,...$values){foreach($values as $v)$sql=preg_replace('/%[ds]/',is_int($v)?(string)$v:"'".str_replace("'","''",$v)."'",$sql,1);return $sql;}
 public function query(string $sql){if(preg_match("/VALUES \((\\d+),'([a-f0-9]{64})',/",$sql,$m))$this->digests[(int)$m[1]]=$m[2];return 1;}
 public function insert(string $table,array $row){$this->inserted[]=$row;return 1;}
}
$db=new StageChunkDb;$stage=new \ZoerConnect\TableStage($db,'wp_test',str_repeat('a',16));
$binary="\xff\xfe\x00\x80quotes'\\";
$rows=[['id'=>1,'payload'=>$binary]];$stage->chunk(0,$rows);$stage->chunk(0,$rows);
if($db->inserted!==$rows||count($db->digests)!==1)throw new RuntimeException('Binary payload or retry changed.');
$failed=false;try{$stage->chunk(0,[['id'=>1,'payload'=>$binary.'changed']]);}catch(InvalidArgumentException $e){$failed=true;}
if(!$failed)throw new RuntimeException('Conflicting binary retry accepted.');
$stage->chunk(1,[['id'=>2,'payload'=>str_repeat('x',300000)]]);
// A row the exporter allows (a 16 MiB statement holds about 8 MiB of hex-encoded data) stages.
$stage->chunk(2,[['id'=>3,'payload'=>str_repeat('x',8388608)]]);
if(count($db->digests)!==3)throw new RuntimeException('8 MiB row refused.');
$failed=false;try{$stage->chunk(3,[['id'=>4,'payload'=>str_repeat('x',\ZoerConnect\TableStage::MAX_BATCH_BYTES)]]);}catch(InvalidArgumentException $e){$failed=str_contains($e->getMessage(),'16 MiB');}
if(!$failed)throw new RuntimeException('Oversized batch accepted.');
// A destination whose max_allowed_packet cannot carry the row says so before inserting anything.
$db->packet=16777216;$before=count($db->inserted);$message='';
try{$stage->chunk(3,[['id'=>4,'payload'=>str_repeat('x',9437184)]]);}catch(RuntimeException $e){$message=$e->getMessage();}
if(!str_contains($message,'max_allowed_packet')||count($db->inserted)!==$before)throw new RuntimeException('Packet limit not explained: '.$message);
echo "PASS binary-safe staging digest, exact replay, conflicting retry, >256 KiB row, 8 MiB row, 16 MiB batch bound and max_allowed_packet message\n";
