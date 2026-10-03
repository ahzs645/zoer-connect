<?php
namespace ZoerConnect;
/** Per-transfer quota. Free-space checks are repeated before every allocation. */
final class TransferStorage {
    public const FILE_BYTES=4294967296;
    public const RESERVE_BYTES=67108864;
    public static function quota():int {
        $value=defined('ZOER_CONNECT_TRANSFER_QUOTA_BYTES')?ZOER_CONNECT_TRANSFER_QUOTA_BYTES:(function_exists('get_option')?get_option('zoer_connect_transfer_quota_bytes',17179869184):17179869184);
        if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new \RuntimeException('Invalid transfer storage quota.');
        $value=(int)$value;if(PHP_INT_SIZE<8||$value<1048576||$value>68719476736)throw new \RuntimeException('Invalid transfer storage quota.');return $value;
    }
    public static function allocation(string $directory,int $bytes):void {
        $free=disk_free_space($directory);
        if($bytes<0||$free===false||$free<$bytes+self::RESERVE_BYTES)throw new \RuntimeException('Insufficient private transfer storage.');
    }
    public static function size(int $bytes,int $total):void {
        if($bytes<0||$bytes>self::FILE_BYTES||$total>self::quota())throw new \RuntimeException('Transfer exceeds its storage quota or per-file limit.');
    }
}
