<?php
namespace ZoerConnect;

/** Opt-in retention; page loads read cached sizes instead of walking backups. */
final class TransferRetention {
    public const OPTION='zoer_connect_import_retention';
    private const SIZES='zoer_connect_import_sizes';
    private const HOOK='zoer_connect_import_retention';
    public static function boot(): void {
        add_action('admin_post_zoer_import_retention',[self::class,'save']);
        add_action('admin_post_zoer_import_sizes',[self::class,'measure']);
        add_action(self::HOOK,[self::class,'run']);
    }
    public static function policy(): array {
        $p=get_option(self::OPTION,[]);
        return ['enabled'=>is_array($p)&&($p['enabled']??false)===true,'days'=>is_array($p)&&is_int($p['days']??null)&&$p['days']>=1&&$p['days']<=365?$p['days']:30];
    }
    private static function check(string $action): void {
        if(!current_user_can('manage_options')||is_multisite())wp_die('Administrator on a single site required.',403);
        check_admin_referer($action);
    }
    public static function save(): void {
        self::check('zoer_import_retention');
        $days=filter_var(wp_unslash($_POST['days']??''),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>365]]);
        if($days===false)wp_die('Choose a retention period from 1 to 365 days.');
        $enabled=isset($_POST['enabled']);
        update_option(self::OPTION,['enabled'=>$enabled,'days'=>$days],false);
        if($enabled&&!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+3600,'daily',self::HOOK);
        if(!$enabled)wp_clear_scheduled_hook(self::HOOK);
        wp_safe_redirect(admin_url('tools.php?page=zoer-connect#zoer-transfers'));exit;
    }
    private static function engine(string $private): TransferImport {
        require_once __DIR__.'/TransferImport.php';
        $record=get_option(ConnectionKey::OPTION,[]);
        $owner=is_array($record)&&is_string($record['hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$record['hash'])?$record['hash']:str_repeat('0',64);
        global $wpdb;
        return new TransferImport($wpdb,ABSPATH,$private,$owner,rtrim((string)get_option('home'),'/'),true);
    }
    public static function run(): void {
        if(is_multisite())return;
        $policy=self::policy();if(!$policy['enabled'])return;
        $result=['checkedAt'=>gmdate('c'),'cleaned'=>0,'protected'=>0,'failed'=>0];
        try {
            $private=Plugin::storageRoot();$engine=self::engine($private);$deadline=microtime(true)+8;
            foreach(array_reverse(TransferAdmin::imports($private,0)) as $i){
                if(microtime(true)>=$deadline)break;
                if($i['cleanedUp']||!in_array($i['phase'],TransferAdmin::TERMINAL,true))continue;
                try{$done=$engine->retentionCleanup($i['id'],time()-$policy['days']*86400);$result[($done['cleanedUp']??false)?'cleaned':'protected']++;}catch(\Throwable $ignored){$result['failed']++;}
            }
        }catch(\Throwable $ignored){$result['failed']++;}
        update_option('zoer_connect_import_retention_last',$result,false);
    }
    public static function size(string $id): ?array {
        $cache=get_option(self::SIZES,[]);$s=is_array($cache)?($cache[$id]??null):null;
        return is_array($s)&&is_int($s['bytes']??null)&&$s['bytes']>=0?$s:null;
    }
    public static function measureImport(string $private,string $id,$db,float $deadline): array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid import ID.');
        $dir=$private.'/import-'.$id;
        if(is_link($dir)||is_link($dir.'/state.json'))throw new \RuntimeException('Import state unavailable.');
        $s=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
        if(($s['cleanedUp']??false)===true)return ['bytes'=>0,'complete'=>true,'measuredAt'=>gmdate('c')];
        $bytes=0;$complete=true;$seen=0;
        $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
        foreach($walk as $file){
            if(++$seen>20000||microtime(true)>=$deadline){$complete=false;break;}
            if($file->isLink()){$complete=false;continue;}
            if($file->isFile())$bytes+=$file->getSize();
        }
        $names=[];
        foreach($s['tables']??[] as $table)if(preg_match('/^[a-f0-9]{16}$/D',$table['id']??''))foreach(['s','b','l'] as $kind)$names[]='zoer_'.$kind.'_'.$table['id'];
        if($names){
            $sql='SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($names),'%s')).')';
            $value=$db->get_var($db->prepare($sql,...$names));
            if($value===null||$db->last_error)$complete=false;else $bytes+=(int)$value;
        }
        return ['bytes'=>$bytes,'complete'=>$complete,'measuredAt'=>gmdate('c')];
    }
    public static function measure(): void {
        self::check('zoer_import_sizes');$cache=[];
        try{
            $private=Plugin::storageRoot();$deadline=microtime(true)+2;global $wpdb;
            foreach(TransferAdmin::imports($private) as $i){
                if(microtime(true)>=$deadline)break;
                $cache[$i['id']]=self::measureImport($private,$i['id'],$wpdb,$deadline);
            }
            update_option(self::SIZES,$cache,false);
        }catch(\Throwable $e){wp_die('Storage sizes could not be measured. Try again.');}
        wp_safe_redirect(admin_url('tools.php?page=zoer-connect#zoer-transfers'));exit;
    }
    public static function render(): void {
        $p=self::policy();
        $last=get_option('zoer_connect_import_retention_last',[]);
        if(is_array($last)&&is_string($last['checkedAt']??null))echo '<p class="description">Last cleanup check: '.esc_html($last['checkedAt']).' · '.(int)($last['cleaned']??0).' cleaned · '.(int)($last['protected']??0).' protected · '.(int)($last['failed']??0).' need attention.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_import_sizes');
        echo '<input type="hidden" name="action" value="zoer_import_sizes"><p><button class="button">Measure storage sizes</button></p></form>';
        echo '<p class="description">Retained storage includes uploaded files, file backups and estimated private database tables. Sizes are measured on request; incomplete measurements show a lower bound.</p>';
        echo '<details class="zc-more"><summary>Automatic backup cleanup</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return !this.enabled.checked || confirm(\'Enable permanent cleanup of older import backups? Their rollback will no longer be possible. The newest successful import is kept.\')">';wp_nonce_field('zoer_import_retention');
        echo '<input type="hidden" name="action" value="zoer_import_retention"><p><label><input type="checkbox" name="enabled" '.checked($p['enabled'],true,false).'> Automatically clean older finished imports</label></p><p><label>Keep backups for at least <input type="number" name="days" min="1" max="365" value="'.esc_attr($p['days']).'" class="small-text"> days</label></p><p>Cleanup starts after a successful import. The newest successful import is always kept. Active imports and uploads in use are protected. Cleanup runs daily through WordPress cron; sites with cron disabled need an external cron runner.</p><p>Cleanup permanently removes rollback data. Import history remains.</p><button class="button">Save cleanup settings</button></form></details>';
    }
    public static function format(int $bytes): string {
        $units=['B','KiB','MiB','GiB','TiB'];$n=(float)$bytes;$i=0;while($n>=1024&&$i<count($units)-1){$n/=1024;$i++;}
        return ($i?number_format($n,1):number_format($n)).' '.$units[$i];
    }
}
