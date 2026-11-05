#!/usr/bin/env python3
"""Real PHP 7.0 HTTP, SMTP and Chromium checks in a disposable installation.
Requires PHP 7.0, Node/Playwright, Chromium and pdftotext. Never sends external mail.
"""
import email.policy
from email.parser import BytesParser
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import socketserver
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP70_BIN','php')
assert subprocess.check_output([PHP,'-r','echo PHP_VERSION;'],text=True).startswith('7.0.')

def check(ok,label):
    assert ok,label
    print('PASS (HTTP/SMTP): '+label,flush=True)

messages=[]
class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        def reply(value):self.wfile.write(value+b'\r\n');self.wfile.flush()
        reply(b'220 fixture SMTP')
        auth=0
        while True:
            line=self.rfile.readline()
            if not line:return
            command=line.strip().upper()
            if auth==1:reply(b'334 UGFzc3dvcmQ6');auth=2
            elif auth==2:reply(b'235 Authenticated');auth=0
            elif command.startswith(b'EHLO'):reply(b'250 fixture')
            elif command==b'AUTH LOGIN':reply(b'334 VXNlcm5hbWU6');auth=1
            elif command.startswith((b'MAIL FROM:',b'RCPT TO:')):reply(b'250 OK')
            elif command==b'DATA':
                reply(b'354 Send data');parts=[]
                while True:
                    part=self.rfile.readline()
                    if part in (b'.\r\n',b''):break
                    parts.append(part[1:] if part.startswith(b'..') else part)
                messages.append(b''.join(parts));reply(b'250 Accepted')
            elif command==b'QUIT':reply(b'221 Bye');return
            else:reply(b'500 Unknown command')

with tempfile.TemporaryDirectory(prefix='purchase-order-http-') as directory:
    root=Path(directory)/'app';root.mkdir()
    for name in set(subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=ROOT).decode().split('\0')):
        src=ROOT/name
        if not name or not src.is_file() or name.startswith(('vendor/','work/','storage/')) or src.suffix=='.sqlite':continue
        dest=root/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(src,dest)
    (root/'vendor').symlink_to((ROOT/'vendor').resolve(),target_is_directory=True)
    smtp=socketserver.ThreadingTCPServer(('127.0.0.1',0),SMTP);threading.Thread(target=smtp.serve_forever,daemon=True).start()
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    url='http://127.0.0.1:'+str(port)+'/'
    env=dict(os.environ,APP_ENV='development',GESTISSER_DB_PATH=str(root/'database.sqlite'),GESTISSER_SMTP_HOST='127.0.0.1',GESTISSER_SMTP_PORT=str(smtp.server_address[1]),GESTISSER_SMTP_SECURE='none',GESTISSER_SMTP_USER='fixture@example.invalid',GESTISSER_SMTP_PASS='fixture-only')
    (ROOT/'work').mkdir(exist_ok=True)
    log=(ROOT/'work/purchase-order-http.log').open('w+')
    server=subprocess.Popen([PHP,'-d','sendmail_path=/bin/false','-S','127.0.0.1:'+str(port),'-t',str(root)],cwd=root,env=env,stdout=log,stderr=log)
    jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    def req(path,data=None,c=client):
        try:response=c.open(url+path,data=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None,timeout=40)
        except urllib.error.HTTPError as e:response=e
        return response.status,response.read(),response.geturl()
    def token(body):return re.search(rb'name="_token" value="([^"]+)"',body).group(1).decode()
    def db():return sqlite3.connect(root/'database.sqlite')
    try:
        for attempt in range(40):
            try:status,body,_=req('install.php');break
            except urllib.error.URLError:time.sleep(.15)
        password=secrets.token_urlsafe(24)
        csrf=re.search(rb'name="csrf" value="([^"]+)"',body).group(1).decode()
        status,body,_=req('install.php',dict(action='new',environment='development',name='Compras fixture',email='admin@example.invalid',password=password,csrf=csrf))
        check(status==200 and 'Ativação concluída'.encode() in body,'Instalação real e migrações em PHP 7.0')
        _,body,_=req('login.php');status,body,target=req('login.php',dict(action='login_password',email='admin@example.invalid',password=password,_token=token(body)))
        check(status==200 and not target.endswith('login.php'),'Login com CSRF')
        status,body,_=req('erp.php?page=orders&new_purchase_order=1');csrf=token(body)
        check(status==200 and b'data-order-lines' in body and b'Erro interno' not in body,'Formulário de encomenda renderiza')
        config=root/'storage/installation.json';settings=json.loads(config.read_text());settings['external_services_enabled']=True;config.write_text(json.dumps(settings))
        with db() as c:
            c.execute("INSERT OR IGNORE INTO erp_units(code,name) VALUES('KG','Quilograma teste')")
            unit=c.execute("SELECT id FROM erp_units WHERE code='KG'").fetchone()[0]
            supplier=c.execute('INSERT INTO erp_suppliers(code,name,email,is_active) VALUES(?,?,?,1)',('TEST-BUY','Fornecedor de teste','compras@example.invalid')).lastrowid
            materials=[]
            for code,desc,price in [('TEST-AM','Tinta amarela',6.65),('TEST-CT','Tinta castanha',6.49)]:
                materials.append(c.execute('INSERT INTO erp_raw_materials(code,description,primary_unit_id,last_price) VALUES(?,?,?,?)',(code,desc,unit,price)).lastrowid)
            warehouse=c.execute("SELECT id FROM erp_warehouses LIMIT 1").fetchone()[0]
            # Explicit fixture settings override any inherited machine SMTP configuration.
            for key,value in [('smtp_host','127.0.0.1'),('smtp_port',str(smtp.server_address[1])),('smtp_secure','none'),('smtp_username','fixture@example.invalid'),('smtp_password','fixture-only')]:
                c.execute('INSERT OR REPLACE INTO app_settings(setting_key,setting_value) VALUES(?,?)',(key,value))
        with db() as c:
            c.execute('INSERT OR IGNORE INTO erp_ink_types(code,name,icon,is_active) VALUES("UV","Tinta de UV","bi-circle-fill",1)')
            water_type=c.execute('SELECT id FROM erp_ink_types WHERE code="AGUA"').fetchone()[0]
            material_type=c.execute('INSERT INTO erp_material_types(code,name) VALUES("TINTA","Tinta existente")').lastrowid
            feature=c.execute('INSERT INTO erp_material_features(code,description,material_type_id) VALUES("DOURADO-871","COR: DOURADO | PANTONE: 871",?)',(material_type,)).lastrowid
            existing_gold=c.execute('INSERT INTO erp_raw_materials(code,description,material_type_id,material_feature_id,ink_type_id,is_water_based_ink,primary_unit_id,last_price) VALUES("504355","TINTA DOURADO PANTONE 871",?,?,?,1,?,4.12)',(material_type,feature,water_type,unit)).lastrowid
            c.execute('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,lot,physical_qty) VALUES("raw_material",?,? ,"LATA-EXISTENTE",19.69)',(existing_gold,warehouse))
            c.execute('DELETE FROM erp_ink_material_schema')
            catalogue_before=c.execute('SELECT id,code,name,icon,is_active FROM erp_ink_types ORDER BY id').fetchall()
        # Reproduce production's old required column names and a previous migration marker.
        with db() as c:
            c.execute('PRAGMA foreign_keys=OFF')
            c.execute('DROP TABLE erp_purchase_order_lines')
            c.execute('CREATE TABLE erp_purchase_order_lines(id INTEGER PRIMARY KEY AUTOINCREMENT,purchase_order_id INTEGER NOT NULL,line_no INTEGER NOT NULL,item_type TEXT NOT NULL,item_id INTEGER NOT NULL,ordered_quantity REAL NOT NULL,unit_cost REAL NOT NULL DEFAULT 0)')
            legacy_order=c.execute('INSERT INTO erp_purchase_orders(order_number,supplier_id,order_date,status) VALUES(?,?,?,?)',('LEGACY-KEEP',supplier,'2026-11-01','Parcial')).lastrowid
            c.execute('INSERT INTO erp_purchase_order_lines(id,purchase_order_id,line_no,item_type,item_id,ordered_quantity,unit_cost) VALUES(770,?,1,"raw_material",?,5,2)',(legacy_order,materials[0]))
            c.execute('INSERT INTO erp_stock_movements(movement_number,movement_type,item_type,item_id,quantity,source_type,source_id,purchase_order_line_id) VALUES("LEGACY-RECEIPT","Entrada","raw_material",?,2,"purchase_order",?,770)',(materials[0],legacy_order))
            c.execute('DELETE FROM gestisser_erp_schema_migrations')
            c.execute('INSERT INTO gestisser_erp_schema_migrations(version) VALUES("2026110501")')
        status,body,_=req('erp.php?page=orders&new_purchase_order=1')
        check(status==200 and b'Erro interno' not in body,'Atualização automática de tabela legacy com campos obrigatórios')
        with db() as c:
            check(c.execute('SELECT line_number,quantity,unit_price FROM erp_purchase_order_lines WHERE id=770').fetchone()==(1,5,2),'Migração conserva os valores e IDs das linhas antigas')
            check(c.execute('SELECT purchase_order_line_id FROM erp_stock_movements WHERE movement_number="LEGACY-RECEIPT"').fetchone()[0]==770,'Migração conserva ligações às receções existentes')
        fixture={'existing_gold':existing_gold,'url':url,'supplier':supplier,'materials':materials,'warehouse':warehouse,'cookies':[dict(name=c.name,value=c.value,domain='127.0.0.1',path=c.path) for c in jar],'result':str(root/'browser-result.json')}
        fixture_path=root/'browser.json';fixture_path.write_text(json.dumps(fixture))
        subprocess.run(['node',str(ROOT/'tests/purchase_order_browser.js'),str(fixture_path)],cwd=ROOT,env=env,check=True)
        result=json.loads((root/'browser-result.json').read_text());order=result['order']
        with db() as c:
            check(c.execute('SELECT ink_color,ink_pantone,ink_type_id,legacy_ink_id FROM erp_raw_materials WHERE id=?',(existing_gold,)).fetchone()==('DOURADO','871',water_type,None),'Material 504355 reutilizado com característica e tipo originais')
            check(c.execute('SELECT physical_qty FROM erp_stock_balances WHERE item_id=? AND lot="LATA-EXISTENTE"',(existing_gold,)).fetchone()[0]==19.69,'Stock do material existente conservado')
            check(catalogue_before==c.execute('SELECT id,code,name,icon,is_active FROM erp_ink_types ORDER BY id').fetchall(),'Tipos e ícones existentes sem duplicações ou alterações')
            check(c.execute('SELECT t.code,r.is_water_based_ink,r.is_solvent_based_ink FROM erp_raw_materials r JOIN erp_ink_types t ON t.id=r.ink_type_id WHERE r.code="5SF08AZ1183"').fetchone()==('UV',0,0),'Edição usa o tipo UV configurado e deriva os indicadores corretamente')
            check(c.execute('SELECT ink_color,ink_pantone,is_water_based_ink,last_price,legacy_ink_id FROM erp_raw_materials WHERE code="MODAL-NEW"').fetchone()==('AMARELO','000',1,4.12,'00215'),'Modal persiste cor, Pantone, água, preço e ID antigo')
            check(c.execute('SELECT COUNT(*) FROM erp_raw_materials WHERE code="5SF08AZ1183"').fetchone()[0]==1,'Reimportação usa o código e não duplica a tinta')
            check(c.execute('SELECT COUNT(*) FROM erp_audit_log WHERE action="import_ink"').fetchone()[0]==2,'Cada importação da tinta mantém auditoria própria')
            trace=c.execute('SELECT new_values_json FROM erp_audit_log WHERE action="import_ink" ORDER BY id DESC LIMIT 1').fetchone()[0]
            check(json.loads(trace)['_import_original_row']['id_antigo']=='00009' and json.loads(trace)['_import_filename']=='tintas-antigas.csv','Auditoria conserva ID, linha original e nome do ficheiro de importação')
        check(len(messages)==1,'Um único email capturado pelo servidor SMTP local')
        message=BytesParser(policy=email.policy.default).parsebytes(messages[0]);attachments=list(message.iter_attachments())
        check(len(attachments)==1 and attachments[0].get_content_type()=='application/pdf','PDF anexo no email MIME real')
        check('confirmação da encomenda' in message.get_body(preferencelist=('plain',)).get_content(),'Texto e acentuação conservados no email')
        with db() as c:
            archive=c.execute('SELECT id,pdf_content,pdf_sha256,dispatch_key FROM erp_purchase_order_emails WHERE purchase_order_id=?',(order,)).fetchone()
            lines=c.execute('SELECT id,quantity FROM erp_purchase_order_lines WHERE purchase_order_id=? ORDER BY line_number',(order,)).fetchall()
            check(c.execute('SELECT status FROM erp_purchase_orders WHERE id=?',(order,)).fetchone()[0]=='Parcial','Receção parcial atualiza o estado')
        archived=archive[1].encode('latin1') if isinstance(archive[1],str) else archive[1]
        check(hashlib.sha256(archived).hexdigest()==archive[2] and attachments[0].get_payload(decode=True)==archived,'PDF enviado corresponde exatamente ao arquivo')
        status,body,_=req('erp_purchase_document.php?archive_id='+str(archive[0]));check(status==200 and body==archived,'PDF arquivado acessível pelo endpoint autenticado')
        pdf=root/'purchase.pdf';pdf.write_bytes(body);text=subprocess.check_output(['pdftotext',str(pdf),'-'],text=True)
        check(all(value in text for value in ['TEST-AM','TEST-CT','Tinta amarela','Fornecedor de teste','EUR']),'PDF extraído contém fornecedor, duas linhas e moeda')
        _,body,_=req('erp.php?page=orders&purchase_order_id='+str(order));csrf=token(body)
        dispatch=dict(purchase_order_id=order,recipient='compras@example.invalid',subject='Teste',body='Texto',dispatch_key=archive[3],_token=csrf)
        req('erp_purchase_document.php',dispatch);check(len(messages)==1,'Repetir POST não duplica o email')
        dispatch['dispatch_key']=secrets.token_hex(16);dispatch['_token']='invalid';status,_,_=req('erp_purchase_document.php',dispatch)
        check(status==419 and len(messages)==1,'CSRF inválido bloqueia o envio')
        dispatch['_token']=csrf;dispatch['recipient']='invalid';status,_,_=req('erp_purchase_document.php',dispatch)
        check(status==400 and len(messages)==1,'Destinatário inválido rejeitado')
        anonymous=urllib.request.build_opener();_,_,target=req('erp_purchase_document.php?archive_id='+str(archive[0]),c=anonymous)
        check(target.endswith('login.php'),'Arquivo protegido contra acesso anónimo')
        receipt={'action':'stock_adjust','movement_direction':'entry','purchase_order_id':order,'warehouse_id':warehouse,'receipt_line_id[]':[x[0] for x in lines],'receipt_quantity[]':[31,0],'receipt_labels[]':[0,0],'_token':csrf}
        status,body,_=req('erp.php?page=warehouse',receipt)
        check(b'excede' in body,'Receber acima da quantidade em falta é rejeitado')
        with db() as c:check(c.execute('SELECT SUM(quantity) FROM erp_stock_movements WHERE source_type="purchase_order" AND source_id=?',(order,)).fetchone()[0]==10,'Receção rejeitada não altera stock')
        receipt['receipt_quantity[]']=[5,21];_,body,_=req('erp.php?page=warehouse',receipt);check(b'excede' in body,'Segunda linha inválida rejeitada')
        with db() as c:check(c.execute('SELECT SUM(quantity) FROM erp_stock_movements WHERE source_type="purchase_order" AND source_id=?',(order,)).fetchone()[0]==10,'Erro na segunda linha reverte também a primeira linha e o stock')
        receipt['receipt_quantity[]']=[30,20];status,body,_=req('erp.php?page=warehouse',receipt)
        check(status==200 and 'Erro:'.encode() not in body,'Receção do restante da encomenda')
        _,body,_=req('erp.php?page=orders&purchase_order_id='+str(order));check(b'100% entregue' in body,'Encomenda concluída apresenta 100% entregue')
        with db() as c:
            check(c.execute('SELECT status FROM erp_purchase_orders WHERE id=?',(order,)).fetchone()[0]=='Recebida','Estado final Recebida')
            check(c.execute('PRAGMA integrity_check').fetchone()[0]=='ok','Integridade SQLite')
        # Validate rollback for an invalid line on a new order through the actual action.
        data={'action':'save_purchase_order','supplier_id':supplier,'order_date':'2026-11-05','status':'Aberta','line_item_type[]':['raw_material','raw_material'],'line_item_id[]':[materials[0],99999999],'line_quantity[]':[2,1],'line_unit_cost[]':[1,1],'_token':csrf}
        with db() as c:
            before=c.execute('SELECT COUNT(*) FROM erp_purchase_orders').fetchone()[0]
            before_number=c.execute("SELECT next_number FROM erp_number_sequences WHERE code='purchase_order'").fetchone()[0]
        _,body,_=req('erp.php?page=orders&new_purchase_order=1',data);check('não existe'.encode() in body,'Artigo inexistente rejeitado')
        with db() as c:
            check(c.execute('SELECT COUNT(*) FROM erp_purchase_orders').fetchone()[0]==before,'Guardar inválido reverte cabeçalho e linhas')
            check(c.execute("SELECT next_number FROM erp_number_sequences WHERE code='purchase_order'").fetchone()[0]==before_number,'Guardar inválido não consome numeração')
        data['line_item_id[]']=[materials[0],materials[1]];data['order_date']='2026-02-31';_,body,_=req('erp.php?page=orders&new_purchase_order=1',data);check('datas válidas'.encode() in body,'Data inexistente rejeitada')
        data['order_date']='2026-11-05';data['line_unit_cost[]']=[-1,2];_,body,_=req('erp.php?page=orders&new_purchase_order=1',data);check('preços válidos'.encode() in body,'Preço negativo rejeitado');check(b'value=\"'+str(supplier).encode()+b'\" selected' in body and b'\"unit_price\":\"-1\"' in body,'Erro conserva fornecedor e linhas do rascunho')
        # An authenticated user without purchasing permission must not read or send.
        with db() as c:
            denied_id=c.execute('INSERT INTO users(name,email,password,is_admin,is_active,access_profile) VALUES(?,?,?,0,1,?)',('Sem compras','denied@example.invalid','unused','Produção')).lastrowid
        code="require 'bootstrap/config.php';require 'bootstrap/session.php';$_SESSION['user_id']="+str(denied_id)+";echo session_name().'='.session_id();"
        cookie=subprocess.check_output([PHP,'-r',code],cwd=root,env=env,text=True).strip()
        denied=urllib.request.build_opener();denied.addheaders=[('Cookie',cookie)]
        check(req('erp_purchase_document.php?archive_id='+str(archive[0]),c=denied)[0]==403,'Utilizador autenticado sem compras não acede ao arquivo')
        check(req('erp_purchase_document.php',dispatch,c=denied)[0]==403,'Utilizador sem compras não pode enviar')
        # Quick creation endpoint: explicit permissions, CSRF and both article classes.
        quick={'_token':csrf,'item_type':'finished_product','description':'Acabado criado na compra','unit_id':unit,'price':'2.5'}
        status,created,_=req('erp_purchase_item.php',quick);created=json.loads(created)
        check(status==201 and created['item']['code'].startswith('PA-'),'Criação de artigo acabado com código automático')
        quick['_token']='invalid';check(req('erp_purchase_item.php',quick)[0]==419,'Criação de artigo exige CSRF')
        check(req('erp_purchase_item.php',quick,c=denied)[0]==403,'Criação de artigo exige permissões de compras e dados mestre')
        with db() as c:
            check(c.execute('SELECT COUNT(*) FROM erp_raw_materials WHERE code="MODAL-NEW"').fetchone()[0]==1,'Código duplicado na modal não cria outro artigo')
        # Transport refusal must retain the message and PDF in the archive.
        with db() as c:c.execute('UPDATE app_settings SET setting_value="1" WHERE setting_key="smtp_port"')
        dispatch.update(recipient='compras@example.invalid',_token=csrf,dispatch_key=secrets.token_hex(16));req('erp_purchase_document.php',dispatch)
        with db() as c:check(c.execute('SELECT status FROM erp_purchase_order_emails ORDER BY id DESC LIMIT 1').fetchone()[0]=='Falhou','Falha SMTP arquivada sem indicar sucesso')
        log.flush();log.seek(0);server_log=log.read()
        check('PHP Fatal error' not in server_log and 'PHP Warning' not in server_log,'Sem erros ou avisos PHP nos fluxos exercitados')
    finally:
        server.terminate();server.wait(timeout=10);smtp.shutdown();smtp.server_close();log.close()
