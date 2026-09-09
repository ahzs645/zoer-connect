<?php
namespace ZoerConnect;

/** Read-only, single InnoDB snapshot. No shell commands and no source writes. */
final class DatabaseExporter {
    public const MAX_BYTES = 268435456;
    public static function write($db, string $destination, ?callable $clock = null, float $budgetSeconds = 40): void {
        $requestStarted=$clock===null ? ($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)) : null;
        $clock ??= static fn() => microtime(true);
        $hostLimit=(int)ini_get('max_execution_time');
        $budgetSeconds=min(40, $budgetSeconds, $hostLimit>0 ? max(0.1,$hostLimit-5) : 40);
        $started=$clock();
        if($hostLimit>0&&$requestStarted!==null)$budgetSeconds=min($budgetSeconds,max(0,$hostLimit-5-($started-$requestStarted)));
        $check=static function()use($clock,$started,$budgetSeconds){if($clock()-$started >= $budgetSeconds)throw new \RuntimeException('Database snapshot exceeded its request time budget. Retry a fresh export.');};
        $partial=$destination.'.partial';
        if(file_exists($destination)||is_link($destination)||file_exists($partial)||is_link($partial))throw new \RuntimeException('Snapshot already exists.');
        $prefix=$db->prefix;
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$prefix))throw new \RuntimeException('Unsupported table prefix.');
        $tables=$db->get_results('SHOW TABLE STATUS',ARRAY_A);
        if($db->last_error||!is_array($tables))throw new \RuntimeException('Cannot inspect database.');
        $selected=[];
        foreach($tables as $table){
            $name=$table['Name'];
            if(!str_starts_with($name,$prefix))continue;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$name)||strtolower((string)$table['Engine'])!=='innodb')throw new \RuntimeException('Pull requires InnoDB tables.');
            $selected[]=$name;
        }
        if(!$selected || count($selected)>500)throw new \RuntimeException('Unsupported table count.');
        sort($selected);
        $out=fopen($partial,'xb');if(!$out)throw new \RuntimeException('Cannot create database snapshot.');
        chmod($partial,0600);$bytes=0;$complete=false;
        // Fatal PHP timeout cannot enter catch; leave no artifact that could be advertised as complete.
        register_shutdown_function(static function()use(&$complete,$partial){if(!$complete&&is_file($partial))@unlink($partial);});
        $write=static function(string $sql)use($out,&$bytes,$check){
            $check();$bytes+=strlen($sql);
            if($bytes>self::MAX_BYTES)throw new \RuntimeException('Database snapshot exceeds the 256 MiB or 40 second limit.');
            if(fwrite($out,$sql)!==strlen($sql))throw new \RuntimeException('Cannot write database snapshot.');
        };
        $query=static function(string $sql)use($db,$check){$check();if($db->query($sql)===false)throw new \RuntimeException('Database snapshot query failed.');$check();};
        try{
            $query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $write("-- Zoer Connect database snapshot\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            foreach($selected as $table){
                $create=$db->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
                if($db->last_error||!is_array($create)||!isset($create[1]))throw new \RuntimeException('Cannot inspect table schema.');
                $write("DROP TABLE IF EXISTS `$table`;\n".$create[1].";\n");
                $keys=$db->get_results("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'",ARRAY_A);
                $check();
                if($db->last_error||!is_array($keys)||!$keys)throw new \RuntimeException('Pull requires primary keys for stable table ordering.');
                usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
                $order=[];
                foreach($keys as $key){if(!preg_match('/^[A-Za-z0-9_]+$/D',$key['Column_name']))throw new \RuntimeException('Unsupported primary key.');$order[]='`'.$key['Column_name'].'`';}
                $order=implode(',',$order);
                $where='';
                if($table===$prefix.'options')$where=" WHERE option_name NOT IN ('zoer_connect_connection','zoer_connect_profiles') AND option_name NOT LIKE 'wpmdb%' AND option_name NOT LIKE '_transient_%' AND option_name NOT LIKE '_site_transient_%'";
                if($table===$prefix.'usermeta')$where=" WHERE meta_key NOT IN ('_application_passwords','session_tokens')";
                for($offset=0;;$offset+=200){
                    $rows=$db->get_results("SELECT * FROM `$table`$where ORDER BY $order LIMIT 200 OFFSET $offset",ARRAY_A);
                    $check();
                    if($db->last_error || !is_array($rows))throw new \RuntimeException('Cannot read table snapshot.');
                    foreach($rows as $row){
                        $columns=[];$values=[];
                        foreach($row as $column=>$value){
                            if(!preg_match('/^[A-Za-z0-9_]+$/D',$column))throw new \RuntimeException('Unsupported column name.');
                            $columns[]='`'.$column.'`';$values[]=$value===null?'NULL':"X'".bin2hex((string)$value)."'";
                        }
                        $write('INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");
                    }
                    if(count($rows)<200)break;
                }
                $after=$db->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
                if($db->last_error||$after!==$create)throw new \RuntimeException('Schema changed during export.');
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");$query('COMMIT');
            if(!fflush($out))throw new \RuntimeException('Cannot flush snapshot.');
            fclose($out);$out=null;
            $check();
            if(!rename($partial,$destination))throw new \RuntimeException('Cannot finalize snapshot.');
            $complete=true;
        }catch(\Throwable $e){$db->query('ROLLBACK');if(is_resource($out))fclose($out);if(is_file($partial))unlink($partial);throw $e;}
    }
}
