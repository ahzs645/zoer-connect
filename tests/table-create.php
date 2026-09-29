<?php
require __DIR__.'/fixtures/memory-db.php';
require __DIR__.'/../includes/TableStage.php';
use ZoerConnect\TableStage;
function expect($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function reject($fn,$label,$message=null){try{$fn();}catch(Throwable $e){if($message!==null&&!str_contains($e->getMessage(),$message))throw new RuntimeException("$label: ".$e->getMessage());echo "PASS $label\n";return;}throw new RuntimeException($label);}
function run(callable $step){for($i=0;$i<200;$i++)if($step())return;throw new RuntimeException('Step never completed.');}
$ddl="CREATE TABLE `wp_widgets` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(191) NOT NULL DEFAULT '' COMMENT 'SELECT; is only text',\n  `connection` longtext,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
// A7: strict single-statement validation; quoted identifiers and strings cannot hide clauses.
expect(TableStage::sourceSchema(str_replace('SELECT; is only text','plain',$ddl),'wp_widgets')!=='','ordinary InnoDB schema accepted');
reject(fn()=>TableStage::sourceSchema($ddl,'wp_widgets'),'statement separator refused even inside a comment string');
$ddl=str_replace('SELECT; is only text','SELECT is only text',$ddl);
expect(TableStage::sourceSchema($ddl,'wp_widgets')===$ddl,'keywords inside quoted strings and identifiers are data');
foreach([
 'foreign key'=>str_replace("PRIMARY KEY (`id`)","PRIMARY KEY (`id`),\n  CONSTRAINT `f` FOREIGN KEY (`id`) REFERENCES `wp_posts` (`ID`)",$ddl),
 'partition'=>$ddl.' PARTITION BY HASH (`id`) PARTITIONS 2',
 'data directory'=>$ddl." DATA DIRECTORY='/tmp'",
 'MyISAM'=>str_replace('ENGINE=InnoDB','ENGINE=MyISAM',$ddl),
 'federated connection'=>$ddl." CONNECTION='mysql://u@h/db/t'",
 'no primary key'=>str_replace(",\n  PRIMARY KEY (`id`)",'',$ddl),
 'line comment'=>str_replace('longtext,','longtext, -- x',$ddl),
 'block comment'=>str_replace('longtext,','longtext /* x */,',$ddl),
 'hash comment'=>str_replace('longtext,','longtext # x'."\n,",$ddl),
 'escaped backtick'=>str_replace('`connection`','`conn\`ection`',$ddl),
 'expression default'=>str_replace('longtext,',"varchar(9) DEFAULT (LOAD_FILE('/etc/passwd')),",$ddl),
 'generated column'=>str_replace('longtext,','int AS (1) STORED,',$ddl),
 'unterminated string'=>str_replace("COMMENT 'SELECT is only text'","COMMENT 'open",$ddl),
 'quote-smuggled keyword'=>str_replace("COMMENT 'SELECT is only text'","COMMENT 'a`' REFERENCES `x` (`y`) '`'",$ddl),
 'other table name'=>str_replace('`wp_widgets`','`wp_other`',$ddl),
 'second statement'=>$ddl."\nCREATE TABLE `x` (`a` int)",
] as $label=>$bad)reject(fn()=>TableStage::sourceSchema($bad,'wp_widgets'),"source schema with $label refused");
$db=new MemoryDb();
$db->define("CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text NOT NULL,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB",[['ID'=>'1','post_title'=>'kept']]);
$source=['name'=>'wp_widgets','schema'=>$ddl];
// Created table: stage from the validated schema, activate by plain rename.
$created=new TableStage($db,'wp_widgets',str_repeat('1',16),false,$source);
run(fn()=>$created->prepareStep());
expect(isset($db->tables['zoer_s_1111111111111111'])&&!isset($db->tables['wp_widgets'])&&str_contains($db->log[array_key_last(array_filter($db->log,fn($q)=>str_starts_with($q,'CREATE TABLE `zoer_s_')))],'CREATE TABLE `zoer_s_1111111111111111` (')&&$db->get_var("SELECT phase FROM `zoer_l_1111111111111111` WHERE sequence_id=-4")==='created','missing table staged from rewritten source schema with durable created marker');
$created->chunk(0,[['id'=>'1','name'=>'a','connection'=>'x']]);$created->chunk(1,[['id'=>'2','name'=>'b','connection'=>null]]);
run(fn()=>$created->verifyStep(2,2));
run(fn()=>$created->activationCheckStep());
run(fn()=>$created->activateStep());
expect(count($db->dump('wp_widgets'))===2&&!isset($db->tables['zoer_s_1111111111111111'])&&!isset($db->tables['zoer_b_1111111111111111']),'created table activated by rename without a backup');
run(fn()=>$created->activateStep());
// Rollback refuses once the created table was edited, then restores after the edit is reverted.
$db->query("UPDATE `wp_widgets` SET name='edited' WHERE id=1");
reject(fn()=>run(fn()=>$created->rollbackPreflightStep()),'edited created table refuses rollback','Destination edited after publication');
$db->query("UPDATE `wp_widgets` SET name='a' WHERE id=1");$created->resetRollbackPreflight();
run(fn()=>$created->rollbackPreflightStep());run(fn()=>$created->rollbackStep());
expect(!isset($db->tables['wp_widgets'])&&count($db->dump('zoer_s_1111111111111111'))===2&&$db->get_var("SELECT phase FROM `zoer_l_1111111111111111` WHERE sequence_id=-1")==='restored','created table rolled back by rename to its private stage name');
run(fn()=>$created->rollbackStep());
$created->cleanup();
expect(!array_filter(array_keys($db->tables),fn($t)=>str_starts_with($t,'zoer_')),'cleanup drops the restored stage and ledger');
// A table that appears during staging aborts activation instead of being overwritten.
$race=new TableStage($db,'wp_widgets',str_repeat('2',16),false,$source);
run(fn()=>$race->prepareStep());$race->chunk(0,[['id'=>'1','name'=>'a','connection'=>'x']]);run(fn()=>$race->verifyStep(1,1));
$db->define(str_replace('`wp_widgets`','`wp_widgets`',$ddl),[['id'=>'9','name'=>'someone else','connection'=>'']]);
reject(fn()=>run(fn()=>$race->activationCheckStep()),'concurrently created live table blocks the activation check','Destination changed since preparation');
reject(fn()=>run(fn()=>$race->activateStep()),'concurrently created live table blocks activation','Destination changed since preparation');
expect($db->dump('wp_widgets')[0]['name']==='someone else','concurrent live table untouched');
run(fn()=>$race->rollbackStep());$race->cleanup();
expect(isset($db->tables['wp_widgets'])&&!isset($db->tables['zoer_s_2222222222222222']),'never-activated stage cleaned without touching live');
// Schema replacement: stage from the source schema, swap, and restore the old schema.
$db->query('DROP TABLE IF EXISTS `wp_widgets`');
$db->define("CREATE TABLE `wp_widgets` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(20) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB",[['id'=>'5','name'=>'old']]);
$old=$db->get_row('SHOW CREATE TABLE `wp_widgets`',ARRAY_N)[1];
$swap=new TableStage($db,'wp_widgets',str_repeat('3',16),false,$source);
run(fn()=>$swap->prepareStep());
expect($db->get_var("SELECT phase FROM `zoer_l_3333333333333333` WHERE sequence_id=-4")===null&&in_array('connection',$db->get_col('SHOW COLUMNS FROM `zoer_s_3333333333333333`'),true),'mismatched schema staged from source schema, not LIKE');
$swap->chunk(0,[['id'=>'1','name'=>'new','connection'=>'c']]);run(fn()=>$swap->verifyStep(1,1));
$db->query("UPDATE `wp_widgets` SET name='live edit' WHERE id=5");
reject(fn()=>run(fn()=>$swap->activationCheckStep()),'live edit after staging blocks the activation check','Destination changed since preparation');
$db->query("UPDATE `wp_widgets` SET name='old' WHERE id=5");
run(fn()=>$swap->activationCheckStep());run(fn()=>$swap->activateStep());
expect(str_contains($db->get_row('SHOW CREATE TABLE `wp_widgets`',ARRAY_N)[1],'`connection`')&&$db->dump('wp_widgets')[0]['name']==='new'&&isset($db->tables['zoer_b_3333333333333333']),'schema-replaced table activated with the old table kept as backup');
(new TableStage($db,'wp_posts',str_repeat('4',16)))->cleanup();expect(count($db->dump('wp_posts'))===1,'cleanup without a ledger never touches the live table');
$swap->cleanup();
expect(!isset($db->tables['zoer_b_3333333333333333'])&&!isset($db->tables['zoer_l_3333333333333333'])&&$db->dump('wp_widgets')[0]['name']==='new','cleanup after activation drops only the backup and ledger');
$db->query('DROP TABLE IF EXISTS `wp_widgets`');
$db->define($old,[['id'=>'5','name'=>'old']]);
$again=new TableStage($db,'wp_widgets',str_repeat('5',16),false,$source);
run(fn()=>$again->prepareStep());$again->chunk(0,[['id'=>'1','name'=>'new','connection'=>'c']]);run(fn()=>$again->verifyStep(1,1));run(fn()=>$again->activateStep());
$db->query("UPDATE `zoer_l_5555555555555555` SET phase='restoring' WHERE sequence_id=-1");
reject(fn()=>$again->cleanup(),'cleanup refuses an in-progress restore','Table cleanup requires');
$db->query("UPDATE `zoer_l_5555555555555555` SET phase='activated' WHERE sequence_id=-1");
run(fn()=>$again->rollbackPreflightStep());run(fn()=>$again->rollbackStep());
expect($db->get_row('SHOW CREATE TABLE `wp_widgets`',ARRAY_N)[1]===$old&&$db->dump('wp_widgets')[0]['name']==='old','schema-replaced table rollback restores the original schema and rows');
reject(fn()=>new TableStage($db,'wp_widgets',str_repeat('6',16),false,['name'=>'wp_widgets','schema'=>str_replace('ENGINE=InnoDB','ENGINE=MEMORY',$ddl)]),'invalid source schema refused at construction');
