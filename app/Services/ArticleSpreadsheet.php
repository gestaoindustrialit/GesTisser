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
        return self::readWithColumns($path, $extension, self::columns(), ['codigo', 'descricao']);
    }

    public static function readWithColumns(string $path, string $extension, array $columns, array $required): array
    {
        $matrices = strtolower($extension) === 'xlsx' ? self::readXlsxMatrices($path) : [self::readCsvMatrix($path)];
        $diagnostics=[];
        foreach ($matrices as $matrix) {
            $known=[];$widths=[];foreach(array_slice($matrix,0,20) as $row){$widths[]=count($row);foreach($row as $value){$header=self::normalizeHeader($value);if(isset($columns[$header])){$known[$header]=true;}}}$diagnostics[]=['rows'=>count($matrix),'sample_widths'=>$widths,'recognized_headers'=>array_keys($known)];
            $headerIndex = self::findHeaderRow($matrix, $columns, $required);
            if ($headerIndex !== null) {
                $headers = $matrix[$headerIndex];
                $matrix = array_slice($matrix, $headerIndex + 1);
                return self::combine($headers, $matrix, $columns, $required);
            }
        }
        if (function_exists('safe_log')) { safe_log('Spreadsheet header not found',['extension'=>strtolower($extension),'required'=>$required,'sheets'=>$diagnostics]); }
        throw new RuntimeException('O ficheiro deve incluir as colunas '.implode(' e ',$required).'.');
    }

    private static function readCsvMatrix(string $path): array
    {
        $contents=file_get_contents($path);if($contents===false){throw new RuntimeException('Não foi possível ler o ficheiro.');}$contents=self::csvToUtf8($contents);
        $handle=fopen('php://temp','w+b');if(!$handle){throw new RuntimeException('Não foi possível preparar o ficheiro para leitura.');}fwrite($handle,$contents);rewind($handle);
        $sample=[]; while (count($sample)<20 && ($line=fgets($handle))!==false) { $sample[]=$line; } if (!$sample) { fclose($handle); return []; }
        $delimiter=';'; $bestCount=0; foreach ([';', ',', "\t"] as $candidate) { $count=0;foreach($sample as $line){$count=max($count,count(str_getcsv($line,$candidate)));}if($count>$bestCount){$bestCount=$count;$delimiter=$candidate;} }
        rewind($handle); $headers=fgetcsv($handle,0,$delimiter); if ($headers && isset($headers[0])) { $headers[0]=ltrim($headers[0], "\xEF\xBB\xBF"); }
        $rows=[]; while (($row=fgetcsv($handle,0,$delimiter))!==false) { $rows[]=$row; } fclose($handle);
        array_unshift($rows, $headers ?: []); return $rows;
    }

    private static function csvToUtf8(string $contents): string
    {
        $encoding='';
        if (substr($contents,0,2)==="\xFF\xFE") {$encoding='UTF-16LE';$contents=substr($contents,2);}
        elseif(substr($contents,0,2)==="\xFE\xFF"){$encoding='UTF-16BE';$contents=substr($contents,2);}
        elseif(substr($contents,0,3)==="\xEF\xBB\xBF") { return substr($contents,3); }
        elseif(strpos(substr($contents,0,200),"\0")!==false){$even=$odd=0;$sample=substr($contents,0,200);for($i=0,$length=strlen($sample);$i<$length;$i++){if($sample[$i]==="\0"){if($i%2===0)$even++;else$odd++;}}$encoding=$odd>=$even?'UTF-16LE':'UTF-16BE';}
        // Excel on Windows commonly saves a plain "CSV (Comma delimited)" in
        // Windows-1252 without a BOM. Accented headings such as Código and
        // Descrição are then invalid UTF-8 and used to normalize to c_digo and
        // descri_o, preventing the required columns from being found.
        if($encoding===''&&preg_match('//u',$contents)===1){return $contents;}
        if($encoding===''){$encoding='Windows-1252';}
        if(function_exists('mb_convert_encoding')){return mb_convert_encoding($contents,'UTF-8',$encoding);}
        if(function_exists('iconv')){$converted=iconv($encoding,'UTF-8//IGNORE',$contents);if($converted!==false)return $converted;}
        throw new RuntimeException('O CSV está em '.$encoding.', mas o servidor não possui suporte para converter a codificação.');
    }

    private static function readXlsxMatrices(string $path): array
    {
        $zip=new ZipArchive(); if ($zip->open($path)!==true) { throw new RuntimeException('O ficheiro Excel não é um .xlsx válido.'); }
        $shared=[]; $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml!==false) { $xml=simplexml_load_string(self::normalizeSpreadsheetXml($sharedXml)); if ($xml!==false) { foreach ($xml->si as $si) { $parts=[]; foreach ($si->xpath('.//t') as $text) { $parts[]=(string)$text; } $shared[]=implode('',$parts); } } }
        $matrices=[]; foreach (self::worksheetPaths($zip) as $sheetPath) { $sheetXml=$zip->getFromName($sheetPath); if ($sheetXml===false) { continue; }
            $xml=simplexml_load_string(self::normalizeSpreadsheetXml($sheetXml)); if ($xml===false) { continue; } $matrix=[];
            foreach ($xml->sheetData->row as $row) { $values=[];$sequentialIndex=0;foreach ($row->c as $cell) { preg_match('/[A-Z]+/i',(string)$cell['r'],$m);$index=isset($m[0])?self::columnIndex(strtoupper($m[0])):$sequentialIndex;$type=(string)$cell['t'];$value=$type==='inlineStr'?self::inlineString($cell):(string)$cell->v;if($type==='s'){$value=$shared[(int)$value]??'';}$values[$index]=$value;$sequentialIndex=$index+1;}if($values){$matrix[]=array_replace(array_fill(0,max(array_keys($values))+1,''),$values);} }
            $matrices[]=$matrix;
        } $zip->close(); if (!$matrices) { throw new RuntimeException('Não foram encontradas folhas no ficheiro Excel.'); } return $matrices;
    }

    private static function inlineString(SimpleXMLElement $cell): string
    {
        $parts=[];
        foreach ($cell->xpath('.//t') ?: [] as $text) { $parts[]=(string)$text; }
        return implode('', $parts);
    }

    private static function worksheetPaths(ZipArchive $zip): array
    {
        $workbookXml=$zip->getFromName('xl/workbook.xml');
        $relationshipsXml=$zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml===false || $relationshipsXml===false) { return self::archiveWorksheetPaths($zip); }

        $workbook=simplexml_load_string(self::normalizeSpreadsheetXml($workbookXml));
        $relationships=simplexml_load_string(self::normalizeSpreadsheetXml($relationshipsXml));
        if ($workbook===false || $relationships===false) { return self::archiveWorksheetPaths($zip); }
        $targets=[]; foreach ($relationships->Relationship as $relationship) { $targets[(string)$relationship['Id']]=(string)$relationship['Target']; }
        $paths=[]; foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $relationshipId=(string)($sheet['id'] ?? '');
            $target=str_replace('\\','/',$targets[$relationshipId]??''); if ($target==='') { continue; }
            if ($target[0]==='/') { $paths[]=ltrim($target,'/'); continue; }
            while (strpos($target,'../')===0) { $target=substr($target,3); }
            $paths[]=strpos($target,'xl/')===0?$target:'xl/'.$target;
        }
        // Some spreadsheet generators omit or damage the workbook relationship
        // metadata.  The worksheet XML is still usable, so also inspect every
        // worksheet stored in the archive instead of silently missing its header.
        foreach (self::archiveWorksheetPaths($zip) as $entry) { if (!in_array($entry,$paths,true)) { $paths[]=$entry; } }
        return $paths ?: ['xl/worksheets/sheet1.xml'];
    }

    private static function normalizeSpreadsheetXml(string $xml): string
    {
        if (substr($xml,0,3)==="\xEF\xBB\xBF") { $xml=substr($xml,3); }
        // SimpleXML exposes prefixed spreadsheet elements through their namespace,
        // while the reader deliberately uses the namespace-neutral OOXML names.
        $xml=(string)preg_replace('/<(\/?)\s*[A-Za-z_][A-Za-z0-9_.-]*:([A-Za-z_][A-Za-z0-9_.-]*)/','<$1$2',$xml);
        $xml=(string)preg_replace('/\s+xmlns(?::[A-Za-z_][A-Za-z0-9_.-]*)?\s*=\s*(["\'])[^"\']*\1/','',$xml);
        // Namespace declarations have gone, so attributes such as r:id and
        // x14ac:dyDescent must also be made namespace-neutral for valid XML.
        return (string)preg_replace('/(\s)[A-Za-z_][A-Za-z0-9_.-]*:([A-Za-z_][A-Za-z0-9_.-]*)(\s*=)/','$1$2$3',$xml);
    }

    private static function archiveWorksheetPaths(ZipArchive $zip): array
    {
        $paths=[];for ($index=0; $index<$zip->numFiles; $index++) {$entry=$zip->getNameIndex($index);if($entry!==false&&preg_match('#^xl/worksheets/[^/]+\.xml$#i',$entry)){$paths[]=$entry;}}
        return $paths ?: ['xl/worksheets/sheet1.xml'];
    }

    private static function combine(array $headers, array $rows, array $columns, array $required): array
    {
        $headers=array_map([self::class, 'normalizeHeader'], $headers);
        foreach ($required as $header) { $target=$columns[$header];$found=false;foreach($headers as $candidate){if(isset($columns[$candidate])&&$columns[$candidate]===$target){$found=true;break;}}if(!$found){throw new RuntimeException('O ficheiro deve incluir as colunas '.implode(' e ',$required).'.');} }
        foreach ($headers as $header) { if ($header!==''&&!isset($columns[$header])) { throw new RuntimeException('Coluna desconhecida: '.$header); } }
        $result=[]; foreach ($rows as $row) { if (!array_filter($row,static function($v){return trim((string)$v)!=='';})) continue;$combined=[];$mappedTargets=[];foreach($headers as $index=>$header){if($header==='')continue;$target=$columns[$header];if(isset($mappedTargets[$target]))continue;$mappedTargets[$target]=true;$value=$row[$index]??null;$combined[$header]=trim((string)$value)===''?null:(string)$value;}$result[]=$combined; }
        return $result;
    }

    /** @return int|null */
    private static function findHeaderRow(array $matrix, array $columns, array $required)
    {
        foreach ($matrix as $index => $row) {
            $headers=array_map([self::class, 'normalizeHeader'], $row);
            $mapped=[];foreach($headers as $header){if(isset($columns[$header])){$mapped[]=$columns[$header];}}
            $requiredTargets=[];foreach($required as $header){$requiredTargets[]=$columns[$header];}
            if (!array_diff($requiredTargets, $mapped)) { return $index; }
        }
        return null;
    }

    private static function normalizeHeader($value): string
    {
        $header=trim(str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], ['', ' '], (string)$value));
        $header=strtr($header, [
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ç'=>'C','ç'=>'c',
        ]);
        $header=strtolower($header);
        return trim((string)preg_replace('/[^a-z0-9]+/','_',$header),'_');
    }

    private static function columnIndex(string $letters): int
    {
        $value=0; foreach (str_split($letters) as $letter) { $value=$value*26+(ord($letter)-64); } return $value-1;
    }
}
