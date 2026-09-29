<?php
// Minimal WordPress boundary for CLI/form tests, never a live WordPress integration.
if(!in_array(PHP_SAPI,['cli','cli-server'],true))exit;
$GLOBALS['fixtureOptions']??=[];
$GLOBALS['fixtureAdmin']??=true;
$GLOBALS['fixtureSsl']??=true;
function get_option($key,$default=null){return $GLOBALS['fixtureOptions'][$key]??($key==='home'?'https://destination.example':$default);}
function update_option($key,$value,$autoload=false){$GLOBALS['fixtureOptions'][$key]=$value;return true;}
function current_user_can($cap){return $GLOBALS['fixtureAdmin'];}
function get_current_user_id(){return 7;}
function user_can($id,$cap){return $id===7;}
function is_ssl(){return $GLOBALS['fixtureSsl'];}
function is_multisite(){return false;}
function wp_verify_nonce($nonce,$action){return $nonce==='fixture:'.$action;}
function check_admin_referer($action){if(!wp_verify_nonce($_POST['_wpnonce']??'',$action))throw new RuntimeException('Invalid nonce.');}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="'.esc_attr('fixture:'.$action).'">';}
function wp_die($message){throw new RuntimeException($message);}
function wp_unslash($value){return $value;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}
function esc_textarea($value){return esc_html($value);}
function esc_url($value){return esc_html($value);}
function checked($value,$expected=true,$echo=true){$result=$value===$expected?'checked':'';if($echo)echo $result;return $result;}
function disabled($value,$expected=true,$echo=true){$result=$value===$expected?'disabled':'';if($echo)echo $result;return $result;}
function home_url(){return get_option('home');}
function untrailingslashit($value){return rtrim($value,'/');}
function admin_url($path){return '/'.$path;}
foreach(['StageStore','WriteFence','ConnectionKey','Plugin','ConnectionAdmin','ImportAdmin'] as $class)require_once __DIR__.'/../../includes/'.$class.'.php';
