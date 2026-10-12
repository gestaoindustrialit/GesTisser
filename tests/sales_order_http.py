"""Validate sales-order routes on a disposable application/database copy only."""
import hashlib,json,os,re,shutil,socket,sqlite3,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse
from pathlib import Path
root=Path(__file__).resolve().parents[1];base=Path(tempfile.mkdtemp(prefix='sales-order-test-'));app=base/'app';app.mkdir()
for name in subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard'],cwd=root,text=True).splitlines():
    src=root/name
    if not src.is_file() or name.startswith(('work/','storage/')) or src.suffix=='.sqlite':continue
    dst=app/name;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
# No vendor directory: production hosting must export without Composer dependencies.
source=Path('/workspace/gestisser-env/logs/initial-test-artifacts/database.sqlite');original=hashlib.sha256(source.read_bytes()).hexdigest();db=app/'database.sqlite';shutil.copyfile(source,db)
env=dict(os.environ,APP_ENV='test',GESTISSER_DB_PATH=str(db),APP_DEBUG='0');php=os.environ.get('SALES_TEST_PHP','/workspace/gestisser-env/bin/php')
subprocess.run([php,'-r',"require '"+str(app)+"/config.php'; "],env=env,check=True,stdout=subprocess.DEVNULL)
c=sqlite3.connect(db)
for uid,admin in [(90001,1),(90002,0),(90003,0)]:
    c.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(?,?,?,?,?,1,?)',(uid,'Order test',str(uid)+'@example.invalid','unused',admin,'SalesTest'))
for p in ['erp.view','erp.sales','erp.customers']:c.execute('INSERT OR REPLACE INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(90002,?,1)',(p,))
c.execute("INSERT INTO erp_customers(code,name,is_active) VALUES('SALES_TEST','<script>alert(1)</script>',1)");customer=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute("INSERT INTO erp_customers(code,name,is_active) VALUES('SALES_OTHER','Outro cliente',1)");other_customer=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute("INSERT INTO erp_units(code,name) VALUES('test-un','Unidade de teste')");unit=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute("INSERT INTO erp_finished_products(code,description,customer_id,unit_id,status) VALUES('SALES_ART','Artigo de teste',?,?,'Ativo')",(customer,unit));article=c.execute('SELECT last_insert_rowid()').fetchone()[0]
c.execute("INSERT INTO erp_finished_products(code,description,customer_id,unit_id,status) VALUES('SALES_OTHER_ART','Artigo de teste de outro cliente',?,?,'Ativo'),('SALES_GENERIC','Artigo de teste genérico',NULL,?,'Ativo')",(other_customer,unit,unit));c.commit();c.close()
# A realistic catalogue exposes quadratic option refreshes masked by tiny fixtures.
c=sqlite3.connect(db)
c.executemany('INSERT INTO erp_finished_products(code,description,customer_id,unit_id,status) VALUES(?,?,?,?,"Ativo")', [('BULK_'+str(n),'Artigo de catálogo '+str(n),other_customer,unit) for n in range(2000)])
c.commit();c.close()
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
        try:status,_,body=req(route,90003);break
        except urllib.error.URLError:time.sleep(.1)
    check(status==403,'Unauthorized first access denied before migration')
    c=sqlite3.connect(db);check(c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'erp_sales_order%' AND type='table'").fetchone()[0]==0,'Denied access leaves sales tables absent');c.close()
    status,_,body=req(route)
    check(status==200,'First authorized access automatically installs sales schema in PHP 7.0')
    c=sqlite3.connect(db);check(c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('erp_sales_orders','erp_sales_order_lines','erp_sales_order_work_orders','erp_sales_order_deliveries')").fetchone()[0]==4,'All four commercial tables created automatically');c.close()
    before_ready=hashlib.sha256(db.read_bytes()).hexdigest();check(req(route)[0]==200 and hashlib.sha256(db.read_bytes()).hexdigest()==before_ready,'Second access skips migration without database writes')
    check(req(route,90003)[0]==403,'Sales permission required')
    check(req(route+'&new=1')[0]==200,'New order form')
    check(req(route,data={'action':'save','id':'0'})[0]==419,'CSRF required before writes')
    data={'_token':'salesordertesttoken','action':'save','id':'0','customer_id':str(customer),'order_date':'2026-01-01','expected_date':'2026-01-10','status':'Confirmada','lines[0][finished_product_id]':str(article),'lines[0][quantity]':'100','lines[0][unit_price]':'2.50','lines[0][discount_percent]':'10'}
    status,_,body=req(route+'&new=1',data=data)
    check(status==200 and b'EC-' in body,'Create via authenticated POST and redirect')
    c=sqlite3.connect(db);order=c.execute('SELECT id FROM erp_sales_orders WHERE customer_id=?',(customer,)).fetchone()[0];line=c.execute('SELECT id FROM erp_sales_order_lines WHERE sales_order_id=?',(order,)).fetchone()[0];c.close();profile=route+'&id='+str(order)
    before=hashlib.sha256(db.read_bytes()).hexdigest()
    for path in [route,route+'&new=1']:
        status,_,body=req(path);check(status==200 and b'data-order-history' in body and b'Hist'+bytes([195,179])+b'rico de encomendas' in body,'Saved order history visible on list and new form')
    check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'History rendering leaves database byte-identical')
    for tab in ['overview','articles','ofs','delivery','costs','documents']:
        status,_,body=req(profile+'&tab='+tab)
        check(status==200 and b'&lt;script&gt;' in body and b'<script>alert(1)</script>' not in body,'Escaped profile / '+tab)
    check(req(profile+'&tab=costs',90002)[0]==403,'Direct financial URL denied')
    check(req(profile+'&action=pdf',90002)[0]==403,'PDF export permission required')
    status,_,body=req(profile+'&action=pdf');check(status==200 and body.startswith(b'%PDF'),'PDF export generated in PHP 7.0 without vendor directory');(base/'encomenda.pdf').write_bytes(body)
    check(req(route+'&id=9999999')[0]==404,'Missing order is 404')
    status,_,body=req('erp.php?page=customer_profile&id='+str(customer)+'&tab=orders');check(status==200 and b'EC-' in body,'Customer commercial list linked to order')
    check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'Profile, PDF, customer integration and denied requests leave database byte-identical')
    data['id']=str(order);data['revision']='1';data['lines[0][id]']=str(line)
    status,_,body=req(profile+'&edit=1',data=data);check(status==200,'Edit recorded commercial values')
    check(req(profile+'&edit=1',data=data)[0]==422,'Stale revision rejected over HTTP')
    status,_,body=req('erp.php?page=production&sales_order_line='+str(line))
    (base/'production-form.html').write_bytes(body)
    check(status==200 and b'data-of-sales-line' in body and ('value="'+str(line)+'" data-order-id="'+str(order)+'"').encode() in body and re.search(rb'name="planned_quantity" value="100(?:\.0)?"',body),'Current OF form receives commercial line and pending quantity')
    # association permissions without granting writes
    check(req(profile,data={'_token':'salesordertesttoken','action':'link_of','id':str(order)},uid=90002)[0]==403,'OF association permission required')
    check(req(profile,data={'_token':'salesordertesttoken','action':'link_delivery','id':str(order)},uid=90002)[0]==403,'Delivery confirmation permission required')
    fixture={'url':url,'cookie':sessions[90001],'order':order,'customer':customer,'article':article,'base':str(base)};(base/'fixture.json').write_text(json.dumps(fixture))
    subprocess.run(['node',str(root/'tests/sales_order_browser.js'),str(base/'fixture.json')],env=dict(env,BOOTSTRAP_ASSETS='/workspace/gestisser-env'),check=True,timeout=180)
    # Real OF creation exercises the shared transaction, snapshots and commercial FK.
    c=sqlite3.connect(db)
    c.execute('INSERT INTO erp_customer_delivery_addresses(customer_id,label,address,postal_code,city,country,transporter) VALUES(?,"Entrega","Rua teste","1000","Lisboa","Portugal","Teste")',(customer,));address=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_article_routings(finished_product_id,name) VALUES(?,"Routing HTTP")',(article,));routing=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_article_routing_versions(routing_id,version_no,status,effective_from) VALUES(?,1,"active","2020-01-01")',(routing,));routing_version=c.execute('SELECT last_insert_rowid()').fetchone()[0];c.commit()
    payload={'_token':'salesordertesttoken','action':'create_finished_order','sales_order_id':str(order),'sales_order_line_id':str(line),'finished_product_id':str(article),'planned_quantity':'40','delivery_address_id':str(address),'routing_version_id':str(routing_version),'customer_order':'Forged reference'}
    before_of=c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0]
    for key,value in [('sales_order_id',''),('sales_order_id','999999'),('sales_order_line_id','999999'),('finished_product_id','999999'),('planned_quantity','101')]:
        bad=dict(payload);bad[key]=value;status,_,body=req('erp.php?page=production',data=bad)
        check(b'Erro:' in body and c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0]==before_of,'Invalid OF source rolls back creation: '+key)
    status,_,body=req('erp.php?page=production',data=payload)
    check(status==200 and c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0]==before_of+1,'Create real OF from saved commercial line')
    new_of=c.execute('SELECT MAX(id) FROM erp_production_orders').fetchone()[0]
    check(c.execute('SELECT sales_order_line_id FROM erp_sales_order_work_orders WHERE production_order_id=?',(new_of,)).fetchone()[0]==line,'OF automatically linked to exact saved order line')
    snapshot=json.loads(c.execute('SELECT snapshot_json FROM erp_technical_sheets WHERE production_order_id=?',(new_of,)).fetchone()[0])
    check(snapshot['_order']['customer_order']==c.execute('SELECT order_number FROM erp_sales_orders WHERE id=?',(order,)).fetchone()[0],'Snapshot uses saved order reference instead of forged POST text')
    excessive=dict(payload,planned_quantity='70');status,_,body=req('erp.php?page=production',data=excessive)
    check(b'Erro:' in body and c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0]==before_of+1,'Pending quantity rechecked after earlier OF')
    no_order=dict(payload,sales_order_id='none',sales_order_line_id='0',planned_quantity='10');status,_,body=req('erp.php?page=production',data=no_order)
    check(status==200 and c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0]==before_of+2,'Explicit Sem Encomenda creates unlinked OF')
    standalone=c.execute('SELECT MAX(id) FROM erp_production_orders').fetchone()[0]
    snapshot=json.loads(c.execute('SELECT snapshot_json FROM erp_technical_sheets WHERE production_order_id=?',(standalone,)).fetchone()[0])
    check(snapshot['_order']['customer_order']=='Sem Encomenda' and not c.execute('SELECT 1 FROM erp_sales_order_work_orders WHERE production_order_id=?',(standalone,)).fetchone(),'Standalone OF snapshot explicitly records Sem Encomenda without commercial FK')
    c.close()

    # Real stored OF and delivery evidence drives the independent history meters.
    c=sqlite3.connect(db)
    c.execute('INSERT INTO erp_products(code,description,unit_id) VALUES("HISTORY_PRODUCT","Produto histórico",?)',(unit,));product=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_production_orders(order_number,customer_id,product_id,finished_product_id,planned_quantity,produced_quantity,status) VALUES("HISTORY_OF",?,?,?,60,25,"Em produção")',(customer,product,article));of=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_stock_movements(movement_number,movement_type,item_type,item_id,quantity,created_by) VALUES("HISTORY_OUT","Saída","finished_product",?,-20,90001)',(article,));movement=c.execute('SELECT last_insert_rowid()').fetchone()[0];c.commit();c.close()
    check(req(profile,data={'_token':'salesordertesttoken','action':'link_of','id':str(order),'line_id':str(line),'of_id':str(of)})[0]==200,'Existing OF linked for real evolution evidence')
    status,_,body=req(route+'&new=1')
    check(status==200 and b'class="so-progress so-progress-production" max="100" value="25"' in body and b'class="so-progress so-progress-delivery" max="100" value="0"' in body,'History shows 25 percent production without inferring delivery')
    check(b'HISTORY_OF' in body and 'OF associada'.encode() in body,'Expandable history contains associated OF and audit event')
    check(req(profile,data={'_token':'salesordertesttoken','action':'link_delivery','id':str(order),'line_id':str(line),'movement_id':str(movement),'delivery_reference':'GUIA-HISTORY'})[0]==200,'Confirmed outgoing movement associated to order line')
    unchanged=hashlib.sha256(db.read_bytes()).hexdigest();status,_,body=req(route)
    check(status==200 and b'class="so-progress so-progress-delivery" max="100" value="20"' in body and 'Entrega associada'.encode() in body,'Delivery progress and activity reflect registered evidence')
    check(unchanged==hashlib.sha256(db.read_bytes()).hexdigest(),'Progress and expanded history perform zero database writes')
    # Confirmed order with legacy articles lacking units must not vanish.
    c=sqlite3.connect(db);c.execute('INSERT INTO erp_finished_products(code,description,customer_id,status) VALUES("UNKNOWN_UNIT_ART","Artigo importado sem unidade",?,"Ativo")',(customer,));unknown_article=c.execute('SELECT last_insert_rowid()').fetchone()[0];c.commit()
    unknown_order_payload={'_token':'salesordertesttoken','action':'save','id':'0','customer_id':str(customer),'order_date':'2026-10-12','status':'Confirmada','lines[0][finished_product_id]':str(unknown_article),'lines[0][quantity]':'12'}
    check(req(route+'&new=1',data=unknown_order_payload)[0]==200,'Confirmed order saved with explicitly unrecorded article unit')
    unknown_order,unknown_line=c.execute('SELECT sales_order_id,id FROM erp_sales_order_lines WHERE finished_product_id=?',(unknown_article,)).fetchone()
    status,_,body=req('erp.php?page=production')
    check(status==200 and ('data-order-id="'+str(unknown_order)+'" data-article-id="'+str(unknown_article)+'"').encode() in body,'Confirmed order with unrecorded unit appears in OF selector')
    c.execute('INSERT INTO erp_article_routings(finished_product_id,name) VALUES(?,"Unknown-unit routing")',(unknown_article,));unknown_routing=c.execute('SELECT last_insert_rowid()').fetchone()[0]
    c.execute('INSERT INTO erp_article_routing_versions(routing_id,version_no,status,effective_from) VALUES(?,1,"active","2020-01-01")',(unknown_routing,));unknown_version=c.execute('SELECT last_insert_rowid()').fetchone()[0];c.commit()
    unknown_of_payload=dict(payload,sales_order_id=str(unknown_order),sales_order_line_id=str(unknown_line),finished_product_id=str(unknown_article),planned_quantity='3',routing_version_id=str(unknown_version))
    previous_of_count=c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0];status,_,body=req('erp.php?page=production',data=unknown_of_payload)
    check(status==200 and c.execute('SELECT COUNT(*) FROM erp_production_orders').fetchone()[0]==previous_of_count+1,'Create actual OF from confirmed line with both units unrecorded')
    unknown_of=c.execute('SELECT MAX(id) FROM erp_production_orders').fetchone()[0]
    check(c.execute('SELECT sales_order_line_id FROM erp_sales_order_work_orders WHERE production_order_id=?',(unknown_of,)).fetchone()[0]==unknown_line,'Unknown-unit OF linked to exact commercial line')
    check(c.execute('SELECT unit_id FROM erp_sales_order_lines WHERE id=?',(unknown_line,)).fetchone()[0] is None and c.execute('SELECT unit_id FROM erp_finished_products WHERE id=?',(unknown_article,)).fetchone()[0] is None,'Unknown units remain unknown; no units invented or historical lines rewritten');c.close()
    # Explicit numbered issuance archives exact bytes; GET never consumes numbers.
    c=sqlite3.connect(db);revision=c.execute('SELECT revision FROM erp_sales_orders WHERE id=?',(order,)).fetchone()[0]
    emission={'_token':'salesordertesttoken','action':'generate_confirmation','id':str(order),'revision':str(revision)}
    for expected in ['CONF-00001','CONF-00002']:
        status,headers,pdf=req(profile,data=emission)
        check(status==200 and pdf.startswith(b'%PDF-1.4') and expected in headers.get('Content-Disposition',''),'Issue archived numbered PDF '+expected)
        doc=c.execute('SELECT id,notes FROM erp_product_documents WHERE entity_type="sales_order" AND entity_id=? AND version=?',(order,expected)).fetchone()
        metadata=json.loads(doc[1]);check(metadata['number']==expected and metadata['sha256']==hashlib.sha256(pdf).hexdigest(),'Archive snapshot and checksum match issued PDF')
        download=profile+'&action=document&document='+str(doc[0])
        status,headers,reprint=req(download);check(status==200 and reprint==pdf and headers.get('Content-Type')=='application/pdf','Download preserves archived number and exact PDF bytes')
        check(req(download,uid=90002)[0]==403,'Archived confirmation requires report export permission')
    next_number=c.execute('SELECT next_number FROM erp_number_sequences WHERE code="sales_order_confirmation"').fetchone()[0]
    check(req(profile,data=dict(emission,revision='999'))[0]==422,'Reject stale numbered issuance')
    check(req(profile,data=dict(emission,_token='invalid'))[0]==419,'Require CSRF for numbered issuance')
    check(req(profile,data=emission,uid=90002)[0]==403,'Require permission for numbered issuance')
    check(c.execute('SELECT next_number FROM erp_number_sequences WHERE code="sales_order_confirmation"').fetchone()[0]==next_number,'Denied issuance and reprints do not consume numbers');c.close()
    check(hashlib.sha256(source.read_bytes()).hexdigest()==original,'Original database untouched')
    print('ARTIFACTS: '+str(base),flush=True)
finally:
    server.terminate();server.wait();log.close()
