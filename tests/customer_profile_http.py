"""Integration regression on a disposable copy only. Usage: python3 tests/customer_profile_http.py DATABASE --php PHP [--browser]."""
import argparse, hashlib, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse, zipfile, io
from pathlib import Path
parser=argparse.ArgumentParser();parser.add_argument('database');parser.add_argument('--php',default='php');parser.add_argument('--browser',action='store_true');args=parser.parse_args()
root=Path(__file__).resolve().parents[1];source=Path(args.database).resolve();original=hashlib.sha256(source.read_bytes()).hexdigest()
base=Path(tempfile.mkdtemp(prefix='customer-profile-test-'));app=base/'app';app.mkdir()
paths=subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard'],cwd=root,text=True).splitlines()
for relative in paths:
    src=root/relative
    if not src.is_file() or relative.startswith(('work/','storage/','node_modules/')) or src.suffix in ('.sqlite','.mdb'):continue
    dest=app/relative;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dest)
if (root/'vendor').exists():os.symlink(root/'vendor',app/'vendor')
db=app/'database.sqlite';shutil.copyfile(source,db)
c=sqlite3.connect(db)
c.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(90001,?,?,?,?,1,?)',('Profile admin','profile-admin@example.invalid','unused',1,'Administrador'))
for uid in (90002,90003):
    c.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(?,?,?,?,0,1,?)',(uid,'Profile user',str(uid)+'@example.invalid','unused','ProfileTest'))
for p in ('erp.view','erp.customers'):c.execute('INSERT OR REPLACE INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(90002,?,1)',(p,))
c.execute('INSERT INTO erp_customers(code,name,tax_number,country,city,is_active) VALUES(?,?,?,?,?,1)',('PROFILE_TEST','<script>alert(1)</script>','000000000','Portugal','Porto'));customer=c.execute('SELECT last_insert_rowid()').fetchone()[0];c.commit();c.close()
env=dict(os.environ,APP_ENV='test',GESTISSER_DB_PATH=str(db),APP_DEBUG='0')
sessions={}
for uid in (90001,90002,90003):
    code="require '"+str(app)+"/bootstrap/config.php'; require '"+str(app)+"/bootstrap/session.php'; $_SESSION['user_id']="+str(uid)+"; $_SESSION['_csrf_token']='customerprofiletesttoken'; echo session_id();"
    sessions[uid]=subprocess.check_output([args.php,'-r',code],env=env,text=True).strip()
s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close();url='http://127.0.0.1:'+str(port)
log=open(base/'server.log','w+');server=subprocess.Popen([args.php,'-S','127.0.0.1:'+str(port),'-t',str(app)],env=env,stdout=log,stderr=log)
def req(path,uid=90001,data=None,follow=True):
    headers={'Cookie':'gestisser_test_session='+sessions[uid]} if uid else {}
    r=urllib.request.Request(url+'/'+path,data=data,headers=headers)
    try:
        with urllib.request.urlopen(r) as response:return response.status,response.headers,response.read()
    except urllib.error.HTTPError as e:return e.code,e.headers,e.read()
def check(condition,label):
    assert condition,label
    print('PASS (HTTP): '+label,flush=True)
profile='erp.php?page=customer_profile&id='+str(customer)
try:
    for i in range(50):
        try:status,_,body=req(profile);break
        except urllib.error.URLError:time.sleep(.1)
    check(status==200,'Customer profile opens using PHP runtime')
    before=hashlib.sha256(db.read_bytes()).hexdigest()
    for tab in ('overview','orders','articles','ofs','costs','trace','general'):
        status,_,body=req(profile+'&tab='+tab)
        check(status==200 and b'&lt;script&gt;' in body and b'<script>alert(1)</script>' not in body,'Escaped correct customer and tab '+tab)
    status,_,body=req(profile+'&tab=orders');check(b'compras a fornecedores' in body,'Sales tab explains missing commercial structure')
    status,_,body=req(profile+'&action=export');check(status==200 and body.startswith(b'PK'),'Profile Excel export');
    with zipfile.ZipFile(io.BytesIO(body)) as z:check(b'&lt;script&gt;' in z.read('xl/worksheets/sheet1.xml'),'Export contains correct customer safely')
    check(req(profile,uid=90003)[0]==403,'Missing customer permission denied')
    status,_,body=req(profile,uid=90002);check(status==200 and 'Histórico / Custeio'.encode() not in body,'Customer reader allowed without financial tab')
    check(req(profile+'&tab=costs',uid=90002)[0]==403,'Direct financial URL denied')
    check(req(profile+'&action=export',uid=90002)[0]==403,'Export permission enforced')
    for invalid in ('-1','abc','999999999','1%27%20OR%201%3D1','%5B%5D'):
        check(req('erp.php?page=customer_profile&id='+invalid)[0]==404,'Invalid or missing customer '+invalid)
    check(req(profile,data=b'action=save_customer&name=changed')[0]==419,'Save without CSRF rejected before DB writes')
    check(req(profile,data=b'action=delete_customer')[0]==405,'Unsupported profile action rejected')
    check(req(profile,data=b'action=save_customer&_token=customerprofiletesttoken&customer_id=1')[0]==400,'Posted customer ID cannot replace URL customer')
    check(req(profile,uid=90003,data=b'action=save_customer&_token=customerprofiletesttoken')[0]==403,'Save denied without customer permission')
    check(req(profile+'&tab=general&edit=1')[0]==200,'Inline edit mode is a GET and does not write')
    check(req(profile+'&tab=ofs&year[]=2025&status[]=x&article[]=1&p[]=x&sort[]=x')[0]==200,'Malformed filter arrays harmless')
    check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'All seven tabs, export and denied requests leave DB byte-identical')
    # Existing ERP startup migrations and existing write forms run only in this disposable clone.
    status,_,body=req('erp.php?page=sales');check(status==200 and b'Ver ficha' in body and b'data-sortable-table' in body,'Original customer list, sorting and profile actions')
    for suffix in ('&new_customer=1','&customer_id='+str(customer)):
        status,_,body=req('erp.php?page=sales'+suffix);check(status==200 and b'name="action" value="save_customer"' in body and b'data-delivery-addresses' in body,'Existing new/edit form and delivery controls '+suffix)
    fields={'_token':'customerprofiletesttoken','action':'save_customer','customer_id':str(customer),'code':'PROFILE_TEST','name':'Nome editado','tax_number':'000000000','country':'Portugal','city':'Porto','is_active':'1','delivery_label[]':'Armazém','delivery_address[]':'Rua de teste','delivery_country[]':'Portugal','delivery_transporter[]':'Transportes Teste'}
    status,_,body=req('erp.php?page=sales',data=urllib.parse.urlencode(fields).encode());c=sqlite3.connect(db)
    check(status==200 and c.execute('SELECT name FROM erp_customers WHERE id=?',(customer,)).fetchone()[0]=='Nome editado','Original save handler preserves customer ID')
    check(c.execute('SELECT transporter FROM erp_customer_delivery_addresses WHERE customer_id=?',(customer,)).fetchone()[0]=='Transportes Teste','Original delivery address save remains functional');c.close()
    fields.update(customer_id='0',code='PROFILE_NEW',name='Novo cliente teste');check(req('erp.php?page=sales',data=urllib.parse.urlencode(fields).encode())[0]==200,'Original create submits successfully')
    c=sqlite3.connect(db);check(c.execute('SELECT COUNT(*) FROM erp_customers WHERE code="PROFILE_NEW"').fetchone()[0]==1,'Original create inserts exactly one customer');c.close()
    status,_,body=req('erp_customer_export.php');check(status==200 and b'PROFILE_TEST' in body and b'PROFILE_NEW' in body,'Existing Excel list export includes saved and created customers')
    status,_,body=req(profile+'&tab=general');check(b'Transportes Teste' in body and b'Nome editado' in body,'Profile reads original edited customer and delivery address')
    # Profile editing shares the legacy writer and keeps delivery FKs stable.
    c=sqlite3.connect(db);c.row_factory=sqlite3.Row
    address=c.execute('SELECT * FROM erp_customer_delivery_addresses WHERE customer_id=?',(customer,)).fetchone();address_id=address['id']
    c.execute('INSERT INTO erp_production_orders(id,order_number,customer_id,product_id,status,planned_quantity,produced_quantity,delivery_address_id) VALUES(90001,"PROFILE_OF",?,1,"Encerrada",1,1,?)',(customer,address_id));c.commit()
    untouched={t:c.execute('SELECT * FROM '+t+' ORDER BY id').fetchall() for t in ('erp_finished_products','erp_raw_materials','erp_suppliers','erp_production_orders')}
    other_customers=c.execute('SELECT * FROM erp_customers WHERE id<>? ORDER BY id',(customer,)).fetchall()
    full=dict(c.execute('SELECT * FROM erp_customers WHERE id=?',(customer,)).fetchone());c.close()
    full.update(_token='customerprofiletesttoken',action='save_customer',customer_id=str(customer),name='Nome na ficha',country_prefix='PT',**{'delivery_id[]':str(address_id),'delivery_label[]':'Armazém editado','delivery_address[]':'Rua editada','delivery_country[]':'Portugal','delivery_transporter[]':'Transportes Atualizados'})
    bad=dict(full,name='Tentativa inválida',**{'delivery_transporter[]':''})
    before=hashlib.sha256(db.read_bytes()).hexdigest();status,_,body=req(profile,data=urllib.parse.urlencode(bad).encode())
    check(status==422 and b'Tentativa inv' in body,'Invalid save keeps submitted values in inline edit mode')
    check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'Invalid address rolls back the whole save')
    bad=dict(full,**{'delivery_id[]':'999999'})
    check(req(profile,data=urllib.parse.urlencode(bad).encode())[0]==422 and before==hashlib.sha256(db.read_bytes()).hexdigest(),'Forged delivery identifier rejected without writes')
    removed={k:v for k,v in full.items() if not k.startswith('delivery_')}
    check(req(profile,data=urllib.parse.urlencode(removed).encode())[0]==422 and before==hashlib.sha256(db.read_bytes()).hexdigest(),'Removing OF-linked destination rejected without detaching OF')
    status,_,body=req(profile,data=urllib.parse.urlencode(full).encode());c=sqlite3.connect(db);c.row_factory=sqlite3.Row
    saved=c.execute('SELECT * FROM erp_customers WHERE id=?',(customer,)).fetchone()
    check(status==200 and b'Cliente atualizado com sucesso' in body and saved['name']=='Nome na ficha' and saved['country_prefix']=='PT','Inline customer save returns to same profile with updated fields')
    saved_address=c.execute('SELECT * FROM erp_customer_delivery_addresses WHERE customer_id=?',(customer,)).fetchone()
    check(saved_address['id']==address_id and saved_address['transporter']=='Transportes Atualizados','Inline save updates destination in place')
    check(c.execute('SELECT delivery_address_id FROM erp_production_orders WHERE id=90001').fetchone()[0]==address_id,'Existing OF retains delivery FK after customer edit')
    check(all(untouched[t]==c.execute('SELECT * FROM '+t+' ORDER BY id').fetchall() for t in untouched) and other_customers==c.execute('SELECT * FROM erp_customers WHERE id<>? ORDER BY id',(customer,)).fetchall(),'Customer save leaves other customers, catalogues and all OFs unchanged');c.close()
    if args.browser:
        c=sqlite3.connect(db);c.row_factory=sqlite3.Row;order=c.execute('SELECT id,customer_id,finished_product_id FROM erp_production_orders WHERE customer_id IS NOT NULL AND finished_product_id IS NOT NULL AND id<>90001 LIMIT 1').fetchone();c.close()
        fixture=base/'browser.json';fixture.write_text(json.dumps({'url':url,'id':customer,'cookie':sessions[90001],'order':dict(order) if order else None}));subprocess.run(['node',str(root/'tests/customer_profile_browser.js'),str(fixture)],check=True,env=env)
    check(original==hashlib.sha256(source.read_bytes()).hexdigest(),'Supplied database remains byte-identical')
    print('Artifacts: '+str(base),flush=True)
finally:
    server.terminate();server.wait(timeout=10);log.close()
