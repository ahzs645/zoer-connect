"""Host-side engine integration only. Does not claim public push/pull API coverage."""
import subprocess,json,tempfile,os
sites=['ddev-zoer-connect-security-test-web','ddev-zoer-connect-transfer-peer-web']
def run(site,mode,label='',kind=''):
 return subprocess.check_output(['sudo','docker','exec','-e','ZOER_RESOURCE_MODE='+mode,'-e','ZOER_RESOURCE_LABEL='+label,'-e','ZOER_RESOURCE_KIND='+kind,site,'wp','--allow-root','--path=/var/www/html','eval-file','/tmp/zoer-resources.php'],text=True)
seeded=[]
try:
 for i,site in enumerate(sites):
  run(site,'seed',str(i));seeded.append(site)
 for source,dest in [sites,list(reversed(sites))]:
  payload=json.loads(run(source,'export'))
  with tempfile.NamedTemporaryFile(mode='w',delete=False) as f:json.dump(payload,f);path=f.name
  try:
   subprocess.run(['sudo','docker','cp',path,dest+':/tmp/zoer-resource-data.json'],check=True)
   uid=subprocess.check_output(['sudo','docker','exec',dest,'id','-u'],text=True).strip()
   subprocess.run(['sudo','docker','exec','-u','0',dest,'chown',uid,'/tmp/zoer-resource-data.json'],check=True)
  finally:os.unlink(path)
  for kind in ['themes','plugins','media','database']:print(source+' -> '+dest+': '+run(dest,'receive',kind=kind).strip())
finally:
 for site in seeded:run(site,'cleanup')
