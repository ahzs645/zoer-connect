<?php
namespace ZoerConnect;
final class ExportAdmin {
    public static function boot(): void {
        add_action('admin_post_zoer_export_profile',[self::class,'save']);
        add_action('admin_post_zoer_export_download',[self::class,'download']);
    }
    private static function check(string $action): void {
        if(!current_user_can('manage_options')||is_multisite())wp_die('Administrator on a single site required.',403);
        check_admin_referer($action);
    }
    /** Form fields to a normalized profile; empty lines are ignored and the default exclusions are re-added. */
    public static function fromForm(array $post): array {
        $lines=static fn($key)=>array_values(array_filter(array_map('trim',preg_split('/\r?\n/',(string)wp_unslash($post[$key]??''))),static fn($v)=>$v!==''));
        $profile=['name'=>sanitize_text_field(wp_unslash($post['name']??'')),'excludes'=>$lines('excludes')];
        foreach(['themes','plugins','media','muplugins','core'] as $key)$profile[$key]=isset($post[$key]);
        if(isset($post['languages']))$profile['languages']=true;
        foreach(['themes','plugins'] as $key){$profile[$key.'Mode']=sanitize_key($post[$key.'Mode']??'all');$profile[$key.'Items']=$lines($key.'Items');}
        $since=trim((string)wp_unslash($post['mediaSince']??''));if($since!=='')$profile['mediaSince']=$since;
        return ExportProfile::normalize($profile);
    }
    public static function save(): void {
        self::check('zoer_export_profile');
        $action=sanitize_key($_POST['operation']??'save');$profiles=get_option('zoer_connect_profiles',[]);
        if(!is_array($profiles))$profiles=[];
        try{
            if($action==='delete')unset($profiles[sanitize_key($_POST['profile_id']??'')]);
            elseif($action==='save'){
                $profile=self::fromForm($_POST);$id=sanitize_key($_POST['profile_id']??'');
                if($id!==''){if(!isset($profiles[$id]))throw new \RuntimeException('Profile not found.');$profiles[$id]=$profile;}
                else{if(count($profiles)>=20)throw new \RuntimeException('Maximum 20 profiles.');$profiles[bin2hex(random_bytes(8))]=$profile;}
            }else throw new \InvalidArgumentException('Unknown action.');
            update_option('zoer_connect_profiles',$profiles,false);
        }catch(\Throwable $e){wp_die(esc_html($e->getMessage()));}
        wp_safe_redirect(admin_url('tools.php?page=zoer-connect'));exit;
    }
    public static function download(): void {
        self::check('zoer_export_download');$profiles=get_option('zoer_connect_profiles',[]);$id=sanitize_key($_POST['profile_id']??'');
        if(!isset($profiles[$id]))wp_die('Profile not found.');
        $tmp=tempnam(sys_get_temp_dir(),'zoer-export-');
        if(!$tmp)wp_die('Private temporary storage unavailable.');
        try{
            $real=realpath($tmp);$public=realpath($_SERVER['DOCUMENT_ROOT']??ABSPATH);
            if(!$real||!$public||str_starts_with($real,$public.'/'))throw new \RuntimeException('Temporary storage must be private.');
            $active=Plugin::activeResources();$plan=FileExporter::plan(ABSPATH,$profiles[$id],$active);FileExporter::zip(ABSPATH,$plan,$tmp,$active);
            nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="zoer-files.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);
        }catch(\Throwable $e){if(is_file($tmp))unlink($tmp);wp_die(esc_html($e->getMessage()));}
        finally{if(is_file($tmp))unlink($tmp);}exit;
    }
    private static function form(?string $id,array $p): void {
        $uid=$id===null?'new':preg_replace('/[^a-z0-9_-]/','',(string)$id);
        echo '<form method="post" class="zc-profile-form" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_profile');
        echo '<input type="hidden" name="action" value="zoer_export_profile"><input type="hidden" name="operation" value="save">';
        if($id!==null)echo '<input type="hidden" name="profile_id" value="'.esc_attr($id).'">';
        echo '<label>Profile name <input type="text" name="name" required maxlength="80" class="regular-text" value="'.esc_attr($p['name']??'').'"></label><fieldset><legend>Include</legend>';
        $modes=['all'=>'All','active'=>'Active only','selected'=>'Only listed','except'=>'All except listed'];
        foreach(['themes'=>'Themes','plugins'=>'Plugins','media'=>'Media uploads','muplugins'=>'Must-use plugins','languages'=>'Translations','core'=>'WordPress core'] as $key=>$label){
            echo '<label><input type="checkbox" name="'.esc_attr($key).'" '.checked(($p[$key]??false)===true,true,false).'> '.esc_html($label).'</label>';
            if(in_array($key,['themes','plugins'],true)){
                echo '<span class="zc-sub"><label>'.esc_html($label).' selection <select name="'.esc_attr($key).'Mode">';
                foreach($modes as $value=>$text)echo '<option value="'.esc_attr($value).'" '.(($p[$key.'Mode']??'all')===$value?'selected':'').'>'.esc_html($text).'</option>';
                echo '</select></label><label for="zoer-'.esc_attr($uid.'-'.$key).'-items">Folder names, one per line'.($key==='plugins'?' (folder or single-file name)':'').'</label><textarea id="zoer-'.esc_attr($uid.'-'.$key).'-items" name="'.esc_attr($key).'Items" rows="2" cols="40">'.esc_textarea(implode("\n",$p[$key.'Items']??[])).'</textarea></span>';
            }
            if($key==='media')echo '<span class="zc-sub"><label>Only uploads changed on or after (UTC, optional) <input type="date" name="mediaSince" value="'.esc_attr($p['mediaSince']??'').'"></label></span>';
        }
        $excludes=array_values(array_diff($p['excludes']??[],ExportProfile::DEFAULT_EXCLUDES));
        echo '</fieldset><label for="zoer-'.esc_attr($uid).'-excludes">Exclusions, one pattern per line, relative to the WordPress root</label><textarea id="zoer-'.esc_attr($uid).'-excludes" name="excludes" rows="4" cols="50">'.esc_textarea(implode("\n",$excludes)).'</textarea><p><button class="button button-primary">'.($id===null?'Save profile':'Update profile').'</button></p></form>';
    }
    public static function render(): void {
        $profiles=array_filter((array)get_option('zoer_connect_profiles',[]),static fn($p)=>is_array($p)&&is_string($p['name']??null));
        echo '<section class="zc-card" id="zoer-exports" aria-labelledby="zoer-exports-title"><h2 id="zoer-exports-title">File exports</h2><p class="zc-lead">Download a ZIP of chosen files. The database is not included.</p>';
        echo '<details class="zc-more"><summary>Learn more</summary><p>Exports never publish anything. Configuration credentials and this connector are excluded, and default exclusions for Finder metadata and repository control files always apply.</p></details>';
        if(!$profiles)echo '<p class="zc-empty">No export profiles yet.</p>';
        foreach($profiles as $id=>$profile){
            echo '<div class="zc-profile"><div class="zc-profile-head"><h3>'.esc_html($profile['name']).'</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_download');echo '<input type="hidden" name="action" value="zoer_export_download"><input type="hidden" name="profile_id" value="'.esc_attr($id).'"><button class="button button-primary">Download file export</button></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_profile');echo '<input type="hidden" name="action" value="zoer_export_profile"><input type="hidden" name="operation" value="delete"><input type="hidden" name="profile_id" value="'.esc_attr($id).'"><button class="button zc-danger" data-zc-confirm="Delete this export profile?">Delete profile</button></form></div>';
            echo '<details class="zc-more"><summary>Edit profile</summary>';self::form((string)$id,$profile);echo '</details></div>';
        }
        echo '<details class="zc-advanced"'.($profiles?'':' open').'><summary>New export profile</summary>';self::form(null,[]);echo '</details></section>';
    }
}
