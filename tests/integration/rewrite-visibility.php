<?php
if (strpos(home_url(), 'zoer-connect-wp-integration.test') === false) throw new RuntimeException('Disposable WordPress site required.');
require_once WP_PLUGIN_DIR.'/zoer-connect/includes/TableStage.php';
global $wpdb;

$id=bin2hex(random_bytes(8));
$table=new \ZoerConnect\TableStage($wpdb,$wpdb->options,$id,true);
$originalVisibility=get_option('blog_public');
$originalStructure=get_option('permalink_structure');
$swapped=false;
try {
    update_option('blog_public','1');
    update_option('permalink_structure','/%postname%/');
    $table->prepare();
    $rows=$wpdb->get_results("SELECT * FROM `$wpdb->options` ORDER BY option_id",ARRAY_A);
    foreach ($rows as &$row) {
        if ($row['option_name']==='blog_public') $row['option_value']='0';
        if ($row['option_name']==='rewrite_rules') $row['option_value']='source-only-rules';
    }
    unset($row);
    $chunks=array_chunk($rows,20);
    foreach ($chunks as $i=>$chunk) $table->chunk($i,$chunk);
    $table->verify((int)$wpdb->get_var("SELECT COUNT(*) FROM `zoer_s_$id`"),count($chunks));
    $table->activate();$swapped=true;
    if ($wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='blog_public'")!=='1') throw new RuntimeException('Destination search visibility changed.');

    if (!\ZoerConnect\RewriteRefresh::queue($wpdb,['hasDb'=>true,'artifacts'=>[]])) throw new RuntimeException('Refresh was not queued.');
    wp_cache_flush();
    \ZoerConnect\RewriteRefresh::run();
    $rules=$wpdb->get_var("SELECT option_value FROM `$wpdb->options` WHERE option_name='rewrite_rules'");
    if ($rules==='source-only-rules' || $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$wpdb->options` WHERE option_name=%s",\ZoerConnect\RewriteRefresh::OPTION))!==null) throw new RuntimeException('Permalink refresh did not complete.');

    $wpdb->update($wpdb->options,['option_value'=>'0'],['option_name'=>'blog_public']);
    $table->resetRollbackPreflight();$refused=false;
    try { while (!$table->rollbackCheckStep()) {} } catch (RuntimeException $e) { $refused=str_contains($e->getMessage(),'edited after publication'); }
    if (!$refused) throw new RuntimeException('A later search-visibility edit did not block rollback.');
    $wpdb->update($wpdb->options,['option_value'=>'1'],['option_name'=>'blog_public']);
    $table->resetRollbackPreflight();$table->rollback();$swapped=false;
    echo "PASS live WordPress preserves destination visibility, refreshes rules once, guards later edits and rolls back\n";
} finally {
    if ($swapped) { $table->resetRollbackPreflight();$table->rollback(); }
    foreach (['zoer_s_'.$id,'zoer_b_'.$id,'zoer_l_'.$id] as $name) $wpdb->query("DROP TABLE IF EXISTS `$name`");
    update_option('blog_public',$originalVisibility);
    update_option('permalink_structure',$originalStructure);
    flush_rewrite_rules(false);
    wp_cache_flush();
}
