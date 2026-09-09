<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/helpers.php';
require_once dirname(__DIR__).'/erp_migrations.php';
require_once dirname(__DIR__).'/app/Services/CustomerSpreadsheet.php';
erp_run_phase1_migrations($pdo);
$erpSource=file_get_contents(dirname(__DIR__).'/erp.php');
if($erpSource===false||strpos($erpSource,'data-customer-editor')===false){throw new RuntimeException('O formulário de cliente não está disponível no quadro da página.');}
foreach(['save_customer'=>'Clientes','save_supplier'=>'Fornecedores','save_article'=>'Artigos'] as $action=>$table){
    $formPosition=strpos($erpSource,'name="action" value="'.$action.'"');
    $tablePosition=strpos($erpSource,'data-sortable-table="'.$table.'"');
    if($formPosition===false||$tablePosition===false||$formPosition>$tablePosition){throw new RuntimeException('O formulário de '.$table.' deve aparecer antes da listagem.');}
}
$tmp=tempnam(sys_get_temp_dir(),'customer_csv_');
file_put_contents($tmp,"codigo;nome;nif;telemovel;plafond;ativo\nCLI-TEST;Cliente Teste;500000000;910000000;2500,50;Sim\n");
$rows=CustomerSpreadsheet::read($tmp,'csv'); unlink($tmp);
if(count($rows)!==1||$rows[0]['codigo']!=='CLI-TEST'){throw new RuntimeException('Falhou a leitura do modelo de clientes.');}
$pdo->beginTransaction();
try {
    $columns=CustomerSpreadsheet::columns();
    $formColumns=array_values(array_unique($columns));
    if(count($formColumns)!==count(array_unique($formColumns))){throw new RuntimeException('O formulário de clientes contém colunas duplicadas.');}
    foreach(['country_prefix','mobile','address_2','city','postal_code','contact_name','salesperson','credit_limit'] as $column){if(!erp_column_exists($pdo,'erp_customers',$column)){throw new RuntimeException('Coluna de cliente em falta: '.$column);}}
    if(!erp_table_exists($pdo,'erp_customer_delivery_addresses')){throw new RuntimeException('Tabela de moradas de entrega em falta.');}
    foreach(['delivery_address_id','delivery_address_snapshot','transporter'] as $column){if(!erp_column_exists($pdo,'erp_production_orders',$column)){throw new RuntimeException('Coluna de entrega da OF em falta: '.$column);}}
    $code='TEST-CUSTOMER-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO erp_customers(code,name,mobile,city,credit_limit,is_active) VALUES (?,?,?,?,?,1)')->execute([$code,'Cliente inicial','910000000','Porto',1000]);
    $values=[];foreach($formColumns as $column){$values[$column]='';}
    $values=array_replace($values,['code'=>$code,'name'=>'Cliente atualizado','country'=>'Portugal','mobile'=>'920000000','city'=>'Braga','credit_limit'=>2500.5,'is_active'=>1]);
    $sets=[];foreach($formColumns as $column){$sets[]=$column.'=?';}
    $params=array_values($values);$params[]=$code;
    $pdo->prepare('UPDATE erp_customers SET '.implode(',',$sets).',updated_at=CURRENT_TIMESTAMP WHERE code=?')->execute($params);
    $q=$pdo->prepare('SELECT * FROM erp_customers WHERE code=?');$q->execute([$code]);$customer=$q->fetch(PDO::FETCH_ASSOC);
    if(!$customer||$customer['name']!=='Cliente atualizado'||$customer['city']!=='Braga'||(float)$customer['credit_limit']!==2500.5){throw new RuntimeException('Falhou a criação/atualização do cliente.');}
    $pdo->prepare('INSERT INTO erp_customer_delivery_addresses(customer_id,label,address,postal_code,city,country,transporter) VALUES (?,?,?,?,?,?,?)')->execute([(int)$customer['id'],'Armazém Norte','Rua da Entrega, 10','4700-000','Braga','Portugal','Transportes Teste']);
    $delivery=$pdo->prepare('SELECT * FROM erp_customer_delivery_addresses WHERE customer_id=?');$delivery->execute([(int)$customer['id']]);$delivery=$delivery->fetch(PDO::FETCH_ASSOC);
    if(!$delivery||$delivery['transporter']!=='Transportes Teste'){throw new RuntimeException('Falhou a associação entre morada de entrega e transportador.');}
    $pdo->rollBack(); echo "Clientes ERP validados: template, criação, atualização, moradas e transportadores OK.\n";
} catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,$e->getMessage()."\n");exit(1);}
