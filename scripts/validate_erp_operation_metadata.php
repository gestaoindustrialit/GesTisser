<?php
declare(strict_types=1);

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../erp_migrations.php';
require_once __DIR__.'/../app/Services/RoutingService.php';

erp_run_phase1_migrations($pdo);
$tag='META'.bin2hex(random_bytes(3));
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO erp_operation_types(code,name) VALUES (?,?)')->execute([strtolower($tag),'Tipo '.$tag]);
    $pdo->prepare('INSERT INTO erp_work_centers(code,name,hourly_rate,is_active) VALUES (?,?,?,1)')->execute([$tag,'Setor '.$tag,12.5]);
    $sectorId=(int)$pdo->lastInsertId();
    $userId=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if(!$userId){$pdo->prepare('INSERT INTO users(name,email,password,is_admin) VALUES (?,?,?,1)')->execute(['Metadata Test',strtolower($tag).'@test.local','test']);$userId=(int)$pdo->lastInsertId();}
    $service=new RoutingService($pdo);
    $operationId=$service->saveOperation(['code'=>$tag,'name'=>'Operação '.$tag,'operation_type'=>strtolower($tag),'work_center_id'=>$sectorId,'is_active'=>1],[],$userId);
    $stmt=$pdo->prepare('SELECT operation_type,default_work_center_id FROM erp_operations WHERE id=?');$stmt->execute([$operationId]);$operation=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$operation||$operation['operation_type']!==strtolower($tag)||(int)$operation['default_work_center_id']!==$sectorId)throw new RuntimeException('Os metadados configuráveis não ficaram associados à operação.');
    $pdo->rollBack();
    echo "Tipos e setores de operações configuráveis validados com sucesso.\n";
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
