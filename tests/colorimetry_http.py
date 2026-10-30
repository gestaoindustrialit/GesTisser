#!/usr/bin/env python3
"""PHP 7.0 endpoints and Android tablet checks against a disposable installation."""
import http.cookiejar
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

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP70_BIN', 'php')
version = subprocess.check_output([PHP, '-r', 'echo PHP_VERSION;'], text=True)
assert version.startswith('7.0.'), version

def check(value, message):
    assert value, message
    print('PASS: ' + message, flush=True)

with tempfile.TemporaryDirectory(prefix='ink-http-') as directory:
    root = Path(directory) / 'source'
    root.mkdir()
    files = subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'], cwd=ROOT).decode().split('\0')
    for name in set(filter(None, files)):
        source = ROOT / name
        if not source.is_file() or name.startswith(('vendor/','work/','storage/')):
            continue
        dest = root / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, dest)
    (root/'vendor').symlink_to((ROOT/'vendor').resolve(), target_is_directory=True)
    log = (ROOT/'work'/'colorimetry-http.log').open('w')
    env = dict(os.environ, APP_ENV='development', GESTISSER_DB_PATH=str(root/'database.sqlite'))
    port = 8127
    server = subprocess.Popen([PHP,'-S','127.0.0.1:'+str(port),'-t',str(root)], cwd=root, env=env, stdout=log, stderr=log)
    url = 'http://127.0.0.1:'+str(port)+'/'
    def client():
        jar = http.cookiejar.CookieJar()
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)), jar
    def request(c, path, data=None):
        data = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response = c.open(url+path,data=data,timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        return response.status,response.read().decode(),response.geturl()
    def token(body):
        return re.search(r'name="(?:_token|csrf_token)" value="([^"]+)"',body).group(1)
    def login(c,email,password):
        _,body,_=request(c,'login.php')
        status,body,target=request(c,'login.php',dict(action='login_password',email=email,password=password,_token=token(body)))
        check(status==200 and not target.endswith('login.php'),'Autenticação existente: '+email)
    try:
        admin, adminjar = client()
        for attempt in range(40):
            try:
                status,body,_=request(admin,'install.php')
                break
            except urllib.error.URLError:
                time.sleep(.15)
        if server.poll() is not None:
            raise RuntimeError('O servidor HTTP não arrancou; consulte work/colorimetry-http.log')
        password=secrets.token_urlsafe(24)
        csrf=re.search(r'name="csrf" value="([^"]+)"',body).group(1)
        status,body,_=request(admin,'install.php',dict(action='new',environment='development',name='Fixture colorimetria',email='admin@example.invalid',password=password,csrf=csrf))
        check(status==200 and 'Ativação concluída' in body,'Instalação descartável em PHP '+version)
        login(admin,'admin@example.invalid',password)
        status,body,_=request(admin,'colorimetry.php')
        check(status==200 and 'Pesquisar Cor' in body and 'Erro interno' not in body,'Migração e página administrativa')
        # Build fixtures through the real service and existing ERP tables.
        seed=root/'seed.php'
        seed.write_text('''<?php
require __DIR__.'/helpers.php';require __DIR__.'/app/Services/ColorimetryService.php';
$admin=$pdo->query('SELECT * FROM users WHERE is_admin=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);$ink=new ColorimetryService($pdo,$admin);
$pdo->exec('INSERT OR IGNORE INTO erp_units(code,name) VALUES("kg","Quilograma")');$unit=$pdo->query('SELECT id FROM erp_units WHERE code="kg"')->fetchColumn();
$pdo->exec('INSERT INTO erp_warehouses(code,name) VALUES("INKTEST","Tintas teste")');$wh=(int)$pdo->lastInsertId();
$components=[];$sources=[];
foreach(["AZUL"=>650,"BRANCO"=>250,"PRETO"=>100] as $code=>$mass){$pdo->prepare('INSERT INTO erp_raw_materials(code,description,primary_unit_id,status,roll_controlled) VALUES (?,?,?,"Ativo",0)')->execute(["TEST-".$code,$code,$unit]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES("raw_material",?,?,0,"ORIGEM",100)')->execute([$id,$wh]);$sources[]=(int)$pdo->lastInsertId();$components[]=["material_id"=>$id,"quantity"=>(string)$mass];}
$c=$ink->execute('color',["code"=>"TEST-001","name"=>"Cor de teste","customer_id"=>"","article_id"=>"","ink_type_id"=>""],bin2hex(random_bytes(16)));
$f=$ink->execute('formula',["color_id"=>$c['color_id'],"components"=>$components],bin2hex(random_bytes(16)));$ink->execute('formula_state',["id"=>$f['formula_id'],"status"=>"Aprovada"],bin2hex(random_bytes(16)));
foreach(["operador","bloqueado"] as $name){$pdo->prepare('INSERT INTO users(name,email,username,password,is_admin,access_profile,is_active) VALUES (?,?,?,?,0,"Produção",1)')->execute([$name,$name."@example.invalid",$name,$admin['password']]);$id=(int)$pdo->lastInsertId();if($name==="operador")$pdo->prepare('INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES (?,"ink.prepare",1)')->execute([$id]);}
echo json_encode(["formula_id"=>$f['formula_id'],"warehouse_id"=>$wh,"sources"=>$sources]);
''')
        fixture=json.loads(subprocess.check_output([PHP,str(seed)],cwd=root,env=env,text=True))
        denied,_=client();login(denied,'bloqueado@example.invalid',password)
        for path in ['colorimetry.php','prepared_ink_label.php?container_id=1','ink_formula_attachment.php?id=1','print_label.php?kind=prepared_ink&id=1']:
            status,body,_=request(denied,path)
            check(status==403,'Backend sem Colorista bloqueia '+path.split('?')[0])
        status,body,_=request(denied,'shopfloor.php')
        check('href="colorimetry.php"' not in body,'Botão oculto sem Colorista')
        operator,jar=client();login(operator,'operador@example.invalid',password)
        status,body,_=request(operator,'shopfloor.php')
        check('href="colorimetry.php"' in body,'Botão Colorista junto às etiquetas no Shopfloor')
        status,body,_=request(operator,'colorimetry.php?tab=library')
        check(status==403,'Biblioteca administrativa bloqueada ao preparador')
        status,body,_=request(operator,'colorimetry.php?tab=prepare&formula_id='+str(fixture['formula_id']))
        key=secrets.token_hex(16)
        status,_,_=request(operator,'colorimetry.php',{'action':'formula_state','_token':token(body),'request_key':key,'data[id]':fixture['formula_id'],'data[status]':'Inativa'})
        check(status==403,'Aprovação proibida no endpoint POST')
        status,body,_=request(operator,'colorimetry.php?tab=stock')
        status,body,_=request(operator,'colorimetry.php',{'action':'consume','_token':'invalid','request_key':key,'data[container_id]':1})
        check('Pedido inválido' in body,'CSRF rejeitado no endpoint POST')
        fixture.update(url=url,root=str(root),cookies=[dict(name=c.name,value=c.value,domain='127.0.0.1',path=c.path) for c in jar])
        fixture_path=ROOT/'work'/'colorimetry-browser-fixture.json'
        fixture_path.write_text(json.dumps(fixture))
        subprocess.run(['node',str(ROOT/'tests'/'colorimetry_browser.js'),str(fixture_path)],cwd=ROOT,env=env,check=True)
        fixture_path.unlink()
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
