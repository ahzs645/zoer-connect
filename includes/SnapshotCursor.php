<?php
namespace ZoerConnect;
/** Typed keyset cursors retain uint64 precision and native text collation. */
final class SnapshotCursor {
 public static function columns(array $indexes,array $columns):array{
  if(!$indexes)throw new \RuntimeException('Primary key required');
  usort($indexes,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);$byName=[];foreach($columns as $column)$byName[$column['Field']]=$column;
  $keys=[];
  foreach($indexes as $index){$name=$index['Column_name']??'';
   if(!is_string($name)||!preg_match('/^[A-Za-z0-9_]+$/D',$name)||!isset($byName[$name])||isset($index['Sub_part']))throw new \RuntimeException('Unsupported primary key');
   $column=$byName[$name];$type=strtolower((string)($column['Type']??''));$cast=null;$collation=null;$charset=null;
   if(preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\([0-9]+\))?(?: unsigned)?(?: zerofill)?$/D',$type))$cast=str_contains($type,'unsigned')?'UNSIGNED':'SIGNED';
   elseif(preg_match('/^decimal\(([0-9]{1,2}),([0-9]{1,2})\)(?: unsigned)?$/D',$type,$m))$cast='DECIMAL('.$m[1].','.$m[2].')';
   elseif(preg_match('/^(?:var)?char\([0-9]+\)$/D',$type)){
    $collation=$column['Collation']??'';if(!is_string($collation)||!preg_match('/^([a-z0-9]+)_[a-z0-9_]+$/D',$collation,$m))throw new \RuntimeException('Unsupported key collation');$charset=$m[1];
   }
   elseif(preg_match('/^(?:var)?binary\([0-9]+\)$/D',$type))$cast='BINARY';
   elseif($type==='date')$cast='DATE';
   elseif(preg_match('/^(?:datetime|timestamp)(?:\([0-6]\))?$/D',$type))$cast='DATETIME(6)';
   elseif(preg_match('/^time(?:\([0-6]\))?$/D',$type))$cast='TIME(6)';
   elseif(preg_match('/^year(?:\([0-9]+\))?$/D',$type))$cast='UNSIGNED';
   else throw new \RuntimeException('Unsupported primary key type');
   $keys[]=['name'=>$name,'cast'=>$cast,'collation'=>$collation,'charset'=>$charset];
  }return $keys;
 }
 public static function order(array $keys):string{return implode(',',array_map(static fn($key)=>'`'.$key['name'].'`',$keys));}
 private static function literal(array $key,$value):string{
  if(!is_string($value)&&!is_int($value))throw new \RuntimeException('Invalid primary key cursor');$value=(string)$value;$hex="X'".bin2hex($value)."'";
  if(in_array($key['cast'],['SIGNED','UNSIGNED'],true)&&!preg_match($key['cast']==='UNSIGNED'?'/^[0-9]+$/D':'/^-?[0-9]+$/D',$value))throw new \RuntimeException('Invalid integer cursor');
  if($key['collation']!==null)return 'CONVERT(CONVERT('.$hex.' USING utf8mb4) USING '.$key['charset'].') COLLATE '.$key['collation'];
  return $key['cast']==='BINARY'?$hex:'CAST('.$hex.' AS '.$key['cast'].')';
 }
 public static function after(array $keys,?array $row):string{
  if($row===null)return '';$equals=[];$alternatives=[];
  foreach($keys as $key){if(!array_key_exists($key['name'],$row))throw new \RuntimeException('Missing primary key cursor');$column='`'.$key['name'].'`';$value=self::literal($key,$row[$key['name']]);$alternatives[]='('.implode(' AND ',[...$equals,$column.' > '.$value]).')';$equals[]=$column.' = '.$value;}
  return '('.implode(' OR ',$alternatives).')';
 }
}
