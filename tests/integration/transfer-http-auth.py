#!/usr/bin/env python3
"""Peer-only native HTTPS authorization probes; restore the native record in finally."""
import json,subprocess,urllib.request,urllib.error,uuid
PEER='ddev-zoer-connect-transfer-peer-web';PRIVATE='/var/www/.zoer-connect/'
def docker(*args):return subprocess.check_output(['sudo','docker','exec',PEER,*args])
def php(code):return docker('php','-r',"define('SHORTINIT',true);require '/var/www/html/wp-load.php';"+code)
key=docker('cat',PRIVATE+'acceptance-native-key').decode().strip()
id=json.loads(docker('cat',PRIVATE+'acceptance-postlogin-job.json'))['id']
url='https://zoer-connect-transfer-peer.wp.k8s.ahmad.sh/wp-json/zoer-connect/v1/imports/'+id
backup=PRIVATE+'acceptance-auth-record-'+uuid.uuid4().hex
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
def get(token):
 req=urllib.request.Request(url,headers={'X-Zoer-Connection':token})
 try:
  with opener.open(req,timeout=20) as r:return r.status
 except urllib.error.HTTPError as e:return e.code
assert get(key)==200,'Baseline native authorization failed'
assert get('')==401 and get('zc_'+'0'*64)==401,'Missing/invalid native key accepted'
php("if(is_file('/var/www/.zoer-connect/write-fence.json'))throw new RuntimeException('Active import prevents auth test');$raw=$wpdb->get_var(\"SELECT option_value FROM `$wpdb->options` WHERE option_name='zoer_connect_connection'\");file_put_contents('"+backup+"',$raw);chmod('"+backup+"',0600);")
try:
 php("$r=unserialize(file_get_contents('"+backup+"'),['allowed_classes'=>false]);$r['push']=false;$wpdb->update($wpdb->options,['option_value'=>serialize($r)],['option_name'=>'zoer_connect_connection']);")
 assert get(key)==403,'Disabled Push permission accepted on retained job'
 php("$r=unserialize(file_get_contents('"+backup+"'),['allowed_classes'=>false]);$r['hash']=hash('sha256',random_bytes(32));$wpdb->update($wpdb->options,['option_value'=>serialize($r)],['option_name'=>'zoer_connect_connection']);")
 assert get(key)==401,'Revoked generation accessed retained job'
finally:
 php("$raw=file_get_contents('"+backup+"');if($wpdb->update($wpdb->options,['option_value'=>$raw],['option_name'=>'zoer_connect_connection'])===false)throw new RuntimeException('Key restoration failed');unlink('"+backup+"');")
assert get(key)==200,'Native generation was not restored'
print('PASS real HTTPS missing/invalid key401, disabled Push403, revoked generation401, restored native-key access200; no active import or other-site changes')
