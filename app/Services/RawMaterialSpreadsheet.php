<?php
declare(strict_types=1);

final class RawMaterialSpreadsheet
{
    public static function columns(): array
    {
        return [
            'codigo'=>'code','cod_material'=>'code','codigo_material'=>'code','codigo_materia_prima'=>'code','codigo_produto'=>'code','referencia'=>'code','code'=>'code',
            'descricao'=>'description','descricao_material'=>'description','descricao_materia_prima'=>'description','descricao_produto'=>'description','designacao'=>'description','description'=>'description',
            'categoria'=>'product_category','tipo'=>'material_type',
            'caracteristica'=>'material_feature','unidade'=>'primary_unit','largura'=>'width','gramagem'=>'grammage',
            'stock_minimo'=>'min_stock','stock_maximo'=>'max_stock','ponto_reposicao'=>'reorder_point',
            'prazo_entrega_dias'=>'lead_time_days','fornecedor_preferencial'=>'preferred_supplier',
            'preco_padrao'=>'standard_price','armazem_standard'=>'standard_warehouse',
            'localizacao_standard'=>'preferred_location','email_alerta'=>'alert_email','alertas_ativos'=>'alert_enabled',
            'controlar_lote'=>'lot_controlled','controlar_bobina'=>'roll_controlled',
            'permitir_consumo_parcial'=>'allow_partial_consumption','estado'=>'status','observacoes'=>'notes',
        ];
    }

    public static function templateColumns(): array
    {
        $headers=[];$targets=[];
        foreach (self::columns() as $header=>$target) {
            if (isset($targets[$target])) { continue; }
            $targets[$target]=true;$headers[]=$header;
        }
        return $headers;
    }

    public static function read(string $path, string $extension): array
    {
        require_once __DIR__.'/ArticleSpreadsheet.php';
        $rows=ArticleSpreadsheet::readWithColumns($path,$extension,self::columns(),['codigo','descricao']);
        $canonical=[];foreach(self::columns() as$header=>$target){if(!isset($canonical[$target]))$canonical[$target]=$header;}
        foreach($rows as$rowIndex=>$row){$normalized=[];foreach($row as$header=>$value){$normalized[$canonical[self::columns()[$header]]]=$value;}$rows[$rowIndex]=$normalized;}
        return $rows;
    }
}
