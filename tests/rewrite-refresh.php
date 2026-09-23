<?php
define('ARRAY_A', 'ARRAY_A');
require_once __DIR__.'/../includes/SettingsPreservation.php';
require_once __DIR__.'/../includes/TableStage.php';

use ZoerConnect\RewriteRefresh;
use ZoerConnect\SettingsPreservation;
use ZoerConnect\TableStage;

$options=[];$flushes=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function flush_rewrite_rules($hard){$GLOBALS['flushes'][]=$hard;$GLOBALS['options']['rewrite_rules']='fresh';}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}

$db=new class {
    public string $prefix='wp_',$options='wp_options',$users='wp_users',$usermeta='wp_usermeta',$dbname='test';
    public array $stage=['blog_public'=>['option_name'=>'blog_public','option_value'=>'0','autoload'=>'yes'],
        RewriteRefresh::OPTION=>['option_name'=>RewriteRefresh::OPTION,'option_value'=>'1','autoload'=>'no'],
        'active_plugins'=>['option_name'=>'active_plugins','option_value'=>'a:0:{}','autoload'=>'yes']];
    public array $destination=['blog_public'=>['option_name'=>'blog_public','option_value'=>'1','autoload'=>'yes']];
    public function prepare($sql,...$values){foreach($values as $value)$sql=preg_replace('/%s/',"'".str_replace("'","''",$value)."'",$sql,1);return $sql;}
    public function get_row($sql,$format){preg_match("/option_name='([^']+)'/",$sql,$m);return $this->destination[$m[1]??'']??null;}
    public function delete($table,$where){unset($this->stage[$where['option_name']]);return 1;}
    public function insert($table,$row){$this->stage[$row['option_name']]=$row;return 1;}
    public function get_var($sql){return $this->stage['active_plugins']['option_value'];}
    public function replace($table,$row){if($table===$this->options)$GLOBALS['options'][$row['option_name']]=$row['option_value'];else $this->stage[$row['option_name']]=$row;return 1;}
    public function esc_like($value){return $value;}
    public function query($sql){return 1;}
};

SettingsPreservation::apply($db,'zoer_s_'.str_repeat('a',16));
check($db->stage['blog_public']['option_value']==='1','Destination search visibility was replaced by the source.');
check(!isset($db->stage[RewriteRefresh::OPTION]),'A source permalink refresh marker was imported.');
check(in_array('zoer-connect/zoer-connect.php',unserialize($db->stage['active_plugins']['option_value'],['allowed_classes'=>false]),true),'Connector activation was lost.');
$table=new TableStage($db,$db->options,str_repeat('a',16),true);
$ephemeral=new ReflectionMethod(TableStage::class,'ephemeral');
check($ephemeral->invoke($table,['option_name'=>'rewrite_rules']) && $ephemeral->invoke($table,['option_name'=>RewriteRefresh::OPTION]),'Generated route state would block rollback.');
check(!$ephemeral->invoke($table,['option_name'=>'permalink_structure']) && !$ephemeral->invoke($table,['option_name'=>'blog_public']),'Authored permalink or search settings were excluded from rollback protection.');

check(!RewriteRefresh::needed(['hasDb'=>false,'artifacts'=>[['path'=>'wp-content/uploads/image.jpg']]]),'Media-only Push scheduled a permalink refresh.');
check(RewriteRefresh::needed(['hasDb'=>true,'artifacts'=>[]]),'Database Push did not schedule a permalink refresh.');
check(RewriteRefresh::queue($db,['hasDb'=>false,'artifacts'=>[['path'=>'wp-content/themes/fixture/style.css']]]),'Theme Push could not schedule a permalink refresh.');
$wp_rewrite=new class {public array $endpoints=['custom'];public int $initializations=0;public function init(){$this->initializations++;$this->endpoints=[];}};
RewriteRefresh::run();
check($flushes===[false]&&$wp_rewrite->initializations===1&&$wp_rewrite->endpoints===['custom'],'Soft permalink refresh did not preserve registered endpoints.');
check(!isset($options[RewriteRefresh::OPTION]),'Completed permalink refresh remained queued.');
RewriteRefresh::run();
check(count($flushes)===1,'Completed permalink refresh ran again.');
echo "PASS destination search visibility and one-time soft permalink refresh\n";
