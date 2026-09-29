<?php
namespace ZoerConnect;

/** Read-only, single InnoDB snapshot. No shell commands and no source writes. */
final class DatabaseExporter {
    public const MAX_BYTES = 268435456;
    /** Export `database` input: true/false keep 0.3.14 semantics; an object selects the database with filters. */
    public static function selection(mixed $value): bool|array {
        if(is_bool($value))return $value;
        if(!is_array($value))throw new \InvalidArgumentException('Database selection must be a boolean or filter object.');
        return self::filters($value);
    }
    /** Validates optional Pull filters (API A3). Missing keys keep 0.3.14 behaviour: every prefixed
     * table, every post type, transients excluded. Values are embedded only after strict validation. */
    public static function filters(array $input): array {
        if(array_diff(array_keys($input),['tables','postTypes','excludeRevisions','excludeSpam','excludeTransients']))throw new \InvalidArgumentException('Unknown database filter.');
        $out=['tables'=>null,'postTypes'=>null,'excludeRevisions'=>false,'excludeSpam'=>false,'excludeTransients'=>true];
        foreach(['tables'=>'/^[A-Za-z0-9_]{1,64}$/D','postTypes'=>'/^[A-Za-z0-9_-]{1,64}$/D'] as $key=>$pattern){
            $list=$input[$key]??null;if($list===null)continue;
            if(!is_array($list)||!array_is_list($list)||count($list)>500)throw new \InvalidArgumentException('Invalid database '.$key.' filter.');
            foreach($list as $item)if(!is_string($item)||!preg_match($pattern,$item))throw new \InvalidArgumentException('Invalid database '.$key.' filter.');
            $list=array_values(array_unique($list));sort($list);$out[$key]=$list;
        }
        foreach(['excludeRevisions','excludeSpam','excludeTransients'] as $key){if(isset($input[$key])&&!is_bool($input[$key]))throw new \InvalidArgumentException('Invalid database filter switch.');$out[$key]=$input[$key]??$out[$key];}
        return $out;
    }
    /** Row filters in WP Migrate style. Dependent rows are dropped only when their parent row is excluded, so
     * orphaned meta, comments without a post and link-category relationships keep 0.3.14 behaviour. */
    private static function where(string $table,string $prefix,array $f,array $present,bool $self=false): string {
        $list=static fn(array $v)=>$v?"'".implode("','",$v)."'":'';
        $keep=[];
        if($f['postTypes']!==null)$keep[]=$f['postTypes']?'post_type IN ('.$list($f['postTypes']).')':'0=1';
        if($f['excludeRevisions'])$keep[]="post_type<>'revision'";
        $posts=$keep&&isset($present[$prefix.'posts']);
        $droppedPosts=$posts?"SELECT ID FROM `{$prefix}posts` WHERE NOT (".implode(' AND ',$keep).')':null;
        $droppedComment=[];
        if($f['excludeSpam'])$droppedComment[]="comment_approved='spam'";
        if($droppedPosts!==null)$droppedComment[]="comment_post_ID IN ($droppedPosts)";
        $where=[];
        if($table===$prefix.'options'){
            // `_` is a LIKE wildcard; escape it so only real transient rows match.
            // A self-snapshot (replace kind) replaces this site's own options: keep other tools' settings.
            $where[]="option_name NOT IN ('zoer_connect_connection','zoer_connect_profiles','zoer_connect_storage_dir')".($self?'':" AND option_name NOT LIKE 'wpmdb%'");
            if($f['excludeTransients'])$where[]="option_name NOT LIKE '|_transient|_%' ESCAPE '|' AND option_name NOT LIKE '|_site|_transient|_%' ESCAPE '|'";
        }
        if($table===$prefix.'usermeta')$where[]="meta_key NOT IN ('_application_passwords','session_tokens')";
        if($table===$prefix.'posts'&&$posts)$where[]=implode(' AND ',$keep);
        if($table===$prefix.'postmeta'&&$droppedPosts!==null)$where[]="post_id NOT IN ($droppedPosts)";
        if($table===$prefix.'term_relationships'&&$droppedPosts!==null)$where[]="(object_id NOT IN ($droppedPosts)".(isset($present[$prefix.'term_taxonomy'])?" OR term_taxonomy_id IN (SELECT term_taxonomy_id FROM `{$prefix}term_taxonomy` WHERE taxonomy='link_category')":'').')';
        if($table===$prefix.'comments'&&$droppedComment)$where[]='NOT ('.implode(' OR ',$droppedComment).')';
        if($table===$prefix.'commentmeta'&&$droppedComment&&isset($present[$prefix.'comments']))$where[]="comment_id NOT IN (SELECT comment_ID FROM `{$prefix}comments` WHERE ".implode(' OR ',$droppedComment).')';
        return $where?' WHERE '.implode(' AND ',$where):'';
    }
    /** Returns the exported table suffixes (names after the prefix). */
    public static function write($db, string $destination, ?callable $clock = null, float $budgetSeconds = 40, array $filters = [], bool $selfSnapshot = false): array {
        $filters=self::filters($filters);
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
        $selected=[];$present=[];
        foreach($tables as $table){
            $name=$table['Name'];
            if(!str_starts_with($name,$prefix))continue;
            $present[$name]=true;
            // A table subset may leave out an unsupported table; unselected tables are never read.
            if($filters['tables']!==null&&!in_array(substr($name,strlen($prefix)),$filters['tables'],true))continue;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$name)||strtolower((string)$table['Engine'])!=='innodb')throw new \RuntimeException('Pull requires InnoDB tables.');
            $selected[]=$name;
        }
        if($filters['tables']!==null)foreach($filters['tables'] as $suffix)if(!in_array($prefix.$suffix,$selected,true))throw new \InvalidArgumentException('Selected database table is unavailable.');
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
                $where=self::where($table,$prefix,$filters,$present,$selfSnapshot);
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
            return array_map(static fn($t)=>substr($t,strlen($prefix)),$selected);
        }catch(\Throwable $e){$db->query('ROLLBACK');if(is_resource($out))fclose($out);if(is_file($partial))unlink($partial);throw $e;}
    }
}
