<?php
namespace {
    class WP_Error { public function __construct(public string $code, public string $message) {} }
    $filters=[]; $responses=[]; $requests=[]; $downloadContent=''; $downloaded=[];
    function add_filter($hook,$callback,$priority,$arguments){$GLOBALS['filters'][$hook]=[$callback,$priority,$arguments];}
    function wp_safe_remote_get($url,$options){$GLOBALS['requests'][]=[$url,$options];return $GLOBALS['responses'][$url]??new WP_Error('missing','No fixture');}
    function wp_remote_retrieve_response_code($response){return $response['code'];}
    function wp_remote_retrieve_body($response){return $response['body'];}
    function is_wp_error($value){return $value instanceof WP_Error;}
    function download_url($url,$timeout){$path=tempnam(sys_get_temp_dir(),'zoer-update-');file_put_contents($path,$GLOBALS['downloadContent']);$GLOBALS['downloaded'][]=$path;return $path;}
    function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
}
namespace ZoerConnect {
    require __DIR__.'/../includes/GitHubUpdater.php';
    $repo='https://github.com/ahzs645/zoer-connect';
    $legacy='https://raw.githubusercontent.com/ahzs645/zoer-connect-releases/main';
    $latest=$repo.'/releases/latest/download/update.json';
    $version='0.5.2';
    $package=$repo.'/releases/download/v'.$version.'/zoer-connect-'.$version.'.zip';
    $versioned=$repo.'/releases/download/v'.$version.'/update.json';
    $content='qualified ZIP fixture';
    $manifest=['version'=>$version,'sha256'=>hash('sha256',$content)];
    $GLOBALS['responses'][$latest]=['code'=>200,'body'=>json_encode($manifest)];
    $GLOBALS['responses'][$versioned]=['code'=>200,'body'=>json_encode($manifest)];
    $GLOBALS['downloadContent']=$content;
    GitHubUpdater::boot();
    \check(isset($GLOBALS['filters']['update_plugins_github.com'],$GLOBALS['filters']['upgrader_pre_download']),'Update hooks missing.');
    $update=GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[]);
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
    $GLOBALS['responses'][$latest]=['code'=>200,'body'=>'{"version":"0.3.12","sha256":"bad"}'];
    \check(GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[])===false,'Invalid manifest was accepted.');
    foreach (['0.5.2-rc.1', '0.5.2/other', '1.0', null] as $badVersion) {
        $GLOBALS['responses'][$latest]=['code'=>200,'body'=>json_encode(['version'=>$badVersion,'sha256'=>hash('sha256',$content)])];
        \check(GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[])===false,'Non-stable version accepted.');
    }
    foreach ([['code'=>404,'body'=>json_encode($manifest)], ['code'=>200,'body'=>str_repeat(' ',4097)], ['code'=>200,'body'=>'{invalid']] as $badResponse) {
        $GLOBALS['responses'][$latest]=$badResponse;
        \check(GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[])===false,'Bad response accepted.');
    }
    // Versioned metadata, never the moving latest asset, authenticates downloads.
    $GLOBALS['downloadContent']=$content;
    $GLOBALS['responses'][$versioned]=['code'=>200,'body'=>json_encode(['version'=>'0.5.3','sha256'=>hash('sha256',$content)])];
    $before=count($GLOBALS['downloaded']);
    $file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check($file instanceof \WP_Error&&$file->code==='zoer_connect_update_manifest'&&count($GLOBALS['downloaded'])===$before,'Mismatched version downloaded.');
    unset($GLOBALS['responses'][$versioned]);
    \check(GitHubUpdater::download(false,$package,null,[]) instanceof \WP_Error,'Missing versioned manifest accepted.');
    // A pre-upgrade WordPress transient may still point at the old feed.
    $legacyPackage=$legacy.'/releases/v'.$version.'/zoer-connect-'.$version.'.zip';
    $GLOBALS['responses'][$legacy.'/releases/v'.$version.'/manifest.json']=['code'=>200,'body'=>json_encode($manifest)];
    $file=GitHubUpdater::download(false,$legacyPackage,null,[]);
    \check(is_string($file)&&file_get_contents($file)===$content,'Cached legacy package not verified.');
    unlink($file);
    $GLOBALS['downloadContent']='corrupt legacy ZIP';
    \check(GitHubUpdater::download(false,$legacyPackage,null,[]) instanceof \WP_Error,'Corrupt legacy ZIP accepted.');
    \check(!file_exists(end($GLOBALS['downloaded'])),'Corrupt legacy ZIP retained.');
    $GLOBALS['responses'][$latest]=['code'=>200,'body'=>json_encode($manifest)];
    $GLOBALS['requests']=[];
    $update=GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[]);
    \check($update['id']===$repo&&$update['url']===$repo&&$update['autoupdate']===false,'Source repository identity or update policy changed.');
    \check($GLOBALS['requests'][0][0]===$latest&&$GLOBALS['requests'][0][1]['redirection']===3&&$GLOBALS['requests'][0][1]['limit_response_size']===4097,'Release asset redirects or response bound missing.');
    \check(GitHubUpdater::download('already handled',$package,null,[])==='already handled','Previous handler overridden.');
    echo "PASS source-repository update discovery, immutable versioned checksums, malformed metadata, and legacy transient compatibility\n";
}
