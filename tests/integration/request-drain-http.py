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
 # Reset only this isolated test's prior qualification receipt for a fresh setup.
 php("$p='/var/www/.zoer-connect/import-readiness.json';if(is_file($p)){copy($p,'/var/www/.zoer-connect/031-readiness-before-test.json');unlink($p);}")
 before=workers();before_master=master()
 code,page=req(PAGE);assert code==200 and 'Zoer Connect' in page
 # Simulate an earlier CLI request that has not seen the installed MU bootstrap.
 old=subprocess.Popen(['sudo','docker','exec','-i',PEER,'php','-r','echo "OLD\\n";fflush(STDOUT);fgets(STDIN);'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 assert old.stdout.readline()==b'OLD\n'
 code,page=req(PAGE,form(page,'install'));assert code==200 and 'Request protection installed.' in page
 def probes(page):
  m=re.search(r'_ajax_nonce:("[^"]+")',page);assert m,'Probe nonce missing';nonce=json.loads(m[1]);url=BASE+'/wp-admin/admin-ajax.php'
  assert req(url,{'action':'zoer_connect_drain_probe','_ajax_nonce':'invalid'})[0]==403
  assert req(url,{'action':'zoer_connect_drain_probe','_ajax_nonce':nonce},False)[0]==400
  for _ in range(4):
   with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    replies=list(pool.map(lambda _:req(url,{'action':'zoer_connect_drain_probe','_ajax_nonce':nonce}),range(6)))
   assert all(code==200 and json.loads(body)['success'] for code,body in replies)
 probes(page)
 confirm=form(page,'confirm');confirm.update(no_external_writers='1',single_host_local_locks='1')
 code,page=req(PAGE,confirm);assert code==200 and 'have not yet entered request protection' in page and 'Destination setup confirmed.' not in page
 print('PASS real admin setup rejects a still-running unprotected process; probe nonce and authentication enforced',flush=True)
 old.stdin.write(b'end\n');old.stdin.close();assert old.wait(timeout=10)==0;old=None
 probes(page)
 confirm=form(page,'confirm');confirm.update(no_external_writers='1',single_host_local_locks='1')
 code,page=req(PAGE,confirm);assert code==200 and 'Destination setup confirmed.' in page, [html.unescape(re.sub('<[^>]+>',' ',x)) for x in re.findall(r'<div class="notice[^"]*">(.*?)</div>',page,re.S)]
 after=workers();assert master()==before_master,'PHP pool master was restarted';retained=sum(after.get(pid)==start for pid,start in before.items())
 receipt=json.loads(docker('cat','/var/www/.zoer-connect/import-readiness.json'))
 assert receipt['version']==2 and receipt['workersVerified'] and 'phpWorkersRestarted' not in receipt
 print('PASS real WordPress HTTP setup enabled imports after natural drain; pool master unchanged; '+str(retained)+' original workers retained (natural max_requests recycling allowed); no hosting control or restart used',flush=True)
 # Prepare the existing native HTTP regression scripts' private test key; preserve original.
 php("$p='/var/www/.zoer-connect/';if(file_exists($p.'acceptance-native-key'))throw new Exception('Existing test key');$old=get_option(ZoerConnect\\ConnectionKey::OPTION,null);file_put_contents($p.'031-key-backup.json',json_encode($old));chmod($p.'031-key-backup.json',0600);[$key,$r]=ZoerConnect\\ConnectionKey::create((int)get_users(['role'=>'administrator','fields'=>'ID'])[0]);$r['push']=true;$r['pull']=true;update_option(ZoerConnect\\ConnectionKey::OPTION,$r,false);file_put_contents($p.'acceptance-native-key',$key);chmod($p.'acceptance-native-key',0600);")
finally:
 if old:
  old.stdin.write(b'end\n');old.stdin.close();old.wait(timeout=10)
 # Revoke only our generated session, preserving other administrators' sessions.
 payload=json.dumps({'uid':login['uid'],'token':login['token']}).encode()
 docker('php','-r',"require '/var/www/html/wp-load.php';$j=json_decode(stream_get_contents(STDIN),true);WP_Session_Tokens::get_instance($j['uid'])->destroy($j['token']);",input=payload)
