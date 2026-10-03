<?php
require __DIR__.'/../includes/BatchUpload.php';
use ZoerConnect\BatchUpload;
if(function_exists('inflate_init')&&function_exists('inflate_add'))throw new RuntimeException('Run with either inflation function disabled.');
$compressed=hex2bin('789caba8200f0000f7900f01'); // zlib stream for 32 x bytes.
$header=['v'=>1,'enc'=>'deflate','payloadBytes'=>strlen($compressed),'spans'=>[[0,0,32,strlen($compressed)]]];
$raw=json_encode($header);$h=fopen('php://temp','w+b');fwrite($h,'ZBT1'.pack('N',strlen($raw)).$raw.$compressed);rewind($h);
foreach([
 fn()=>BatchUpload::framed($h,8+strlen($raw)+strlen($compressed),1048576),
 fn()=>BatchUpload::json(['v'=>1,'enc'=>'deflate','spans'=>[[0,0,32,base64_encode($compressed)]]]),
] as $parse){try{$parse();throw new RuntimeException('Unavailable deflate accepted.');}catch(InvalidArgumentException $e){if($e->getMessage()!=='Deflate is unavailable on this server.')throw $e;}}
fclose($h);
$plain=BatchUpload::json(['v'=>1,'spans'=>[[0,0,32,base64_encode(str_repeat('x',32))]]]);
$plain->next();if($plain->read(32)!==str_repeat('x',32))throw new RuntimeException('Uncompressed upload refused.');$plain->end();
echo "PASS either disabled inflation function safely refuses deflate while plain uploads remain available\n";
