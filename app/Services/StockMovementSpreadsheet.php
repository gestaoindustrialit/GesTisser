<?php
declare(strict_types=1);

/** Spreadsheet contract for importing historical raw-material stock movements. */
final class StockMovementSpreadsheet
{
    const TEMPLATE_COLUMNS = ['numero_movimento','data','codigo_artigo','movimento','quantidade','armazem','localizacao','lote','custo_unitario','motivo','observacoes'];

    public static function columns(): array
    {
        return [
            'numero_movimento'=>'movement_number','numero'=>'movement_number','documento'=>'movement_number','movement_number'=>'movement_number',
            'data'=>'movement_date','data_movimento'=>'movement_date','movement_date'=>'movement_date',
            'codigo_artigo'=>'item_code','codigo_material'=>'item_code','codigo_materia_prima'=>'item_code','codigo'=>'item_code','referencia'=>'item_code','item_code'=>'item_code',
            'movimento'=>'movement_type','tipo_movimento'=>'movement_type','tipo'=>'movement_type','movement_type'=>'movement_type',
            'quantidade'=>'quantity','qtd'=>'quantity','quantity'=>'quantity',
            'armazem'=>'warehouse','codigo_armazem'=>'warehouse','warehouse'=>'warehouse',
            'localizacao'=>'location','posicao'=>'location','location'=>'location',
            'lote'=>'lot','lot'=>'lot','custo_unitario'=>'unit_cost','preco_unitario'=>'unit_cost','unit_cost'=>'unit_cost',
            'motivo'=>'reason','reason'=>'reason','observacoes'=>'notes','notas'=>'notes','notes'=>'notes',
        ];
    }

    public static function templateColumns(): array { return self::TEMPLATE_COLUMNS; }

    public static function read(string $path,string $extension): array
    {
        require_once __DIR__.'/ArticleSpreadsheet.php';
        $rows=ArticleSpreadsheet::readWithColumns($path,$extension,self::columns(),['codigo_artigo','quantidade']);
        $canonical=[];foreach(self::columns() as$header=>$target){if(!isset($canonical[$target]))$canonical[$target]=$header;}
        foreach($rows as$rowIndex=>$row){$normalized=[];foreach($row as$header=>$value){$normalized[$canonical[self::columns()[$header]]]=$value;}$rows[$rowIndex]=$normalized;}
        return$rows;
    }
}


// The importer intentionally lives with the spreadsheet contract. Some older
// deployments do not contain the former standalone service file.
/** Applies stock movement rows to the immutable ledger and current balances. */
final class StockMovementImportService
{
    public static function import(PDO $pdo,array $rows,int $userId): array
    {
        $result=['imported'=>0,'skipped'=>0,'general'=>0];
        foreach($rows as $index=>$row){
            $line=$index+2;$code=trim((string)($row['codigo_artigo']??''));
            $item=$pdo->prepare('SELECT id,standard_warehouse_id FROM erp_raw_materials WHERE code=? COLLATE NOCASE AND product_category IN ("raw_material","subsidiary","consumable") LIMIT 1');
            $item->execute([$code]);$material=$item->fetch(PDO::FETCH_ASSOC);
            if(!$material)throw new RuntimeException('Linha '.$line.': matéria-prima ou tinta não encontrada: '.$code.'.');
            $quantity=self::number($row['quantidade']??'');if($quantity==0.0)throw new RuntimeException('Linha '.$line.': a quantidade não pode ser zero.');
            $kind=self::movementKind((string)($row['movimento']??''),$quantity);$quantity=$kind==='Saída'?-abs($quantity):abs($quantity);
            $external=trim((string)($row['numero_movimento']??''));
            if($external!==''){$exists=$pdo->prepare('SELECT 1 FROM erp_stock_movements WHERE movement_number=?');$exists->execute([$external]);if($exists->fetchColumn()){$result['skipped']++;continue;}}
            list($warehouseId,$locationId,$usedGeneral)=self::destination($pdo,$row,(int)($material['standard_warehouse_id']??0));if($usedGeneral)$result['general']++;
            $lot=trim((string)($row['lote']??''));$cost=max(0,self::number($row['custo_unitario']??0));
            $balance=$pdo->prepare('SELECT physical_qty,reserved_qty,blocked_qty FROM erp_stock_balances WHERE item_type="raw_material" AND item_id=? AND warehouse_id=? AND location_id=? AND COALESCE(lot,"")=?');
            $balance->execute([(int)$material['id'],$warehouseId,$locationId,$lot]);$current=$balance->fetch(PDO::FETCH_ASSOC);$physical=(float)($current['physical_qty']??0)+$quantity;
            $allowNegative=$pdo->query("SELECT value FROM erp_settings WHERE key='allow_negative_stock'")->fetchColumn()==='1';
            if($physical-(float)($current['reserved_qty']??0)-(float)($current['blocked_qty']??0)<0&&!$allowNegative)throw new RuntimeException('Linha '.$line.': o movimento deixaria o stock de '.$code.' negativo.');
            if($current){$update=$pdo->prepare('UPDATE erp_stock_balances SET physical_qty=?,updated_at=CURRENT_TIMESTAMP WHERE item_type="raw_material" AND item_id=? AND warehouse_id=? AND location_id=? AND COALESCE(lot,"")=?');$update->execute([$physical,(int)$material['id'],$warehouseId,$locationId,$lot]);}
            else{$pdo->prepare('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES ("raw_material",?,?,?,?,?)')->execute([(int)$material['id'],$warehouseId,$locationId,$lot,$physical]);}
            $number=$external!==''?$external:'IMP-'.date('YmdHis').'-'.$userId.'-'.($index+1).'-'.substr(sha1($code.microtime(true)),0,6);
            $date=self::date((string)($row['data']??''),$line);
            $insert=$pdo->prepare('INSERT INTO erp_stock_movements(movement_number,movement_date,movement_type,item_type,item_id,lot,quantity,warehouse_from_id,location_from_id,warehouse_to_id,location_to_id,unit_cost,total_cost,source_type,reason,notes,created_by) VALUES (?,?,?,"raw_material",?,?,?,?,?,?,?,?,?,"spreadsheet_import",?,?,?)');
            $insert->execute([$number,$date,$kind,(int)$material['id'],$lot,$quantity,$kind==='Saída'?$warehouseId:null,$kind==='Saída'?$locationId:null,$kind==='Entrada'?$warehouseId:null,$kind==='Entrada'?$locationId:null,$cost,$quantity*$cost,trim((string)($row['motivo']??'')),trim((string)($row['observacoes']??'')),$userId]);
            $result['imported']++;
        }
        return$result;
    }

    private static function destination(PDO $pdo,array $row,int $standardWarehouse): array
    {
        $warehouseText=trim((string)($row['armazem']??''));$usedGeneral=false;$warehouseId=0;
        if($warehouseText!==''){$find=$pdo->prepare('SELECT id FROM erp_warehouses WHERE code=? COLLATE NOCASE OR name=? COLLATE NOCASE LIMIT 1');$find->execute([$warehouseText,$warehouseText]);$warehouseId=(int)$find->fetchColumn();if(!$warehouseId)throw new RuntimeException('Armazém não encontrado: '.$warehouseText.'.');}
        elseif($standardWarehouse>0)$warehouseId=$standardWarehouse;
        else{$usedGeneral=true;$find=$pdo->query('SELECT id FROM erp_warehouses WHERE code="GERAL" COLLATE NOCASE OR name="Geral" COLLATE NOCASE ORDER BY id LIMIT 1');$warehouseId=(int)$find->fetchColumn();if(!$warehouseId){$pdo->exec('INSERT INTO erp_warehouses(code,name,location,is_active) VALUES ("GERAL","Geral","",1)');$warehouseId=(int)$pdo->lastInsertId();}}
        $locationText=trim((string)($row['localizacao']??''));if($locationText===''){$locationText='GERAL';$usedGeneral=true;}
        $find=$pdo->prepare('SELECT id FROM erp_locations WHERE warehouse_id=? AND code=? COLLATE NOCASE LIMIT 1');$find->execute([$warehouseId,$locationText]);$locationId=(int)$find->fetchColumn();
        if(!$locationId&&strcasecmp($locationText,'GERAL')===0){$pdo->prepare('INSERT INTO erp_locations(warehouse_id,code,description,is_active) VALUES (?,"GERAL","Localização geral",1)')->execute([$warehouseId]);$locationId=(int)$pdo->lastInsertId();}
        if(!$locationId)throw new RuntimeException('Localização não encontrada no armazém indicado: '.$locationText.'.');return[$warehouseId,$locationId,$usedGeneral];
    }

    private static function number($value): float {$value=str_replace([' ','€'],['',''],trim((string)$value));if(strpos($value,',')!==false)$value=str_replace(['.',','],['','.'],$value);if($value===''||!is_numeric($value))throw new RuntimeException('Quantidade ou custo com formato inválido: '.(string)$value.'.');return(float)$value;}
    private static function movementKind(string $value,float $quantity): string {$key=strtolower(trim($value));if($key==='')return$quantity<0?'Saída':'Entrada';if(in_array($key,['entrada','entry','in','e'],true))return'Entrada';if(in_array($key,['saida','saída','exit','out','s'],true))return'Saída';throw new RuntimeException('Tipo de movimento inválido: '.$value.'.');}
    private static function date(string $value,int $line): string {if(trim($value)==='')return date('Y-m-d H:i:s');$value=trim($value);$date=DateTime::createFromFormat('d/m/Y H:i:s',$value)?:DateTime::createFromFormat('d/m/Y',$value);if(!$date){$stamp=strtotime($value);if($stamp===false)throw new RuntimeException('Linha '.$line.': data de movimento inválida.');return date('Y-m-d H:i:s',$stamp);}return$date->format('Y-m-d H:i:s');}
}
