#!/usr/bin/env python3
"""Disposable peer ONLY: native HTTPS import, real external administrator login, and rollback.
No keys or database content are printed. Run on the k3s/DDEV host after admin setup.
"""
import base64, hashlib, json, subprocess, sys, urllib.request, urllib.error, uuid
PEER='ddev-zoer-connect-transfer-peer-web'
BASE='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh'
TARGET='http://zoer-connect-transfer-peer.ddev.site:8080'
PRIVATE='/var/www/.zoer-connect/'
def docker(*args,input=None):
 return subprocess.check_output(['sudo','docker','exec','-i',PEER,*args],input=input)
def php(code):
 return docker('php','-r',"define('SHORTINIT',true);require '/var/www/html/wp-load.php';"+code)
key=docker('cat',PRIVATE+'acceptance-native-key').decode().strip()
sql=subprocess.check_output(['sudo','docker','exec','-u','root',PEER,'cat','/tmp/zoer-sparklab-source.sql'])
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
receipt=PRIVATE+'034-real-source-job.json'
route='/wp-json/zoer-connect/v1/imports'
if sys.argv[1]=='start':
 baseline=json.loads(php(hash_code))
 cron_before=php("echo hash('sha256',(string)$wpdb->get_var(\"SELECT option_value FROM `$wpdb->options` WHERE option_name='cron'\"));")
 id=uuid.uuid4().hex
 manifest={'id':id,'target':TARGET,'sourceUrl':'http://sparklab-ddev-restore.ddev.site:8080','originalUrls':['https://sparklab.wp.k8s.ahmad.sh'],'sourcePrefix':'wp_',**POLICY,'database':{'bytes':len(sql),'sha256':hashlib.sha256(sql).hexdigest(),'chunkSha256':[hashlib.sha256(sql[o:o+262144]).hexdigest() for o in range(0,len(sql),262144)]},'files':[]}
 code,state=request(route,manifest)
 assert code==200,(code,state)
 docker('tee',receipt,input=json.dumps({'id':id,'baseline':baseline}).encode())
 for offset in range(0,len(sql),262144):
  code,state=request(route+'/'+id+'/chunks',{'index':0,'offset':offset,'data':base64.b64encode(sql[offset:offset+262144]).decode()})
  assert code==200,(code,state)
 for n in range(3000):
  code,state=request(route+'/'+id+'/step',{})
  assert code==200,(code,state)
  if n%25==0: print(state['phase'],state.get('cursor'),sum(state.get('tableRows',[])),flush=True)
  if state['phase']=='verification_required':break
 assert state['phase']=='verification_required',state
 code,state=request(route+'/'+id+'/finish',{})
 assert code==200 and state['phase']=='complete',(code,state)
 assert php("echo hash('sha256',(string)$wpdb->get_var(\"SELECT option_value FROM `$wpdb->options` WHERE option_name='cron'\"));")==cron_before,'Import replaced destination runtime queue'
 print('PASS new-policy nativeHTTPS database import completed; ready for real administrator login/logout')
elif sys.argv[1]=='rollback':
 saved=json.loads(docker('cat',receipt));id=saved['id'];cron_fenced=None
 for n in range(3000):
  code,state=request(route+'/'+id+'/rollback',{})
  assert code==200,(code,state)
  if cron_fenced is None:
   cron_fenced=php("echo hash('sha256',(string)$wpdb->get_var(\"SELECT option_value FROM `$wpdb->options` WHERE option_name='cron'\"));")
  if state['phase']=='rolled_back':break
 assert state['phase']=='rolled_back',state
 assert php("echo hash('sha256',(string)$wpdb->get_var(\"SELECT option_value FROM `$wpdb->options` WHERE option_name='cron'\"));")==cron_fenced,'Rollback replaced current runtime queue'
 actual=json.loads(php(hash_code));expected=saved['baseline']
 # Login/logout legitimately changes retained session/dashboard user metadata.
 # Every migrated table and administrator credential row must match exactly.
 actual.pop('usermeta');expected.pop('usermeta')
 assert actual==expected,'Migrated tables or administrator credential rows differ from originals'
 docker('test','!','-e',PRIVATE+'write-fence.json')
 print('PASS nativeHTTPS rollback after real admin login/logout; ten tables and administrator credentials restored with current runtime cron preserved, without fixture cleanup')
else:raise RuntimeError('Choose start or rollback')
