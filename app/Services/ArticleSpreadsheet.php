<?php
declare(strict_types=1);

final class ArticleSpreadsheet
{
    public static function columns(): array
    {
        return [
            'codigo'=>'code','descricao'=>'description','cliente'=>'customer_name','codigo_cliente'=>'customer_product_code',
            'largura'=>'width','comprimento'=>'length','gramagem'=>'grammage','cores_face'=>'colors_per_face',
            'stock_minimo'=>'min_stock','preco_venda'=>'sale_price','referencia_prova'=>'proof_reference',
            'material'=>'material','cor_saco'=>'bag_color','composicao'=>'composition','peso_teorico'=>'theoretical_weight',
            'tolerancia_largura'=>'width_tolerance','tolerancia_comprimento'=>'length_tolerance',
            'cores_frente'=>'front_colors','cores_verso'=>'back_colors','cor_fio'=>'thread_color',
            'tipo_perfuracao'=>'perforation_type','tipo_costura'=>'seam_type','regra_lote'=>'lot_identification_rule',
            'microperfuracao'=>'microperforation','asa'=>'has_handle','furos'=>'has_holes','fole'=>'has_gusset',
            'fole_centrado'=>'centered_gusset','medida_fole'=>'gusset_length','medidas_palete'=>'pallet_dimensions',
            'tampa_palete'=>'pallet_lid','numero_fitas'=>'pallet_straps','filme_palete'=>'pallet_film',
            'analise_gramagem'=>'analysis_grammage','analise_peso_total'=>'analysis_total_weight',
            'analise_largura_aparente'=>'analysis_apparent_width','analise_largura_fole'=>'analysis_gusset_width',
            'analise_altura_saco'=>'analysis_bag_height','analise_rotura_altura'=>'analysis_break_height',
            'analise_rotura_comprimento'=>'analysis_break_length','analise_resistencia_costura'=>'analysis_seam_strength',
            'analise_friccao_estatica'=>'analysis_static_friction','analise_friccao_dinamica'=>'analysis_dynamic_friction',
            'analise_permeabilidade_ar'=>'analysis_air_permeability'
        ];
    }

    public static function read(string $path, string $extension): array
    {
        return strtolower($extension) === 'xlsx' ? self::readXlsx($path) : self::readCsv($path);
    }

    private static function readCsv(string $path): array
    {
        $handle=fopen($path,'rb'); if (!$handle) { throw new RuntimeException('Não foi possível ler o ficheiro.'); }
        $headers=fgetcsv($handle,0,';'); if ($headers && isset($headers[0])) { $headers[0]=ltrim($headers[0], "\xEF\xBB\xBF"); }
        $rows=[]; while (($row=fgetcsv($handle,0,';'))!==false) { $rows[]=$row; } fclose($handle);
        return self::combine($headers ?: [],$rows);
    }

    private static function readXlsx(string $path): array
    {
        $zip=new ZipArchive(); if ($zip->open($path)!==true) { throw new RuntimeException('O ficheiro Excel não é um .xlsx válido.'); }
        $shared=[]; $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml!==false) { $sharedXml=preg_replace('/\sxmlns="[^"]+"/','',$sharedXml,1); $xml=simplexml_load_string($sharedXml); foreach ($xml->si as $si) { $parts=[]; foreach ($si->xpath('.//t') as $text) { $parts[]=(string)$text; } $shared[]=implode('',$parts); } }
        $sheetXml=$zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
        if ($sheetXml===false) { throw new RuntimeException('A primeira folha do Excel não foi encontrada.'); }
        $sheetXml=preg_replace('/\sxmlns="[^"]+"/','',$sheetXml,1); $xml=simplexml_load_string($sheetXml); $matrix=[];
        foreach ($xml->sheetData->row as $row) { $values=[]; $sequentialIndex=0; foreach ($row->c as $cell) { preg_match('/[A-Z]+/',(string)$cell['r'],$m); $index=isset($m[0])?self::columnIndex($m[0]):$sequentialIndex; $type=(string)$cell['t']; $value=$type==='inlineStr'?(string)$cell->is->t:(string)$cell->v; if ($type==='s') { $value=$shared[(int)$value]??''; } $values[$index]=$value; $sequentialIndex=$index+1; } if ($values) { $matrix[]=array_replace(array_fill(0,max(array_keys($values))+1,''),$values); } }
        $headers=array_shift($matrix) ?: []; return self::combine($headers,$matrix);
    }

    private static function combine(array $headers, array $rows): array
    {
        $headers=array_map(static function($v){ return strtolower(trim((string)$v)); },$headers);
        if (!in_array('codigo',$headers,true) || !in_array('descricao',$headers,true)) { throw new RuntimeException('O ficheiro deve incluir as colunas codigo e descricao.'); }
        foreach ($headers as $header) { if (!isset(self::columns()[$header])) { throw new RuntimeException('Coluna desconhecida: '.$header); } }
        $result=[]; foreach ($rows as $row) { if (!array_filter($row,static function($v){return trim((string)$v)!=='';})) continue; $row=array_pad($row,count($headers),''); $result[]=array_combine($headers,array_slice($row,0,count($headers))); }
        return $result;
    }

    private static function columnIndex(string $letters): int
    {
        $value=0; foreach (str_split($letters) as $letter) { $value=$value*26+(ord($letter)-64); } return $value-1;
    }
}
