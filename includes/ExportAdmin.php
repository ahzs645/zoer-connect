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
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_profile');
        echo '<input type="hidden" name="action" value="zoer_export_profile"><input type="hidden" name="operation" value="save">';
        if($id!==null)echo '<input type="hidden" name="profile_id" value="'.esc_attr($id).'">';
        echo '<label>Profile name <input name="name" required maxlength="80" value="'.esc_attr($p['name']??'').'"></label><p>';
        $modes=['all'=>'All','active'=>'Active only','selected'=>'Only listed','except'=>'All except listed'];
        foreach(['themes'=>'Themes','plugins'=>'Plugins','media'=>'Media uploads','muplugins'=>'Must-use plugins','core'=>'WordPress core'] as $key=>$label){
            echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="'.esc_attr($key).'" '.checked(($p[$key]??false)===true,true,false).'> '.esc_html($label).'</label>';
            if(in_array($key,['themes','plugins'],true)){
                echo '<span style="display:block;margin:0 0 8px 24px"><label>'.esc_html($label).' selection <select name="'.esc_attr($key).'Mode">';
                foreach($modes as $value=>$text)echo '<option value="'.esc_attr($value).'" '.(($p[$key.'Mode']??'all')===$value?'selected':'').'>'.esc_html($text).'</option>';
                echo '</select></label><br><label>Folder names (plugins: folder or single-file name), one per line<br><textarea name="'.esc_attr($key).'Items" rows="2" cols="40">'.esc_textarea(implode("\n",$p[$key.'Items']??[])).'</textarea></label></span>';
            }
            if($key==='media')echo '<span style="display:block;margin:0 0 8px 24px"><label>Only uploads modified on or after (UTC, optional) <input type="date" name="mediaSince" value="'.esc_attr($p['mediaSince']??'').'"></label></span>';
        }
        $excludes=array_values(array_diff($p['excludes']??[],ExportProfile::DEFAULT_EXCLUDES));
        echo '</p><label>Exclusions (relative to the WordPress root, one pattern per line)<br><textarea name="excludes" rows="4" cols="50">'.esc_textarea(implode("\n",$excludes)).'</textarea></label><p><button class="button button-primary">'.($id===null?'Save profile':'Update profile').'</button></p></form>';
    }
    public static function render(): void {
        echo '<h2>File export profiles</h2><p>Download selected file categories. This does not publish or include the database. Configuration credentials and this connector are excluded. Default exclusions for Finder metadata and repository control files always apply.</p>';
        self::form(null,[]);
        foreach((array)get_option('zoer_connect_profiles',[]) as $id=>$profile){
            if(!is_array($profile)||!is_string($profile['name']??null))continue;
            echo '<h3>'.esc_html($profile['name']).'</h3><details><summary>Edit profile</summary>';self::form((string)$id,$profile);echo '</details><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_download');echo '<input type="hidden" name="action" value="zoer_export_download"><input type="hidden" name="profile_id" value="'.esc_attr($id).'"><button class="button">Download file export</button></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_profile');echo '<input type="hidden" name="action" value="zoer_export_profile"><input type="hidden" name="operation" value="delete"><input type="hidden" name="profile_id" value="'.esc_attr($id).'"><button class="button">Delete profile</button></form>';
        }
    }
}
