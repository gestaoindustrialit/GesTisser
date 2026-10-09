<?php
require_once __DIR__ . '/SalesOrderService.php';
/** Bobinas planning only: no importer, numbering, OF, stock or current-record writes. */
final class SalesOrderHistoryPreparation
{
    public static function simulate(PDO $pdo,array $orders) {
        $report=['ready'=>[],'unchanged'=>[],'conflicts'=>[]];$seen=[];
        $table=$pdo->query("SELECT 1 FROM sqlite_master WHERE name='erp_legacy_import_map' AND type='table'")->fetchColumn();
        foreach($orders as $index=>$original){
            try {
                if(!is_array($original))throw new RuntimeException('Registo inválido.');
                foreach(['original_id','original_number','customer_original_id','order_date','lines'] as $key)if(!isset($original[$key])||$original[$key]==='')throw new RuntimeException('Campo de origem em falta: '.$key);
                if(!is_scalar($original['original_id'])||!is_scalar($original['original_number'])||!is_scalar($original['customer_original_id']))throw new RuntimeException('Identificador de origem inválido.');
                $key=(string)$original['original_id'];if(isset($seen[$key]))throw new RuntimeException('Identificador original repetido no lote.');$seen[$key]=true;
                SalesOrderService::date((string)$original['order_date'],true);
                if(!empty($original['expected_date']))SalesOrderService::date((string)$original['expected_date']);
                if(!$table)throw new RuntimeException('Correspondências históricas ainda não preparadas; reutilizar erp_legacy_import_map.');
                $find=$pdo->prepare('SELECT gestisser_id,source_hash FROM erp_legacy_import_map WHERE source_system="Bobinas" AND target_table=? AND legacy_id=?');
                $find->execute(['erp_customers',(string)$original['customer_original_id']]);$customer=$find->fetch(PDO::FETCH_ASSOC);
                if(!$customer)throw new RuntimeException('Cliente original sem correspondência validada.');
                $exists=$pdo->prepare('SELECT id FROM erp_customers WHERE id=?');$exists->execute([$customer['gestisser_id']]);if(!$exists->fetchColumn())throw new RuntimeException('Correspondência de cliente sem destino.');
                if(!is_array($original['lines'])||!$original['lines'])throw new RuntimeException('Linhas de origem em falta.');
                $lines=[];foreach($original['lines'] as $line){
                    if(!is_array($line)||!isset($line['article_original_id'],$line['quantity'])||!is_scalar($line['article_original_id'])||!is_numeric($line['quantity'])||!is_finite((float)$line['quantity'])||(float)$line['quantity']<=0)throw new RuntimeException('Artigo ou quantidade de origem inválidos.');
                    $find->execute(['erp_finished_products',(string)$line['article_original_id']]);$article=$find->fetch(PDO::FETCH_ASSOC);if(!$article)throw new RuntimeException('Artigo original sem correspondência validada.');
                    $exists=$pdo->prepare('SELECT id FROM erp_finished_products WHERE id=?');$exists->execute([$article['gestisser_id']]);if(!$exists->fetchColumn())throw new RuntimeException('Correspondência de artigo sem destino.');
                    $lines[]=['finished_product_id'=>(int)$article['gestisser_id'],'original'=>$line];
                }
                $json=json_encode($original,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Dados originais não serializáveis.');$hash=hash('sha256',$json);
                $find->execute(['erp_sales_orders',$key]);$mapped=$find->fetch(PDO::FETCH_ASSOC);
                $current=$pdo->prepare('SELECT id,source_system,original_id,original_data_json FROM erp_sales_orders WHERE (source_system="Bobinas" AND original_id=?) OR order_number=?');$current->execute([$key,(string)$original['original_number']]);$matches=$current->fetchAll(PDO::FETCH_ASSOC);
                if($mapped){
                    if($mapped['source_hash']!==$hash||count($matches)!==1||$matches[0]['id']!=$mapped['gestisser_id']||$matches[0]['source_system']!=='Bobinas'||$matches[0]['original_id']!==$key||$matches[0]['original_data_json']!==$json)throw new RuntimeException('Conflito de correspondência ou alteração da origem; não atualizar automaticamente.');
                    $report['unchanged'][]=$key;continue;
                }
                if($matches)throw new RuntimeException('Número ou identificador já existente sem correspondência auditada.');
                $report['ready'][]=['original_id'=>$key,'original_number'=>(string)$original['original_number'],'customer_id'=>(int)$customer['gestisser_id'],'source_system'=>'Bobinas','source_hash'=>$hash,'original_data_json'=>$json,'lines'=>$lines];
            }catch(Throwable $e){$report['conflicts'][]=['row'=>$index+1,'reason'=>$e->getMessage()];}
        }
        return $report;
    }
}
