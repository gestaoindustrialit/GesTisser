"""Validate sales-order routes on a disposable application/database copy only."""
import hashlib,json,os,re,shutil,socket,sqlite3,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse
from pathlib import Path
root=Path(__file__).resolve().parents[1];base=Path(tempfile.mkdtemp(prefix='sales-order-test-'));app=base/'app';app.mkdir()
for name in subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard'],cwd=root,text=True).splitlines():
    src=root/name
    if not src.is_file() or name.startswith(('work/','storage/')) or src.suffix=='.sqlite':continue
    dst=app/name;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
os.symlink(root/'vendor',app/'vendor')
source=Path('/workspace/gestisser-env/logs/initial-test-artifacts/database.sqlite');original=hashlib.sha256(source.read_bytes()).hexdigest();db=app/'database.sqlite';shutil.copyfile(source,db)
env=dict(os.environ,APP_ENV='test',GESTISSER_DB_PATH=str(db),APP_DEBUG='0');php=os.environ.get('SALES_TEST_PHP','/workspace/gestisser-env/bin/php')
subprocess.run([php,'-r',"require '"+str(app)+"/config.php'; require '"+str(app)+"/app/Services/SalesOrderSchema.php'; SalesOrderSchema::migrate($pdo);"],env=env,check=True,stdout=subprocess.DEVNULL)
c=sqlite3.connect(db)
for uid,admin in [(90001,1),(90002,0),(90003,0)]:
    c.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(?,?,?,?,?,1,?)',(uid,'Order test',str(uid)+'@example.invalid','unused',admin,'SalesTest'))
for p in ['erp.view','erp.sales','erp.customers']:c.execute('INSERT OR REPLACE INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(90002,?,1)',(p,))
c.execute("INSERT INTO erp_customers(code,name,is_active) VALUES('SALES_TEST','<script>alert(1)</script>',1)");customer=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute("INSERT INTO erp_units(code,name) VALUES('test-un','Unidade de teste')");unit=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute("INSERT INTO erp_finished_products(code,description,customer_id,unit_id,status) VALUES('SALES_ART','Artigo de teste',?,?,'Ativo')",(customer,unit));article=c.execute('SELECT last_insert_rowid()').fetchone()[0];c.commit();c.close()
sessions={}
for uid in (90001,90002,90003):
    code="require '"+str(app)+"/bootstrap/config.php';require '"+str(app)+"/bootstrap/session.php';$_SESSION['user_id']="+str(uid)+";$_SESSION['_csrf_token']='salesordertesttoken';echo session_id();"
    sessions[uid]=subprocess.check_output([php,'-r',code],env=env,text=True).strip()
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();url='http://127.0.0.1:'+str(port)
log=open(base/'server.log','w+');server=subprocess.Popen([php,'-S','127.0.0.1:'+str(port),'-t',str(app)],env=env,stdout=log,stderr=log)
def req(path,uid=90001,data=None):
    headers={'Cookie':'gestisser_test_session='+sessions[uid]} if uid else {}
    request=urllib.request.Request(url+'/'+path,data=urllib.parse.urlencode(data).encode() if data else None,headers=headers)
    try:
        with urllib.request.urlopen(request) as response:return response.status,response.headers,response.read()
    except urllib.error.HTTPError as e:return e.code,e.headers,e.read()
def check(yes,label):
    assert yes,label
    print('PASS (HTTP): '+label,flush=True)
try:
    route='erp.php?page=sales_orders'
    for i in range(50):
        try:status,_,body=req(route);break
        except urllib.error.URLError:time.sleep(.1)
    check(status==200,'List opens in PHP 7.0')
    check(req(route,90003)[0]==403,'Sales permission required')
    check(req(route+'&new=1')[0]==200,'New order form')
    check(req(route,data={'action':'save','id':'0'})[0]==419,'CSRF required before writes')
    data={'_token':'salesordertesttoken','action':'save','id':'0','customer_id':str(customer),'order_date':'2026-01-01','expected_date':'2026-01-10','status':'Confirmada','lines[0][finished_product_id]':str(article),'lines[0][quantity]':'100','lines[0][unit_price]':'2.50','lines[0][discount_percent]':'10'}
    status,_,body=req(route+'&new=1',data=data)
    check(status==200 and b'EC-' in body,'Create via authenticated POST and redirect')
    c=sqlite3.connect(db);order=c.execute('SELECT id FROM erp_sales_orders WHERE customer_id=?',(customer,)).fetchone()[0];line=c.execute('SELECT id FROM erp_sales_order_lines WHERE sales_order_id=?',(order,)).fetchone()[0];c.close();profile=route+'&id='+str(order)
    before=hashlib.sha256(db.read_bytes()).hexdigest()
    for tab in ['overview','articles','ofs','delivery','costs','documents']:
        status,_,body=req(profile+'&tab='+tab)
        check(status==200 and b'&lt;script&gt;' in body and b'<script>alert(1)</script>' not in body,'Escaped profile / '+tab)
    check(req(profile+'&tab=costs',90002)[0]==403,'Direct financial URL denied')
    check(req(profile+'&action=pdf',90002)[0]==403,'PDF export permission required')
    status,_,body=req(profile+'&action=pdf');check(status==200 and body.startswith(b'%PDF'),'mPDF export generated in PHP 7.0');(base/'encomenda.pdf').write_bytes(body)
    check(req(route+'&id=9999999')[0]==404,'Missing order is 404')
    status,_,body=req('erp.php?page=customer_profile&id='+str(customer)+'&tab=orders');check(status==200 and b'EC-' in body,'Customer commercial list linked to order')
    check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'Profile, PDF, customer integration and denied requests leave database byte-identical')
    data['id']=str(order);data['revision']='1';data['lines[0][id]']=str(line)
    status,_,body=req(profile+'&edit=1',data=data);check(status==200,'Edit recorded commercial values')
    check(req(profile+'&edit=1',data=data)[0]==422,'Stale revision rejected over HTTP')
    status,_,body=req('erp.php?page=production&sales_order_line='+str(line))
    check(status==200 and ('name="sales_order_line_id" value="'+str(line)+'"').encode() in body and b'name="planned_quantity" value="100"' in body,'Current OF form receives commercial line and pending quantity')
    # association permissions without granting writes
    check(req(profile,data={'_token':'salesordertesttoken','action':'link_of','id':str(order)},uid=90002)[0]==403,'OF association permission required')
    check(req(profile,data={'_token':'salesordertesttoken','action':'link_delivery','id':str(order)},uid=90002)[0]==403,'Delivery confirmation permission required')
    fixture={'url':url,'cookie':sessions[90001],'order':order,'customer':customer,'article':article,'base':str(base)};(base/'fixture.json').write_text(json.dumps(fixture))
    subprocess.run(['node',str(root/'tests/sales_order_browser.js'),str(base/'fixture.json')],env=dict(env,BOOTSTRAP_ASSETS='/workspace/gestisser-env'),check=True)
    check(hashlib.sha256(source.read_bytes()).hexdigest()==original,'Original database untouched')
    print('ARTIFACTS: '+str(base),flush=True)
finally:
    server.terminate();server.wait();log.close()
