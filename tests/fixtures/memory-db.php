<?php
// In-memory wpdb seam for the exact SQL subset used by TableStage, TransferImport,
// SettingsPreservation and DatabaseExporter. Unknown statements fail loudly so a
// new query shape cannot silently pass. Not a SQL engine; no transactions.
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
if(!defined('ARRAY_N'))define('ARRAY_N','ARRAY_N');
final class MemoryDb {
 public string $prefix='wp_',$options='wp_options',$users='wp_users',$usermeta='wp_usermeta',$posts='wp_posts',$dbname='memory',$last_error='';
 public array $tables=[];public array $log=[];public ?Closure $hook=null;public string $lowerCaseTableNames='0';
 public function __construct(string $prefix='wp_'){$this->prefix=$prefix;foreach(['options','users','usermeta','posts'] as $t)$this->$t=$prefix.$t;}
 /** Define a table from a SHOW CREATE TABLE style statement. */
 public function define(string $ddl,array $rows=[]): void {
  if(!preg_match('/^CREATE TABLE `(\w+)` (.*)$/s',$ddl,$m))throw new RuntimeException('Fixture DDL');
  $this->createFrom($m[1],$m[2]);foreach($rows as $row)if($this->insert($m[1],$row)===false)throw new RuntimeException('Fixture row: '.$this->last_error);
 }
 private function createFrom(string $name,string $body): void {
  if(str_starts_with($name,'zoer_l_'))$t=['columns'=>['sequence_id','digest','row_count','phase','progress_json'],'pk'=>['sequence_id'],'unique'=>[],'auto'=>null];
  else{
   preg_match_all('/^\s*`(\w+)` /m',$body,$c);preg_match('/PRIMARY KEY \(([^)]+)\)/',$body,$p);preg_match_all('/UNIQUE KEY `\w+` \(([^)]+)\)/',$body,$u);preg_match('/^\s*`(\w+)` [^\n]*AUTO_INCREMENT/m',$body,$a);
   $cols=fn($s)=>array_map(fn($x)=>trim($x,'` '),explode(',',$s));
   $t=['columns'=>$c[1],'pk'=>$p?$cols($p[1]):[],'unique'=>array_map($cols,$u[1]),'auto'=>$a[1]??null];
  }
  $this->tables[$name]=$t+['body'=>$body,'rows'=>[]];
 }
 public function prepare(string $sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];return preg_replace_callback('/%[ds]/',function($m)use(&$args){$v=array_shift($args);return $m[0]==='%d'?(string)(int)$v:"'".str_replace(['\\',"'"],['\\\\',"\\'"],(string)$v)."'";},$sql);}
 public function esc_like(string $v): string {return addcslashes($v,'_%\\');}
 private function fail(string $message){$this->last_error=$message;return false;}
 private function table(string $name): array {if(!isset($this->tables[$name]))throw new RuntimeException("Missing table $name");return $this->tables[$name];}
 private static function literal(string $v){if($v==='NULL')return null;if($v[0]==="'")return preg_replace('/\\\\(.)/s','$1',substr($v,1,-1));return $v;}
 private static function likeRegex(string $pattern,string $escape='\\'): string {$out='';for($i=0;$i<strlen($pattern);$i++){$c=$pattern[$i];if($c===$escape){$out.=preg_quote($pattern[++$i],'/');}elseif($c==='%')$out.='.*';elseif($c==='_')$out.='.';else $out.=preg_quote($c,'/');}return '/^'.$out.'$/s';}
 private static function cmp($a,$b): int {if($a===null||$b===null)return ($a===null)<=>($b===null)?:0;return is_numeric($a)&&is_numeric($b)?((float)$a<=>(float)$b):strcmp((string)$a,(string)$b);}
 private function key(array $t,array $row): string {return implode("\0",array_map(fn($c)=>(string)($row[$c]??''),$t['pk']));}
 /** WHERE evaluator: (, ), AND, OR, col op value, NOT IN (...), [NOT] LIKE. */
 private function where(string $sql): Closure {
  $re="/'(?:[^'\\\\]|\\\\.)*'|-?\d+(?:\.\d+)?|`?\w+`?|>=|<=|<>|!=|[()=<>,]/s";
  preg_match_all($re,$sql,$m);$tokens=$m[0];$pos=0;
  if(trim(preg_replace($re,'',$sql))!=='')throw new RuntimeException("Unsupported WHERE: $sql");
  $peek=function()use(&$tokens,&$pos){return $tokens[$pos]??null;};$next=function()use(&$tokens,&$pos){return $tokens[$pos++]??null;};
  $or=null;$and=null;$atom=null;
  $atom=function()use(&$atom,&$or,$peek,$next){
   if($peek()==='('){$next();$e=$or();if($next()!==')')throw new RuntimeException('WHERE paren');return $e;}
   $col=trim($next(),'`');$op=strtoupper($next());
   if($op==='NOT'){$op='NOT '.strtoupper($next());}
   if($op==='NOT IN'||$op==='IN'){if($next()!=='(')throw new RuntimeException('IN');$vals=[];while(($t=$next())!==')')if($t!==',')$vals[]=self::literal($t);return fn($r)=>in_array($r[$col]??null,$vals,true)!==($op==='NOT IN');}
   $v=self::literal($next());
   if(str_contains($op,'LIKE')&&strtoupper((string)$peek())==='ESCAPE'){$next();$esc=self::literal($next());$re=self::likeRegex($v,$esc);return $op==='LIKE'?fn($r)=>(bool)preg_match($re,(string)($r[$col]??'')):fn($r)=>!preg_match($re,(string)($r[$col]??''));}
   return match($op){'='=>fn($r)=>self::cmp($r[$col]??null,$v)===0&&($r[$col]??null)!==null,'>'=>fn($r)=>self::cmp($r[$col]??null,$v)>0,'>='=>fn($r)=>self::cmp($r[$col]??null,$v)>=0,'<'=>fn($r)=>self::cmp($r[$col]??null,$v)<0,'LIKE'=>fn($r)=>(bool)preg_match(self::likeRegex($v),(string)($r[$col]??'')),'NOT LIKE'=>fn($r)=>!preg_match(self::likeRegex($v),(string)($r[$col]??'')),default=>throw new RuntimeException("Operator $op")};
  };
  $and=function()use(&$atom,$peek,$next){$e=$atom();while(strtoupper((string)$peek())==='AND'){$next();$l=$e;$r=$atom();$e=fn($x)=>$l($x)&&$r($x);}return $e;};
  $or=function()use(&$and,$peek,$next){$e=$and();while(strtoupper((string)$peek())==='OR'){$next();$l=$e;$r=$and();$e=fn($x)=>$l($x)||$r($x);}return $e;};
  $e=$or();if($pos!==count($tokens))throw new RuntimeException("Trailing WHERE: $sql");return $e;
 }
 /** Returns [rows, columns] for a SELECT, or null for non-row statements. */
 private function select(string $sql): ?array {
  $sql=trim($sql);
  if(preg_match('/^SELECT (?:GET_LOCK|RELEASE_LOCK)\(/',$sql))return [[['v'=>'1']],['v']];
  if($sql==='SELECT @@SESSION.sql_mode')return [[['v'=>'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO']],['v']];
  if($sql==='SELECT @@lower_case_table_names')return [[['v'=>$this->lowerCaseTableNames]],['v']];
  if(preg_match("/^SHOW TABLES LIKE ('(?:[^'\\\\]|\\\\.)*')$/s",$sql,$m)){$re=self::likeRegex(self::literal($m[1])).($this->lowerCaseTableNames==='0'?'':'i');$names=array_values(array_filter(array_keys($this->tables),fn($n)=>preg_match($re,$n)));sort($names);return [array_map(fn($n)=>['n'=>$this->lowerCaseTableNames==='0'?$n:strtolower($n)],$names),['n']];}
  if($sql==='SHOW TABLE STATUS'){$names=array_keys($this->tables);sort($names);return [array_map(fn($n)=>['Name'=>$this->lowerCaseTableNames==='0'?$n:strtolower($n),'Engine'=>'InnoDB'],$names),['Name','Engine']];}
  if(preg_match("/^SELECT ENGINE FROM information_schema\.TABLES .*TABLE_NAME='(\w+)'$/",$sql,$m))return [isset($this->tables[$m[1]])?[['e'=>'InnoDB']]:[],['e']];
  if(preg_match('/^SELECT COUNT\(\*\) FROM information_schema\./',$sql))return [[['c'=>'0']],['c']];
  if(preg_match('/^SHOW CREATE TABLE `(\w+)`$/',$sql,$m)){if(!isset($this->tables[$m[1]])){$this->last_error="Table '{$m[1]}' doesn't exist";return [[],[]];}return [[['Table'=>$this->lowerCaseTableNames==='0'?$m[1]:strtolower($m[1]),'Create Table'=>'CREATE TABLE `'.($this->lowerCaseTableNames==='0'?$m[1]:strtolower($m[1])).'` '.$this->tables[$m[1]]['body']]],['Table','Create Table']];}
  if(preg_match("/^SHOW INDEX FROM `(\w+)` WHERE Key_name ?= ?'PRIMARY'$/",$sql,$m)){$t=$this->table($m[1]);return [array_map(fn($c,$i)=>['Column_name'=>$c,'Seq_in_index'=>(string)($i+1),'Key_name'=>'PRIMARY'],$t['pk'],array_keys($t['pk'])),['Column_name','Seq_in_index','Key_name']];}
  if(preg_match('/^SHOW COLUMNS FROM `(\w+)`$/',$sql,$m))return [array_map(fn($c)=>['Field'=>$c],$this->table($m[1])['columns']),['Field']];
  if(preg_match('/^SELECT COALESCE\(MAX\(sequence_id\),-1\)\+1 FROM `(\w+)`$/',$sql,$m)){$keys=array_map(fn($r)=>(int)$r['sequence_id'],$this->table($m[1])['rows']);return [[['v'=>(string)(($keys?max($keys):-1)+1)]],['v']];}
  if(preg_match('/^SELECT COUNT\(\*\) AS batches,COALESCE\(SUM\(row_count\),0\) AS rows_total FROM `(\w+)` WHERE sequence_id>=0$/',$sql,$m)){$rows=array_filter($this->table($m[1])['rows'],fn($r)=>(int)$r['sequence_id']>=0);return [[['batches'=>(string)count($rows),'rows_total'=>(string)array_sum(array_column($rows,'row_count'))]],['batches','rows_total']];}
  if(!preg_match('/^SELECT (.+?) FROM `?(\w+)`?(?: WHERE (.+?))?(?: ORDER BY ([`\w,]+))?(?: LIMIT (\d+)(?: OFFSET (\d+))?)?$/s',$sql,$m))return null;
  $t=$this->table($m[2]);$rows=array_values($t['rows']);
  if(($m[3]??'')!==''){$f=$this->where($m[3]);$rows=array_values(array_filter($rows,$f));}
  if(($m[4]??'')!==''){$order=array_map(fn($c)=>trim($c,'`'),explode(',',$m[4]));usort($rows,function($a,$b)use($order){foreach($order as $c)if($r=self::cmp($a[$c],$b[$c]))return $r;return 0;});}
  if(($m[5]??'')!=='')$rows=array_slice($rows,(int)($m[6]??0),(int)$m[5]);
  $what=trim($m[1]);
  if($what==='*')return [$rows,$t['columns']];
  if(str_starts_with($what,'(COALESCE(OCTET_LENGTH(')){preg_match_all('/OCTET_LENGTH\(`(\w+)`\)/',$what,$c);return [array_map(fn($r)=>['n'=>(string)array_sum(array_map(fn($x)=>strlen((string)$r[$x]),$c[1]))],$rows),['n']];}
  $cols=array_map('trim',explode(',',$what));foreach($cols as $c)if(!in_array($c,$t['columns'],true))throw new RuntimeException("Unsupported select: $sql");
  return [array_map(fn($r)=>array_intersect_key($r,array_flip($cols)),$rows),$cols];
 }
 private function rows(string $sql){$this->last_error='';$r=$this->select($sql);if($r===null)throw new RuntimeException("Unsupported query: $sql");return $r;}
 public function get_results(string $sql,$format='OBJECT'){[$rows]=$this->rows($sql);return $format===ARRAY_N?array_map('array_values',$rows):$rows;}
 public function get_row(string $sql,$format='OBJECT'){[$rows]=$this->rows($sql);if(!$rows)return null;return $format===ARRAY_N?array_values($rows[0]):$rows[0];}
 public function get_col(string $sql){[$rows]=$this->rows($sql);return array_map(fn($r)=>array_values($r)[0],$rows);}
 public function get_var(string $sql){[$rows]=$this->rows($sql);return $rows?array_values($rows[0])[0]:null;}
 private function conflicts(array $t,array $row): array {
  $hits=[];foreach($t['rows'] as $k=>$r){if($t['pk']&&$this->key($t,$r)===$this->key($t,$row)){$hits[]=$k;continue;}foreach($t['unique'] as $u)if(array_intersect_key($r,array_flip($u))==array_intersect_key($row,array_flip($u))){$hits[]=$k;break;}}return $hits;
 }
 private function put(string $name,array $row,string $mode){
  if(!isset($this->tables[$name]))return $this->fail("Missing table $name");$t=&$this->tables[$name];
  if(array_diff(array_keys($row),$t['columns']))return $this->fail('Unknown column');
  if($t['auto']&&(!isset($row[$t['auto']])||$row[$t['auto']]===''))$row[$t['auto']]=(string)(max([0,...array_map(fn($r)=>(int)$r[$t['auto']],$t['rows'])])+1);
  $full=[];foreach($t['columns'] as $c)$full[$c]=array_key_exists($c,$row)?($row[$c]===null?null:(string)$row[$c]):($c==='progress_json'?null:'');
  $hits=$this->conflicts($t,$full);
  if($hits&&$mode==='insert')return $this->fail('Duplicate entry');
  foreach($hits as $k)unset($t['rows'][$k]);
  $t['rows'][$this->key($t,$full)]=$full;return 1;
 }
 public function insert(string $table,array $row){$this->last_error='';return $this->put($table,$row,'insert');}
 public function replace(string $table,array $row){$this->last_error='';return $this->put($table,$row,'replace');}
 public function delete(string $table,array $where){$this->last_error='';$n=0;foreach($this->tables[$table]['rows'] as $k=>$r)if(array_intersect_key($r,$where)==$where){unset($this->tables[$table]['rows'][$k]);$n++;}return $n;}
 private function tuples(string $values): array {
  preg_match_all("/'(?:[^'\\\\]|\\\\.)*'|NULL|-?\d+|[(),]/s",$values,$m);$out=[];$cur=null;
  foreach($m[0] as $tok){if($tok==='('){$cur=[];}elseif($tok===')'){$out[]=$cur;$cur=null;}elseif($tok!==','&&$cur!==null)$cur[]=self::literal($tok);}
  return $out;
 }
 public function query(string $sql){
  $this->last_error='';$this->log[]=$sql;if($this->hook)($this->hook)($sql,$this);$sql=trim($sql);
  if(preg_match('/^(SET |START TRANSACTION|COMMIT|ROLLBACK)/',$sql))return 1;
  if(preg_match('/^CREATE TABLE `(\w+)` LIKE `(\w+)`$/',$sql,$m)){if(isset($this->tables[$m[1]]))return $this->fail('exists');$this->tables[$m[1]]=['rows'=>[]]+$this->table($m[2]);return 1;}
  if(preg_match('/^CREATE TABLE `(\w+)` (.*)$/s',$sql,$m)){if(isset($this->tables[$m[1]]))return $this->fail('exists');$this->createFrom($m[1],$m[2]);return 1;}
  if(preg_match('/^DROP TABLE IF EXISTS `(\w+)`$/',$sql,$m)){unset($this->tables[$m[1]]);return 1;}
  if(preg_match('/^RENAME TABLE (.+)$/',$sql,$m)){
   preg_match_all('/`(\w+)` TO `(\w+)`/',$m[1],$pairs,PREG_SET_ORDER);$copy=$this->tables;
   foreach($pairs as [,$from,$to]){if(!isset($copy[$from])||isset($copy[$to]))return $this->fail('rename');$copy[$to]=$copy[$from];unset($copy[$from]);}
   $this->tables=$copy;return 1;
  }
  if(preg_match('/^INSERT INTO `(\w+)` \(([\w,]+)\) SELECT ([\w,]+) FROM `(\w+)` WHERE (.+)$/s',$sql,$m)){$f=$this->where($m[5]);foreach(array_filter($this->table($m[4])['rows'],$f) as $r)if($this->put($m[1],array_intersect_key($r,array_flip(explode(',',$m[3]))),'insert')===false)return false;return 1;}
  if(preg_match('/^INSERT INTO `(\w+)` \(([\w,]+)\) VALUES (.+?)( ON DUPLICATE KEY UPDATE (.+))?$/s',$sql,$m)){
   $cols=explode(',',$m[2]);$update=isset($m[5])?array_map(fn($x)=>explode('=',$x)[0],explode(',',$m[5])):null;
   foreach($this->tuples($m[3]) as $tuple){$row=array_combine($cols,$tuple);$t=$this->table($m[1]);
    $hits=$this->conflicts($t,$row+array_fill_keys($t['columns'],''));
    if($hits&&$update!==null){foreach($hits as $k)foreach($update as $c)$this->tables[$m[1]]['rows'][$k][$c]=$row[$c]===null?null:(string)$row[$c];continue;}
    if($this->put($m[1],$row,'insert')===false)return false;}
   return 1;
  }
  if(preg_match("/^UPDATE `(\w+)` SET (.+?) WHERE (.+)$/s",$sql,$m)){
   preg_match_all("/(\w+)=('(?:[^'\\\\]|\\\\.)*'|NULL|-?\d+)/s",$m[2],$sets,PREG_SET_ORDER);$f=$this->where($m[3]);$n=0;
   foreach($this->tables[$m[1]]['rows'] as $k=>$r)if($f($r)){foreach($sets as [,$c,$v])$this->tables[$m[1]]['rows'][$k][$c]=self::literal($v);$n++;}return $n;
  }
  if(preg_match('/^DELETE FROM `(\w+)` WHERE (.+)$/s',$sql,$m)){$f=$this->where($m[2]);$n=0;foreach($this->table($m[1])['rows'] as $k=>$r)if($f($r)){unset($this->tables[$m[1]]['rows'][$k]);$n++;}return $n;}
  $r=$this->select($sql);if($r!==null)return count($r[0]);
  throw new RuntimeException("Unsupported query: $sql");
 }
 /** Test helper: rows keyed by primary key, sorted. */
 public function dump(string $table): array {$rows=$this->tables[$table]['rows']??[];ksort($rows,SORT_NATURAL);return array_values($rows);}
}
