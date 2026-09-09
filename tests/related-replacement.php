<?php
require __DIR__.'/../includes/Selection.php';require __DIR__.'/../includes/RelatedRows.php';require __DIR__.'/../includes/Replacement.php';
use ZoerConnect\RelatedRows;use ZoerConnect\Replacement;
function expect($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function reject($fn,$label){try{$fn();}catch(Throwable $e){echo "PASS $label\n";return;}throw new RuntimeException($label);}
$data=['posts'=>[['ID'=>1,'post_type'=>'post'],['ID'=>2,'post_type'=>'revision']],'postmeta'=>[['post_id'=>1],['post_id'=>2]],'comments'=>[['comment_ID'=>10,'comment_post_ID'=>1,'comment_approved'=>'1'],['comment_ID'=>11,'comment_post_ID'=>1,'comment_approved'=>'spam'],['comment_ID'=>12,'comment_post_ID'=>2,'comment_approved'=>'1']],'commentmeta'=>[['comment_id'=>10],['comment_id'=>11],['comment_id'=>12]],'term_relationships'=>[['object_id'=>1],['object_id'=>2]],'term_taxonomy'=>[],'terms'=>[],'termmeta'=>[]];
$out=RelatedRows::select($data,['excludeRevisions'=>true,'excludeSpam'=>true]);
foreach(['posts','postmeta','comments','commentmeta','term_relationships'] as $key)expect(count($out[$key])===1,'related '.$key.' exclusion');
$bad=$data;$bad['posts'][0]['post_parent']=2;reject(fn()=>RelatedRows::select($bad,['excludeRevisions'=>true]),'excluded parent refused');
$bad=$data;$bad['postmeta'][0]=['post_id'=>1,'meta_key'=>'_thumbnail_id','meta_value'=>2];reject(fn()=>RelatedRows::select($bad,['excludeRevisions'=>true]),'excluded featured image refused');
$rules=[['mode'=>'literal','find'=>'old.example','replace'=>'longer.example.org']];
$value=serialize(['url'=>'https://old.example','nested'=>serialize(['x'=>'old.example'])]);
$result=unserialize(Replacement::apply($value,$rules));expect($result['url']==='https://longer.example.org','serialized lengths preserved');expect(unserialize($result['nested'])['x']==='longer.example.org','nested serialization');
expect(Replacement::apply('abc-123',[['mode'=>'regex','find'=>'/([0-9]+)/','replace'=>'[$1]']])==='abc-[123]','regex captures');
reject(fn()=>Replacement::apply('abc',[['mode'=>'regex','find'=>'/[invalid/','replace'=>'x']]),'invalid regex refused');
$schedule='O:30:"ActionScheduler_SimpleSchedule":1:{s:9:"timestamp";i:1788910000;}';
expect(Replacement::apply($schedule,$rules)===$schedule,'unrelated serialized schedule preserved byte-for-byte');
expect(Replacement::apply('a:1:{broken}',$rules)==='a:1:{broken}','unrelated opaque state preserved');
reject(fn()=>Replacement::apply('O:8:"stdClass":1:{s:3:"url";s:11:"old.example";}',$rules),'object requiring replacement refused without instantiation');
reject(fn()=>Replacement::apply('a:1:{old.example}',$rules),'malformed serialization requiring replacement refused');
reject(fn()=>Replacement::apply($schedule,[['mode'=>'regex','find'=>'/timestamp/','replace'=>'x']]),'regex object handling remains refused');
