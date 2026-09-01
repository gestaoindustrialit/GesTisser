<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/helpers.php';
require_once dirname(__DIR__).'/erp_migrations.php';
require_once dirname(__DIR__).'/app/Services/CustomerSpreadsheet.php';
erp_run_phase1_migrations($pdo);
$tmp=tempnam(sys_get_temp_dir(),'customer_csv_');
file_put_contents($tmp,"codigo;nome;nif;telemovel;plafond;ativo\nCLI-TEST;Cliente Teste;500000000;910000000;2500,50;Sim\n");
$rows=CustomerSpreadsheet::read($tmp,'csv'); unlink($tmp);
if(count($rows)!==1||$rows[0]['codigo']!=='CLI-TEST'){throw new RuntimeException('Falhou a leitura do modelo de clientes.');}
$pdo->beginTransaction();
try {
    $columns=CustomerSpreadsheet::columns();
    foreach(['country_prefix','mobile','address_2','city','postal_code','contact_name','salesperson','credit_limit'] as $column){if(!erp_column_exists($pdo,'erp_customers',$column)){throw new RuntimeException('Coluna de cliente em falta: '.$column);}}
    $code='TEST-CUSTOMER-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO erp_customers(code,name,mobile,city,credit_limit,is_active) VALUES (?,?,?,?,?,1)')->execute([$code,'Cliente inicial','910000000','Porto',1000]);
    $pdo->prepare('INSERT INTO erp_customers(code,name,mobile,city,credit_limit,is_active) VALUES (?,?,?,?,?,1) ON CONFLICT(code) DO UPDATE SET name=excluded.name,mobile=excluded.mobile,city=excluded.city,credit_limit=excluded.credit_limit')->execute([$code,'Cliente atualizado','920000000','Braga',2500.5]);
    $q=$pdo->prepare('SELECT * FROM erp_customers WHERE code=?');$q->execute([$code]);$customer=$q->fetch(PDO::FETCH_ASSOC);
    if(!$customer||$customer['name']!=='Cliente atualizado'||$customer['city']!=='Braga'||(float)$customer['credit_limit']!==2500.5){throw new RuntimeException('Falhou a criação/atualização do cliente.');}
    $pdo->rollBack(); echo "Clientes ERP validados: template, criação e atualização OK.\n";
} catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,$e->getMessage()."\n");exit(1);}
