"""HTTP checks against a disposable copy; never mutate the supplied SQLite."""
import argparse, hashlib, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse, io, zipfile
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('database');p.add_argument('--php',default='php');args=p.parse_args()
root=Path(__file__).resolve().parents[1];source=Path(args.database).resolve();original=hashlib.sha256(source.read_bytes()).hexdigest()
base=Path(tempfile.mkdtemp(prefix='legacy-of-http-',dir=root/'work'));app=base/'app';app.mkdir()
files=subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard'],cwd=root,text=True).splitlines()
for relative in files:
 src=root/relative
 if not src.is_file() or relative.startswith(('work/','storage/','node_modules/')) or src.suffix in ('.sqlite','.mdb'):continue
 dst=app/relative;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
if (root/'vendor').exists():(app/'vendor').symlink_to(root/'vendor')
db=app/'database.sqlite';source_conn=sqlite3.connect('file:'+str(source)+'?mode=ro',uri=True);conn=sqlite3.connect(db);source_conn.backup(conn);source_conn.close()
conn.execute('PRAGMA journal_mode=DELETE')
for uid,admin in [(90001,1),(90002,0),(90003,0)]:
 conn.execute('INSERT OR REPLACE INTO users(id,name,email,password,is_admin,is_active,access_profile) VALUES(?,?,?,?,?,1,?)',(uid,'HTTP test',str(uid)+'@example.invalid','unused',admin,'HistoryTest'))
for permission in ['erp.view','erp.customers']:
 conn.execute('INSERT OR REPLACE INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(90002,?,1)',(permission,))
conn.execute('INSERT OR REPLACE INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(90002,"erp.costs_view",0)')
conn.execute('INSERT INTO erp_customers(code,name) VALUES(?,?)',('HISTORY_HTTP_C','Synthetic client'))
conn.execute('INSERT INTO erp_finished_products(code,description) VALUES(?,?)',('HISTORY_HTTP_A','Synthetic article'))
customer=conn.execute('SELECT id,code FROM erp_customers WHERE code="HISTORY_HTTP_C"').fetchone();article=conn.execute('SELECT id,code FROM erp_finished_products WHERE code="HISTORY_HTTP_A"').fetchone();conn.commit();conn.close()
env=dict(os.environ,APP_ENV='gestisser-dev',GESTISSER_DB_PATH=str(db),APP_DEBUG='0')
sessions={}
for uid in [90001,90002,90003]:
 code="require '"+str(app)+"/bootstrap/config.php';require '"+str(app)+"/bootstrap/session.php';$_SESSION['user_id']="+str(uid)+";$_SESSION['_csrf_token']='historytesttoken';echo session_id();"
 sessions[uid]=subprocess.check_output([args.php,'-r',code],env=env,text=True).strip()
s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close();url='http://127.0.0.1:'+str(port)
log=open(base/'server.log','w+');server=subprocess.Popen([args.php,'-S','127.0.0.1:'+str(port),'-t',str(app)],env=env,stdout=log,stderr=log)
def req(path,uid=90001,data=None,headers=None):
 h={'Cookie':'gestisser_gestisser-dev_session='+sessions[uid]};h.update(headers or {})
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 r=urllib.request.Request(url+'/'+path,data=data,headers=h)
 try:
  with urllib.request.urlopen(r,timeout=10) as response:return response.status,response.read()
 except urllib.error.HTTPError as e:return e.code,e.read()
def check(c,label):
 if not c:log.flush();raise AssertionError(label+'\n'+(base/'server.log').read_text()[-2500:])
 print('PASS HTTP: '+label,flush=True)
def form(action,**extra):return dict(_token='historytesttoken',action=action,**extra)
try:
 for attempt in range(30):
  try:req('legacy_of_import.php');break
  except OSError:time.sleep(.1)
 before=hashlib.sha256(db.read_bytes()).hexdigest()
 status,body=req('legacy_of_import.php');check(status==200 and b'Importa' in body,'Admin import page renders before schema setup')
 status,templates=req('legacy_of_import.php?download=templates');check(status==200 and set(zipfile.ZipFile(io.BytesIO(templates)).namelist())=={'03_ofs.xlsx','04_of_operacoes.xlsx','LEIA-ME.txt'},'Download includes exactly the two historical templates')
 check(req('legacy_of_import.php',90002)[0]==403,'Non-admin import denied')
 check(req('legacy_of_history.php',90003)[0]==403,'Missing ERP permissions denied')
 check(req('legacy_of_import.php',data=dict(action='setup',confirmation='PREPARAR HISTORICO',_token='wrong'))[0]==419,'CSRF rejected')
 check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'Page visits and rejected writes leave database byte-identical')
 # Existing templates generate a small synthetic XLSX using actual catalog codes.
 generator=base/'generate.php';xlsx=base/'small.xlsx'
 generator.write_text("<?php require '"+str(app)+"/historical-import/Spreadsheet.php';$h=HistoricalSpreadsheet::contracts()['ofs']['columns'];$d=array_fill_keys($h,'');$d=array_replace($d,json_decode("+json.dumps(json.dumps(dict(legacy_id='HTTP-SYNTHETIC',numero_of='<script>historical</script>',cliente_codigo=customer[1],artigo_codigo=article[1],quantidade_planeada='5',quantidade_produzida='4',data_criacao='2001-01-01',estado='Livre',importar='1')))+",true));$f=HistoricalSpreadsheet::workbook([$h,array_values($d)],'OF');copy($f,'"+str(xlsx)+"');unlink($f);")
 subprocess.run([args.php,str(generator)],env=env,check=True,capture_output=True)
 boundary='LegacyBoundary123';payload=(f'--{boundary}\r\nContent-Disposition: form-data; name="_token"\r\n\r\nhistorytesttoken\r\n--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload\r\n--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="03_ofs.xlsx"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n').encode()+xlsx.read_bytes()+f'\r\n--{boundary}--\r\n'.encode()
 status,body=req('legacy_of_import.php',data=payload,headers={'Content-Type':'multipart/form-data; boundary='+boundary});check(status==200 and b'03_ofs.xlsx' in body and b'&lt;script&gt;' in body,'XLSX upload and escaped preview')
 check(req('legacy_of_import.php',data=form('validate'))[0]==200,'Dry-run before schema setup succeeds')
 status,report=req('legacy_of_import.php?download=report');check(status==200 and json.loads(report)['inserted']==1,'Complete dry-run report download')
 check(before==hashlib.sha256(db.read_bytes()).hexdigest(),'Upload/preview/dry-run perform zero database writes')
 check(req('legacy_of_import.php',data=form('setup',confirmation='PREPARAR HISTORICO'))[0]==200,'Explicit historical schema setup with backup')
 check(req('legacy_of_import.php',data=form('validate'))[0]==200,'Fresh dry-run after schema setup')
 check(req('legacy_of_import.php',data=form('import',confirmation='IMPORTAR LOTE HISTORICO'))[0]==200,'Confirmed synthetic small-lot import')
 status,body=req('legacy_of_history.php?origin=Bobinas&q=historical');check(status==200 and b'&lt;script&gt;' in body,'Unified search renders escaped historical number')
 status,body=req('legacy_of_history.php?id=1');check(status==200 and b'Custeio' in body and b'Sem dados' in body,'Historical detail renders unknown costs and trace availability')
 status,body=req('legacy_of_history.php?id=1',90002);check(status==200 and b'Custeio' not in body and b'Resolver com backup' not in body,'Financial information and resolution hidden without permissions')
 check(req('legacy_of_history.php?id=1',90002,data=form('resolve',kind='customer',target=str(customer[0]),evidence='x'))[0]==403,'Non-admin resolution denied on server')
 for profile in ['erp_customer.php?id='+str(customer[0])+'&tab=ofs','erp_article.php?id='+str(article[0])+'&tab=history']:
  status,body=req(profile);check(status==200 and b'Hist' in body and b'&lt;script&gt;' in body,'Existing profile integrates historical OFs: '+profile.split('?')[0])
 conn=sqlite3.connect(db);check(conn.execute('SELECT COUNT(*) FROM erp_legacy_production_orders').fetchone()[0]==1,'Exactly one historical OF inserted')
 source_conn=sqlite3.connect('file:'+str(source)+'?mode=ro',uri=True)
 for table in ['erp_production_orders','erp_production_order_operations','erp_stock_movements','erp_stock_balances']:
  check(conn.execute('SELECT * FROM '+table).fetchall()==source_conn.execute('SELECT * FROM '+table).fetchall(),'Operational table unchanged: '+table)
 conn.close();source_conn.close()
 check(hashlib.sha256(source.read_bytes()).hexdigest()==original,'Supplied source remains byte-identical')
 print('All historical HTTP tests passed. Disposable copy only.')
finally:
 server.terminate();server.wait(timeout=5);log.close()
 shutil.rmtree(base)
