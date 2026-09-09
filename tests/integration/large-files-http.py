#!/usr/bin/env python3
"""Destructive large-file/recovery qualification: transfer-peer ONLY."""
import base64, concurrent.futures, hashlib, json, subprocess, time, urllib.request, urllib.error, uuid
PEER='ddev-zoer-connect-transfer-peer-web'
BASE='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh'
TARGET='http://zoer-connect-transfer-peer.ddev.site:8080'
PRIVATE='/var/www/.zoer-connect/'
PATH='wp-content/uploads/zoer-large-proof.bin'
def docker(*args,input=None):return subprocess.check_output(['sudo','docker','exec','-i',PEER,*args],input=input)
def php(code):return docker('php','-r',"define('SHORTINIT',true);require '/var/www/html/wp-load.php';if($wpdb->get_var(\"SELECT option_value FROM `$wpdb->options` WHERE option_name='home'\")!=='http://zoer-connect-transfer-peer.ddev.site:8080')throw new Exception('Peer only');"+code)
KEY=docker('cat',PRIVATE+'acceptance-native-key').decode().strip()
def request(route,body=None):
 req=urllib.request.Request(BASE+'/wp-json/zoer-connect/v1'+route,data=None if body is None else json.dumps(body).encode(),headers={'X-Zoer-Connection':KEY,'Content-Type':'application/json'})
 try:
  with urllib.request.urlopen(req,timeout=30) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:
  try:data=json.load(e)
  except Exception:data={}
  return e.code,data
sql=docker('cat',PRIVATE+'acceptance-source.sql')
size=40*1024*1024+3;block=b'n'*262144;full=hashlib.sha256();hashes=[]
for off in range(0,size,262144):b=block[:min(262144,size-off)];full.update(b);hashes.append(hashlib.sha256(b).hexdigest())
php("$p='/var/www/html/"+PATH+"';if(file_exists($p))throw new Exception('Fixture exists');$h=fopen($p,'wb');for($i=0;$i<192;$i++)fwrite($h,str_repeat('o',262144));fwrite($h,'old-tail');fclose($h);chmod($p,0600);")
old=php("echo hash_file('sha256','/var/www/html/"+PATH+"');").decode()
baseline=php("$out=[];foreach(['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options','users','usermeta'] as $suffix){$t=$wpdb->prefix.$suffix;$where=$suffix==='options'?\" WHERE option_name<>'cron'\":'';$out[$suffix]=hash('sha256',serialize($wpdb->get_results(\"SELECT * FROM `$t`$where ORDER BY 1\",ARRAY_A)));}echo json_encode($out);")
id=uuid.uuid4().hex;route='/imports/'+id
manifest={'id':id,'target':TARGET,'sourceUrl':'https://transfer-source.example','sourcePrefix':'wp_','migrationMode':'shared-replacement','replacementAccepted':True,'database':{'bytes':len(sql),'sha256':hashlib.sha256(sql).hexdigest(),'chunkSha256':[hashlib.sha256(sql[o:o+262144]).hexdigest() for o in range(0,len(sql),262144)]},'files':[{'path':PATH,'bytes':size,'sha256':full.hexdigest(),'chunkSha256':hashes}]}
state={};killed=False;killed_restore=False
try:
 code,state=request('/imports',manifest);assert code==200,(code,state)
 for off in range(0,len(sql),262144):
  code,state=request(route+'/chunks',{'index':0,'offset':off,'data':base64.b64encode(sql[off:off+262144]).decode()});assert code==200,(code,state)
 assert request(route+'/chunks',{'index':1,'offset':0,'data':base64.b64encode(b'x'*262144).decode()})[0]==400,'Bad block accepted'
 for off in range(0,size,262144):
  body={'index':1,'offset':off,'data':base64.b64encode(block[:min(262144,size-off)]).decode()}
  code,state=request(route+'/chunks',body);assert code==200,(code,state)
  if off==262144:assert request(route+'/chunks',body)[0]==200,'Lost-response retry failed'
 print('PASS >32 MiB HTTPS upload, invalid block rejection and duplicate chunk retry',flush=True)
 def phase():
  try:return json.loads(docker('cat',PRIVATE+'import-'+id+'/artifact-1/publication.json')).get('phase')
  except subprocess.CalledProcessError:return None
 def kill_during(action):
  with concurrent.futures.ThreadPoolExecutor() as pool:
   pending=pool.submit(request,route+'/'+action,{})
   time.sleep(.02)
   subprocess.run(['sudo','docker','exec','-u','root',PEER,'pkill','-9','-f','^php-fpm: pool'],check=True)
   try:pending.result()
   except Exception:pass
  print('Injected real PHP-FPM worker death during '+action,flush=True)
 for n in range(3000):
  code,state=request(route+'/step',{});assert code==200,(code,state)
  if state['phase']=='applying_files' and not killed and phase()=='copy_new':kill_during('step');killed=True
  if state['phase']=='verification_required':break
 assert state['phase']=='verification_required' and killed,state
 assert php("echo hash_file('sha256','/var/www/html/"+PATH+"');").decode()==full.hexdigest(),'Activated hash mismatch'
 code,state=request(route+'/finish',{});assert code==200 and state['phase']=='complete',(code,state)
 print('PASS resumed native import after PHP death, exact 40 MiB file activated',flush=True)
 for n in range(3000):
  code,state=request(route+'/rollback',{});assert code==200,(code,state)
  if state['phase']=='rollback_files' and not killed_restore and phase()=='restore_copy':kill_during('rollback');killed_restore=True
  if state['phase']=='rolled_back':break
 assert state['phase']=='rolled_back' and killed_restore,state
 assert php("echo hash_file('sha256','/var/www/html/"+PATH+"');").decode()==old,'Original hash differs'
 assert php("echo fileperms('/var/www/html/"+PATH+"')&0777;").decode()==str(0o600),'Mode changed'
 actual=php("$out=[];foreach(['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships','options','users','usermeta'] as $suffix){$t=$wpdb->prefix.$suffix;$where=$suffix==='options'?\" WHERE option_name<>'cron'\":'';$out[$suffix]=hash('sha256',serialize($wpdb->get_results(\"SELECT * FROM `$t`$where ORDER BY 1\",ARRAY_A)));}echo json_encode($out);")
 assert actual==baseline,'Business database or identities differ after rollback'
 print('PASS database content and administrator identities exactly restored alongside large files',flush=True)
 docker('test','!','-e',PRIVATE+'write-fence.json')
 print('PASS interrupted native restore resumes, exact 48 MiB original and permissions restored, peer unfenced',flush=True)
finally:
 if state.get('phase') not in ('rolled_back','cancelled'):
  for n in range(3000):
   code,state=request(route+'/rollback',{})
   if state.get('phase') in ('rolled_back','cancelled'):break
 if state.get('phase') in ('rolled_back','cancelled'):php("unlink('/var/www/html/"+PATH+"');")
 else:print('Recovery still required',id,state.get('phase'),flush=True)
