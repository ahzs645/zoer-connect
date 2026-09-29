<?php
require __DIR__.'/../includes/CachePurge.php';
use ZoerConnect\CachePurge;
$options=[];$calls=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function do_action($hook,...$args){$GLOBALS['calls'][]=$hook;if($hook==='breeze_clear_all_cache')throw new RuntimeException('broken cache plugin');}
function rocket_clean_domain(){$GLOBALS['calls'][]='rocket';return true;}
function wp_cache_flush(){$GLOBALS['calls'][]='object';return true;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new class{public $options='wp_options';public array $rows=[];public function replace($table,$row){$this->rows[$row['option_name']]=$row['option_value'];return 1;}};
check(CachePurge::queue($db,['options'=>['purgeCaches'=>false]])&&$db->rows===[],'Purge queued without request.');
check(CachePurge::queue($db,['hasDb'=>true])&&$db->rows===[],'Legacy import queued a purge.');
check(CachePurge::queue($db,['options'=>['purgeCaches'=>true]])&&$db->rows===[CachePurge::OPTION=>'1'],'Requested purge not queued.');
CachePurge::run();check($calls===[],'Purge ran without a queued marker.');
$options[CachePurge::OPTION]='1';CachePurge::run();
check(!isset($options[CachePurge::OPTION])&&in_array('litespeed_purge_all',$calls,true)&&in_array('rocket',$calls,true)&&in_array('cache_enabler_clear_complete_cache',$calls,true)&&end($calls)==='object','Known cache purges did not run past a failing integration.');
$count=count($calls);CachePurge::run();check(count($calls)===$count,'Completed purge ran again.');
echo "PASS queued one-time cache purge isolates failing cache integrations\n";
