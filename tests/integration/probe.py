"""Run on the DDEV host against the named disposable site only. No credentials logged."""
import subprocess,json,urllib.request,urllib.error,base64,hashlib,ssl,uuid
container='ddev-zoer-connect-security-test-web'
site='https://zoer-connect-security-test.wp.k8s.ahmad.sh'
def wp(*args):
 return subprocess.check_output(['sudo','docker','exec',container,'wp','--allow-root','--path=/var/www/html',*args],text=True).strip()
label='zoer-test-'+str(uuid.uuid4())
password=wp('user','application-password','create','admin',label,'--porcelain')
auth=base64.b64encode(('admin:'+password).encode()).decode()
def req(path,method='GET',body=None,authorized=True):
 headers={'Content-Type':'application/json','Host':'zoer-connect-security-test.ddev.site'}
 if authorized: headers['Authorization']='Basic '+auth
 r=urllib.request.Request('https://127.0.0.1:8443/wp-json/zoer-connect/v1'+path,data=json.dumps(body).encode() if body is not None else None,headers=headers,method=method)
 try:
  with urllib.request.urlopen(r,timeout=30,context=ssl._create_unverified_context()) as response: return response.status,json.load(response)
 except urllib.error.HTTPError as e: return e.code,json.load(e)
try:
 code,_=req('/status',authorized=False);assert code==401,(code,'anonymous accepted');print('PASS anonymous rejected')
 saved_auth=auth
 auth=base64.b64encode(b'admin:incorrect-test-password').decode()
 assert req('/status')[0]==401;print('PASS invalid credentials rejected')
 auth=saved_auth
 lowuser='zoertest'+uuid.uuid4().hex[:10]
 wp('user','create',lowuser,lowuser+'@example.invalid','--role=subscriber','--user_pass='+uuid.uuid4().hex)
 try:
  lowpass=wp('user','application-password','create',lowuser,label,'--porcelain')
  auth=base64.b64encode((lowuser+':'+lowpass).encode()).decode()
  assert req('/status')[0]==401;print('PASS subscriber application password rejected')
 finally:
  auth=saved_auth
  wp('user','delete',lowuser,'--yes')
 code,status=req('/status');assert code==200 and status['stagingReady'];print('PASS authenticated HTTPS status')
 payload=b'/* disposable integration fixture */'
 manifest={'version':1,'target':status['target'],'files':[{'path':'wp-content/themes/zoer-fixture/style.css','bytes':len(payload),'sha256':hashlib.sha256(payload).hexdigest()}]}
 bad=dict(manifest,target='https://wrong.example');assert req('/jobs','POST',bad)[0]==400;print('PASS wrong destination rejected')
 bad=dict(manifest,files=[dict(manifest['files'][0],path='wp-content/themes/../../wp-config.php')]);assert req('/jobs','POST',bad)[0]==400;print('PASS traversal rejected')
 code,job=req('/jobs','POST',manifest);assert code==200,(code,job)
 jid=job['id']
 try:
  assert req('/jobs')[1][0]['id']==jid;print('PASS lost response discovery')
  chunk={'index':0,'offset':0,'data':base64.b64encode(payload[:10]).decode()}
  assert req('/jobs/'+jid+'/chunks','POST',chunk)[1]['offset']==10
  assert req('/jobs/'+jid+'/chunks','POST',chunk)[1]['offset']==10;print('PASS identical retry')
  conflict=dict(chunk,data=base64.b64encode(b'XXXXXXXXXX').decode());assert req('/jobs/'+jid+'/chunks','POST',conflict)[0]==400;print('PASS conflicting retry rejected')
  assert req('/jobs/'+jid+'/verify','POST',{})[0]==400;print('PASS incomplete verification rejected')
  offset=req('/jobs/'+jid)[1]['offsets'][0];assert offset==10
  assert req('/jobs/'+jid+'/chunks','POST',{'index':0,'offset':offset,'data':base64.b64encode(payload[offset:]).decode()})[0]==200
  assert req('/jobs/'+jid+'/verify','POST',{})[1]['status']=='staged';print('PASS resumed transfer verified')
  assert req('/jobs/'+jid+'/chunks','POST',chunk)[0]==400;print('PASS verified content immutable')
  assert req('/jobs/'+jid+'/publish','POST',{})[0]==501;print('PASS unsupported publication refused')
  exists=wp('eval',"echo file_exists(ABSPATH . 'wp-content/themes/zoer-fixture/style.css') ? 'present' : 'absent';")
  assert exists=='absent';print('PASS staging did not modify live files')
 finally:
  assert req('/jobs/'+jid,'DELETE')[0]==200
 assert req('/jobs')[1]==[];print('PASS cancellation removes staging')
finally:
 # Delete only the test-created application password by its returned UUID lookup.
 entries=json.loads(wp('user','application-password','list','admin','--format=json'))
 for entry in entries:
  if entry['name']==label:wp('user','application-password','delete','admin',entry['uuid'])
