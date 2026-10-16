<?php
require __DIR__.'/../includes/CachePurge.php';
use ZoerConnect\CachePurge;
$options=[];$calls=[];$hooks=['litespeed_purge_all'=>true,'breeze_clear_all_cache'=>true];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function has_action($hook){return isset($GLOBALS['hooks'][$hook]);}
function do_action($hook,...$args){$GLOBALS['calls'][]=$hook;if($hook==='breeze_clear_all_cache')throw new RuntimeException('broken cache plugin');}
function rocket_clean_domain(){$GLOBALS['calls'][]='rocket';return true;}
function wp_cache_flush(){$GLOBALS['calls'][]='object';return $GLOBALS['objectFlush']??true;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new class{public $options='wp_options';public array $rows=[];public function replace($table,$row){$this->rows[$row['option_name']]=$row['option_value'];return 1;}};
check(CachePurge::queue($db,['options'=>['purgeCaches'=>false]])&&$db->rows===[],'Purge queued without request.');
check(CachePurge::queue($db,['hasDb'=>true])&&$db->rows===[],'Legacy import queued a purge.');
check(CachePurge::queue($db,['id'=>str_repeat('a',32),'options'=>['purgeCaches'=>true]])&&($db->rows[CachePurge::OPTION]??null)==='1','Requested purge not queued.');
$queued=json_decode($db->rows[CachePurge::RECEIPT]??'null',true);
check(($queued['state']??null)==='queued'&&$queued['importId']===str_repeat('a',32)&&$queued['layers']===[],'A queued purge must say queued, not done.');
CachePurge::run();check($calls===[],'Purge ran without a queued marker.');
$options[CachePurge::OPTION]='1';$options[CachePurge::RECEIPT]=$db->rows[CachePurge::RECEIPT];CachePurge::run();
check(!isset($options[CachePurge::OPTION])&&in_array('litespeed_purge_all',$calls,true)&&in_array('rocket',$calls,true)&&in_array('cache_enabler_clear_complete_cache',$calls,true)&&end($calls)==='object','Known cache purges did not run past a failing integration.');
$receipt=CachePurge::receipt();$status=array_column($receipt['layers'],'status','key');
check($receipt['state']==='ran'&&$receipt['source']==='import'&&$receipt['importId']===str_repeat('a',32),'Receipt lost the queued import.');
check($status['litespeed']==='requested'&&$status['wp-rocket']==='requested'&&$status['breeze']==='failed'&&$status['w3tc']==='not-detected'&&$status['object-cache']==='flushed','Per-layer receipt is wrong: '.json_encode($status));
check(str_contains($receipt['scope'],'CDN'),'Receipt must say hosting/CDN caches are not covered.');
$count=count($calls);CachePurge::run();check(count($calls)===$count,'Completed purge ran again.');
$objectFlush=false;$now=CachePurge::purgeNow();$status=array_column($now['layers'],'status','key');
check($now['source']==='request'&&$status['object-cache']==='failed','An object-cache failure must be reported, not hidden.');
echo "PASS queued one-time cache purge isolates failing cache integrations and leaves per-layer receipts\n";
