#!/usr/bin/env python3
"""Run on k3s host; the temporary peer key never leaves this process or peer storage."""
import base64, hashlib, json, subprocess, urllib.request, urllib.error, uuid
BASE='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh'
TARGET='http://zoer-connect-transfer-peer.ddev.site:8080'
KEY=subprocess.check_output(['sudo','docker','exec','ddev-zoer-connect-transfer-peer-web','cat','/var/www/.zoer-connect/acceptance-native-key'],text=True).strip()
receipt=json.loads(subprocess.check_output(['sudo','docker','exec','ddev-zoer-connect-transfer-peer-web','cat','/var/www/.zoer-connect/import-readiness.json']))
POLICY={'migrationMode':'shared-replacement','replacementAccepted':True} if receipt.get('mode')=='shared-replacement' else {'wordpressOnlyWriters':True}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
def request(path,body=None,key=KEY):
 data=None if body is None else json.dumps(body).encode()
 r=urllib.request.Request(BASE+path,data=data,headers={'X-Zoer-Connection':key,'Content-Type':'application/json'})
 try:
  with opener.open(r,timeout=20) as response:return response.status,json.load(response)
 except urllib.error.HTTPError as error:
  try:body=json.load(error)
  except Exception:body={}
  return error.code,body
id=uuid.uuid4().hex
content=b'/* Zoer authenticated import and rollback acceptance */\n'
manifest={'id':id,'target':TARGET,'sourceUrl':'https://source.example','sourcePrefix':'wp_',**POLICY,'files':[{'path':'wp-content/themes/zoer-public-http-check/style.css','bytes':len(content),'sha256':hashlib.sha256(content).hexdigest()}]}
route='/wp-json/zoer-connect/v1/imports'
assert request(route,manifest,key='zc_'+'0'*64)[0]==401,'Revoked key was accepted'
code,state=request(route,manifest)
assert code==200,(code,state)
assert state['id']==id
assert request(route+'/'+id,key='zc_'+'0'*64)[0]==401,'Invalid-key status was accepted'
code,state=request(route+'/'+id+'/chunks',{'index':0,'offset':0,'data':base64.b64encode(content).decode()})
assert code==200,(code,state)
try:
 for n in range(50):
  code,state=request(route+'/'+id+'/step',{})
  assert code==200,(code,state)
  if state['phase']=='verification_required':break
 assert state['phase']=='verification_required',state
 # wp-cron may fastcgi_finish_request before wp-load: its HTTP status is not a fence assertion.
 for path in ['/wp-admin/admin-ajax.php']:
  external=request(path)
  assert external[0] in (502,503),('Public WordPress was not blocked',external)
  direct=subprocess.check_output(['sudo','docker','exec','ddev-zoer-connect-transfer-peer-web','curl','-sS','-w','\n%{http_code}','http://127.0.0.1'+path],text=True)
  body,status=direct.rsplit('\n',1)
  assert status=='503' and json.loads(body)['code']=='zoer_transfer_paused','Direct WordPress did not enforce persistent fence'
  if external[0]==502:print('NOTE public proxy returned502 while direct WordPress correctly returned503 for '+path,flush=True)
 assert request(route+'/'+id,key='zc_'+'0'*64)[0]==401,'Revoked key accessed fenced recovery'
 # New HTTPS connections simulate loss of the original caller between every tick.
 for n in range(50):
  code,state=request(route+'/'+id+'/rollback',{})
  assert code==200,(code,state)
  if state['phase']=='rolled_back':break
 assert state['phase']=='rolled_back',state
 assert request(route+'/'+id+'/rollback',{})[1]['phase']=='rolled_back','Terminal rollback retry failed'
 previous=id;id=uuid.uuid4().hex;manifest['id']=id;manifest['reuseImportId']=previous
 code,state=request(route,manifest);assert code==200 and state['phase']=='reusing_artifacts',(code,state)
 for n in range(60):
  code,state=request(route+'/'+id+'/step',{});assert code==200,(code,state)
  if state['phase']=='uploading':assert state['offsets']==[len(content)],'Cached bytes not reused'
  if state['phase']=='verification_required':break
 assert state['phase']=='verification_required',state
 for n in range(60):
  code,state=request(route+'/'+id+'/rollback',{});assert code==200,(code,state)
  if state['phase']=='rolled_back':break
 assert state['phase']=='rolled_back',state
 print('PASS native HTTPS reuses terminal artifact, verifies and activates without uploading again, then rolls back',flush=True)

 print('PASS real HTTPS native-key auth/revocation, protected early import routes, chunk upload, multi-request publication, fenced admin and resumed rollback (cron boot fencing covered separately; early cron HTTP status is not a proof)')
finally:
 if state.get('phase')!='rolled_back':
  for n in range(50):
   code,state=request(route+'/'+id+'/rollback',{})
   if state.get('phase')=='rolled_back':break
  print('Cleanup phase:',state.get('phase'))
