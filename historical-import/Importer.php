<?php
require_once __DIR__.'/Validator.php';
/** Explicit administrative import only. Never invoked by upload or validation. */
final class HistoricalImporter
{
    public static function setup(PDO $pdo)
    {
        $pdo->beginTransaction();
        try { $pdo->exec(file_get_contents(__DIR__.'/schema.sql')); $pdo->commit(); }
        catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    public static function configuration()
    {
        $path=dirname(__DIR__).'/storage/historical-import-config.php';
        if(!is_file($path)) return ['enabled'=>false,'reviewed_schema_hash'=>'','entities'=>[]];
        $config=require $path;
        return is_array($config)?$config:['enabled'=>false];
    }
    public static function blockers(array $report,array $config)
    {
        $reasons=[];
        if(empty($config['enabled'])) $reasons[]='Importação real desativada até validar a base atual e os destinos.';
        if(($config['reviewed_schema_hash']??'')!==$report['schema_hash']) $reasons[]='Esquema atual ainda não aprovado ou alterado desde a revisão.';
        foreach($report['destinations']??[] as $entity=>$destination) if(isset($report['entities'][$entity]) && $report['entities'][$entity]['validas']>0 && !$destination['ready']) $reasons[]='Esquema de destino incompatível: '.$entity;
        foreach($report['entities'] as $entity=>$result) if($result['validas']>0 && (!in_array($entity,$config['entities']??[],true) || !in_array($entity,['ofs','of_operacoes','rolos_rafia','lotes_tintas','movimentos_historicos'],true))) $reasons[]='Adaptador não aprovado/disponível: '.$entity;
        if($report['errors']>0) $reasons[]='Existem linhas com erros; resolver antes de importar o lote.';
        return array_unique($reasons);
    }
    public static function run(PDO $pdo,array $batch,array $previousReport,array $config,$userId,$root)
    {
        $reasons=self::blockers($previousReport,$config);
        if($reasons) throw new RuntimeException(implode(' ',$reasons));
        if(!$pdo->query("SELECT 1 FROM sqlite_master WHERE name='erp_legacy_import_map'")->fetchColumn()) throw new RuntimeException('Preparar tabelas auxiliares primeiro.');
        require_once $root.'/app/Services/BackupManager.php';
        // A real, verified snapshot is mandatory. Failure prevents all business INSERTs.
        $backup=(new BackupManager($pdo,$root))->create('historical_import',$userId);
        $batchHash=hash('sha256',json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $s=$pdo->prepare('INSERT INTO erp_legacy_import_runs(user_id,filename,entity,status,backup_path,batch_hash,schema_hash) VALUES (?,?,?,"running",?,?,?)');
        $s->execute([$userId,implode(', ',array_map(function($file){return $file['filename']??'';},$batch)),implode(',',array_keys($batch)),$backup['filename'],$batchHash,$previousReport['schema_hash']]);
        $runId=(int)$pdo->lastInsertId();$inserted=0;$skipped=0;$activeEntity='';$activeLine=0;$activeLegacy='';
        // Use SQL COMMIT/ROLLBACK consistently: PDO 7.0 does not track SQL BEGIN IMMEDIATE.
        $transactionStarted=false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');$transactionStarted=true;
            $report=(new HistoricalValidator($pdo))->validate($batch);
            $reasons=self::blockers($report,$config);
            if($reasons) throw new RuntimeException(implode(' ',$reasons));
            if($report['schema_hash']!==$previousReport['schema_hash']) throw new RuntimeException('Esquema mudou após o dry-run.');
            $resolved=[];$total=0;
            foreach(array_keys(HistoricalSpreadsheet::contracts()) as $entity) {
                if(!isset($report['entities'][$entity])) continue;
                foreach($report['entities'][$entity]['rows'] as $rowIndex=>$row) {
                    $activeEntity=$entity;$activeLine=$row['line'];$activeLegacy=$row['legacy_id'];
                    $original=$batch[$entity]['original_rows'][$rowIndex]['data']??$batch[$entity]['rows'][$rowIndex]['data'];
                    $total++;$d=$row['data'];
                    if($row['status']==='Ignorada') { $skipped++; continue; }
                    if($row['status']==='Já importado') {
                        $s=$pdo->prepare('SELECT gestisser_id FROM erp_legacy_import_map WHERE source_system="Bobinas" AND entity_type=? AND legacy_id=?');$s->execute([$entity,$row['legacy_id']]);
                        $id=(int)$s->fetchColumn();$skipped++;
                    } else {
                        list($table,$data)=self::target($entity,$row,$resolved,$userId);
                        self::validateTarget($pdo,$table,$data);
                        $columns=array_keys($data);$s=$pdo->prepare('INSERT INTO "'.$table.'" ("'.implode('","',$columns).'") VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
                        $s->execute(array_values($data));$id=(int)$pdo->lastInsertId();
                        $s=$pdo->prepare('INSERT INTO erp_legacy_import_map(source_system,entity_type,legacy_id,gestisser_id,target_table,legacy_code,legacy_document_number,import_run_id,source_hash,original_data_json) VALUES ("Bobinas",?,?,?,?,?,?,?,?,?)');
                        $s->execute([$entity,$row['legacy_id'],$id,$table,$d['identificacao_rolo']??($d['identificacao_recipiente']??null),$d['numero_of']??($d['numero_entrada']??($d['documento']??null)),$runId,$row['source_hash'],json_encode(['source'=>$original,'normalized'=>$d,'references'=>$row['refs']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$inserted++;
                    }
                    $resolved[$entity]['legacy_id'][$d['legacy_id']]=$id;
                    $natural=HistoricalSpreadsheet::contracts()[$entity]['natural'];
                    if($natural!=='' && ($d[$natural]??'')!=='') $resolved[$entity][$natural][$d[$natural]]=$id;
                    $s=$pdo->prepare('INSERT INTO erp_legacy_import_logs(import_run_id,entity_type,row_number,legacy_id,severity,message,source_hash) VALUES (?,?,?,?,?,?,?)');
                    $s->execute([$runId,$entity,$row['line'],$row['legacy_id'],'info',$row['status']==='Já importado'?'Já importado':'Inserido',$row['source_hash']]);
                }
            }
            if($pdo->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Verificação de FK falhou.');
            $s=$pdo->prepare('UPDATE erp_legacy_import_runs SET finished_at=CURRENT_TIMESTAMP,rows_total=?,rows_inserted=?,rows_skipped=?,status="success",log=? WHERE id=?');
            $s->execute([$total,$inserted,$skipped,json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$runId]);
            $pdo->exec('COMMIT');
            return ['id'=>$runId,'inserted'=>$inserted,'skipped'=>$skipped,'backup'=>$backup['filename']];
        } catch(Throwable $e) {
            if($transactionStarted) $pdo->exec('ROLLBACK');
            $s=$pdo->prepare('UPDATE erp_legacy_import_runs SET finished_at=CURRENT_TIMESTAMP,status="failed",rows_errors=1,log=? WHERE id=?');$s->execute(['ROLLBACK: '.$e->getMessage(),$runId]);
            $s=$pdo->prepare('INSERT INTO erp_legacy_import_logs(import_run_id,entity_type,row_number,legacy_id,severity,message) VALUES (?,?,?,?,?,?)');
            $s->execute([$runId,$activeEntity,$activeLine,$activeLegacy,'error','ROLLBACK: '.$e->getMessage()]);
            throw $e;
        }
    }
    private static function validateTarget(PDO $pdo,$table,array $data)
    {
        $columns=$pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
        if(!$columns) throw new RuntimeException('Tabela de destino inexistente: '.$table);
        $known=[];
        foreach($columns as $column) {
            $known[]=$column['name'];
            if($column['notnull'] && $column['dflt_value']===null && !$column['pk'] && !array_key_exists($column['name'],$data)) throw new RuntimeException('Campo obrigatório não mapeado: '.$table.'.'.$column['name']);
        }
        if(array_diff(array_keys($data),$known)) throw new RuntimeException('Esquema incompatível com adaptador: '.$table);
    }
    private static function target($entity,array $row,array $resolved,$userId)
    {
        $d=$row['data'];$r=$row['refs'];
        if($entity==='ofs') {
            // No routing, planner, reservations, product bridge creation or stock services.
            if(!in_array($d['estado'],['Concluída','Encerrada','Fechada','Cancelada'],true)) throw new RuntimeException('OF histórica exige estado final explicitamente normalizado.');
            return ['erp_production_orders',['order_number'=>$d['numero_of'],'customer_id'=>$r['customer'],'product_id'=>$r['legacy_product'],'finished_product_id'=>$r['product'],'planned_quantity'=>$d['quantidade_planeada'],'produced_quantity'=>$d['quantidade_produzida']===''?0:$d['quantidade_produzida'],'status'=>$d['estado'],'created_at'=>$d['data_criacao'],'due_date'=>null,'notes'=>$d['observacoes']??'','created_by'=>$userId]];
        }
        if($entity==='of_operacoes') {
            $parent=$r['ofs'];$of=$parent['id']??($resolved['ofs'][$parent['batch_field']][$parent['value']]??null);
            if(!$of) throw new RuntimeException('OF pai não resolvida dentro da transação.');
            if(!in_array($d['estado'],['Concluída','Cancelada'],true)) throw new RuntimeException('Operação histórica exige estado final.');
            return ['erp_production_order_operations',['production_order_id'=>$of,'operation_id'=>$r['operation'],'sequence_no'=>$d['sequencia'],'planned_minutes'=>(int)round(($d['horas_previstas']===''?0:$d['horas_previstas'])*60),'status'=>$d['estado']]];
        }
        if(in_array($entity,['rolos_rafia','lotes_tintas'],true)) {
            $data=['raw_material_id'=>$r['raw_material'],'entry_number'=>$d['numero_entrada'],'supplier_lot'=>$d['lote_fornecedor'],'weight_kg'=>$d['peso_atual_kg'],'initial_weight_kg'=>$d['peso_inicial_kg'],'barcode'=>$d['barcode'],'label_date'=>$d['data_entrada'],'validated_by'=>$userId,'status'=>$d['estado']?:'AVAILABLE','warehouse_id'=>$r['warehouse_id']??null,'location_id'=>$r['location_id']??null];
            if($entity==='rolos_rafia') { $data['metres']=$d['metros_atuais'];$data['initial_metres']=$d['metros_iniciais']; }
            return [$entity==='rolos_rafia'?'erp_raw_material_roll_labels':'erp_raw_material_ink_labels',$data];
        }
        if($entity==='movimentos_historicos') {
            if($d['afeta_stock_atual']!=='0') throw new RuntimeException('Alteração de stock bloqueada.');
            return ['erp_stock_movements',['movement_number'=>'H-BOBINAS-'.strtoupper(hash('sha256',$d['legacy_id'])),'movement_date'=>$d['data_movimento'],'movement_type'=>$d['tipo_movimento'],'item_type'=>'raw_material','item_id'=>$r['raw_material'],'quantity'=>$d['quantidade'],'weight'=>$d['peso_kg']===''?0:$d['peso_kg'],'lot'=>$d['lote']??'','source_type'=>'Bobinas_documental','reason'=>'Histórico documental — não afeta stock atual','notes'=>$d['observacoes']??'','created_by'=>$userId]];
        }
        throw new RuntimeException('Destino ainda não confirmado para '.$entity.'.');
    }
}
