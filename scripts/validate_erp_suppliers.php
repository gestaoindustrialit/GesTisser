<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/helpers.php';
require_once dirname(__DIR__).'/erp_migrations.php';
require_once dirname(__DIR__).'/app/Services/SupplierSpreadsheet.php';

erp_run_phase1_migrations($pdo);

$tmp=tempnam(sys_get_temp_dir(),'supplier_csv_');
file_put_contents($tmp,"codigo;nome;desconto;certificado_pefc;incluir_osaft\nFOR-CSV;Fornecedor CSV;2,5;Sim;Não\n");
$rows=SupplierSpreadsheet::read($tmp,'csv');unlink($tmp);
if(count($rows)!==1||$rows[0]['codigo']!=='FOR-CSV'||$rows[0]['nome']!=='Fornecedor CSV')throw new RuntimeException('Falhou a leitura CSV de fornecedores.');

$tmp=tempnam(sys_get_temp_dir(),'supplier_xls_');
$xml='<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Fornecedores"><Table><Row><Cell><Data ss:Type="String">codigo</Data></Cell><Cell><Data ss:Type="String">nome</Data></Cell></Row><Row><Cell><Data ss:Type="String">FOR-XLS</Data></Cell><Cell><Data ss:Type="String">Fornecedor XLS</Data></Cell></Row></Table></Worksheet></Workbook>';
file_put_contents($tmp,$xml);$rows=SupplierSpreadsheet::read($tmp,'xls');unlink($tmp);
if(count($rows)!==1||$rows[0]['codigo']!=='FOR-XLS'||$rows[0]['nome']!=='Fornecedor XLS')throw new RuntimeException('Falhou a leitura do modelo .xls de fornecedores.');

foreach (['address_2','postal_code','mobile','contact_name','salesperson','website','notes','discount_percent','credit_limit','pefc_certified','stock_order_control','include_osaf','created_at','updated_at'] as $column) {
    if (!erp_column_exists($pdo, 'erp_suppliers', $column)) {
        throw new RuntimeException('Coluna de fornecedor em falta: '.$column);
    }
}

$expectedCodes=['CIF','DAMAN0201','FERTIPLAST','HUBER','KAO','KAYPEE','LINCON','MAURICIO201','MULTISAC001','PLASTENE','REI&REI','SATYENDRA','SINTIGRAF','TISSER0001','TRADIBAG'];
$placeholders=implode(',',array_fill(0,count($expectedCodes),'?'));
$query=$pdo->prepare('SELECT code FROM erp_suppliers WHERE code IN ('.$placeholders.')');
$query->execute($expectedCodes);
if(count($query->fetchAll(PDO::FETCH_COLUMN))!==count($expectedCodes)){
    throw new RuntimeException('A lista inicial de fornecedores não foi carregada integralmente.');
}

$pdo->beginTransaction();
try {
    $code='TEST-SUPPLIER-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO erp_suppliers(code,name,country,contact_name,discount_percent,credit_limit,pefc_certified,is_active) VALUES (?,?,?,?,?,?,?,?)')->execute([$code,'Fornecedor Teste','Portugal','Compras',2.5,5000,1,1]);
    $pdo->prepare('UPDATE erp_suppliers SET name=?,stock_order_control=1 WHERE code=?')->execute(['Fornecedor Atualizado',$code]);
    $query=$pdo->prepare('SELECT * FROM erp_suppliers WHERE code=?');$query->execute([$code]);$supplier=$query->fetch(PDO::FETCH_ASSOC);
    if(!$supplier||$supplier['name']!=='Fornecedor Atualizado'||(float)$supplier['discount_percent']!==2.5||(int)$supplier['stock_order_control']!==1){
        throw new RuntimeException('Falhou a criação/atualização do fornecedor.');
    }
    $pdo->rollBack();
    echo "Fornecedores ERP validados: esquema, dados iniciais, criação e atualização OK.\n";
} catch(Throwable $exception){
    if($pdo->inTransaction()){$pdo->rollBack();}
    fwrite(STDERR,$exception->getMessage()."\n");exit(1);
}
