<?php
/** A strict XLSX boundary, isolated from the other spreadsheet importers. PHP 7.0. */
final class HistoricalSpreadsheet
{
    const MAX_BYTES = 20971520;
    const MAX_EXPANDED = 67108864;
    const MAX_ROWS = 100000;

    public static function contracts()
    {
        $contracts = require __DIR__.'/contracts.php';
        foreach ($contracts as &$contract) {
            $contract['columns'] = explode(' ', $contract['columns']);
            $contract['required'] = explode(' ', $contract['required']);
        }
        unset($contract);
        return $contracts;
    }

    public static function workbook(array $rows,$name)
    {
        require_once dirname(__DIR__).'/app/Services/SimpleXlsx.php';
        $path=SimpleXlsx::create($rows,$name);$zip=new ZipArchive();
        try {
            if($zip->open($path)!==true) throw new RuntimeException('Falha ao abrir Excel.');
            $xml=$zip->getFromName('xl/worksheets/sheet1.xml');$width=count($rows[0]);
            $xml=str_replace('<sheetData>','<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.$width.'" width="24" customWidth="1"/></cols><sheetData>',$xml);
            $xml=str_replace('</worksheet>','<autoFilter ref="A1:'.self::columnName($width).max(2,count($rows)).'"/></worksheet>',$xml);
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();return $path;
        } catch(Throwable $e){$zip->close();@unlink($path);throw $e;}
    }
    public static function articleTemplate()
    {
        return self::workbook([['legacy_id','artigo_codigo','artigo_designacao','cliente_codigo','cliente_nome','gestisser_customer_id','artigo_referencia']], 'Staging artigos');
    }
    public static function templateZip()
    {
        require_once dirname(__DIR__).'/app/Services/SimpleXlsx.php';
        $path = tempnam(sys_get_temp_dir(), 'legacy_templates_');
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Falha ao criar ZIP.');
        try {
            foreach (self::contracts() as $entity => $contract) {
                $xlsx = SimpleXlsx::create([$contract['columns']], $contract['label']);
                try {
                    $zip = new ZipArchive();
                    if ($zip->open($xlsx) !== true) throw new RuntimeException('Falha ao abrir template.');
                    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                    $last = self::columnName(count($contract['columns']));
                    $views = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.count($contract['columns']).'" width="24" customWidth="1"/></cols>';
                    $xml = str_replace('<sheetData>', $views.'<sheetData>', $xml);
                    $xml = str_replace('</worksheet>', '<autoFilter ref="A1:'.$last.'1048576"/></worksheet>', $xml);
                    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
                    $zip->close();
                    $archive->addFromString($contract['file'], file_get_contents($xlsx));
                } finally { @unlink($xlsx); }
            }
            $archive->addFromString('LEIA-ME.txt', "Manter os cabeçalhos. IDs/códigos como TEXTO. Datas AAAA-MM-DD. Decimais com ponto ou vírgula, sem separadores de milhares. importar=1 para selecionar; vazio ou 0 ignora. afeta_stock_atual vazio/0. Sem fórmulas. Não renumerar legacy_id. Uma folha por ficheiro. Não preencher linhas de exemplo.\n");
            $archive->close();
            return $path;
        } catch (Throwable $e) { $archive->close(); @unlink($path); throw $e; }
    }

    private static function columnName($n)
    {
        $name = '';
        while ($n > 0) { $n--; $name = chr(65+$n%26).$name; $n = intdiv($n,26); }
        return $name;
    }

    public static function uploaded(array $file)
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Upload inválido ou excedeu os limites do servidor.');
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Apenas .xlsx é aceite.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/x-zip'], true)) throw new RuntimeException('MIME do Excel inválido.');
        return self::read($file['tmp_name']);
    }

    private static function xml($contents)
    {
        if ($contents === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) throw new RuntimeException('XML ausente ou inseguro.');
        $old = libxml_use_internal_errors(true);
        $entities = libxml_disable_entity_loader(true);
        try {
            $xml = simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml === false) throw new RuntimeException('XML inválido.');
            return $xml;
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($old); libxml_disable_entity_loader($entities);
        }
    }

    public static function read($path)
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) throw new RuntimeException('Limite de 20 MB por ficheiro.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('XLSX inválido.');
        try {
            if ($zip->numFiles > 1000) throw new RuntimeException('Demasiadas entradas no XLSX.');
            $size = 0; $sheets = [];
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $name = $stat['name']; $size += $stat['size'];
                if ($size > self::MAX_EXPANDED || strpos($name,'..') !== false || $name[0] === '/' || strpos($name,'\\') !== false) throw new RuntimeException('Arquivo excede limites ou contém caminhos inseguros.');
                if (preg_match('/vbaProject|externalLinks|embeddings|\.bin$/i',$name)) throw new RuntimeException('Macros, objetos e ligações externas não são aceites.');
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) $sheets[] = $name;
                // All XML is checked, even files the parser does not consume.
                if (preg_match('/\.(xml|rels)$/i',$name)) self::xml($zip->getFromIndex($i));
            }
            if (count($sheets) !== 1) throw new RuntimeException('Cada ficheiro deve conter exatamente uma folha.');
            $workbook = self::xml($zip->getFromName('xl/workbook.xml'));
            if ($workbook->xpath('//*[local-name()="workbookPr" and (@date1904="1" or @date1904="true")] ')) throw new RuntimeException('Datas Excel 1904 não suportadas; converter para AAAA-MM-DD.');
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                foreach (self::xml($sharedXml)->xpath('//*[local-name()="si"]') as $item) {
                    $value = ''; foreach ($item->xpath('.//*[local-name()="t"]') as $part) $value .= (string)$part;
                    $shared[] = $value;
                }
            }
            $xml = self::xml($zip->getFromName($sheets[0]));
            $headers = null; $rows = []; $entity = null; $lastLine = 0;
            foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $line = (int)$row['r'];
                if ($line <= $lastLine || $line > 1048576) throw new RuntimeException('Números de linha inválidos.');
                $lastLine = $line; $values = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if ($cell->xpath('./*[local-name()="f"]')) throw new RuntimeException('Fórmula na linha '.$line.'; substituir pelo valor.');
                    if (!preg_match('/^([A-Z]{1,3})([0-9]+)$/',(string)$cell['r'],$m) || (int)$m[2] !== $line) throw new RuntimeException('Referência de célula inválida.');
                    $n = 0; foreach (str_split($m[1]) as $char) $n = $n*26+ord($char)-64;
                    if ($n > 100 || isset($values[$n-1])) throw new RuntimeException('Coluna fora dos limites ou repetida.');
                    $type = (string)$cell['t']; $value = '';
                    if ($type === 'inlineStr') { foreach ($cell->xpath('.//*[local-name()="t"]') as $text) $value .= (string)$text; }
                    else { $v = $cell->xpath('./*[local-name()="v"]'); $value = $v ? (string)$v[0] : ''; }
                    if ($type === 's') { if (!ctype_digit($value) || !isset($shared[(int)$value])) throw new RuntimeException('Texto partilhado inválido.'); $value = $shared[(int)$value]; }
                    if ($type === 'e') throw new RuntimeException('Erro Excel na linha '.$line.'.');
                    if (strlen($value)>32767) throw new RuntimeException('Célula demasiado longa.');
                    $values[$n-1] = trim($value);
                }
                if (!$values || !array_filter($values, function($v) { return $v !== ''; })) continue;
                if ($headers === null) {
                    if ($line !== 1) throw new RuntimeException('Cabeçalhos devem estar na primeira linha.');
                    $headers = array_replace(array_fill(0,max(array_keys($values))+1,''),$values);
                    if (count(array_unique($headers)) !== count($headers) || in_array('', $headers,true)) throw new RuntimeException('Cabeçalhos vazios ou repetidos.');
                    if($headers===['legacy_id','artigo_codigo','artigo_designacao','cliente_codigo','cliente_nome','gestisser_customer_id','artigo_referencia']) $entity='article_matching';
                    foreach (self::contracts() as $key=>$contract) {
                        // Optional matching fields can be added by the external normalizer.
                        $allowed = array_merge($contract['columns'], ['cliente_nif','fornecedor_nif','artigo_referencia','materia_prima_referencia']);
                        if (!array_diff($contract['columns'],$headers) && !array_diff($headers,$allowed)) { $entity = $key; break; }
                    }
                    if ($entity === null) throw new RuntimeException('Cabeçalhos não correspondem a nenhum template; manter todas as colunas.');
                    continue;
                }
                if (max(array_keys($values)) >= count($headers)) throw new RuntimeException('Dados fora das colunas na linha '.$line.'.');
                $data = []; foreach ($headers as $i=>$header) $data[$header] = $values[$i] ?? '';
                $rows[] = ['line'=>$line,'data'=>$data];
                if (count($rows)>self::MAX_ROWS) throw new RuntimeException('Limite de 100000 linhas por ficheiro.');
            }
            if ($headers === null) throw new RuntimeException('Folha sem cabeçalhos.');
            return ['entity'=>$entity, 'rows'=>$rows];
        } finally { $zip->close(); }
    }
}
