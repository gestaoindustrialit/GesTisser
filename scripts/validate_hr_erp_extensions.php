<?php
$root = dirname(__DIR__);
require_once $root . '/hr_organization_lib.php';
$tmp = sys_get_temp_dir() . '/gestisser_hr_erp_' . getmypid() . '.sqlite'; @unlink($tmp);
$pdo = new PDO('sqlite:' . $tmp); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); $pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,email TEXT,department TEXT,schedule_id INTEGER,is_active INTEGER DEFAULT 1,is_admin INTEGER DEFAULT 0,access_profile TEXT DEFAULT "RH")');
$pdo->exec('CREATE TABLE hr_departments(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE)');
$pdo->exec('CREATE TABLE hr_schedules(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE,start_time TEXT,end_time TEXT,weekdays_mask TEXT DEFAULT "1,2,3,4,5")');
$pdo->exec('CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,details_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
gt_run_org_migrations($pdo);
function ok($cond,$msg){ if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} echo "OK: $msg\n"; }
$pdo->exec("INSERT INTO hr_departments(name) VALUES ('Produção')"); $dep=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO hr_schedules(name,start_time,end_time) VALUES ('T1','08:00','16:00')"); $sch=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users(name,department,schedule_id) VALUES ('A','Produção',$sch),('B','Produção',$sch),('C','Produção',$sch)");
$pdo->exec('UPDATE users SET manager_user_id=1 WHERE id=2'); ok(gt_prevents_cycle($pdo,3,2),'hierarquia válida'); ok(!gt_prevents_cycle($pdo,1,1),'bloqueia auto reporte'); ok(!gt_prevents_cycle($pdo,1,2),'bloqueia hierarquia circular');
$organizationLevels = gt_org_levels([
    ['id' => 1, 'manager_user_id' => null],
    ['id' => 2, 'manager_user_id' => 1],
    ['id' => 3, 'manager_user_id' => 2],
]);
ok(count($organizationLevels) === 3 && (int) $organizationLevels[3][0]['id'] === 3, 'organograma calcula os níveis sem retorno void');
ok(gt_org_role_label(['job_title' => '', 'title' => '', 'profession' => '', 'department' => 'Produção']) === 'Produção', 'organograma usa o departamento quando a função está vazia');
ok(gt_org_shift_color('Prod 01') === '.93 .47 .12' && gt_org_shift_color('Prod 02') === '.18 .41 .63', 'organograma aplica as cores dos turnos de produção');
if (function_exists('imagecreatetruecolor')) {
    $logoPath = sys_get_temp_dir() . '/gestisser_org_logo_' . getmypid() . '.png';
    $logo = imagecreatetruecolor(80, 30);
    imagepng($logo, $logoPath);
    imagedestroy($logo);
    $logoRoot = sys_get_temp_dir() . '/gestisser_org_root_' . getmypid();
    @mkdir($logoRoot . '/assets/uploads', 0777, true);
    copy($logoPath, $logoRoot . '/assets/uploads/report.png');
    ok(gt_org_brand_logo_path('/gestisser/assets/uploads/report.png', $logoRoot) === $logoRoot . '/assets/uploads/report.png', 'organograma resolve o caminho guardado do logótipo');
    $logoPdf = gt_org_native_pdf($organizationLevels, [], 'Teste', '04/09/2026', $logoPath);
    ok(strpos($logoPdf, '/Logo Do') !== false && strpos($logoPdf, '/Subtype /Image') !== false, 'organograma incorpora o logótipo configurado');
    @unlink($logoPath);
    @unlink($logoRoot . '/assets/uploads/report.png');
    @rmdir($logoRoot . '/assets/uploads'); @rmdir($logoRoot . '/assets'); @rmdir($logoRoot);
}
$pdo->prepare('INSERT INTO erp_machines(code,name,department_id,operators_required,criticality) VALUES (?,?,?,?,?)')->execute(['M1','Máquina',$dep,1,'critical']); $mid=(int)$pdo->lastInsertId();
try{$pdo->prepare('INSERT INTO erp_machines(code,name) VALUES (?,?)')->execute(['M1','Dup']); ok(false,'código único');}catch(Throwable $e){ok(true,'código único');}
try{$pdo->prepare('INSERT INTO erp_machines(code,name,operators_required) VALUES (?,?,?)')->execute(['M2','Bad',-1]); ok(false,'operadores >= 0');}catch(Throwable $e){ok(true,'operadores >= 0');}
$pdo->prepare('INSERT INTO hr_machine_competencies(user_id,machine_id,level,assessed_at,expiry_date) VALUES (?,?,?,?,?)')->execute([1,$mid,3,'2026-07-24','2027-07-24']); ok((int)$pdo->query('SELECT COUNT(*) FROM hr_machine_competencies WHERE level>=3')->fetchColumn()===1,'nível 3 autónomo');
try{$pdo->prepare('INSERT INTO hr_machine_competencies(user_id,machine_id,level) VALUES (?,?,?)')->execute([1,$mid,4]); ok(false,'sem competência duplicada ativa');}catch(Throwable $e){ok(true,'sem competência duplicada ativa');}
$pdo->prepare("INSERT INTO company_duty_sheets(title,department_id,responsible_user_id,status,review_date) VALUES (?,?,?,?,?)")->execute(['Caderno',$dep,1,'draft','2026-01-01']); $d=$pdo->query('SELECT * FROM company_duty_sheets')->fetch(PDO::FETCH_ASSOC); $risks=gt_duty_risks($d); ok(in_array('Sem substituto principal',$risks,true),'deteta caderno sem substituto'); ok(in_array('Revisão vencida',$risks,true),'deteta revisão vencida');
$pdo->prepare('UPDATE erp_machines SET deleted_at=CURRENT_TIMESTAMP,is_active=0 WHERE id=?')->execute([$mid]); ok((int)$pdo->query('SELECT COUNT(*) FROM hr_machine_competencies')->fetchColumn()===1,'soft delete preserva histórico de competências');
@unlink($tmp);
