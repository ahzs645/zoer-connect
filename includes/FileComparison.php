<?php
namespace ZoerConnect;
require_once __DIR__.'/StageStore.php';

/** Read-only, bounded destination fingerprints. Never follows symbolic links. */
final class FileComparison {
    public const MAX_HASH_BYTES=33554432;
    public static function fingerprint(string $root,string $path): ?string {
        StageStore::validateManifest(['version'=>1,'target'=>'compare','files'=>[['path'=>$path,'bytes'=>0,'sha256'=>hash('sha256','')]]],'compare');
        $p=realpath($root);if(!$p)throw new \RuntimeException('Destination unavailable.');
        foreach(explode('/',$path) as $part){$p.='/'.$part;clearstatcache(true,$p);if(is_link($p))throw new \RuntimeException('Symbolic links cannot be compared.');if(file_exists($p)&&$p!==realpath($p))throw new \RuntimeException('Destination path changed.');}
        if(!file_exists($p))return null;
        if(!is_file($p)||!is_readable($p)||filesize($p)>self::MAX_HASH_BYTES)throw new \RuntimeException('File cannot be compared within the bounded request.');
        $before=stat($p);$hash=hash_file('sha256',$p);clearstatcache(true,$p);$after=stat($p);
        if(!$hash||array_intersect_key($before,array_flip(['ino','size','mtime','ctime']))!==array_intersect_key($after,array_flip(['ino','size','mtime','ctime'])))throw new \RuntimeException('Destination changed during comparison.');
        return $hash;
    }
    public static function compare(string $root,array $files): array {
        if(!array_is_list($files)||!$files||count($files)>20)throw new \InvalidArgumentException('Compare one to twenty files.');
        $results=[];$start=microtime(true);
        foreach($files as $file){
            $path=$file['path']??null;if(!is_string($path))throw new \InvalidArgumentException('File path required.');
            try{$hash=self::fingerprint($root,$path);$results[]=['path'=>$path,'sha256'=>$hash];}
            catch(\Throwable $e){$results[]=['path'=>$path,'blocked'=>true];}
            if(microtime(true)-$start>=2)break;
        }
        return ['files'=>$results];
    }
}
