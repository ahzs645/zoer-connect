<?php
namespace ZoerConnect;
require_once __DIR__.'/SnapshotCursor.php';
require_once __DIR__.'/DatabaseExporter.php';
require_once __DIR__.'/TransferStorage.php';
/** Multi-request SQL export only while its source fence is held and the caller
 * has explicitly confirmed that old requests and external writers are stopped.
 * Each committed output part binds a typed table/row cursor and its checksum. */
final class PagedDatabase {
    private static function schema($db,string $table):string {
        $row=$db->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
        if($db->last_error||!is_array($row)||!isset($row[1]))throw new \RuntimeException('Cannot inspect table schema.');
        $ddl=$row[1];if(preg_match('/^CREATE TABLE `([A-Za-z0-9_]+)` /',$ddl,$m)&&strcasecmp($m[1],$table)===0)$ddl='CREATE TABLE `'.$table.'` '.substr($ddl,strlen('CREATE TABLE `'.$m[1].'` '));
        return $ddl;
    }
    public static function start($db,array $filters):array {
        $version=(string)$db->get_var('SELECT VERSION()');
        $flavor=stripos($version,'MariaDB')!==false?'mariadb':'mysql';
        if(!preg_match('/^(\d+\.\d+\.\d+)/',$version,$v)||version_compare($v[1],$flavor==='mariadb'?'10.1.1':'5.7.8','<'))throw new \RuntimeException('Resumable export requires MariaDB 10.1.1+ or MySQL 5.7.8+.');
        if($db->query('SET SESSION lock_wait_timeout=2')===false)throw new \RuntimeException('Cannot bound snapshot metadata waits.');
        $prefix=$db->prefix;if(!preg_match('/^[A-Za-z0-9_]+$/D',$prefix))throw new \RuntimeException('Unsupported table prefix.');
        $folded=(int)$db->get_var('SELECT @@lower_case_table_names')!==0;
        $tables=$db->get_results('SHOW TABLE STATUS',ARRAY_A);if($db->last_error||!is_array($tables))throw new \RuntimeException('Cannot inspect database.');
        $f=DatabaseExporter::filters($filters);$selected=[];$present=[];
        foreach($tables as $t){$name=$t['Name'];if($folded&&strncasecmp($name,$prefix,strlen($prefix))===0)$name=$prefix.substr($name,strlen($prefix));if(!str_starts_with($name,$prefix))continue;$present[$name]=true;
            if($f['tables']!==null&&!in_array(substr($name,strlen($prefix)),$f['tables'],true))continue;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$name)||strtolower((string)$t['Engine'])!=='innodb')throw new \RuntimeException('Pull requires InnoDB tables.');$selected[]=$name;
        }
        if($f['tables']!==null)foreach($f['tables'] as $name)if(!in_array($prefix.$name,$selected,true))throw new \InvalidArgumentException('Selected database table is unavailable.');
        if(!$selected||count($selected)>500)throw new \RuntimeException('Unsupported table count.');sort($selected);
        return ['flavor'=>$flavor,'prefix'=>$prefix,'tables'=>$selected,'present'=>$present,'filters'=>$f,'legacy'=>$filters===[],'table'=>0,'row'=>null,'keys'=>null,'ddl'=>null,'bytes'=>0,'rows'=>0,'parts'=>[],'complete'=>false];
    }
    public static function step($db,string $path,array $s,int $otherBytes=0):array {
        if($s['complete'])return $s;
        if(!in_array($s['flavor']??null,['mariadb','mysql'],true)||$db->query('SET SESSION lock_wait_timeout=2')===false)throw new \RuntimeException('Cannot bound snapshot queries.');
        if(is_link($path))throw new \RuntimeException('Unsafe snapshot.');
        clearstatcache(true,$path);if($s['bytes']>0&&(!is_file($path)||filesize($path)<$s['bytes']))throw new \RuntimeException('Snapshot checkpoint bytes unavailable.');
        if($s['keys']!==null&&DatabaseExporter::ddl(self::schema($db,$s['tables'][$s['table']]))!==$s['ddl'])throw new \RuntimeException('Schema changed during export.');
        $out=fopen($path,'c+b');if(!$out)throw new \RuntimeException('Cannot open snapshot.');
        $start=$s['bytes'];$hash=hash_init('sha256');$started=microtime(true);$hostLimit=(int)ini_get('max_execution_time');$budget=min(3,$hostLimit>0?max(0.05,$hostLimit-5-($started-($_SERVER['REQUEST_TIME_FLOAT']??$started))):3);
        $write=static function(string $sql)use($out,&$s,$hash,$otherBytes){
            if(strlen($sql)>16777216)throw new \RuntimeException('A database row exceeds the 16 MiB statement limit.');
            TransferStorage::size($s['bytes']+strlen($sql),$s['bytes']+strlen($sql)+$otherBytes);TransferStorage::allocation(dirname(stream_get_meta_data($out)['uri']),strlen($sql));
            if(fwrite($out,$sql)!==strlen($sql))throw new \RuntimeException('Cannot persist snapshot part.');hash_update($hash,$sql);$s['bytes']+=strlen($sql);
        };
        try {
            if(!chmod($path,0600)||!ftruncate($out,$start)||fseek($out,$start)!==0)throw new \RuntimeException('Cannot resume snapshot part.');
            if($start===0)$write("-- Zoer Connect source-maintenance snapshot\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            while($s['table']<count($s['tables'])&&$s['bytes']-$start<4194304&&microtime(true)-$started<$budget){
                $table=$s['tables'][$s['table']];
                if($s['keys']===null){
                    $ddl=self::schema($db,$table);$s['ddl']=DatabaseExporter::ddl($ddl);
                    $keys=$db->get_results("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'",ARRAY_A);if($db->last_error||!is_array($keys))throw new \RuntimeException('Cannot inspect primary keys.');
                    $columns=$db->get_results("SHOW FULL COLUMNS FROM `$table`",ARRAY_A);if($db->last_error||!is_array($columns))throw new \RuntimeException('Cannot inspect columns.');$s['keys']=SnapshotCursor::columns($keys,$columns);
                    $write("DROP TABLE IF EXISTS `$table`;\n".$ddl.";\n");
                }
                $where=DatabaseExporter::where($table,$s['prefix'],$s['filters'],$s['present'],false,$s['legacy']);
                $row=$s['row']===null?null:array_map(static fn($v)=>base64_decode($v,true),$s['row']);
                $after=SnapshotCursor::after($s['keys'],$row);if($after!=='')$where.=($where===''?' WHERE ':' AND ').$after;
                // One row bounds memory even for a large individual record.
                $query="SELECT ".($s['flavor']==='mysql'?'/*+ MAX_EXECUTION_TIME(1000) */ ':'')."* FROM `$table`$where ORDER BY ".SnapshotCursor::order($s['keys']).' LIMIT 1';
                if($s['flavor']==='mariadb')$query='SET STATEMENT max_statement_time=1 FOR '.$query;
                $rows=$db->get_results($query,ARRAY_A);
                if($db->last_error||!is_array($rows))throw new \RuntimeException('Cannot read snapshot rows.');
                if(!$rows){if(DatabaseExporter::ddl(self::schema($db,$table))!==$s['ddl'])throw new \RuntimeException('Schema changed during export.');$s['table']++;$s['row']=null;$s['keys']=null;$s['ddl']=null;continue;}
                $columns=[];$values=[];foreach($rows[0] as $column=>$value){if(!preg_match('/^[A-Za-z0-9_]+$/D',$column))throw new \RuntimeException('Unsupported column name.');$columns[]='`'.$column.'`';$values[]=$value===null?'NULL':"X'".bin2hex((string)$value)."'";}
                $write('INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");
                $s['rows']=($s['rows']??0)+1;$s['row']=[];foreach($s['keys'] as $key)$s['row'][$key['name']]=base64_encode((string)$rows[0][$key['name']]);
            }
            if($s['table']===count($s['tables'])){$write("SET FOREIGN_KEY_CHECKS=1;\n");$s['complete']=true;}
            if(!fflush($out)||(function_exists('fsync')&&!fsync($out)))throw new \RuntimeException('Cannot flush snapshot part.');
            if($s['bytes']>$start)$s['parts'][]=['offset'=>$start,'bytes'=>$s['bytes']-$start,'sha256'=>hash_final($hash),'table'=>$s['table'],'row'=>$s['row']];
            return $s;
        }finally{fclose($out);}
    }
    public static function verifyPart(string $path,array $part):void {
        $h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Cannot verify snapshot part.');$hash=hash_init('sha256');
        try {if(fseek($h,$part['offset'])!==0)throw new \RuntimeException('Cannot seek snapshot part.');for($n=0;$n<$part['bytes'];){$data=fread($h,min(262144,$part['bytes']-$n));if($data===false||$data==='')throw new \RuntimeException('Snapshot part truncated.');hash_update($hash,$data);$n+=strlen($data);}if(!hash_equals($part['sha256'],hash_final($hash)))throw new \RuntimeException('Snapshot part checksum mismatch.');}finally{fclose($h);}
    }
}
