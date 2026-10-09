<?php
// A source snapshot with another table prefix: its `{source}user_roles` row is not
// imported as an orphan option, and the destination keeps its own roles row.
require __DIR__.'/fixtures/import-site.php';
use ZoerConnect\RewriteRefresh;
use ZoerConnect\SettingsPreservation;
use ZoerConnect\TransferImport;
function reprefix(MemoryDb $db,string $prefix): MemoryDb {$out=new MemoryDb($prefix);foreach($db->tables as $name=>$table)$out->tables[$prefix.substr($name,strlen($db->prefix))]=$table;return $out;}
expect(SettingsPreservation::orphanRoles('src_','wp_','src_user_roles')&&SettingsPreservation::orphanRoles('WP_','wp_','WP_user_roles'),'source-prefixed roles are orphans when prefixes differ');
expect(!SettingsPreservation::orphanRoles('wp_','wp_','wp_user_roles')&&!SettingsPreservation::orphanRoles('src_','wp_','wp_user_roles')&&!SettingsPreservation::orphanRoles('src_','wp_','src_user_roles_backup'),'same prefix, destination roles and other options are not orphans');
$base=fixtureBase();$target='https://dest.example';$owner=hash('sha256','prefix roles owner');
$db=destination();
$destinationRoles=serialize(['administrator'=>['name'=>'Administrator','capabilities'=>['manage_options'=>true]]]);
$sourceRoles=serialize(['administrator'=>['name'=>'Administrator','capabilities'=>[]],'shop_manager'=>['name'=>'Shop manager','capabilities'=>[]]]);
$db->replace('wp_options',['option_name'=>'wp_user_roles','option_value'=>$destinationRoles,'autoload'=>'yes']);
$new=fn()=>new TransferImport($db,$base.'/public',$base.'/private',$owner,$target,true);
$names=fn()=>array_column($db->dump('wp_options'),'option_name');
try{
 $new()->installFence();
 $src=reprefix(source(),'src_');
 $src->replace('src_options',['option_name'=>'src_user_roles','option_value'=>$sourceRoles,'autoload'=>'yes']);
 $dump=snapshot($src,$base.'/src.sql');
 $body=fn(string $prefix,array $dump)=>['id'=>bin2hex(random_bytes(16)),'target'=>$target,'sourceUrl'=>'https://source.example','sourcePrefix'=>$prefix,'wordpressOnlyWriters'=>true,'destinationAdminId'=>1,'database'=>$dump];
 $before=$db->dump('wp_options');
 foreach([[],['options'=>['fence'=>'activation']]] as $extra){
  $s=upload($new,$body('src_',$dump)+$extra,[file_get_contents($base.'/src.sql')]);
  [$s]=drive($new,$s['id'],['verification_required']);
  $label=$extra?'activation fence':'default pipeline';
  expect(!in_array('src_user_roles',$names(),true)&&option($db,'wp_user_roles')===$destinationRoles,"$label: source-prefixed roles dropped, destination roles preserved");
  expect(option($db,'blogname')==='Acme Blog'&&$s['tableRows'][array_search('wp_options',array_column($s['stats']['tables'],'name'),true)]===count($src->dump('src_options'))-1,"$label: other source options imported and the dropped row is not counted");
  $s=$new()->finish($s['id']);expect($s['phase']==='complete',"$label: import finishes");
  $s=rollbackAll($new,$s['id']);$db->delete('wp_options',['option_name'=>RewriteRefresh::OPTION]);
  expect($s['phase']==='rolled_back'&&array_column($db->dump('wp_options'),'option_value','option_name')===array_column($before,'option_value','option_name'),"$label: rollback restores the destination options");
 }
 // Same prefix: the source roles row has the destination's name and is replaced by the destination's.
 $same=source();$same->replace('wp_options',['option_name'=>'wp_user_roles','option_value'=>$sourceRoles,'autoload'=>'yes']);
 $dump=snapshot($same,$base.'/same.sql');
 $s=upload($new,$body('wp_',$dump),[file_get_contents($base.'/same.sql')]);[$s]=drive($new,$s['id'],['verification_required']);
 expect(option($db,'wp_user_roles')===$destinationRoles,'same prefix: destination roles preserved');
 rollbackAll($new,$new()->finish($s['id'])['id']);
}finally{removeTree($base);}
