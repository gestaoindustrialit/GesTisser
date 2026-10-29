#!/usr/bin/env python3
"""Disposable PHP 7.0 integration + desktop/Android Chromium validation."""
import http.cookiejar
import io
import base64
import zipfile
import xml.etree.ElementTree as ET
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

source=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP70_BIN','php')
version=subprocess.check_output([php,'-r','echo PHP_VERSION;'],text=True)
assert version.startswith('7.0.'),version
checks=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
    print('OK '+label,flush=True)
with tempfile.TemporaryDirectory(prefix='assist-http-') as directory:
    root=Path(directory)
    files=subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=source).decode().split('\0')
    for name in filter(None,files):
        if name.startswith(('work/','vendor/','node_modules/')): continue
        destination=root/name;destination.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source/name,destination)
    # Synthetic PNG only in the disposable installation; never replace the real mascot.
    (root/'assets/assist-saco.png').write_bytes(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR9sAAAAASUVORK5CYII='))
    if (source/'vendor').exists():(root/'vendor').symlink_to((source/'vendor').resolve(),target_is_directory=True)
    with (root/'http.log').open('w') as log:
        server=subprocess.Popen([php,'-S','127.0.0.1:8093','-t',str(root)],cwd=root,env=dict(os.environ,APP_ENV='development',GESTISSER_DB_PATH=str(root/'database.sqlite')),stdout=log,stderr=log)
        try:
            jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
            def request(path,data=None,who=None,binary=False):
                try:r=(who or client).open('http://127.0.0.1:8093/'+path,data=urllib.parse.urlencode(data).encode() if data is not None else None,timeout=30)
                except urllib.error.HTTPError as error:r=error
                body=r.read();return r.status,body if binary else body.decode(),r.geturl()
            def upload(path,fields,filename,payload):
                boundary='assist-'+secrets.token_hex(16);parts=[]
                for name,value in fields.items():parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+str(value)+'\r\n').encode())
                parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="knowledge_file"; filename="'+filename+'"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n').encode()+payload+b'\r\n')
                parts.append(('--'+boundary+'--\r\n').encode());req=urllib.request.Request('http://127.0.0.1:8093/'+path,data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
                try:r=client.open(req,timeout=30)
                except urllib.error.HTTPError as error:r=error
                return r.status,r.read().decode()
            body=None
            for attempt in range(50):
                try:status,body,url=request('install.php');break
                except urllib.error.URLError:time.sleep(.1)
            if body is None: raise RuntimeError('PHP test server did not start; local network permissions may be required.')
            password=secrets.token_urlsafe(24);csrf=re.search(r'name="csrf" value="([^"]+)"',body).group(1)
            status,body,_=request('install.php',dict(action='new',environment='development',name='Assist fixture',email='assist@example.invalid',password=password,csrf=csrf))
            check(status==200 and 'Ativação concluída' in body,'isolated installation')
            status,body,_=request('login.php');token=re.search(r'name="_token" value="([^"]+)"',body).group(1)
            status,body,_=request('login.php',dict(action='login_password',email='assist@example.invalid',password=password,_token=token))
            check(status==200 and 'Erro interno' not in body,'existing authentication')
            status,body,_=request('assist_admin.php');check(status==200 and 'Base de conhecimento' in body,'admin screen renders')
            check('assist-open' in body,'shared shell contains SACO launcher')
            token=re.search(r'name="_token" value="([^"]+)"',body).group(1)
            db=sqlite3.connect(root/'database.sqlite')
            category=db.execute("SELECT id FROM assist_categories WHERE name='Tintas'").fetchone()[0]
            machine_id=db.execute("INSERT INTO erp_machines(code,name) VALUES('M-CTX','Impressora contexto')").lastrowid;db.commit()
            status,body,_=request('assist_admin.php',dict(action='save',category_id=category,title='Verificação viscosidade',procedure='1. Consultar a ficha interna.\n2. Registar a observação.',safety='Parar em segurança. <script>window.assistPwned=1</script>',synonyms='tinta grossa',equipment='M-CTX',language='pt',_token=token))
            check(status==200 and 'Rascunho guardado' in body,'admin creates draft through CSRF form')
            article=db.execute('SELECT id FROM assist_articles').fetchone()[0]
            for action in ['submit','approve','publish']:
                status,body,_=request('assist_admin.php',dict(action=action,id=article,version=1,_token=token));check(status==200 and 'Estado atualizado' in body,'human workflow '+action)
            status,_,_=request('assist_article.php?id='+str(article)+'&version=999');check(status==404,'obsolete source version denied')
            status,body,_=request('assist_api.php',dict(action='ask',question='viscosidade',language='pt',_token=token));answer=json.loads(body)
            check(status==200 and answer['answer']['text']=='1. Consultar a ficha interna.\n2. Registar a observação.','real endpoint returns exact source')
            status,body,_=request('assist_api.php',dict(action='ask',question='Quanto solvente adicionar?',language='pt',_token=token));check(json.loads(body)['answer']['outcome']=='none','real endpoint abstains on missing value')
            status,body,_=request('assist_api.php',dict(action='ask',question='viscosidade'));check(status==419,'CSRF enforced')
            anonymous=urllib.request.build_opener();status,_,_=request('assist_api.php',who=anonymous);check(status==401,'anonymous endpoint denied')
            status,_,_=request('assist_article.php?id='+str(article),who=anonymous);check(status==401,'anonymous source denied')
            for endpoint,marker in [('erp.php','ERP'),('hr.php','RH'),('shopfloor.php','Shopfloor')]:
                status,body,_=request(endpoint);check(status==200 and 'Erro interno' not in body and marker.lower() in body.lower(),endpoint+' still renders')
            status,template,_=request('assist_template.php',binary=True)
            check(status==200 and template[:2]==b'PK','authenticated Excel template download')
            template_zip=zipfile.ZipFile(io.BytesIO(template));ns={'x':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
            sheet=ET.fromstring(template_zip.read('xl/worksheets/sheet1.xml'));data=sheet.find('x:sheetData',ns)
            columns=[c.find('x:is/x:t',ns).text for c in data[0]]
            check(set(['titulo','categoria','procedimento']).issubset(columns) and 'xl/worksheets/sheet3.xml' in template_zip.namelist(),'template contains headers, instructions and categories')
            for row in list(data)[1:]:data.remove(row)
            row=ET.SubElement(data,'{'+ns['x']+'}row',{'r':'2'})
            values={'titulo':'Conhecimento importado','categoria':'Tintas','procedimento':'Texto interno para revisão.','seguranca':'<script>window.previewPwned=1</script>'}
            for name in columns:
                cell=ET.SubElement(row,'{'+ns['x']+'}c',{'t':'inlineStr'});inline=ET.SubElement(cell,'{'+ns['x']+'}is');ET.SubElement(inline,'{'+ns['x']+'}t').text=values.get(name,'')
            filled=io.BytesIO()
            with zipfile.ZipFile(filled,'w',zipfile.ZIP_DEFLATED) as target:
                for name in template_zip.namelist():target.writestr(name,ET.tostring(sheet,encoding='utf-8',xml_declaration=True) if name=='xl/worksheets/sheet1.xml' else template_zip.read(name))
            before=db.execute('SELECT COUNT(*) FROM assist_articles').fetchone()[0]
            status,body=upload('assist_admin.php',{'action':'preview_import','_token':token},'conhecimento.xlsx',filled.getvalue())
            check(status==200 and 'Pré-visualização: 1 artigo' in body and db.execute('SELECT COUNT(*) FROM assist_articles').fetchone()[0]==before,'upload previews without creating articles')
            check('&lt;script&gt;window.previewPwned=1&lt;/script&gt;' in body and '<script>window.previewPwned' not in body,'import preview escapes document HTML')
            preview_token=re.search(r'name="import_token" value="([^"]+)"',body).group(1)
            status,body,_=request('assist_admin.php',{'action':'confirm_import','_token':token,'import_token':'wrong'})
            check(db.execute('SELECT COUNT(*) FROM assist_articles').fetchone()[0]==before,'tampered import confirmation token rejected')
            status,body,_=request('assist_admin.php',{'action':'confirm_import','_token':token,'import_token':preview_token})
            imported=db.execute('SELECT status,version,approved_by FROM assist_articles WHERE title="Conhecimento importado"').fetchone()
            check(status==200 and imported==('draft',1,None),'explicit confirmation creates an unapproved version-one draft')
            status,body,_=request('assist_admin.php',{'action':'confirm_import','_token':token,'import_token':preview_token})
            check(db.execute('SELECT COUNT(*) FROM assist_articles').fetchone()[0]==before+1,'replaying confirmation creates no duplicate')
            status,body,_=request('assist_api.php',dict(action='ask',question='Conhecimento importado',language='pt',_token=token))
            check(json.loads(body)['answer']['outcome']=='none','imported draft remains outside SACO responses')
            status,body=upload('assist_admin.php',{'action':'preview_import'},'conhecimento.xlsx',filled.getvalue());check(status==419,'CSRF enforced on spreadsheet uploads')
            status,_,_=request('assist_template.php',who=anonymous);check(status==401,'anonymous template download denied')
            # Real shared gateway + two separate employee PIN logins, not a simulated session flag.
            password_hash=db.execute("SELECT password FROM users WHERE email='assist@example.invalid'").fetchone()[0]
            gateway_id=db.execute('INSERT INTO users(name,email,password,is_active,is_admin,access_profile,pin_only_login) VALUES (?,?,?,1,0,"Utilizador",1)',('Gateway fixture','shopfloor@tisser.pt',password_hash)).lastrowid
            pins=['135790','246801'];employees=[]
            for index,pin in enumerate(pins):
                hashed=subprocess.check_output([php,'-r','echo password_hash("'+pin+'", PASSWORD_DEFAULT);'],text=True)
                employee=db.execute('INSERT INTO users(name,email,password,is_active,is_admin,access_profile,pin_only_login,pin_code_hash) VALUES (?,?,?,1,0,"Utilizador",0,?)',('PIN fixture '+str(index),'pin-'+str(index)+'@example.invalid',password_hash,hashed)).lastrowid
                employees.append(employee)
            db.commit()
            def fresh_client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            def login_pin(who,pin,direct=False):
                status,page,_=request('login.php?mode=pin' if direct else 'login.php',who=who)
                csrf=re.search(r'name="_token" value="([^"]+)"',page).group(1)
                fields=dict(action='login_pin',pin=pin,_token=csrf)
                if direct:fields['pin_entry']='1'
                else:fields['email']='shopfloor@tisser.pt'
                return request('login.php',fields,who=who)
            first=fresh_client();status,page,_=login_pin(first,'999999')
            status,_,_=request('assist_api.php',who=first);check(status==401,'invalid PIN cannot establish gateway access')
            status,page,_=login_pin(first,pins[0]);check(status==200 and 'id="assist-open"' not in page,'real PIN login stays blocked before gateway activation')
            status,page,_=request('assist_admin.php',{'action':'enable_shopfloor','_token':token})
            gate_grant=db.execute('SELECT is_allowed FROM erp_user_permissions WHERE user_id=? AND permission_code="assist.use"',(gateway_id,)).fetchone()
            check(status==200 and gate_grant==(1,) and db.execute('SELECT COUNT(*) FROM erp_user_permissions WHERE user_id IN (?,?)',employees).fetchone()[0]==0,'one administrator action configures gateway without employee grants')
            check(db.execute('SELECT value FROM assist_settings WHERE key="enabled"').fetchone()[0]=='0','shared activation leaves general feature flag disabled')
            status,page,_=request('shopfloor.php',who=first);check(status==200 and 'id="assist-open"' in page,'SACO launcher appears for actual employee PIN session')
            first_token=re.search(r'data-token="([^"]+)"',page).group(1)
            status,body,_=request('assist_api.php',dict(action='ask',question='viscosidade',language='pt',_token=first_token,**{'context[equipment]':machine_id}),who=first);first_answer=json.loads(body)
            actual_owner=db.execute('SELECT user_id FROM assist_messages WHERE id=?',(first_answer['id'],)).fetchone()[0]
            check(status==200 and first_answer['answer']['outcome']=='answer' and actual_owner==employees[0] and actual_owner!=gateway_id,'PIN machine ID retrieves code-scoped answer and attributes it to employee')
            status,body,_=request('assist_api.php',dict(action='ask',question='viscosidade',language='pt',_token=first_token,**{'context[equipment]':machine_id+999}),who=first);check(json.loads(body)['answer']['outcome']=='none','unknown machine context does not receive another machine procedure')
            status,body,_=request('assist_api.php?'+urllib.parse.urlencode({'context[equipment]':machine_id}),who=first);check(status==200 and any(s['title']=='Verificação viscosidade' for s in json.loads(body)['suggestions']),'PIN suggestions resolve the same machine ID as answers')
            status,body,_=request('assist_api.php?'+urllib.parse.urlencode({'context[equipment]':machine_id+999}),who=first);check(status==200 and not any(s['title']=='Verificação viscosidade' for s in json.loads(body)['suggestions']),'suggestions do not offer procedures excluded by machine scope')
            second=fresh_client();status,page,_=login_pin(second,pins[1],True)
            check(status==200 and 'id="assist-open"' in page,'direct keypad chooses shared gateway even without its own PIN')
            second_token=re.search(r'data-token="([^"]+)"',page).group(1)
            status,body,_=request('assist_api.php',who=second);check(status==200 and json.loads(body)['messages']==[],'second PIN employee cannot see first employee history')
            status,_,_=request('assist_api.php',dict(action='feedback',message_id=first_answer['id'],useful=1,_token=second_token),who=second);check(status==404,'shared entry does not merge feedback ownership')
            # Giving the gateway management/restricted rights must not delegate those rights.
            for capability in ['assist.edit','assist.admin','assist.restricted']:
                db.execute('INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES (?,?,1)',(gateway_id,capability))
            db.commit()
            status,_,_=request('assist_admin.php',who=second);check(status==403,'gateway administration permission is not inherited by PIN employee')
            status,_,_=request('assist_template.php',who=second);check(status==403,'gateway editing permission is not inherited by PIN employee')
            db.execute('INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES (?,"assist.use",0)',(employees[0],));db.commit()
            status,_,_=request('assist_api.php',who=first);check(status==403,'personal deny overrides shared PIN grant')
            db.execute('DELETE FROM erp_user_permissions WHERE user_id=? AND permission_code="assist.use"',(employees[0],));db.commit()
            db.execute('UPDATE erp_user_permissions SET is_allowed=0 WHERE user_id=? AND permission_code="assist.use"',(gateway_id,));db.commit()
            status,_,_=request('assist_api.php',who=second);check(status==403,'gateway grant revocation applies to active PIN session')
            db.execute('UPDATE erp_user_permissions SET is_allowed=1 WHERE user_id=? AND permission_code="assist.use"',(gateway_id,));db.execute('UPDATE users SET is_active=0 WHERE id=?',(gateway_id,));db.commit()
            status,_,_=request('assist_api.php',who=second);check(status==403,'inactive shared gateway cannot authorize PIN session')
            db.execute('UPDATE users SET is_active=1 WHERE id=?',(gateway_id,));db.commit()
            status,page,_=request('logout.php?next=pin',who=first)
            check('value="shopfloor@tisser.pt"' in page,'changing employee returns to canonical shared keypad')
            status,_,_=request('assist_api.php',who=first);check(status==401,'logout removes PIN employee and gateway authorization')
            csrf=re.search(r'name="_token" value="([^"]+)"',page).group(1)
            status,page,_=request('login.php',dict(action='login_password',email='pin-0@example.invalid',password=password,remember_login='1',_token=csrf),who=first)
            status,_,_=request('assist_api.php',who=first);check(status==403,'password login cannot inherit shared PIN authorization')
            status,page,_=request('shopfloor.php',who=first);password_token=re.search(r'name="_token" value="([^"]+)"',page).group(1)
            status,_,_=request('assist_api.php',dict(action='ask',question='viscosidade',language='pt',shopfloor_gateway_user_id=gateway_id,_token=password_token),who=first);check(status==403,'gateway ID in request cannot forge session authorization')
            status,page,_=request('logout.php?next=pin',who=first);check('value="shopfloor@tisser.pt"' in page,'personal remembered email cannot replace direct shared keypad gateway')
            status,page,_=login_pin(first,pins[1],True)
            status,body,_=request('assist_api.php',who=first);check(status==200 and json.loads(body)['messages']==[],'switching PIN employee resets history ownership')
            db.execute('UPDATE users SET is_active=0 WHERE id=?',(employees[1],));db.commit()
            status,_,_=request('assist_api.php',who=first);check(status==401,'inactive PIN employee denied independently of gateway')
            db.execute('UPDATE users SET is_active=1 WHERE id=?',(employees[1],));db.execute('UPDATE assist_settings SET value="" WHERE key="pilot_users"');db.commit()
            user_id=db.execute("SELECT id FROM users WHERE email='assist@example.invalid'").fetchone()[0]
            # Turn the fixture into a normal PIN operator; exercise exactly the same session.
            db.execute('UPDATE users SET is_admin=0,access_profile="Utilizador",pin_only_login=1 WHERE id=?',(user_id,))
            db.commit()
            status,body,_=request('shopfloor.php');check('id="assist-open"' not in body,'non-pilot operator does not receive launcher')
            db.execute('UPDATE assist_settings SET value=? WHERE key="pilot_users"',(str(user_id),));db.commit()
            status,body,_=request('shopfloor.php');check('id="assist-open"' not in body,'pilot without assist.use does not receive launcher')
            db.execute('INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES (?,"assist.use",1)',(user_id,));db.commit()
            center_id=db.execute("INSERT INTO erp_work_centers(code,name,center_type,machine_id) VALUES('ASSIST-TEST','Posto Assist','machine',?)",(machine_id,)).lastrowid;db.commit()
            status,body,_=request('shopfloor.php',dict(action='select_work_center',work_center_id=center_id,_token=token));check(status==200,'existing Shopfloor workstation selection')
            cookies=[dict(name=c.name,value=c.value,domain=c.domain,path=c.path) for c in jar]
            fixture=dict(url='http://127.0.0.1:8093/shopfloor.php',cookies=cookies,source=str(source),article=article)
            fixture_path=root/'fixture.json';fixture_path.write_text(json.dumps(fixture))
            browser=subprocess.run(['node',str(source/'tests/assist_browser.js'),str(fixture_path)],cwd=source,capture_output=True,text=True,timeout=120)
            print(browser.stdout,flush=True)
            if browser.returncode: print(browser.stderr,flush=True)
            check(browser.returncode==0,'desktop and Android tablet browser checks')
            status,body,_=request('assist_api.php');check(status==200,'PIN operator retains endpoint access')
            status,_,_=request('assist_template.php');check(status==403,'operator without editing permission cannot download import template')
            status,_,_=request('assist_admin.php');check(status==403,'operator cannot access import administration')
            # Source and history revoke instantly, including protected embedded article.
            db.execute('UPDATE assist_articles SET status="archived" WHERE id=?',(article,));db.commit()
            status,_,_=request('assist_article.php?id='+str(article)+'&embedded=1');check(status==404,'archived direct source denied')
            status,body,_=request('assist_api.php');check(all(m['answer']['outcome']!='answer' for m in json.loads(body)['messages']),'archived excerpts removed from history')
            db.execute('UPDATE users SET is_active=0 WHERE id=?',(user_id,));db.commit();status,_,_=request('assist_api.php');check(status==401,'inactive user denied')
            check(db.execute('PRAGMA integrity_check').fetchone()[0]=='ok','SQLite integrity')
            db.close()
        finally:
            server.terminate();server.wait(timeout=10)
            # Keep only diagnostics in the ignored work directory.
            work=source/'work/assist-http';work.mkdir(parents=True,exist_ok=True)
            shutil.copy2(root/'http.log',work/'server.log')
(source/'work/assist-http/results.json').write_text(json.dumps(dict(php=version,checks=checks),ensure_ascii=False,indent=2))
print('OK HTTP: '+str(len(checks))+' checks')
