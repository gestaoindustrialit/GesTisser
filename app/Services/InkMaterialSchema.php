<?php
/** Small independent migration: no supplier seed data and no changes to material IDs. */
final class InkMaterialSchema
{
    public static function ensure(PDO $pdo)
    {
        $columns=[];foreach($pdo->query('PRAGMA table_info(erp_raw_materials)')->fetchAll(PDO::FETCH_ASSOC) as $column)$columns[$column['name']]=true;
        $definitions=['is_ink'=>'INTEGER NOT NULL DEFAULT 0','ink_color'=>'TEXT','ink_pantone'=>'TEXT','legacy_ink_id'=>'TEXT'];
        if(count(array_intersect_key($columns,$definitions))===count($definitions))return;
        $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{
            foreach($definitions as $name=>$definition)if(!isset($columns[$name]))$pdo->exec('ALTER TABLE erp_raw_materials ADD COLUMN '.$name.' '.$definition);
            $pdo->exec('UPDATE erp_raw_materials SET is_ink=1 WHERE is_water_based_ink=1 OR is_solvent_based_ink=1 OR ink_type_id IS NOT NULL');
            $pdo->exec('UPDATE erp_raw_materials SET ink_color=COALESCE((SELECT name FROM erp_colors WHERE id=erp_raw_materials.color_id),description) WHERE is_ink=1 AND COALESCE(ink_color,"")=""');
            $pdo->exec('UPDATE erp_raw_materials SET ink_pantone=(SELECT pantone FROM erp_colors WHERE id=erp_raw_materials.color_id) WHERE is_ink=1 AND COALESCE(ink_pantone,"")=""');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_raw_material_legacy_ink ON erp_raw_materials(legacy_ink_id)');
            if($ownsTransaction)$pdo->commit();
        }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
