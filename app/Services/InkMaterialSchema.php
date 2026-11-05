<?php
require_once __DIR__.'/InkMaterialFields.php';
/** Reconcile only ink metadata, preserving the existing type catalogue, material IDs and stock. */
final class InkMaterialSchema
{
    const VERSION='20261105-ink-integration-2';
    public static function ensure(PDO $pdo)
    {
        $exists=$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='erp_ink_material_schema'")->fetchColumn();
        if($exists){$q=$pdo->prepare('SELECT 1 FROM erp_ink_material_schema WHERE version=?');$q->execute([self::VERSION]);if($q->fetchColumn())return;}
        $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{
            $columns=[];foreach($pdo->query('PRAGMA table_info(erp_raw_materials)')->fetchAll(PDO::FETCH_ASSOC) as $column)$columns[$column['name']]=true;
            foreach(['is_ink'=>'INTEGER NOT NULL DEFAULT 0','ink_color'=>'TEXT','ink_pantone'=>'TEXT','legacy_ink_id'=>'TEXT'] as $name=>$definition)if(!isset($columns[$name]))$pdo->exec('ALTER TABLE erp_raw_materials ADD COLUMN '.$name.' '.$definition);
            $pdo->exec('CREATE TABLE IF NOT EXISTS erp_ink_material_schema(version TEXT PRIMARY KEY,applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS erp_ink_material_reconciliation_log(id INTEGER PRIMARY KEY,material_id INTEGER NOT NULL,field_name TEXT NOT NULL,previous_value TEXT,derived_value TEXT,status TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            $types=[];$codes=[];foreach($pdo->query('SELECT * FROM erp_ink_types')->fetchAll(PDO::FETCH_ASSOC) as $type){$types[(int)$type['id']]=$type;$codes[strtoupper($type['code'])]=(int)$type['id'];}
            $rows=$pdo->query('SELECT r.*,f.description feature_description,t.code material_type_code,c.name registered_color,c.pantone registered_pantone FROM erp_raw_materials r LEFT JOIN erp_material_features f ON f.id=r.material_feature_id LEFT JOIN erp_material_types t ON t.id=r.material_type_id LEFT JOIN erp_colors c ON c.id=r.color_id')->fetchAll(PDO::FETCH_ASSOC);
            $log=$pdo->prepare('INSERT INTO erp_ink_material_reconciliation_log(material_id,field_name,previous_value,derived_value,status) VALUES(?,?,?,?,?)');
            foreach($rows as $row){
                $feature=InkMaterialFields::featureValues((string)$row['feature_description']);
                $isInk=!empty($row['is_ink'])||!empty($row['ink_type_id'])||!empty($row['is_water_based_ink'])||!empty($row['is_solvent_based_ink'])||in_array(strtoupper(trim((string)$row['material_type_code'])),['TINTA','TINTAS','INK'],true)||$feature;
                if(!$isInk)continue;$changes=[];if(!(int)$row['is_ink'])$changes['is_ink']=1;
                $typeId=(int)$row['ink_type_id'];
                if(!$typeId){$water=!empty($row['is_water_based_ink']);$solvent=!empty($row['is_solvent_based_ink']);if($water!==$solvent){$typeId=$codes[$water?'AGUA':'SOLVENTE']??0;if($typeId)$changes['ink_type_id']=$typeId;}elseif($water&&$solvent)$log->execute([(int)$row['id'],'ink_type_id','Água e solvente assinalados','Selecionar um tipo de tinta existente','pending']);}
                if(isset($types[$typeId]))foreach(['is_water_based_ink'=>'AGUA','is_solvent_based_ink'=>'SOLVENTE'] as $field=>$code){$value=strtoupper($types[$typeId]['code'])===$code?1:0;if((int)$row[$field]!==$value)$changes[$field]=$value;}
                foreach(['ink_color'=>'registered_color','ink_pantone'=>'registered_pantone'] as $field=>$fallback){
                    $derived=trim((string)($feature[$field]??$row[$fallback]??''));$current=trim((string)$row[$field]);if($derived==='')continue;
                    if($current===''||($field==='ink_color'&&$current===trim((string)$row['description'])&&isset($feature[$field])))$changes[$field]=$derived;
                    elseif(isset($feature[$field])&&strcasecmp($current,$derived)!==0)$log->execute([(int)$row['id'],$field,$current,$derived,'pending']);
                }
                foreach($changes as $field=>$value){if((string)($row[$field]??'')===(string)$value)continue;$pdo->prepare('UPDATE erp_raw_materials SET '.$field.'=? WHERE id=?')->execute([$value,(int)$row['id']]);$log->execute([(int)$row['id'],$field,(string)($row[$field]??''),(string)$value,'applied']);}
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_raw_material_legacy_ink ON erp_raw_materials(legacy_ink_id)');
            $pdo->prepare('INSERT INTO erp_ink_material_schema(version) VALUES(?)')->execute([self::VERSION]);
            if($ownsTransaction)$pdo->commit();
        }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public static function resolved(PDO $pdo,int $id)
    {
        $pdo->prepare('UPDATE erp_ink_material_reconciliation_log SET status="resolved" WHERE material_id=? AND status="pending"')->execute([$id]);
    }
}
