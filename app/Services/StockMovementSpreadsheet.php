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
