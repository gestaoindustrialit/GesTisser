<?php
// Read-only check against a supplied current database. No installation and no imports.
require_once __DIR__.'/../historical-import/Validator.php';
$path=getenv('HISTORICAL_TEST_DB');
if(!$path || !is_file($path)) { fwrite(STDERR,"Set HISTORICAL_TEST_DB to a current database copy.\n");exit(2); }
$before=hash_file('sha256',$path);
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA query_only=ON');
function assertion($condition,$message) { if(!$condition) throw new RuntimeException($message); }
assertion($pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok','Database integrity failed');
assertion(!$pdo->query('PRAGMA foreign_key_check')->fetch(),'Foreign key violations');
$compatibility=HistoricalCompatibility::inspect($pdo);
assertion(!$compatibility['encomendas']['ready'] && !$compatibility['encomendas_linhas']['ready'] && !$compatibility['entradas_mp']['ready'],'Unconfirmed business destinations accepted');
foreach(['ofs','of_operacoes','rolos_rafia','lotes_tintas','movimentos_historicos'] as $entity) assertion($compatibility[$entity]['ready'],'Current adapter columns do not fit '.$entity);
$customer=$pdo->query('SELECT id,code FROM erp_customers ORDER BY id LIMIT 1')->fetch();
$article=$pdo->query('SELECT id,code FROM erp_finished_products WHERE NOT EXISTS(SELECT 1 FROM erp_products p WHERE p.code=erp_finished_products.code COLLATE NOCASE) ORDER BY id LIMIT 1')->fetch();
$material=$pdo->query('SELECT id,code FROM erp_raw_materials ORDER BY id LIMIT 1')->fetch();
assertion($customer && $article && $material,'Catalogs missing');
function currentInput($entity,$values) {return ['entity'=>$entity,'rows'=>[['line'=>2,'data'=>array_replace(array_fill_keys(HistoricalSpreadsheet::contracts()[$entity]['columns'],''),$values,['importar'=>'1'])]]];}
$batch=[
 'encomendas'=>currentInput('encomendas',['legacy_id'=>'READONLY-ORDER','numero_encomenda'=>'READONLY-ORDER','cliente_codigo'=>$customer['code'],'data_encomenda'=>'2000-01-01']),
 'ofs'=>currentInput('ofs',['legacy_id'=>'READONLY-OF','numero_of'=>'READONLY-OF','cliente_codigo'=>$customer['code'],'artigo_codigo'=>$article['code'],'data_criacao'=>'2000-01-01','quantidade_planeada'=>'1','estado'=>'Concluída']),
 'rolos_rafia'=>currentInput('rolos_rafia',['legacy_id'=>'READONLY-ROLL','numero_entrada'=>'READONLY-ENTRY','data_entrada'=>'2000-01-01','materia_prima_codigo'=>$material['code'],'lote_fornecedor'=>'READONLY-LOT','peso_inicial_kg'=>'10','peso_atual_kg'=>'0','metros_iniciais'=>'100','metros_atuais'=>'0','estado'=>'CONSUMED']),
];
$report=(new HistoricalValidator($pdo))->validate($batch);
assertion($report['entities']['encomendas']['validas']===1,'Existing customer ID not resolved');
assertion($report['entities']['encomendas']['rows'][0]['refs']['customer']===(int)$customer['id'],'Existing customer ID changed');
assertion($report['entities']['ofs']['erros']===1,'Missing product bridge not blocked');
assertion($report['entities']['ofs']['rows'][0]['refs']['product']===(int)$article['id'],'Existing finished product not resolved');
assertion($report['entities']['rolos_rafia']['validas']===1,'Existing raw material not resolved');
assertion($report['entities']['rolos_rafia']['rows'][0]['refs']['raw_material']===(int)$material['id'],'Existing raw material ID changed');
$pdo=null;assertion(hash_file('sha256',$path)===$before,'Current database changed');
echo "PASS current SQLite: integrity, FKs, exact existing catalog IDs, adapter schema checks, missing OF bridge blocked, byte-identical source database\n";
