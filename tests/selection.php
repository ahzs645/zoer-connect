<?php
require __DIR__.'/../includes/Selection.php';use ZoerConnect\Selection as S;
function same($a,$b,$name){if($a!==$b)throw new RuntimeException($name);echo "PASS $name\n";}
function no($fn,$name){try{$fn();}catch(Throwable $e){echo "PASS $name\n";return;}throw new RuntimeException($name);}
$inv=[['id'=>'one','active'=>true],['id'=>'two','active'=>false]];
same(count(S::resources($inv,'all')),2,'all resources');
same(S::resources($inv,'active'),[$inv[0]],'active resources');
same(S::resources($inv,'selected',['two']),[$inv[1]],'selected resources');
same(S::resources($inv,'except',['two']),[$inv[0]],'all except selected');
same(S::resources($inv,'selected',[]),[],'empty selection selects nothing');
no(fn()=>S::resources($inv,'selected',['missing']),'unknown resource rejected');
foreach(['.DS_Store'=>['.DS_Store'],'dir/app.log'=>['*.log'],'x/cache/a'=>['*cache*/'],'node_modules/x/a'=>['node_modules/'],'nested/x.php'=>['**/*.php']] as $path=>$patterns)same(S::excluded($path,$patterns),true,'exclude '.$path);
same(S::excluded('nested/a.log',['/a.log']),false,'root anchored glob');
same(S::excluded('keep.log',['*.log','!keep.log']),false,'negated exception');
no(fn()=>S::excluded('../x',['*']),'traversal rejected');
no(fn()=>S::excluded('x',['[abc]']),'unsupported glob rejected');
$a=hash('sha256','a');$b=hash('sha256','b');$files=[['path'=>'old.jpg','mtime'=>10,'sha256'=>$a],['path'=>'new.jpg','mtime'=>20,'sha256'=>$b]];
same(count(S::media($files,'all')),2,'all media');
same(S::media($files,'changed',['old.jpg'=>$a]),[$files[1]],'new/changed by hash');
same(S::media($files,'after',[],10),[$files[1]],'media after timestamp excludes equal date');
same(S::media($files,'all',[],null,['new.jpg']),[$files[0]],'media exclusions');
no(fn()=>S::media($files,'after'),'missing date rejected');
$posts=[['post_type'=>'post'],['post_type'=>'revision'],['post_type'=>'page']];
same(S::rows($posts,'posts',['excludeRevisions'=>true]),[$posts[0],$posts[2]],'exclude revisions');
same(S::rows($posts,'posts',['postTypes'=>['page']]),[$posts[2]],'selected post types');
same(S::rows($posts,'posts',['postTypes'=>[]]),[],'empty post types selects nothing');
same(S::rows([['comment_approved'=>'spam'],['comment_approved'=>'1']],'comments',['excludeSpam'=>true]),[['comment_approved'=>'1']],'exclude spam');
same(S::rows([['option_name'=>'_transient_a'],['option_name'=>'_site_transient_a'],['option_name'=>'home']],'options',['excludeTransients'=>true]),[['option_name'=>'home']],'exclude both transient families');
no(fn()=>S::rows($posts,'posts',['postTypes'=>["post' OR 1=1"]]),'invalid post type rejected');
