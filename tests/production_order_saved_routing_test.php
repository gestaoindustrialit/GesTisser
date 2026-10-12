<?php
require_once __DIR__.'/../app/Services/RoutingService.php';
function saved_routing_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_article_routings(id INTEGER PRIMARY KEY,finished_product_id INTEGER)');
$pdo->exec('CREATE TABLE erp_article_routing_versions(id INTEGER PRIMARY KEY,routing_id INTEGER,version_no INTEGER,status TEXT,effective_from TEXT)');
$pdo->exec('INSERT INTO erp_article_routings VALUES(1,10),(2,20)');
$pdo->exec("INSERT INTO erp_article_routing_versions VALUES(1,1,1,'archived',NULL),(2,1,2,'active',NULL),(3,1,3,'draft',NULL),(4,2,1,'active',NULL)");
$erp=file_get_contents(__DIR__.'/../erp.php');
saved_routing_check(preg_match('/\\$routingVersions=\\$pdo->query\(\x27([^\x27]+)\x27\)/',$erp,$match)===1,'Consulta do seletor não encontrada.');
$ids=array_map('intval',array_column($pdo->query($match[1])->fetchAll(PDO::FETCH_ASSOC),'id'));
saved_routing_check($ids===[2,1,4],'A nova OF deve listar versões ativas e arquivadas, excluindo drafts.');
$service=new RoutingService($pdo);
foreach([3,4,999] as $id){
    try{$service->snapshotForOrder(1,10,1.0,1,false,'',$id);throw new RuntimeException('Versão inválida aceite: '.$id);}
    catch(DomainException $e){saved_routing_check(strpos($e->getMessage(),'versão de routing salva')!==false,'Erro de validação inesperado.');}
}
// Execute the explicit selection query against the same fixture to verify saved history remains selectable.
$source=file_get_contents(__DIR__.'/../app/Services/RoutingService.php');
saved_routing_check(preg_match('/\\?\\$this->one\(\x27([^\x27]+)\x27,\[\\$versionId,\\$articleId\]\)/',$source,$match)===1,'Consulta de seleção não encontrada.');
foreach([1,2] as $id){$q=$pdo->prepare($match[1]);$q->execute([$id,10]);saved_routing_check((int)$q->fetchColumn()===$id,'Versão salva não selecionável: '.$id);}
echo "production_order_saved_routing_test: OK\n";
