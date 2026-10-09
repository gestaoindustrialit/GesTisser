"""Article regression on a disposable DB copy. Never mutates the supplied database."""
import argparse, hashlib, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('database');p.add_argument('--php',default='php');p.add_argument('--browser',action='store_true');args=p.parse_args()
root=Path(__file__).resolve().parents[1];source=Path(args.database).resolve();original=hashlib.sha256(source.read_bytes()).hexdigest()
base=Path(tempfile.mkdtemp(prefix='article-profile-test-'));app=base/'app';app.mkdir()
for relative in subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard'],cwd=root,text=True).splitlines():
    src=root/relative
    if not src.is_file() or relative.startswith(('work/','storage/','node_modules/')) or src.suffix in ('.sqlite','.mdb'):continue
    dest=app/relative;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dest)
if (root/'vendor').exists():os.symlink(root/'vendor',app/'vendor')
db=app/'database.sqlite';shutil.copyfile(source,db)
c=sqlite3.connect(db)
c.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(90001,?,?,?,?,1,?)',('Article admin','article-admin@example.invalid','unused',1,'Administrador'))
for uid in (90002,90003):c.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(?,?,?,?,0,1,?)',(uid,'Article user',str(uid)+'@example.invalid','unused','ArticleTest'))
c.execute('INSERT OR REPLACE INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(90002,"erp.view",1)')
c.execute('INSERT INTO erp_customers(code,name) VALUES(?,?)',('ARTICLE_CLIENT','Cliente Artigo'));customer=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_finished_products(code,description,customer_id,width,length,grammage,material,composition,front_colors,status) VALUES(?,?,?,?,?,?,?,?,?,?)',('ARTICLE_TEST','<script>alert(1)</script>',customer,40,60,'60+20','PP','Ráfia','INK_TEST','Ativo'));article=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_finished_products(code,description,status) VALUES(?,?,?)',('ARTICLE_EMPTY','Sem relações','Inativo'));empty=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_article_technical_sheet_versions(finished_product_id,version_no,status,snapshot_json) VALUES(?,1,"approved",?)',(article,json.dumps({'width':37,'description':'Versão histórica'})));version=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_products(code,description) VALUES("ARTICLE_PRODUCT","Produto de teste")');product=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_operations(code,name) VALUES("ARTICLE_OP","Operação atual")');operation=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_article_routings(finished_product_id,name) VALUES(?,"Routing de teste")',(article,));routing=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_article_routing_versions(routing_id,version_no,status,effective_from) VALUES(?,1,"active","2025-01-01")',(routing,));routing_version=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute('INSERT INTO erp_article_routing_steps(routing_version_id,operation_id,operation_no,sort_order,setup_time,run_value,operation_snapshot_json) VALUES(?,?,10,1,15,5,?)',(routing_version,operation,json.dumps({'name':'Operação do routing'})))
(app/'storage/uploads').mkdir(parents=True,exist_ok=True);(app/'storage/uploads/article-test.pdf').write_bytes(b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n')
c.execute('INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title,file_url,status) VALUES("finished_product",?,"production_main","Maquete teste","storage/uploads/article-test.pdf","Ativo")',(article,));document=c.execute('SELECT last_insert_rowid()').fetchone()[0]
for n in range(23):
    c.execute('INSERT INTO erp_production_orders(order_number,product_id,finished_product_id,customer_id,status,planned_quantity,produced_quantity,created_at) VALUES(?,?,?,?,"Encerrada",20,10,"2025-03-10")',('ARTICLE_OF_'+str(n),product,article,customer));of=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_production_order_operations(production_order_id,operation_id,sequence_no,planned_minutes,status,operation_name) VALUES(?,?,1,90,"Concluída","Operação histórica")',(of,operation));op=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_operation_time_entries(production_order_operation_id,user_id,started_at,ended_at,quantity_good,pause_seconds) VALUES(?,90001,"2025-03-10 08:00","2025-03-10 10:00",10,1800)',(op,))
    c.execute('INSERT INTO erp_production_order_closures(production_order_id,metrics_json,total_planned_cost,total_actual_cost,closed_by) VALUES(?,?,20,30,90001)',(of,json.dumps({'good':10,'planned_minutes':90,'actual_minutes':90})))
c.commit();c.close()
env=dict(os.environ,APP_ENV='test',GESTISSER_DB_PATH=str(db),APP_DEBUG='0');sessions={}
for uid in (90001,90002,90003):
    code="require '"+str(app)+"/bootstrap/config.php'; require '"+str(app)+"/bootstrap/session.php'; $_SESSION['user_id']="+str(uid)+"; $_SESSION['_csrf_token']='articleprofiletesttoken'; echo session_id();"
    sessions[uid]=subprocess.check_output([args.php,'-r',code],env=env,text=True).strip()
s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close();url='http://127.0.0.1:'+str(port)
log=open(base/'server.log','w+');server=subprocess.Popen([args.php,'-S','127.0.0.1:'+str(port),'-t',str(app)],env=env,stdout=log,stderr=log)
def req(path,uid=90001,data=None):
    r=urllib.request.Request(url+'/'+path,data=data,headers={'Cookie':'gestisser_test_session='+sessions[uid]} if uid else {})
    try:
        with urllib.request.urlopen(r) as response:return response.status,response.read()
    except urllib.error.HTTPError as e:return e.code,e.read()
def check(condition,label):
    assert condition,label
    print('PASS (HTTP): '+label,flush=True)
profile='erp.php?page=article_profile&id='+str(article)
try:
    for i in range(50):
        try:status,body=req(profile);break
        except urllib.error.URLError:time.sleep(.1)
    check(status==200,'Article profile opens in selected PHP runtime')
    before=hashlib.sha256(db.read_bytes()).hexdigest()
    for tab in ('overview','technical','routing','history','costs','trace','documents'):
        status,body=req(profile+'&tab='+tab)
        check(status==200 and b'&lt;script&gt;' in body and b'<script>alert(1)</script>' not in body,'Escaped article and tab '+tab)
        check(req('erp.php?page=article_profile&id='+str(empty)+'&tab='+tab)[0]==200,'Empty article and tab '+tab)
    status,body=req(profile+'&tab=technical');check(b'60+20' in body and b'PP' in body and b'INK_TEST' in body,'Existing technical fields preserved in consultation')
    status,body=req(profile+'&tab=technical&technical_version='+str(version));check('Snapshot histórico'.encode() in body and b'37' in body,'Historical technical version available')
    status,body=req(profile+'&action=export');check(status==200 and b'window.print()' in body and b'&lt;script&gt;' in body,'Current technical export reuses existing printable sheet')
    for tab in ('overview','technical','history','trace','documents'):check(req(profile+'&tab='+tab,90002)[0]==200,'ERP reader can open '+tab)
    for path in ('&tab=costs','&tab=routing','&action=export'):check(req(profile+path,90002)[0]==403,'Sensitive route enforces permission '+path)
    check(req(profile,90003)[0]==403,'Reader without ERP permission rejected')
    check(req(profile,data=b'action=save_article')[0]==405,'Profile rejects POST without invoking legacy writes')
    check(req('erp.php?page=article_profile&id=999999')[0]==404 and req('erp.php?page=article_profile&id[]=1')[0]==404,'Invalid article IDs return 404')
    check(req(profile+'&tab=history&p=2')[0]==200,'Populated production history second page opens')
    status,body=req(profile+'&tab=costs');check(b'30,00' in body and 'Operação histórica'.encode() in body,'Recorded closure costs and historical operations rendered')
    check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'All profile tabs, export and denied actions leave DB byte-for-byte unchanged')
    status,body=req('erp.php?page=customer_profile&id='+str(customer)+'&tab=articles');check(status==200 and ('page=article_profile&amp;id='+str(article)).encode() in body,'Customer articles link directly to profile')
    # Exercise the existing article editor/create/duplicate, never the profile reader.
    status,body=req('erp.php?page=articles');check(status==200 and ('page=article_profile&amp;id='+str(article)).encode() in body,'Article list links code and description to profile')
    status,body=req('erp.php?page=articles&article_id='+str(article));check(status==200 and b'name="action" value="save_article"' in body,'Existing editor remains available')
    status,body=req('erp.php?page=article_document&id='+str(document));check(status==200 and body.startswith(b'%PDF'),'Existing document endpoint opens original associated PDF')
    c=sqlite3.connect(db)
    preserved_tables=('erp_production_orders','erp_production_order_operations','erp_article_routings','erp_article_routing_versions','erp_article_routing_steps','erp_routing_templates')
    preserved={t:c.execute('SELECT * FROM '+t+' ORDER BY id').fetchall() for t in preserved_tables};c.close()
    payload={'_token':'articleprofiletesttoken','action':'save_article','article_id':str(article),'code':'ARTICLE_TEST','description':'Artigo atualizado','customer_id':str(customer),'width':'40','length':'60','grammage':'60+20','colors_per_face':'0','status':'Ativo','min_stock':'0','sale_price':'0','standard_cost':'0','proof_status':'Pendente'}
    status,body=req('erp.php?page=articles',data=urllib.parse.urlencode(payload).encode());c=sqlite3.connect(db)
    check(status==200 and c.execute('SELECT description FROM erp_finished_products WHERE id=?',(article,)).fetchone()[0]=='Artigo atualizado','Existing editor still saves original article ID')
    payload.update(article_id='0',code='ARTICLE_CREATED',description='Novo artigo')
    status,body=req('erp.php?page=articles',data=urllib.parse.urlencode(payload).encode());check(status==200 and c.execute('SELECT COUNT(*) FROM erp_finished_products WHERE code="ARTICLE_CREATED"').fetchone()[0]==1,'Existing creation still works')
    status,body=req('erp.php?page=articles',data=urllib.parse.urlencode({'_token':'articleprofiletesttoken','action':'duplicate_article','article_id':str(article)}).encode());check(status==200 and c.execute('SELECT COUNT(*) FROM erp_finished_products WHERE code LIKE "ARTICLE_TEST-COPIA%"').fetchone()[0]==1,'Existing duplication still works');c.close()
    status,body=req('erp_routing.php?article_id='+str(article));check(status==200,'Existing routing module still opens')
    c=sqlite3.connect(db);check(all(c.execute('SELECT * FROM '+t+' ORDER BY id').fetchall()==preserved[t] for t in preserved_tables),'Existing OF, operations, routing versions and templates unchanged through editor/create/duplicate/routing regression');c.close()
    if args.browser:
        fixture=base/'browser.json';fixture.write_text(json.dumps({'url':url,'cookie':sessions[90001],'article':article,'customer':customer,'empty':empty}))
        subprocess.run(['node',str(root/'tests/article_profile_browser.js'),str(fixture)],env=env,check=True)
    check(original==hashlib.sha256(source.read_bytes()).hexdigest(),'Supplied source DB remains unchanged')
    print('Fixture and logs: '+str(base))
finally:
    server.terminate();server.wait(timeout=10);log.close()
