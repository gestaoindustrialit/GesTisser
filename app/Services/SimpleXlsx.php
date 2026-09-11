<?php
declare(strict_types=1);

/** Creates a small, dependency-free XLSX workbook from a matrix of values. */
final class SimpleXlsx
{
    public static function create(array $rows, string $sheetName = 'Folha1'): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão ZipArchive é necessária para gerar o ficheiro Excel.');
        $sheetRows = '';
        foreach ($rows as $rowNumber => $row) {
            $cells = '';
            foreach (array_values($row) as $columnNumber => $value) {
                $reference = self::columnName($columnNumber + 1) . ($rowNumber + 1);
                $escaped = htmlspecialchars((string)($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
            }
            $sheetRows .= '<row r="'.($rowNumber + 1).'">'.$cells.'</row>';
        }
        $sheetName = htmlspecialchars(function_exists('mb_substr') ? mb_substr($sheetName, 0, 31) : substr($sheetName, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $files = [
            '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];
        $path = tempnam(sys_get_temp_dir(), 'gestisser_xlsx_');
        if ($path === false) throw new RuntimeException('Não foi possível preparar o ficheiro Excel.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($path); throw new RuntimeException('Não foi possível criar o ficheiro Excel.'); }
        foreach ($files as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
        return $path;
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) { $number--; $name = chr(65 + ($number % 26)).$name; $number = intdiv($number, 26); }
        return $name;
    }
}
