<?php
namespace ZoerConnect;

/** Incremental parser for immutable, digest-verified Zoer snapshots. Imported SQL
 * is parsed as data and is never executed. Cursor state belongs in a private journal,
 * never in a request body. A single row/schema is bounded; total snapshot size is not.
 */
final class SnapshotStream {
    public const HEADER = "-- Zoer Connect database snapshot\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    public const MAX_RECORD_BYTES = 16777216;
    public static function initial(): array { return ['offset'=>0,'current'=>null,'seen'=>[],'done'=>false]; }
    public static function schema(string $sql, string $table): string {
        $prefix='CREATE TABLE `'.$table.'` ';
        if (!str_starts_with($sql,$prefix)) throw new \InvalidArgumentException('Unexpected schema header.');
        return preg_replace('/ AUTO_INCREMENT=[0-9]+(?= |$)/','',substr($sql,strlen($prefix)));
    }
    private static function line($h): string {
        $line=fgets($h,self::MAX_RECORD_BYTES+2);
        if (!is_string($line)||!str_ends_with($line,"\n")||strlen($line)>self::MAX_RECORD_BYTES) throw new \InvalidArgumentException('Incomplete or oversized snapshot record.');
        return $line;
    }
    /** Read up to $budget records. Persist returned cursor only after returned rows
     * have been durably applied with idempotent sequence IDs. Replaying a cursor
     * returns identical records. Empty selected tables appear as schema records.
     */
    public static function read(string $path, array $schemas, array $cursor, int $budget=100,bool $reportSchemas=false): array {
        if ($budget<1||$budget>250||!is_int($cursor['offset']??null)||$cursor['offset']<0||!is_array($cursor['seen']??null)||!is_bool($cursor['done']??null)) throw new \InvalidArgumentException('Invalid snapshot cursor.');
        if ($cursor['done']) return ['cursor'=>$cursor,'records'=>[]];
        $h=fopen($path,'rb');
        if (!$h) throw new \RuntimeException('Cannot open snapshot.');
        $records=[]; $bytes=0;
        try {
            if (fseek($h,$cursor['offset'])!==0) throw new \RuntimeException('Cannot resume snapshot.');
            if ($cursor['offset']===0 && fread($h,strlen(self::HEADER))!==self::HEADER) throw new \InvalidArgumentException('Unsupported snapshot header.');
            for ($i=0;$i<$budget;$i++) {
                $line=self::line($h); $bytes+=strlen($line);
                if ($line==="SET FOREIGN_KEY_CHECKS=1;\n") {
                    if (fread($h,1)!==''||!$cursor['seen']||array_diff(array_keys($schemas),array_keys($cursor['seen']))) throw new \InvalidArgumentException('Incomplete or trailing snapshot data.');
                    $cursor['done']=true; $cursor['offset']=ftell($h); break;
                }
                if (preg_match('/^DROP TABLE IF EXISTS `([A-Za-z0-9_]+)`;\n$/D',$line,$m)) {
                    $table=$m[1];
                    if (isset($cursor['seen'][$table])||count($cursor['seen'])>=500) throw new \InvalidArgumentException('Duplicate or excessive tables.');
                    $create='';
                    do {
                        $part=self::line($h); $create.=$part;
                        if (strlen($create)>self::MAX_RECORD_BYTES) throw new \InvalidArgumentException('Oversized schema.');
                    } while (!str_ends_with($create,";\n"));
                    $create=substr($create,0,-2); $body=self::schema($create,$table);
                    if (isset($schemas[$table])&&$body!==self::schema($schemas[$table],$table)) throw new \InvalidArgumentException('Source and destination schemas differ.');
                    $cursor['seen'][$table]=true; $cursor['current']=$table;
                    if (isset($schemas[$table])||$reportSchemas) $records[]=['type'=>'schema','table'=>$table,'schema'=>$create];
                } else {
                    if (!preg_match('/^INSERT INTO `([A-Za-z0-9_]+)` \((`[A-Za-z0-9_]+`(?:,`[A-Za-z0-9_]+`)*)\) VALUES \((.*)\);\n$/D',$line,$m)||$m[1]!==$cursor['current']) throw new \InvalidArgumentException('Unsupported snapshot statement.');
                    $columns=array_map(static fn($c)=>trim($c,'`'),explode(',',$m[2]));
                    $cells=explode(',',$m[3]); $values=[];
                    foreach ($cells as $cell) {
                        if ($cell==='NULL') $values[]=null;
                        elseif (strlen($cell)>=3&&substr($cell,0,2)==="X'"&&substr($cell,-1)==="'"&&strlen($cell)%2===1&&($cell==="X''"||ctype_xdigit(substr($cell,2,-1)))) $values[]=hex2bin(substr($cell,2,-1));
                        else throw new \InvalidArgumentException('Unsupported snapshot cell.');
                    }
                    if (count($columns)!==count($values)||count(array_unique($columns))!==count($columns)) throw new \InvalidArgumentException('Invalid snapshot columns.');
                    if (isset($schemas[$m[1]])) $records[]=['type'=>'row','table'=>$m[1],'row'=>array_combine($columns,$values)];
                }
                $cursor['offset']=ftell($h);
                if ($bytes>=1048576) break;
            }
            return ['cursor'=>$cursor,'records'=>$records];
        } finally { fclose($h); }
    }
}
