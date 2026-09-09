#!/usr/bin/env python3
"""Run on DDEV host; mutates ONLY transfer-peer. Never uses hosting controls."""
import concurrent.futures, html, http.cookiejar, json, re, subprocess, urllib.request, urllib.error
PEER='ddev-zoer-connect-transfer-peer-web'
BASE='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh'
PAGE=BASE+'/wp-admin/tools.php?page=zoer-connect'
def docker(*args,input=None):return subprocess.check_output(['sudo','docker','exec','-i',PEER,*args],input=input)
def php(code):return docker('php','-r',"require '/var/www/html/wp-load.php';if(get_option('home')!=='http://zoer-connect-transfer-peer.ddev.site:8080')throw new Exception('Peer only');"+code)
# One short-lived real administrator session, revoked in finally. Never print it.
login=json.loads(php("$u=(int)get_users(['role'=>'administrator','fields'=>'ID'])[0];$e=time()+1800;$t=WP_Session_Tokens::get_instance($u)->create($e);echo json_encode(['uid'=>$u,'token'=>$t,'cookies'=>[AUTH_COOKIE=>wp_generate_auth_cookie($u,$e,'auth',$t),SECURE_AUTH_COOKIE=>wp_generate_auth_cookie($u,$e,'secure_auth',$t),LOGGED_IN_COOKIE=>wp_generate_auth_cookie($u,$e,'logged_in',$t)]]);"))
cookie='; '.join(k+'='+v for k,v in login['cookies'].items())
def req(url,data=None,cookies=True):
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 request=urllib.request.Request(url,data=data,headers={'Cookie':cookie if cookies else '', 'Content-Type':'application/x-www-form-urlencoded'})
 try:
  with urllib.request.urlopen(request,timeout=25) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
def form(s,action):
 for f in re.findall(r'<form\b[^>]*>(.*?)</form>',s,re.S):
  vals={html.unescape(k):html.unescape(v) for k,v in re.findall(r'<input\b[^>]*name="([^"]+)"[^>]*value="([^"]*)"',f)}
  if vals.get('zoer_import_setup_action')==action:return vals
 raise AssertionError('Expected setup form not found')
def workers():
 x=json.loads(php("echo json_encode(ZoerConnect\\RequestDrain::workers());"));x['workers'].pop(x['pid'],None);return x['workers']
def master():
 pid=docker('pgrep','-f','^php-fpm: master process').decode().strip();stat=docker('cat','/proc/'+pid+'/stat').decode();return (pid,stat.rsplit(')',1)[1].split()[19])
old=None
try:
 php("$p='/var/www/.zoer-connect/import-readiness.json';if(is_file($p)){copy($p,'/var/www/.zoer-connect/032-readiness-before-test.json');unlink($p);}")
 before_master=master()
 code,page=req(PAGE);assert code==200
 old=subprocess.Popen(['sudo','docker','exec','-i',PEER,'php','-r','echo "OLD\\n";fflush(STDOUT);fgets(STDIN);'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 assert old.stdout.readline()==b'OLD\n'
 shared=form(page,'shared');shared.pop('replacement_accepted',None)
 code,page=req(PAGE,shared);assert 'Confirm replacement' in page and 'Shared-hosting replacement enabled.' not in page
 shared['replacement_accepted']='1';invalid=dict(shared);invalid['_wpnonce']='invalid'
 code,page=req(PAGE,invalid);assert 'Setup confirmation expired' in page
 code,page=req(PAGE,shared);assert code==200 and 'Shared-hosting replacement enabled.' in page
 assert old.poll() is None and master()==before_master,'Shared worker was stopped'
 receipt=json.loads(docker('cat','/var/www/.zoer-connect/import-readiness.json'))
 assert receipt['version']==3 and receipt['replacementAccepted'] and receipt['mode']=='shared-replacement' and 'workersVerified' not in receipt
 print('PASS real admin shared-hosting setup: nonce and replacement acceptance required; unrelated unprotected PHP process remains running; no worker restart or isolation claim',flush=True)
 # Prepare the existing native HTTP regression scripts' private test key; preserve original.
 php("$p='/var/www/.zoer-connect/';if(file_exists($p.'acceptance-native-key'))throw new Exception('Existing test key');$old=get_option(ZoerConnect\\ConnectionKey::OPTION,null);file_put_contents($p.'031-key-backup.json',json_encode($old));chmod($p.'031-key-backup.json',0600);[$key,$r]=ZoerConnect\\ConnectionKey::create((int)get_users(['role'=>'administrator','fields'=>'ID'])[0]);$r['push']=true;$r['pull']=true;update_option(ZoerConnect\\ConnectionKey::OPTION,$r,false);file_put_contents($p.'acceptance-native-key',$key);chmod($p.'acceptance-native-key',0600);")
finally:
 if old:
  old.stdin.write(b'end\n');old.stdin.close();old.wait(timeout=10)
 # Revoke only our generated session, preserving other administrators' sessions.
 payload=json.dumps({'uid':login['uid'],'token':login['token']}).encode()
 docker('php','-r',"require '/var/www/html/wp-load.php';$j=json_decode(stream_get_contents(STDIN),true);WP_Session_Tokens::get_instance($j['uid'])->destroy($j['token']);",input=payload)
