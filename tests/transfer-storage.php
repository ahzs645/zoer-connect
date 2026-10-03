<?php
require_once __DIR__.'/../includes/TransferStorage.php';
use ZoerConnect\TransferStorage;
$quota=17179869184;function get_option($name,$default=null){global $quota;return $quota;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function denied($f,$s){try{$f();}catch(Throwable $e){check(true,$s);return;}throw new RuntimeException($s);}
check(TransferStorage::quota()===17179869184,'default incoming quota allows transfers beyond 2 GiB');
TransferStorage::size(4294967296,17179869184);
denied(fn()=>TransferStorage::size(4294967297,4294967297),'per-artifact ceiling checked independently of total quota');
$quota=1048576;TransferStorage::size(1048576,1048576);denied(fn()=>TransferStorage::size(1,1048577),'configured total quota applies across artifacts');
foreach([0,-1,1048575,68719476737,1.5,true,'1e10','junk'] as $quota)denied(fn()=>TransferStorage::quota(),'invalid quota refused');
$quota='68719476736';check(TransferStorage::quota()===68719476736,'maximum configured quota accepted on 64-bit PHP');
denied(fn()=>TransferStorage::allocation(sys_get_temp_dir(),PHP_INT_MAX-67108864),'free space refusal happens before allocation');
