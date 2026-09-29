<?php
namespace ZoerConnect;

/** Read-only preflight inventory for Zoer (API A2). Reports facts and compatibility warnings; never changes the site.
 * Paths are returned to authenticated Push/Pull clients only, like the export `source.abspath`. */
final class Diagnostics {
    public const FIREWALLS=['wordfence'=>'Wordfence','wp-defender'=>'Defender','wpmu-dev-defender'=>'Defender','defender-security'=>'Defender','better-wp-security'=>'Solid Security','ithemes-security-pro'=>'Solid Security Pro','solid-security'=>'Solid Security','all-in-one-wp-security-and-firewall'=>'All-In-One WP Security','all-in-one-wp-security'=>'All-In-One WP Security','sucuri-scanner'=>'Sucuri Security','ninjafirewall'=>'NinjaFirewall'];
    public const PAGE_CACHES=['breeze'=>'Breeze','wp-rocket'=>'WP Rocket','w3-total-cache'=>'W3 Total Cache','wp-super-cache'=>'WP Super Cache','litespeed-cache'=>'LiteSpeed Cache','wp-fastest-cache'=>'WP Fastest Cache','cache-enabler'=>'Cache Enabler','sg-cachepress'=>'SG Optimizer','autoptimize'=>'Autoptimize'];
    public static function collect($db): array {
        global $wp_version;
        if(!function_exists('get_plugins')&&defined('ABSPATH')&&is_file(ABSPATH.'wp-admin/includes/plugin.php'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        $prefix=(string)$db->prefix;$home=untrailingslashit((string)get_option('home'));$siteurl=untrailingslashit((string)get_option('siteurl'));
        $uploads=function_exists('wp_upload_dir')?wp_upload_dir(null,false):[];
        $wordpress=['version'=>(string)$wp_version,'prefix'=>$prefix,'home'=>$home,'siteurl'=>$siteurl,'abspath'=>untrailingslashit(ABSPATH),'contentDir'=>untrailingslashit(defined('WP_CONTENT_DIR')?WP_CONTENT_DIR:ABSPATH.'wp-content'),'uploadsDir'=>untrailingslashit((string)($uploads['basedir']??'')),'locale'=>(string)get_locale(),'permalinkStructure'=>(string)get_option('permalink_structure',''),'blogPublic'=>(string)get_option('blog_public','1')!=='0'];
        $php=['version'=>PHP_VERSION,'memoryLimit'=>(string)ini_get('memory_limit'),'maxExecutionTime'=>(int)ini_get('max_execution_time'),'postMaxSize'=>(string)ini_get('post_max_size'),'uploadMaxFilesize'=>(string)ini_get('upload_max_filesize'),'extensions'=>['zip'=>extension_loaded('zip'),'openssl'=>extension_loaded('openssl'),'curl'=>extension_loaded('curl'),'mysqli'=>extension_loaded('mysqli')]];
        $database=self::database($db,$prefix);
        $postTypes=[];
        if(in_array($prefix.'posts',array_column($database['tables'],'name'),true)){
            foreach((array)$db->get_results("SELECT post_type, COUNT(*) AS total FROM `{$prefix}posts` GROUP BY post_type ORDER BY post_type",ARRAY_A) as $row){
                $name=(string)$row['post_type'];$object=function_exists('get_post_type_object')?get_post_type_object($name):null;
                $postTypes[]=['name'=>$name,'label'=>is_object($object)&&isset($object->label)?(string)$object->label:$name,'count'=>(int)$row['total']];
            }
        }
        $stylesheet=(string)get_stylesheet();$template=(string)get_template();$themes=[];
        foreach(wp_get_themes() as $slug=>$theme){$parent=(string)$theme->get_template();$themes[]=['slug'=>(string)$slug,'name'=>(string)$theme->get('Name'),'version'=>(string)$theme->get('Version'),'active'=>in_array((string)$slug,[$stylesheet,$template],true),'parent'=>$parent!==''&&$parent!==(string)$slug?$parent:null];}
        $activePlugins=array_values(array_filter((array)get_option('active_plugins',[]),'is_string'));$plugins=[];
        foreach(get_plugins() as $file=>$data){$dir=dirname((string)$file);$plugins[]=['slug'=>$dir==='.'?(string)$file:$dir,'file'=>(string)$file,'name'=>(string)($data['Name']??$file),'version'=>(string)($data['Version']??''),'active'=>in_array($file,$activePlugins,true)];}
        $muPlugins=[];foreach(get_mu_plugins() as $file=>$data)$muPlugins[]=['file'=>(string)$file,'name'=>(string)($data['Name']??$file)];
        $dropins=array_values(array_map('strval',array_keys(get_dropins())));
        $current=Plugin::VERSION;$latest=null;$updates=get_site_transient('update_plugins');$key='zoer-connect/zoer-connect.php';
        if(is_object($updates)){foreach(['response','no_update'] as $list){$bucket=$updates->$list??null;$entry=is_array($bucket)?($bucket[$key]??null):null;$version=is_object($entry)?($entry->new_version??null):(is_array($entry)?($entry['new_version']??null):null);if(is_string($version)&&preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version)){$latest=$version;break;}}}
        $report=['wordpress'=>$wordpress,'php'=>$php,'database'=>$database,'postTypes'=>$postTypes,'themes'=>$themes,'plugins'=>$plugins,'muPlugins'=>$muPlugins,'dropins'=>$dropins,'warnings'=>[],'pluginUpdate'=>['current'=>$current,'latest'=>$latest]];
        $report['warnings']=self::warnings($report,(string)ini_get('auto_prepend_file'));
        return $report;
    }
    private static function database($db,string $prefix): array {
        $version=(string)$db->get_var('SELECT VERSION()');
        $lower=(int)$db->get_var('SELECT @@lower_case_table_names');
        $status=$db->get_results('SHOW TABLE STATUS',ARRAY_A);
        if($db->last_error||!is_array($status))throw new \RuntimeException('Cannot inspect database.');
        $names=static function($sql)use($db):array{$out=[];foreach((array)$db->get_results($sql,ARRAY_A) as $row)foreach($row as $value)if(is_string($value)&&$value!=='')$out[$value]=true;return $out;};
        // One schema query per property; restricted to the current database.
        $primary=$names("SELECT TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='PRIMARY KEY'");
        $foreign=$names('SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()');
        $triggers=$names('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        $tables=[];
        foreach(array_slice($status,0,2000) as $row){
            $name=(string)($row['Name']??'');$prefixed=$prefix!==''&&str_starts_with($name,$prefix);
            $tables[]=['name'=>$name,'suffix'=>$prefixed?substr($name,strlen($prefix)):null,'prefixed'=>$prefixed,'engine'=>isset($row['Engine'])?(string)$row['Engine']:((string)($row['Comment']??'')==='VIEW'?'VIEW':''),'rows'=>(int)($row['Rows']??0),'bytes'=>(int)($row['Data_length']??0)+(int)($row['Index_length']??0),'primaryKey'=>isset($primary[$name]),'foreignKeys'=>isset($foreign[$name]),'triggers'=>isset($triggers[$name])];
        }
        return ['server'=>stripos($version,'mariadb')!==false?'mariadb':'mysql','version'=>preg_match('/^[0-9]+(?:\.[0-9]+)*/',$version,$m)?$m[0]:'','charset'=>(string)($db->charset??''),'collate'=>(string)($db->collate??''),'lowerCaseTableNames'=>$lower,'tables'=>$tables];
    }
    /** Pure warning rules over a collected report, so fixtures can exercise every code. */
    public static function warnings(array $r,string $autoPrepend=''): array {
        $warnings=[];$add=static function(string $code,string $message)use(&$warnings){$warnings[]=['code'=>$code,'message'=>$message];};
        $active=array_column(array_filter($r['plugins'],static fn($p)=>$p['active']),'slug');
        $firewalls=array_values(array_unique(array_intersect_key(self::FIREWALLS,array_flip($active))));
        if(preg_match('/wordfence-waf|ninjafirewall/i',$autoPrepend))$firewalls[]='PHP auto_prepend_file firewall';
        if($firewalls)$add('firewall_plugin','Firewall active: '.implode(', ',array_unique($firewalls)).'. Allow the /wp-json/zoer-connect/v1/ routes and the X-Zoer-Connection header, and avoid rate limits during transfers.');
        $caches=array_values(array_unique(array_intersect_key(self::PAGE_CACHES,array_flip($active))));
        if($caches)$add('page_cache_plugin','Page cache active: '.implode(', ',$caches).'. Exclude /wp-json/zoer-connect/ from caching and purge caches after a transfer.');
        if(in_array('advanced-cache.php',$r['dropins'],true))$add('page_cache_plugin','advanced-cache.php drop-in serves cached pages before WordPress loads. Exclude /wp-json/zoer-connect/ from caching and purge caches after a transfer.');
        if(in_array('object-cache.php',$r['dropins'],true))$add('object_cache_dropin','Persistent object cache drop-in present. Zoer flushes it after imports; flush it manually if content looks stale.');
        $prefixed=array_filter($r['database']['tables'],static fn($t)=>$t['prefixed']);
        $mixed=array_column(array_filter($prefixed,static fn($t)=>$t['name']!==strtolower($t['name'])),'name');
        if($mixed||($r['database']['lowerCaseTableNames']!==0&&$r['wordpress']['prefix']!==strtolower($r['wordpress']['prefix'])))$add('mixed_case_tables','Mixed-case table names ('.implode(', ',array_slice($mixed?:[$r['wordpress']['prefix'].'*'],0,5)).') with lower_case_table_names='.$r['database']['lowerCaseTableNames'].' may not match on a server with a different setting.');
        foreach(['non_innodb'=>[static fn($t)=>strtolower($t['engine'])!=='innodb','Tables not using InnoDB cannot be exported or imported'],'foreign_keys'=>[static fn($t)=>$t['foreignKeys'],'Tables with foreign keys are unsupported'],'triggers'=>[static fn($t)=>$t['triggers'],'Tables with triggers are unsupported']] as $code=>[$test,$text]){
            $names=array_column(array_filter($prefixed,$test),'name');
            if($names)$add($code,$text.': '.implode(', ',array_slice($names,0,10)).(count($names)>10?' and '.(count($names)-10).' more':'').'. Exclude them from the table selection.');
        }
        if(!$r['wordpress']['blogPublic'])$add('blog_private','Search engines are discouraged on this site (blog_public=0). Imports keep the destination setting.');
        foreach(['home','siteurl'] as $key)if(!str_starts_with(strtolower($r['wordpress'][$key]),'https://')){$add('no_https','The WordPress '.$key.' URL does not use HTTPS.');break;}
        return $warnings;
    }
}
