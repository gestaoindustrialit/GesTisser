<?php
require_once __DIR__.'/../app/Services/OrderSupplierDetector.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_suppliers(id INTEGER PRIMARY KEY,code TEXT,name TEXT,tax_number TEXT,email TEXT,is_active INTEGER);INSERT INTO erp_suppliers VALUES(1,"CIF","Compagnie Industrielle des Fibres","04902382","cif@cifco.ma",1)');
$detected=(new OrderSupplierDetector($pdo))->detect('COMPAGNIE INDUSTRIELLE DES FIBRES - I.F. 04902382');
if((int)$detected['id']!==1||$detected['method']!=='tax_number'||(int)$detected['confidence']!==100)throw new RuntimeException('Legacy supplier detector compatibility failed.');
echo "order_supplier_detector_compatibility_test: OK\n";
