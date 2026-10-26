#!/usr/bin/env python3
"""Exercise real installation and authenticated requests on a disposable copy."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import shutil
import sys
SOURCE = Path(__file__).resolve().parents[1]
PHP = shutil.which(os.environ.get('PHP70_BIN', 'php'))
if not PHP or subprocess.check_output([PHP, '-r', 'echo PHP_VERSION;'],text=True).strip() != '7.0.33':
    raise SystemExit('PHP 7.0.33 required; activate the project runtime first.')
def copy_source(target):
    target=Path(target)
    files=subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=SOURCE).decode().split('\0')
    for name in sorted(set(filter(None,files))):
        if name.startswith(('work/','vendor/')): continue
        dest=target/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(SOURCE/name,dest)
    (target/'vendor').symlink_to(SOURCE/'vendor',target_is_directory=True)
    return target

logs = SOURCE/'work/printing-http';logs.mkdir(parents=True,exist_ok=True)
results = []
failures = []

def check(condition, message, required=True):
    if not condition:
        failures.append(message)
        print('FAIL: ' + message, flush=True)
        if required:
            raise RuntimeError(message)
        return
    results.append(message)
    print('PASS: ' + message, flush=True)

with tempfile.TemporaryDirectory(prefix='gestisser-http-smoke-') as directory:
    root = copy_source(directory)
    (root/'printing-router.php').write_text("<?php if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/agent-tls.php'){$_SERVER['HTTPS']='on';require __DIR__.'/print_agent_api.php';return true;}return false;")
    with (logs / 'http-smoke-server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', '127.0.0.1:8017', '-t', str(root),str(root/'printing-router.php')],
                                  cwd=root, env=dict(os.environ, APP_ENV='development'),
                                  stdout=log, stderr=log)
        try:
            cookies = http.cookiejar.CookieJar()
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
            def request(path, data=None):
                encoded = urllib.parse.urlencode(data).encode() if data is not None else None
                try:
                    response = client.open('http://127.0.0.1:8017/' + path, data=encoded, timeout=20)
                except urllib.error.HTTPError as error:
                    response = error
                return response.status, response.read().decode(), response.geturl()

            for attempt in range(30):
                try:
                    status, body, url = request('install.php')
                    break
                except urllib.error.URLError:
                    if attempt == 29:
                        raise
                    time.sleep(0.2)
            check(status == 200 and 'Instalação nova, sem base de dados' in body,
                  'Fresh installer reports a new database (HTTP 200).')
            csrf = re.search(r'name="csrf" value="([^"]+)"', body).group(1)
            password = secrets.token_urlsafe(24)
            status, body, url = request('install.php', dict(action='new', environment='development',
                name='Onboarding smoke fixture', email='smoke@example.invalid', password=password, csrf=csrf))
            (logs / 'http-smoke-install.html').write_text(body)
            check(status == 200 and 'Ativação concluída' in body, 'Development installation creates the administrator and schema.')
            configuration = json.loads((root / 'storage/installation.json').read_text())
            check(configuration['environment'] == 'development' and not configuration['external_services_enabled']
                  and not configuration['cron_enabled'], 'Development mode disables external services and cron.')
            status, body, url = request('install.php')
            check(status == 403 and 'Instalador bloqueado' in body, 'Completed installer is locked (HTTP 403).')
            status, body, url = request('erp.php')
            check(status == 200 and url.endswith('login.php'), 'Anonymous ERP access redirects to login.')
            status, body, url = request('login.php')
            token = re.search(r'name="_token" value="([^"]+)"', body).group(1)
            status, body, url = request('login.php', dict(action='login_password', email='smoke@example.invalid',
                                                        password=password, _token=token))
            check(status == 200 and not url.endswith('login.php') and 'Erro interno' not in body,
                  'Password login with CSRF reaches the authenticated home page.')
            request('erp_machines.php') # Existing page installs its own machine schema in fresh fixtures.
            setup=root/'printing-fixture.php'
            setup.write_text("<?php require __DIR__.'/config.php'; require_once __DIR__.'/erp_migrations.php'; gt_erp_migrate_production_cost_settings($pdo);")
            subprocess.run([PHP,str(setup)],cwd=root,check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            setup.unlink()
            status, body, url = request('erp_settings.php')
            check(status==200 and 'Preparar impressão centralizada' in body and 'Erro interno' not in body,'Printing configuration renders before migration.')
            csrf=re.search(r'name="_token" value="([^"]+)"',body).group(1)
            probe=subprocess.run([PHP,str(root/'scripts/prepare_central_printing.php')],cwd=root,capture_output=True,text=True)
            check(probe.returncode==2 and not json.loads(probe.stdout)['installed'],'CLI diagnosis is read-only before ERP preparation.')
            missing=root/'missing-printing.sqlite'
            probe=subprocess.run([PHP,str(root/'scripts/prepare_central_printing.php'),'--apply'],cwd=root,env=dict(os.environ,GESTISSER_DB_PATH=str(missing)),capture_output=True,text=True)
            check(probe.returncode==1 and not missing.exists(),'CLI refuses a missing database without creating one.')
            with sqlite3.connect(root/'database.sqlite') as database:
                database.execute("INSERT INTO erp_printers(id,name,network_uri) VALUES(1,'HTTP Zebra','smb://PC/Zebra')")
                database.execute("UPDATE erp_work_centers SET default_printer_id=1 WHERE id=1")
            status,body,url=request('erp_settings.php',dict(print_action='prepare',_token=csrf))
            check(status==200 and 'ERP preparado para configurar os agentes' in body and 'Erro interno' not in body,'Printing migration and administration render through authenticated HTTP.')
            with sqlite3.connect(root/'database.sqlite') as database:
                check(database.execute('SELECT enabled FROM erp_print_config').fetchone()[0]==0,'Migration remains disabled.')
            check(len(list((root/'storage/backups/printing').glob('printing-*.sql')))==1,'Migration wrote private SQL snapshot.')
            status,body,url=request('erp_settings.php',dict(print_action='prepare',_token=csrf))
            check(len(list((root/'storage/backups/printing').glob('printing-*.sql')))==1,'Repeated preparation does not duplicate backup or migration.')
            with sqlite3.connect(root/'database.sqlite') as database:
                check(database.execute('SELECT COUNT(*) FROM erp_print_templates').fetchone()[0]==3,'Preparation supplies three reusable ERP models without duplication.')
                check(database.execute('SELECT COUNT(*) FROM erp_print_jobs').fetchone()[0]==0,'Preparation does not create print jobs.')
            check('ERP preparado para configurar e testar os agentes' in body,'ERP readiness checklist renders before any Windows machine is connected.')
            probe=subprocess.run([PHP,str(root/'scripts/prepare_central_printing.php'),'--apply'],cwd=root,capture_output=True,text=True)
            prepared=json.loads(probe.stdout)
            check(probe.returncode==0 and prepared['erp_ready'] and not prepared['enabled'] and prepared['models_created']==0,'CLI preparation is idempotent and leaves printing disabled.')
            status,body,url=request('scripts/prepare_central_printing.php')
            check(status==404,'CLI preparation cannot be invoked over HTTP.')
            status,body,url=request('erp_settings.php',dict(print_action='agent',name='HTTP PC fixture',_token=csrf))
            check(status==200 and 'token só é mostrado' in body and 'Erro interno' not in body,'Agent registration returns one-time credential.')
            token_match=re.search(r'Token: <code>([a-f0-9]{64})</code>',body)
            check(bool(token_match),'Agent token format is valid.')
            plain=token_match.group(1)
            with sqlite3.connect(root/'database.sqlite') as database:
                agent_id,digest=database.execute('SELECT id,token_hash FROM erp_print_agents').fetchone()
                check(digest!=plain,'Server stores token hash, not plaintext.')
            status,body,url=request('erp_settings.php')
            check(plain not in body,'A subsequent GET does not disclose the token.')
            status,body,url=request('erp_settings.php',dict(print_action='enabled',enabled='1',_token='invalid'))
            with sqlite3.connect(root/'database.sqlite') as database:
                check(database.execute('SELECT enabled FROM erp_print_config').fetchone()[0]==0,'Invalid CSRF cannot activate printing.')
            def agent_request(data,credential=plain):
                req=urllib.request.Request('http://127.0.0.1:8017/agent-tls.php',data=json.dumps(data).encode(),headers={'Content-Type':'application/json','Authorization':'Bearer '+credential,'X-GesTisser-Agent':agent_id})
                try: response=client.open(req,timeout=20)
                except urllib.error.HTTPError as err: response=err
                return response.status,response.read()
            astatus,abody=agent_request({'action':'heartbeat','version':'fixture','printers':[{'name':'Zebra','online':True}]})
            check(astatus==200,'Authenticated heartbeat works with a simulated server TLS flag.')
            status,body,url=request('erp_settings.php',dict(print_action='printer',printer_id=1,language='zpl',windows_queue='Zebra',agent_id=agent_id,dpi=203,print_code='ZEBRA_HTTP',brand='Zebra',model='Fixture',notes='',connection_type='windows_usb',_token=csrf))
            check(status==200 and 'Configuração de impressão guardada' in body and 'Erro interno' not in body,'Detected Windows queue can be authorized through administration.')
            status,body,url=request('erp_settings.php',dict(print_action='enabled',enabled='1',_token=csrf))
            check(status==200 and 'Erro interno' not in body,'Administrator can explicitly enable printing.')
            fixture=root/'printing-fixture.php'
            fixture.write_text("<?php require __DIR__.'/config.php'; require __DIR__.'/app/Services/CentralPrinting.php'; $p=new CentralPrinting($pdo);$a=$pdo->query('SELECT id FROM erp_print_agents')->fetchColumn();$p->heartbeat($a,[['name'=>'Zebra','online'=>true]],'fixture');$pdo->exec(\"INSERT INTO erp_work_center_printers(work_center_id,printer_id,type_id,format_id,is_default) VALUES(1,1,2,2,1)\");echo $p->enqueue(1,1,1,0,1,'^XA^XZ','application/zpl',bin2hex(random_bytes(16)));")
            job_id=subprocess.check_output([PHP,str(fixture)],cwd=root).decode().strip();fixture.unlink()
            astatus,abody=agent_request({'action':'claim'});api_job=json.loads(abody).get('job')
            check(astatus==200 and api_job and api_job['id']==job_id,'Agent API claims its queued job.')
            astatus,abody=agent_request({'action':'content','id':job_id,'lease_token':api_job['lease_token']})
            check(astatus==200 and abody==b'^XA^XZ','Agent content endpoint returns exact protected bytes.')
            astatus,abody=agent_request({'action':'claim'},'0'*64)
            check(astatus==401,'API refuses invalid bearer credentials.')
            for state in ['processing','spooler_accepted']:
                astatus,abody=agent_request({'action':'report','id':job_id,'lease_token':api_job['lease_token'],'state':state,'spool_id':'fixture-42'})
                check(astatus==200,'API accepts authenticated transition: '+state)
            with sqlite3.connect(root/'database.sqlite') as database:
                check(database.execute('SELECT state FROM erp_print_jobs WHERE id=?',(job_id,)).fetchone()[0]=='spooler_accepted','HTTP flow preserves distinction between spooler acceptance and physical output.')
            status,body,url=request('erp_settings.php',dict(print_action='revoke',agent_id=agent_id,_token=csrf))
            with sqlite3.connect(root/'database.sqlite') as database:
                check(database.execute('SELECT active FROM erp_print_agents').fetchone()[0]==0,'Agent revocation is persisted through authenticated HTTP.')
            astatus,abody=agent_request({'action':'claim'})
            check(astatus==401,'Revoked agent loses API access immediately.')
            status,body,url=request('print_submit.php')
            check(status==200 and 'Teste de impressão' in body and 'Erro interno' not in body,'Administrator print diagnostics renders.')
            status,body,url=request('print_agent_api.php',dict(action='claim'))
            check(status==403 and 'HTTPS' in body,'Agent endpoint refuses cleartext HTTP.')
            # Routing is article-specific; supply a fixture in the disposable DB.
            with sqlite3.connect(root / 'database.sqlite') as database:
                cursor = database.execute('INSERT INTO erp_finished_products(code,description,status) VALUES (?,?,?)',
                                          ('SMOKE-ARTICLE', 'Onboarding fixture', 'Ativo'))
                article_id = cursor.lastrowid
            for endpoint, marker in [('erp.php', 'ERP'), ('hr.php', 'RH'), ('erp_routing.php?article_id=' + str(article_id), 'Routing'),
                                     ('shopfloor.php', 'Shopfloor')]:
                status, body, url = request(endpoint)
                (logs / ('http-smoke-' + endpoint.split('?')[0] + '.html')).write_text(body)
                check(status == 200 and not url.endswith('login.php') and 'Erro interno' not in body
                      and marker.lower() in body.lower(), endpoint + ' renders for the authenticated administrator (HTTP 200).', required=False)
            with sqlite3.connect(root / 'database.sqlite') as database:
                check(database.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'SQLite integrity check passes.')
                check(database.execute('SELECT count(*) FROM login_attempts WHERE was_success=1').fetchone()[0] == 1,
                      'Successful login was recorded in SQLite.')
                check(database.execute("SELECT count(*) FROM sqlite_master WHERE type='table'").fetchone()[0] > 100,
                      'Application migrations created the required tables.')
            runtime = subprocess.check_output([PHP, '-r', 'echo PHP_VERSION;'], text=True)
            (logs / 'http-smoke-results.json').write_text(json.dumps(dict(status='failed' if failures else 'passed',
                php_version=runtime, checks=results, failures=failures), indent=2))
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
raise SystemExit(1 if failures else 0)
