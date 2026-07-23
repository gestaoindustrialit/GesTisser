<?php
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI apenas.\n"); exit(1); }
$root = dirname(__DIR__); $real=$root.'/database.sqlite'; if (!is_file($real)) { fwrite(STDERR,"database.sqlite não encontrada.\n"); exit(1); }
$tmp = sys_get_temp_dir().'/company_mapper_validate_'.getmypid().'.sqlite'; copy($real,$tmp);
try {
    require_once $root.'/company_mapper_migrations.php';
    $pdo=new PDO('sqlite:'.$tmp); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->exec('PRAGMA foreign_keys=ON');
    company_mapper_run_migrations($pdo);
    $sig1=schema_sig($pdo);
    company_mapper_run_migrations($pdo);
    $sig2=schema_sig($pdo);
    ok($sig1===$sig2,'segunda execução sem alterações de schema');
    foreach (array('erp_machines','hr_machine_competencies','company_duty_sheets','company_duty_sheet_versions','company_systems','company_process_flows','company_process_nodes','company_process_edges','company_improvements') as $t) ok(table_exists($pdo,$t),'tabela '.$t);
    $cols=array('users'=>array('manager_user_id','capacity_percent','org_sort_order'),'hr_departments'=>array('code','color','description','manager_user_id','is_active','sort_order','updated_at'),'hr_schedules'=>array('code','color','is_active','notes','updated_at'),'hr_calendar_events'=>array('description','owner_user_id','department_id','all_day','status','updated_at'),'erp_work_centers'=>array('department_id','location','description','sort_order','updated_at'));
    foreach($cols as $t=>$cs) foreach($cs as $c) ok(col_exists($pdo,$t,$c),'coluna '.$t.'.'.$c);
    foreach (array('idx_erp_machines_code','idx_erp_machines_status','idx_erp_machines_department','idx_hr_calendar_events_mapper','idx_users_manager_user_id') as $i) ok(index_exists($pdo,$i),'índice '.$i);
    $s=$pdo->prepare('SELECT COUNT(*) FROM erp_permissions WHERE code IN ('.implode(',',array_fill(0,9,'?')).')'); $s->execute(array('mapper.view','mapper.org.manage','mapper.duties.manage','mapper.flows.manage','mapper.calendar.manage','mapper.systems.manage','mapper.machines.manage','mapper.competencies.manage','mapper.improvements.manage')); ok((int)$s->fetchColumn()===9,'permissões mapper');
    expect_fail($pdo,"INSERT INTO erp_machines(code,name,status) VALUES ('BAD','Bad','wrong')",'constraint status máquina');
    $pdo->exec("INSERT INTO erp_machines(code,name) VALUES ('MVAL','Máquina validação')"); $mid=(int)$pdo->lastInsertId();
    $uid=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(); if (!$uid) { $pdo->exec("INSERT INTO users(name,email,password) VALUES ('Validador','validator@example.invalid','x')"); $uid=(int)$pdo->lastInsertId(); }
    $pdo->prepare('INSERT INTO hr_machine_competencies(user_id,machine_id,level) VALUES (?,?,?)')->execute(array($uid,$mid,4)); expect_fail($pdo,"INSERT INTO hr_machine_competencies(user_id,machine_id,level) VALUES ($uid,$mid,5)",'constraint nível competência');
    $pdo->prepare('DELETE FROM erp_machines WHERE id=?')->execute(array($mid)); $s=$pdo->prepare('SELECT COUNT(*) FROM hr_machine_competencies WHERE machine_id=?'); $s->execute(array($mid)); ok((int)$s->fetchColumn()===0,'cascade competências por máquina');
    $pdo->exec("INSERT INTO company_duty_sheets(title) VALUES ('Teste')"); $ds=(int)$pdo->lastInsertId(); $pdo->prepare('INSERT INTO company_duty_sheet_versions(duty_sheet_id,version_no,snapshot_json) VALUES (?,?,?)')->execute(array($ds,1,'{}')); $pdo->prepare('DELETE FROM company_duty_sheets WHERE id=?')->execute(array($ds)); $s=$pdo->prepare('SELECT COUNT(*) FROM company_duty_sheet_versions WHERE duty_sheet_id=?'); $s->execute(array($ds)); ok((int)$s->fetchColumn()===0,'cascade versões cadernos');
    echo "Company Mapper schema OK\n"; cleanup($tmp); exit(0);
} catch (Exception $e) { cleanup($tmp); fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
function table_exists($pdo,$t){$s=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute(array($t));return(bool)$s->fetchColumn();}
function col_exists($pdo,$t,$c){foreach($pdo->query('PRAGMA table_info('.$t.')')->fetchAll(PDO::FETCH_ASSOC) as $r) if($r['name']===$c)return true; return false;}
function index_exists($pdo,$i){$s=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");$s->execute(array($i));return(bool)$s->fetchColumn();}
function schema_sig($pdo){return json_encode($pdo->query("SELECT type,name,sql FROM sqlite_master WHERE type IN ('table','index') ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC));}
function ok($v,$m){ if(!$v) throw new RuntimeException('Falhou: '.$m); echo "OK - $m\n"; }
function expect_fail($pdo,$sql,$m){ try{$pdo->exec($sql);}catch(Exception $e){echo "OK - $m\n"; return;} throw new RuntimeException('Falhou: '.$m); }
function cleanup($tmp){ @unlink($tmp); $dir=dirname($tmp).'/backups'; foreach (glob($dir.'/company_mapper_*.sqlite') as $f) { @unlink($f); } @rmdir($dir); }
