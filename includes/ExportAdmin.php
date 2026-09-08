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
    public static function save(): void {
        self::check('zoer_export_profile');
        $action=sanitize_key($_POST['operation']??'save');$profiles=get_option('zoer_connect_profiles',[]);
        if(!is_array($profiles))$profiles=[];
        try{
            if($action==='delete')unset($profiles[sanitize_key($_POST['profile_id']??'')]);
            elseif($action==='save'){
                $profile=['name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'excludes'=>preg_split('/\r?\n/',wp_unslash($_POST['excludes']??''))];
                foreach(['themes','plugins','media','muplugins','core'] as $key)$profile[$key]=isset($_POST[$key]);
                $profile=ExportProfile::normalize($profile);
                if(count($profiles)>=20)throw new \RuntimeException('Maximum 20 profiles.');
                $profiles[bin2hex(random_bytes(8))]=$profile;
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
            $plan=FileExporter::plan(ABSPATH,$profiles[$id]);FileExporter::zip(ABSPATH,$plan,$tmp);
            nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="zoer-files.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);
        }catch(\Throwable $e){if(is_file($tmp))unlink($tmp);wp_die(esc_html($e->getMessage()));}
        finally{if(is_file($tmp))unlink($tmp);}exit;
    }
    public static function render(): void {
        echo '<h2>File export profiles</h2><p>Download selected file categories. This does not publish or include the database. Configuration credentials and this connector are excluded.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('zoer_export_profile');echo '<input type="hidden" name="action" value="zoer_export_profile"><label>Profile name <input name="name" required maxlength="80"></label><p>';
        foreach(['themes'=>'Themes','plugins'=>'Plugins','media'=>'Media uploads','muplugins'=>'Must-use plugins','core'=>'WordPress core'] as $key=>$label)echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="'.esc_attr($key).'"> '.esc_html($label).'</label>';
        echo '</p><label>Exclusions (relative to the WordPress root, one pattern per line)<br><textarea name="excludes" rows="4" cols="50"></textarea></label><p><button class="button button-primary">Save profile</button></p></form>';
        foreach((array)get_option('zoer_connect_profiles',[]) as $id=>$profile){
            echo '<h3>'.esc_html($profile['name']).'</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_download');echo '<input type="hidden" name="action" value="zoer_export_download"><input type="hidden" name="profile_id" value="'.esc_attr($id).'"><button class="button">Download file export</button></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('zoer_export_profile');echo '<input type="hidden" name="action" value="zoer_export_profile"><input type="hidden" name="operation" value="delete"><input type="hidden" name="profile_id" value="'.esc_attr($id).'"><button class="button">Delete profile</button></form>';
        }
    }
}
