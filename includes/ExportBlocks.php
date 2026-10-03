<?php
namespace ZoerConnect;
require_once __DIR__.'/TransferStorage.php';
/** An ordered SHA-256 of the binary 256 KiB block digests. The authenticated
 * manifest also binds the exact byte count. No PHP hash context is serialized.
 * A lost request replays from its journal offset and truncates uncommitted tails. */
final class ExportBlocks {
    public const FORMAT='sha256-blocks-v1';
    public static function append(string $source,string $target,int $offset,int $bytes):int {
        if(is_link($source)||is_link($target)||is_link($target.'.blocks'))throw new \RuntimeException('Unsafe snapshot.');
        if($offset>0&&(!is_file($target)||filesize($target)<$offset||!is_file($target.'.blocks')||filesize($target.'.blocks')<intdiv($offset,262144)*32))throw new \RuntimeException('Snapshot checkpoint bytes unavailable.');
        TransferStorage::allocation(dirname($target),min(4194304,$bytes-$offset));
        $in=fopen($source,'rb');$out=fopen($target,'c+b');$hashes=fopen($target.'.blocks','c+b');
        if(!$in||!$out||!$hashes)throw new \RuntimeException('Cannot open export block.');
        try {
            if(!chmod($target,0600)||!chmod($target.'.blocks',0600)||fseek($in,$offset)!==0||!ftruncate($out,$offset)||fseek($out,$offset)!==0||!ftruncate($hashes,intdiv($offset,262144)*32)||fseek($hashes,intdiv($offset,262144)*32)!==0)throw new \RuntimeException('Cannot resume export block.');
            $started=microtime(true);$end=min($bytes,$offset+4194304);
            while($offset<$end&&microtime(true)-$started<2){
                $length=min(262144,$bytes-$offset);$data='';
                while(strlen($data)<$length){$v=fread($in,$length-strlen($data));if($v===false||$v==='')throw new \RuntimeException('Source changed during pull.');$data.=$v;}
                if(fwrite($out,$data)!==$length||fwrite($hashes,hash('sha256',$data,true))!==32)throw new \RuntimeException('Cannot persist export block.');$offset+=$length;
            }
            foreach([$out,$hashes] as $h)if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))throw new \RuntimeException('Cannot flush export block.');
            return $offset;
        }finally{foreach([$in,$out,$hashes] as $h)if(is_resource($h))fclose($h);}
    }
    /** Seal an existing database, reading a bounded number of blocks per call. */
    public static function seal(string $path,int $offset,int $bytes):int {
        if(is_link($path)||is_link($path.'.blocks'))throw new \RuntimeException('Unsafe snapshot.');
        $in=fopen($path,'rb');$out=fopen($path.'.blocks','c+b');if(!$in||!$out)throw new \RuntimeException('Cannot seal snapshot.');
        try {
            if(fseek($in,$offset)!==0||!ftruncate($out,intdiv($offset,262144)*32)||fseek($out,intdiv($offset,262144)*32)!==0||!chmod($path.'.blocks',0600))throw new \RuntimeException('Cannot resume snapshot seal.');
            for($n=0;$n<16&&$offset<$bytes;$n++){$length=min(262144,$bytes-$offset);$data=fread($in,$length);if(!is_string($data)||strlen($data)!==$length||fwrite($out,hash('sha256',$data,true))!==32)throw new \RuntimeException('Cannot seal snapshot block.');$offset+=$length;}
            if(!fflush($out)||(function_exists('fsync')&&!fsync($out)))throw new \RuntimeException('Cannot flush block seals.');return $offset;
        }finally{foreach([$in,$out] as $h)if(is_resource($h))fclose($h);}
    }
    /** Build block seals from bytes already verified against a committed SQL part.
     * The tail is at most one block; only bytes/indices, never a hash context, persist. */
    public static function sealPart(string $path,array $part,int $offset,string $tail,bool $last):array {
        if(is_link($path)||is_link($path.'.blocks')||$offset%262144!==0||strlen($tail)>=262144||$offset+strlen($tail)!==$part['offset']||$part['bytes']>20971520)throw new \RuntimeException('Invalid snapshot part checkpoint.');
        $h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Cannot verify snapshot part.');
        try{if(fseek($h,$part['offset'])!==0)throw new \RuntimeException('Cannot seek snapshot part.');$data='';while(strlen($data)<$part['bytes']){$v=fread($h,min(262144,$part['bytes']-strlen($data)));if($v===false||$v==='')throw new \RuntimeException('Snapshot part truncated.');$data.=$v;}}finally{fclose($h);}
        if(!hash_equals($part['sha256'],hash('sha256',$data)))throw new \RuntimeException('Snapshot part checksum mismatch.');
        $data=$tail.$data;$out=fopen($path.'.blocks','c+b');if(!$out)throw new \RuntimeException('Cannot seal snapshot part.');
        try{if(!chmod($path.'.blocks',0600)||!ftruncate($out,intdiv($offset,262144)*32)||fseek($out,intdiv($offset,262144)*32)!==0)throw new \RuntimeException('Cannot resume part seals.');
            $used=0;while(strlen($data)-$used>=262144||($last&&$used<strlen($data))){$block=substr($data,$used,262144);if(fwrite($out,hash('sha256',$block,true))!==32)throw new \RuntimeException('Cannot persist part seals.');$used+=strlen($block);$offset+=strlen($block);}
            if(!fflush($out)||(function_exists('fsync')&&!fsync($out)))throw new \RuntimeException('Cannot flush part seals.');return ['offset'=>$offset,'tail'=>base64_encode(substr($data,$used))];
        }finally{fclose($out);}
    }
    /** Re-read the source in bounded requests before advertising its snapshot. */
    public static function verifySource(string $source,string $snapshot,int $offset,int $bytes):int {
        $h=fopen($source,'rb');if(!$h)throw new \RuntimeException('Source changed during pull.');
        try {
            if($offset%262144!==0||fseek($h,$offset)!==0)throw new \RuntimeException('Invalid source verification offset.');
            $started=microtime(true);
            for($n=0;$n<16&&$offset<$bytes&&microtime(true)-$started<2;$n++){
                $length=min(262144,$bytes-$offset);$data=fread($h,$length);
                if(!is_string($data)||strlen($data)!==$length)throw new \RuntimeException('Source changed during pull.');
                self::verify($snapshot,$offset,$data);$offset+=$length;
            }
            return $offset;
        }finally{fclose($h);}
    }
    public static function entry(string $path,string $relative,int $bytes):array {
        clearstatcache();if(filesize($path)!==$bytes||filesize($path.'.blocks')!==(int)ceil($bytes/262144)*32)throw new \RuntimeException('Snapshot block seal incomplete.');
        return ['path'=>$relative,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$path.'.blocks'),'digestFormat'=>self::FORMAT];
    }
    public static function verify(string $path,int $offset,string $data):void {
        $h=fopen($path.'.blocks','rb');if(!$h)throw new \RuntimeException('Snapshot seals unavailable.');try{if($offset%262144!==0||fseek($h,intdiv($offset,262144)*32)!==0||!hash_equals(fread($h,32),hash('sha256',$data,true)))throw new \RuntimeException('Snapshot block changed.');}finally{fclose($h);}
    }
}
