<?php
namespace {
    class WP_Error { public function __construct(public string $code, public string $message) {} }
    $filters=[]; $responses=[]; $requests=[]; $downloadContent=''; $downloaded=[];
    function add_filter($hook,$callback,$priority,$arguments){$GLOBALS['filters'][$hook]=[$callback,$priority,$arguments];}
    function add_action($hook,$callback){$GLOBALS['filters'][$hook]=[$callback];}
    function wp_safe_remote_get($url,$options){$GLOBALS['requests'][]=[$url,$options];return $GLOBALS['responses'][$url]??new WP_Error('missing','No fixture');}
    function wp_remote_retrieve_response_code($response){return $response['code'];}
    function wp_remote_retrieve_body($response){return $response['body'];}
    function is_wp_error($value){return $value instanceof WP_Error;}
    function download_url($url,$timeout){$path=tempnam(sys_get_temp_dir(),'zoer-update-');file_put_contents($path,$GLOBALS['downloadContent']);$GLOBALS['downloaded'][]=$path;return $path;}
    function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
    // Release-candidate channel (0.8.0) and its settings form.
    $options=[]; $can=true; $nonces=[]; $cleared=0; $redirect=null;
    class Redirected extends \Exception {}
    class Died extends \Exception {}
    function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['options'])?$GLOBALS['options'][$key]:$default;}
    function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;$GLOBALS['autoload'][$key]=$autoload;return true;}
    function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
    function current_user_can($capability){return $capability==='update_plugins'&&$GLOBALS['can'];}
    function check_admin_referer($action){$GLOBALS['nonces'][]=$action;return 1;}
    function delete_site_transient($key){if($key==='update_plugins')$GLOBALS['cleared']++;return true;}
    function wp_safe_redirect($url){$GLOBALS['redirect']=$url;throw new Redirected($url);}
    function wp_die($message,$code=500){throw new Died($message,$code);}
    function admin_url($path=''){return 'https://site.test/wp-admin/'.$path;}
    function esc_url($url){return $url;}
    function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES);}
    function wp_nonce_field($action,$name,$referer,$echo){return '<input type="hidden" name="'.$name.'" value="nonce-'.$action.'">';}
    $screen=null;
    function get_current_screen(){return $GLOBALS['screen'];}
    function notice(): string {ob_start();\ZoerConnect\GitHubUpdater::signingNotice();return (string)ob_get_clean();}
    // `--bad-key`: a configured key that is not a 32-byte base64 Ed25519 key refuses every update.
    if (in_array('--bad-key', $argv, true)) define('ZOER_CONNECT_UPDATE_PUBLIC_KEY', base64_encode(str_repeat('k', 31)));
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
    if (in_array('--bad-key', $argv, true)) {
        $signed=$manifest+['signature'=>base64_encode(str_repeat('s',64))];
        $GLOBALS['responses'][$latest]=['code'=>200,'body'=>json_encode($signed)];
        $GLOBALS['responses'][$versioned]=['code'=>200,'body'=>json_encode($signed)];
        \check(GitHubUpdater::publicKey()===false&&str_contains((string)GitHubUpdater::signingStatus(),'refuses all updates'),'Unusable key not reported.');
        \check(str_contains(\notice(),'notice-error'),'Unusable key notice missing.');
        \check(GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[])===false,'Update offered with an unusable key.');
        $file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
        \check($file instanceof \WP_Error&&$file->code==='zoer_connect_update_manifest'&&!$GLOBALS['downloaded'],'Download accepted with an unusable key.');
        echo "PASS a configured but unusable update key refuses every update and shows an error notice\n";
        exit;
    }
    GitHubUpdater::boot();
    \check(isset($GLOBALS['filters']['update_plugins_github.com'],$GLOBALS['filters']['upgrader_pre_download'],$GLOBALS['filters']['admin_post_zoer_update_channel'],$GLOBALS['filters']['admin_notices']),'Update hooks missing.');
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
    // --- Release-candidate channel ---
    $api='https://api.github.com/repos/ahzs645/zoer-connect/releases?per_page=10';
    $releases=fn(array $tags)=>['code'=>200,'body'=>json_encode(array_map(fn($tag)=>is_array($tag)?$tag:['tag_name'=>$tag,'draft'=>false,'prerelease'=>str_contains($tag,'-')],$tags))];
    $rcContent='release candidate ZIP fixture';
    $manifestOf=fn(string $v,string $body)=>['code'=>200,'body'=>json_encode(['version'=>$v,'sha256'=>hash('sha256',$body)])];
    $GLOBALS['responses'][$api]=$releases(['v0.8.0-rc.2','v0.8.0-rc.1','v0.7.0','v0.9.0-beta.1',['tag_name'=>'v0.9.0-rc.1','draft'=>true],'v0.8.0-rc.2.1','latest']);
    $GLOBALS['responses'][$repo.'/releases/download/v0.8.0-rc.2/update.json']=$manifestOf('0.8.0-rc.2',$rcContent);
    $GLOBALS['responses'][$latest]=['code'=>200,'body'=>json_encode(['version'=>'0.7.0','sha256'=>hash('sha256',$content)])];
    // Off (the default): the stable feed only; the releases list is never read.
    $GLOBALS['requests']=[];
    \check(GitHubUpdater::check(false,['Version'=>'0.7.0'],'zoer-connect/zoer-connect.php',[])===false,'Stable channel offered a release candidate.');
    \check(!in_array($api,array_column($GLOBALS['requests'],0),true),'Stable channel read the releases list.');
    // On: the newest stable release or rc by version; drafts, alpha/beta and malformed tags are ignored.
    $GLOBALS['options'][GitHubUpdater::CHANNEL_OPTION]='rc';
    $rcPackage=$repo.'/releases/download/v0.8.0-rc.2/zoer-connect-0.8.0.zip';
    $update=GitHubUpdater::check(false,['Version'=>'0.7.0'],'zoer-connect/zoer-connect.php',[]);
    \check(is_array($update)&&$update['version']==='0.8.0-rc.2'&&$update['package']===$rcPackage&&$update['autoupdate']===false,'Release candidate not offered: '.json_encode($update));
    \check(GitHubUpdater::check(false,['Version'=>'0.8.0-rc.1'],'zoer-connect/zoer-connect.php',[])['version']==='0.8.0-rc.2','rc.2 not offered over rc.1.');
    \check(GitHubUpdater::check(false,['Version'=>'0.8.0-rc.2'],'zoer-connect/zoer-connect.php',[])===false,'Current release candidate offered again.');
    $GLOBALS['downloadContent']=$rcContent;
    $file=GitHubUpdater::download(false,$rcPackage,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check(is_string($file)&&file_get_contents($file)===$rcContent,'Release candidate download not verified.');
    unlink($file);
    $GLOBALS['downloadContent']='tampered release candidate';
    \check(GitHubUpdater::download(false,$rcPackage,null,['plugin'=>'zoer-connect/zoer-connect.php']) instanceof \WP_Error,'Tampered release candidate accepted.');
    \check(GitHubUpdater::download(false,$repo.'/releases/download/v0.8.0-rc.2/zoer-connect-0.8.0-rc.2.zip',null,['plugin'=>'zoer-connect/zoer-connect.php'])===false,'Unexpected release candidate file name accepted.');
    // The release's own update.json must confirm the tag's version.
    $GLOBALS['responses'][$repo.'/releases/download/v0.8.0-rc.2/update.json']=$manifestOf('0.8.0-rc.1',$rcContent);
    \check(GitHubUpdater::check(false,['Version'=>'0.7.0'],'zoer-connect/zoer-connect.php',[])===false,'Mismatched release candidate manifest accepted.');
    // The final release supersedes its candidates for opted-in sites.
    $GLOBALS['responses'][$api]=$releases(['v0.8.0','v0.8.0-rc.2','v0.7.0']);
    $GLOBALS['responses'][$repo.'/releases/download/v0.8.0/update.json']=$manifestOf('0.8.0',$content);
    $update=GitHubUpdater::check(false,['Version'=>'0.8.0-rc.2'],'zoer-connect/zoer-connect.php',[]);
    \check(is_array($update)&&$update['version']==='0.8.0'&&$update['package']===$repo.'/releases/download/v0.8.0/zoer-connect-0.8.0.zip','Final release not offered over its candidate.');
    foreach ([['code'=>403,'body'=>'{"message":"rate limited"}'], ['code'=>200,'body'=>'{"not":"a list"}'], ['code'=>200,'body'=>'[]']] as $badList) {
        $GLOBALS['responses'][$api]=$badList;
        \check(GitHubUpdater::check(false,['Version'=>'0.7.0'],'zoer-connect/zoer-connect.php',[])===false,'Bad releases list produced an update.');
    }
    unset($GLOBALS['options'][GitHubUpdater::CHANNEL_OPTION]);
    // The stable channel never downloads a release candidate, even one a cached update check still offers.
    $GLOBALS['responses'][$repo.'/releases/download/v0.8.0-rc.2/update.json']=$manifestOf('0.8.0-rc.2',$rcContent);
    $GLOBALS['downloadContent']=$rcContent;$before=count($GLOBALS['downloaded']);
    $file=GitHubUpdater::download(false,$rcPackage,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check($file instanceof \WP_Error&&$file->code==='zoer_connect_update_manifest'&&count($GLOBALS['downloaded'])===$before,'Stable channel downloaded a release candidate.');
    // --- Settings form ---
    $GLOBALS['can']=false;
    \check(GitHubUpdater::settingsCard()==='','Card shown without update_plugins.');
    try{GitHubUpdater::saveChannel();\check(false,'Saved without update_plugins.');}catch(\Died $e){\check($e->getCode()===403&&!isset($GLOBALS['options'][GitHubUpdater::CHANNEL_OPTION]),'Refusal changed the channel.');}
    $GLOBALS['can']=true;
    $card=GitHubUpdater::settingsCard();
    \check(str_contains($card,'name="action" value="zoer_update_channel"')&&str_contains($card,'nonce-zoer_update_channel')&&str_contains($card,'value="1">')&&!str_contains($card,'value="1" checked'),'Card form, nonce or default state wrong.');
    $_POST=['release_candidates'=>'1'];
    try{GitHubUpdater::saveChannel();}catch(\Redirected $e){}
    \check(($GLOBALS['options'][GitHubUpdater::CHANNEL_OPTION]??null)==='rc'&&$GLOBALS['autoload'][GitHubUpdater::CHANNEL_OPTION]===false&&end($GLOBALS['nonces'])==='zoer_update_channel'&&$GLOBALS['cleared']===1&&str_contains($GLOBALS['redirect'],'page=zoer-connect'),'Opting in did not save, check the nonce, clear the update cache or redirect.');
    \check(str_contains(GitHubUpdater::settingsCard(),'value="1" checked'),'Card does not show the saved choice.');
    $_POST=[];
    try{GitHubUpdater::saveChannel();}catch(\Redirected $e){}
    \check(!isset($GLOBALS['options'][GitHubUpdater::CHANNEL_OPTION])&&$GLOBALS['cleared']===2,'Opting out did not remove the channel.');
    // --- Signed updates (0.10.0) ---
    \check(GitHubUpdater::PUBLIC_KEY===''||defined('ZOER_CONNECT_UPDATE_PUBLIC_KEY'),'Test assumes no shipped key.');
    \check(GitHubUpdater::publicKey()===null&&str_contains((string)GitHubUpdater::signingStatus(),'unsigned'),'Unsigned updates not reported.');
    $GLOBALS['screen']=(object)['id'=>'dashboard'];\check(\notice()==='','Unsigned notice shown outside plugin screens.');
    $GLOBALS['screen']=(object)['id'=>'plugins'];\check(str_contains(\notice(),'notice-warning')&&str_contains(\notice(),'ZOER_CONNECT_UPDATE_PUBLIC_KEY'),'Unsigned notice missing on Plugins.');
    $GLOBALS['can']=false;\check(\notice()==='','Unsigned notice shown without update_plugins.');$GLOBALS['can']=true;
    \check(str_contains(GitHubUpdater::settingsCard(),'updates are unsigned'),'Settings card does not say updates are unsigned.');
    $pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$other=sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    define('ZOER_CONNECT_UPDATE_PUBLIC_KEY',base64_encode(sodium_crypto_sign_publickey($pair)));
    $sign=fn(string $v,string $sha,string $key)=>base64_encode(sodium_crypto_sign_detached("zoer-connect-update-v1\n$v\n$sha\n",$key));
    \check(GitHubUpdater::signingStatus()===null&&\notice()===''&&!str_contains(GitHubUpdater::settingsCard(),'unsigned'),'Signed updates still reported as unsigned.');
    $serve=function(array $m)use($latest,$versioned){$GLOBALS['responses'][$latest]=['code'=>200,'body'=>json_encode($m)];$GLOBALS['responses'][$versioned]=['code'=>200,'body'=>json_encode($m)];};
    $GLOBALS['downloadContent']=$content;
    $refused=function(string $label)use($package){
        \check(GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[])===false,$label.' offered.');
        $before=count($GLOBALS['downloaded']);$file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
        \check($file instanceof \WP_Error&&$file->code==='zoer_connect_update_manifest'&&str_contains($file->message,'signed')&&count($GLOBALS['downloaded'])===$before,$label.' downloaded.');
    };
    $serve($manifest);$refused('Unsigned release');
    $serve($manifest+['signature'=>'not base64!']);$refused('Malformed signature');
    $serve($manifest+['signature'=>$sign($version,$manifest['sha256'],$other)]);$refused('Release signed by another key');
    $serve($manifest+['signature'=>$sign($version,hash('sha256','other ZIP'),$secret)]);$refused('Signature over another checksum');
    $serve($manifest+['signature'=>$sign('0.5.3',$manifest['sha256'],$secret)]);$refused('Signature over another version');
    $serve($manifest+['signature'=>$sign($version,$manifest['sha256'],$secret)]);
    $update=GitHubUpdater::check(false,['Version'=>'0.5.1'],'zoer-connect/zoer-connect.php',[]);
    \check(is_array($update)&&$update['version']===$version,'Signed release not offered.');
    $file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check(is_string($file)&&file_get_contents($file)===$content,'Signed release not downloaded.');unlink($file);
    $GLOBALS['downloadContent']='altered ZIP fixture';
    $file=GitHubUpdater::download(false,$package,null,['plugin'=>'zoer-connect/zoer-connect.php']);
    \check($file instanceof \WP_Error&&$file->code==='zoer_connect_update_checksum','Signed manifest let a changed ZIP through.');
    // The unsigned legacy bridge feed cannot satisfy a configured key.
    $GLOBALS['downloadContent']=$content;
    \check(GitHubUpdater::download(false,$legacyPackage,null,[]) instanceof \WP_Error,'Unsigned legacy package accepted with a key.');
    echo "PASS signed updates: unsigned notice without a key; with a key, unsigned, malformed, foreign-key and mismatched signatures are refused and signed releases verify\n";
    echo "PASS stable channel never downloads a release candidate\n";
    echo "PASS source-repository update discovery, immutable versioned checksums, malformed metadata, and legacy transient compatibility\n";
    echo "PASS release-candidate channel: opt-in only, newest stable or rc by version, per-release manifests, verified downloads, settings form\n";
}
