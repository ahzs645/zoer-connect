<?php
namespace {
    class WP_Error { public function __construct(public string $code, public string $message) {} }
    $filters=[]; $responses=[]; $downloadContent=''; $downloaded=[];
    function add_filter($hook,$callback,$priority,$arguments){$GLOBALS['filters'][$hook]=[$callback,$priority,$arguments];}
    function wp_safe_remote_get($url,$options){return $GLOBALS['responses'][$url]??new WP_Error('missing','No fixture');}
    function wp_remote_retrieve_response_code($response){return $response['code'];}
    function wp_remote_retrieve_body($response){return $response['body'];}
    function is_wp_error($value){return $value instanceof WP_Error;}
    function download_url($url,$timeout){$path=tempnam(sys_get_temp_dir(),'zoer-update-');file_put_contents($path,$GLOBALS['downloadContent']);$GLOBALS['downloaded'][]=$path;return $path;}
    function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
}
namespace ZoerConnect {
    require __DIR__.'/../includes/GitHubUpdater.php';
    $raw='https://raw.githubusercontent.com/ahzs645/zoer-connect-releases/main';
    $version='0.3.11';
    $package=$raw.'/releases/v'.$version.'/zoer-connect-'.$version.'.zip';
    $content='qualified ZIP fixture';
    $manifest=['version'=>$version,'sha256'=>hash('sha256',$content)];
    $GLOBALS['responses'][$raw.'/latest.json']=['code'=>200,'body'=>json_encode($manifest)];
    $GLOBALS['responses'][$raw.'/releases/v'.$version.'/manifest.json']=['code'=>200,'body'=>json_encode($manifest)];
    $GLOBALS['downloadContent']=$content;
    GitHubUpdater::boot();
    \check(isset($GLOBALS['filters']['update_plugins_github.com'],$GLOBALS['filters']['upgrader_pre_download']),'Update hooks missing.');
    $update=GitHubUpdater::check(false,['Version'=>'0.3.10'],'zoer-connect/zoer-connect.php',[]);
    \check(is_array($update)&&$update['version']===$version&&$update['package']===$package,'Qualified update not offered.');
    \check(GitHubUpdater::check(false,['Version'=>$version],'zoer-connect/zoer-connect.php',[])===false,'Current version offered again.');
    \check(GitHubUpdater::check('untouched',['Version'=>'0.1.0'],'another/plugin.php',[])==='untouched','Another plugin changed.');
    $file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check(is_string($file)&&file_get_contents($file)===$content,'Valid release was not verified.');
    unlink($file);
    $GLOBALS['downloadContent']='altered ZIP fixture';
    $file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check($file instanceof \WP_Error&&$file->code==='zoer_connect_update_checksum','Changed ZIP was accepted.');
    \check(!file_exists(end($GLOBALS['downloaded'])),'Rejected ZIP was retained.');
    \check(GitHubUpdater::download(false,$package,null,['plugin'=>'another/plugin.php'])===false,'Another plugin download changed.');
    $GLOBALS['responses'][$raw.'/latest.json']=['code'=>200,'body'=>'{"version":"0.3.12","sha256":"bad"}'];
    \check(GitHubUpdater::check(false,['Version'=>'0.3.10'],'zoer-connect/zoer-connect.php',[])===false,'Invalid manifest was accepted.');
    echo "PASS public GitHub update discovery, version gating and ZIP checksum verification\n";
}
