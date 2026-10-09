"""HTTP validation against a disposable copy; never boot the legacy app on the uploaded DB."""
import argparse
import hashlib
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('database')
parser.add_argument('--php', default='php')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
source = Path(args.database).resolve()
digest = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
original = digest(source)
base = root.parent / 'work' / 'material-phase3' / 'http'
base.mkdir(parents=True, exist_ok=True)
app = base / 'app'
app.mkdir(exist_ok=True)
paths = subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard'],cwd=root,text=True).splitlines()
for relative in paths:
    src = root / relative
    if not src.is_file() or relative.startswith(('work/','storage/','node_modules/')) or src.suffix in ('.sqlite','.mdb'):
        continue
    dst = app / relative
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(src, dst)
db = app / 'database.sqlite'
shutil.copyfile(source, db)
c = sqlite3.connect(db)
admin = c.execute('SELECT id FROM users WHERE is_active=1 AND is_admin=1 LIMIT 1').fetchone()[0]
limited = c.execute('SELECT id FROM users WHERE is_active=1 AND COALESCE(is_admin,0)=0 LIMIT 1').fetchone()[0]
# Permission scenarios only in the disposable test copy, using existing users.
c.execute("UPDATE users SET access_profile='MaterialHttpValidation' WHERE id=?", (limited,))
c.execute('DELETE FROM erp_user_permissions WHERE user_id=?', (limited,))
c.execute("INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(?,'erp.view',1),(?,'erp.costs_view',0)", (limited,limited))
ids = [r[0] for r in c.execute('SELECT id FROM erp_raw_materials ORDER BY id')]
ink = c.execute('SELECT id FROM erp_raw_materials WHERE ink_type_id IS NOT NULL LIMIT 1').fetchone()[0]
c.commit()
c.close()
env = dict(os.environ, APP_ENV='gestisser-dev', GESTISSER_DB_PATH=str(db), APP_DEBUG='0')
sessions = {}
for uid in (admin, limited):
    code = "require '"+str(app)+"/bootstrap/config.php'; require '"+str(app)+"/bootstrap/session.php'; $_SESSION['user_id']="+str(uid)+"; echo session_id();"
    sessions[uid] = subprocess.check_output([args.php,'-r',code],env=env,text=True).strip()
sock = socket.socket()
sock.bind(('127.0.0.1',0))
port = sock.getsockname()[1]
sock.close()
log = open(base/'server.log','w+')
server = subprocess.Popen([args.php,'-S','127.0.0.1:'+str(port),'-t',str(app)],env=env,stdout=log,stderr=log)
def request(path,uid=admin,data=None):
    req = urllib.request.Request('http://127.0.0.1:'+str(port)+'/'+path, data=data,
        headers={'Cookie':'gestisser_gestisser-dev_session='+sessions[uid]})
    try:
        with urllib.request.urlopen(req,timeout=10) as response:
            return response.status,response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code,error.read().decode()
def check(value,label):
    assert value,label
    print('PASS: '+label,flush=True)
try:
    profile = 'erp.php?page=material_profile&id='+str(ids[0])
    for attempt in range(40):
        try:
            status,body = request(profile)
            break
        except urllib.error.URLError:
            time.sleep(.1)
    check(status==200 and 'Ficha de material' in body,'Ficha abre em PHP 7.0')
    baseline = digest(db)
    for mid in (ids[0],ink,ids[-1]):
        for tab in ('overview','technical','stock','lots','consumptions','suppliers','documents'):
            status,body = request('erp.php?page=material_profile&id='+str(mid)+'&tab='+tab)
            check(status==200 and 'Fatal error' not in body,'Material '+str(mid)+' / '+tab)
    status,body = request(profile+'&tab=suppliers',limited)
    check(status==200 and 'Sem permissão para consultar custos.' in body and 'Custos configurados' not in body,'Custos ocultos sem permissão')
    check(request(profile,data=b'action=save_raw_material')[0]==405,'POST rejeitado')
    check(request('erp.php?page=material_profile&id[]=1')[0]==404,'ID array rejeitado')
    check(request(profile+'&from=2026-02-30')[0]==400,'Data inválida rejeitada')
    check(request(profile+'&tab=stock&p=999999&q=%25')[0]==200,'Pesquisa literal e paginação limitada')
    check(request('erp.php?page=material_profile&id=2147483647')[0]==404,'Material inexistente rejeitado')
    check(digest(db)==baseline,'Todas as consultas preservam byte a byte a base de teste')
    c = sqlite3.connect(db)
    c.execute("UPDATE erp_user_permissions SET is_allowed=0 WHERE user_id=? AND permission_code='erp.view'", (limited,))
    c.execute("UPDATE erp_raw_materials SET description='<script>alert(1)</script>' WHERE id=?", (ids[0],))
    c.commit()
    c.close()
    scenario = digest(db)
    check(request(profile,limited)[0]==403,'Consulta negada sem erp.view')
    status,body = request(profile)
    check(status==200 and '&lt;script&gt;alert(1)&lt;/script&gt;' in body and '<script>alert(1)</script>' not in body,'Designação escapada contra XSS')
    check(digest(db)==scenario,'Consulta mantém intactos os cenários da cópia descartável')
    check(digest(source)==original,'Base enviada preservada byte a byte')
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
