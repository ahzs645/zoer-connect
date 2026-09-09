#!/usr/bin/env python3
"""Disposable peer ONLY: kill real PHP-FPM workers during a blocked HTTPS import tick.
No keys or database content are printed. Run on the k3s/DDEV host after admin setup.
"""
import base64, concurrent.futures, hashlib, json, subprocess, time, urllib.request, urllib.error, uuid
PEER='ddev-zoer-connect-transfer-peer-web'
BASE='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh'
TARGET='http://zoer-connect-transfer-peer.ddev.site:8080'
PRIVATE='/var/www/.zoer-connect/'
def docker(*args,input=None):
 return subprocess.check_output(['sudo','docker','exec','-i',PEER,*args],input=input)
def php(code):
 return docker('php','-r',"define('SHORTINIT',true);require '/var/www/html/wp-load.php';"+code)
key=docker('cat',PRIVATE+'acceptance-native-key').decode().strip()
sql=docker('cat',PRIVATE+'acceptance-source.sql')
receipt_mode=json.loads(docker('cat',PRIVATE+'import-readiness.json')).get('mode')
POLICY={'migrationMode':'shared-replacement','replacementAccepted':True} if receipt_mode=='shared-replacement' else {'wordpressOnlyWriters':True}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
def request(path,body=None):
 data=None if body is None else json.dumps(body).encode()
 req=urllib.request.Request(BASE+path,data=data,headers={'X-Zoer-Connection':key,'Content-Type':'application/json'})
 try:
  with opener.open(req,timeout=20) as response:return response.status,json.load(response)
 except urllib.error.HTTPError as error:
  try:data=json.load(error)
  except Exception:data={}
  return error.code,data
hash_code='''$out=[];foreach(['commentmeta','comments','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','users','usermeta'] as $name)$out[$name]=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `{$wpdb->prefix}$name`".($name==='options'?" WHERE option_name<>\'cron\'":"")." ORDER BY 1",ARRAY_A)));echo json_encode($out);'''
baseline=None
cron_code="echo hash('sha256',serialize($wpdb->get_row(\"SELECT option_value,autoload FROM `$wpdb->options` WHERE option_name='cron'\",ARRAY_A)));"
cron_baseline=None
id=uuid.uuid4().hex
path='wp-content/uploads/zoer-http-kill-proof.txt'
content=b'Protected early recovery survives a real killed PHP request.\n'
block=262144
manifest={'id':id,'target':TARGET,'sourceUrl':'https://transfer-source.example','sourcePrefix':'wp_',**POLICY,'database':{'bytes':len(sql),'sha256':hashlib.sha256(sql).hexdigest(),'chunkSha256':[hashlib.sha256(sql[o:o+block]).hexdigest() for o in range(0,len(sql),block)]},'files':[{'path':path,'bytes':len(content),'sha256':hashlib.sha256(content).hexdigest()}]}
route='/wp-json/zoer-connect/v1/imports'
code,state=request(route,manifest)
assert code==200,(code,state)
lock=None
try:
 for index,data in enumerate([sql,content]):
  for offset in range(0,len(data),block):
   code,state=request(route+'/'+id+'/chunks',{'index':index,'offset':offset,'data':base64.b64encode(data[offset:offset+block]).decode()})
   assert code==200,(code,state)
 for n in range(3000):
  code,state=request(route+'/'+id+'/step',{})
  assert code==200,(code,state)
  if baseline is None and state['phase']=='preparing_tables':baseline=json.loads(php(hash_code));cron_baseline=php(cron_code)
  if state['phase']=='activating_tables' and state['cursor']==5:break
 assert state['phase']=='activating_tables' and state['cursor']==5,state
 # At this point files and the first five tables (including options) have switched.
 assert docker('cat','/var/www/html/'+path)==content
 code_str="define('SHORTINIT',true);require '/var/www/html/wp-load.php';if($wpdb->query('LOCK TABLES `wp_posts` WRITE')===false)exit(2);echo \"LOCKED\\n\";fflush(STDOUT);fgets(STDIN);$wpdb->query('UNLOCK TABLES');"
 lock=subprocess.Popen(['sudo','docker','exec','-i',PEER,'php','-r',code_str],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 assert lock.stdout.readline().strip()==b'LOCKED','Could not hold deterministic test lock'
 with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
  future=pool.submit(request,route+'/'+id+'/step',{})
  blocked=False
  for n in range(60):
   count=int(php('echo $wpdb->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND STATE LIKE \'%lock%\' AND INFO LIKE \'%wp_posts%\'");'))
   if count>0:blocked=True;break
   if future.done():break
   time.sleep(.05)
  assert blocked,'HTTPS worker did not reach the controlled table-lock interruption point'
  workers=[line.split()[0] for line in docker('ps','-eo','pid,args').decode().splitlines() if 'php-fpm: pool www' in line]
  assert workers,'No peer PHP-FPM workers found'
  docker('kill','-KILL',*workers)
  try:
   status,_=future.result(timeout=15)
   assert status>=500,'Killed request unexpectedly completed successfully'
  except (urllib.error.URLError,ConnectionError,TimeoutError):pass
 lock.stdin.write(b'release\n');lock.stdin.flush();lock.communicate(timeout=10);lock=None
 for n in range(100):
  try:
   code,state=request(route+'/'+id)
   if code==200:break
  except (urllib.error.URLError,ConnectionError):pass
  time.sleep(.05)
 assert code==200,(code,state)
 protected_verified=False
 for n in range(3000):
  code,state=request(route+'/'+id+'/rollback',{})
  assert code==200,(code,state)
  if state['phase']=='rollback_files' and state['cursor']<0:
   actual=json.loads(php(hash_code));assert baseline is not None and actual==baseline,('Protected rollback mismatch',[k for k in actual if actual[k]!=(baseline or {}).get(k)])
   assert php(cron_code)==cron_baseline,'Protected rollback changed the runtime cron queue'
   protected_verified=True
  if state['phase']=='rolled_back':break
 assert state['phase']=='rolled_back' and protected_verified,state
 docker('test','!','-e','/var/www/html/'+path)
 docker('test','!','-e',PRIVATE+'write-fence.json')
 print('PASS actual HTTPS import with files and five tables switched; SIGKILL PHP-FPM during blocked next-table activation; fresh authenticated early handler rolled back exact business-table/user/usermeta baseline plus current cron value/autoload verified under the fence before reopening, and removed proof file')
finally:
 if lock is not None:
  try:lock.stdin.write(b'release\n');lock.stdin.flush();lock.communicate(timeout=10)
  except Exception:lock.kill()
 if state.get('phase')!='rolled_back':
  for n in range(3000):
   try:
    code,state=request(route+'/'+id+'/rollback',{})
    if state.get('phase')=='rolled_back':break
   except Exception:break
  print('Cleanup phase:',state.get('phase'))
