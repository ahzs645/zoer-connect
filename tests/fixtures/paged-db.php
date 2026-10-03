<?php
final class PagedDb {
 public string $prefix='Wp_';public string $last_error='';public array $queries=[];public bool $changed=false;public bool $legacy=false;
 function get_var($sql){return $sql==='SELECT VERSION()'?'11.8.0-MariaDB':1;}
 function query($sql){$this->queries[]=$sql;return 1;}
 function get_row($sql,$mode){return ['wp_posts','CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL, `body` longblob, `empty` text, PRIMARY KEY (`ID`)) ENGINE=InnoDB'.($this->changed?' COMMENT=\'changed\'':'')];}
 function get_results($sql,$mode){$this->queries[]=$sql;
  if($sql==='SHOW TABLE STATUS')return [['Name'=>'wp_posts','Engine'=>$this->legacy?'MyISAM':'InnoDB'],['Name'=>'other_secret','Engine'=>'InnoDB']];
  if(str_starts_with($sql,'SHOW INDEX'))return [['Seq_in_index'=>1,'Column_name'=>'ID','Sub_part'=>null]];
  if(str_starts_with($sql,'SHOW FULL COLUMNS'))return [['Field'=>'ID','Type'=>'bigint unsigned','Collation'=>null]];
  $ids=['18446744073709551614','18446744073709551615'];$next=0;
  if(preg_match("/`ID` > CAST\\(X'([a-f0-9]+)' AS UNSIGNED\\)/",$sql,$m))$next=array_search(hex2bin($m[1]),$ids,true)+1;
  return isset($ids[$next])?[['ID'=>$ids[$next],'body'=>str_repeat("binary\0",450000),'empty'=>null]]:[];
 }
}
