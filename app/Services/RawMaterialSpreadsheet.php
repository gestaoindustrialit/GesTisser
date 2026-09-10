<?php
declare(strict_types=1);

final class RawMaterialSpreadsheet
{
    // Visibility on class constants and nullable return types require PHP 7.1.
    // Production installations can still run PHP 7.0, so keep this definition
    // compatible with the same PHP baseline as the existing spreadsheet reader.
    const PRODUCT_GROUPS = [
        'raw_material'=>'Materia Prima', 'subsidiary'=>'Subsidiario',
        'finished_product'=>'Produto Acabado', 'merchandise'=>'Mercadoria',
        'packaging'=>'Embalagem', 'other'=>'Outro',
    ];

    // Keep the model contract explicit. Import-only aliases in columns() must
    // never alter or remove business fields from the downloadable spreadsheet.
    // In particular, grupo_produto is required to classify each reference for
    // later stock, purchasing and production workflows.
    const TEMPLATE_COLUMNS = [
        'codigo','descricao','grupo_produto','categoria','tipo','caracteristica','unidade','largura','gramagem',
        'stock_minimo','stock_maximo','ponto_reposicao','prazo_entrega_dias','fornecedor_preferencial',
        'preco_padrao','armazem_standard','localizacao_standard','email_alerta','alertas_ativos','controlar_lote',
        'controlar_bobina','permitir_consumo_parcial','estado','observacoes',
    ];

    public static function columns(): array
    {
        return [
            'codigo'=>'code','cod_material'=>'code','codigo_material'=>'code','codigo_materia_prima'=>'code','codigo_produto'=>'code','referencia'=>'code','code'=>'code',
            'descricao'=>'description','descricao_material'=>'description','descricao_materia_prima'=>'description','descricao_produto'=>'description','designacao'=>'description','description'=>'description',
            'grupo_produto'=>'product_category','categoria'=>'legacy_product_category','tipo'=>'material_type',
            'caracteristica'=>'material_feature','unidade'=>'primary_unit','largura'=>'width','gramagem'=>'grammage',
            'stock_minimo'=>'min_stock','stock_maximo'=>'max_stock','ponto_reposicao'=>'reorder_point',
            'prazo_entrega_dias'=>'lead_time_days','fornecedor_preferencial'=>'preferred_supplier',
            'preco_padrao'=>'standard_price','armazem_standard'=>'standard_warehouse',
            'localizacao_standard'=>'preferred_location','email_alerta'=>'alert_email','alertas_ativos'=>'alert_enabled',
            'controlar_lote'=>'lot_controlled','controlar_bobina'=>'roll_controlled',
            'permitir_consumo_parcial'=>'allow_partial_consumption','estado'=>'status','observacoes'=>'notes',
        ];
    }

    /** Headers shown in the model, excluding aliases accepted only on import. */
    public static function templateColumns(): array
    {
        return self::TEMPLATE_COLUMNS;
    }

    public static function read(string $path, string $extension): array
    {
        require_once __DIR__.'/ArticleSpreadsheet.php';
        $rows=ArticleSpreadsheet::readWithColumns($path,$extension,self::columns(),['codigo','descricao']);
        $canonical=[];foreach(self::columns() as$header=>$target){if(!isset($canonical[$target]))$canonical[$target]=$header;}
        foreach($rows as$rowIndex=>$row){$normalized=[];foreach($row as$header=>$value){$normalized[$canonical[self::columns()[$header]]]=$value;}$rows[$rowIndex]=$normalized;}
        return $rows;
    }

    public static function productGroups(): array { return self::PRODUCT_GROUPS; }

    public static function normalizeProductGroup($value)
    {
        $value=trim((string)$value);if($value==='')return null;
        $value=strtr($value,['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','á'=>'a','à'=>'a','â'=>'a','ã'=>'a','É'=>'E','Ê'=>'E','é'=>'e','ê'=>'e','Í'=>'I','í'=>'i','Ó'=>'O','Ô'=>'O','Õ'=>'O','ó'=>'o','ô'=>'o','õ'=>'o','Ú'=>'U','ú'=>'u','Ç'=>'C','ç'=>'c']);
        $key=trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower($value)),'_');
        $aliases=['materia_prima'=>'raw_material','raw_material'=>'raw_material','subsidiario'=>'subsidiary','subsidiary'=>'subsidiary','produto_acabado'=>'finished_product','finished_product'=>'finished_product','mercadoria'=>'merchandise','merchandise'=>'merchandise','embalagem'=>'packaging','packaging'=>'packaging','outro'=>'other','other'=>'other','consumivel'=>'consumable','consumable'=>'consumable'];
        return $aliases[$key]??null;
    }

    public static function productGroupLabel($group): string { return self::PRODUCT_GROUPS[$group]??($group==='consumable'?'Consumível (legado)':''); }
}
