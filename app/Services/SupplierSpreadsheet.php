<?php
declare(strict_types=1);

final class SupplierSpreadsheet
{
    public static function columns(): array
    {
        return [
            'codigo'=>'code','nome'=>'name','nif'=>'tax_number','ncontrib'=>'tax_number','pais'=>'country',
            'morada1'=>'address','morada_1'=>'address','morada2'=>'address_2','morada_2'=>'address_2',
            'codpostal'=>'postal_code','codigo_postal'=>'postal_code','telefone'=>'phone','telemovel'=>'mobile',
            'email'=>'email','contacto'=>'contact_name','vendedor'=>'salesperson','site'=>'website',
            'desconto'=>'discount_percent','plafond'=>'credit_limit','certificado_pefc'=>'pefc_certified',
            'controlo_stocks_pedidos'=>'stock_order_control','incluir_osaft'=>'include_osaf',
            'obs'=>'notes','observacoes'=>'notes','ativo'=>'is_active'
        ];
    }

    public static function read(string $path, string $extension): array
    {
        require_once __DIR__.'/ArticleSpreadsheet.php';
        if (strtolower($extension)==='xls') {
            $path=self::convertSpreadsheetXmlToCsv($path);
            try { return ArticleSpreadsheet::readWithColumns($path,'csv',self::columns(),['codigo','nome']); }
            finally { @unlink($path); }
        }
        return ArticleSpreadsheet::readWithColumns($path,$extension,self::columns(),['codigo','nome']);
    }

    public static function templateColumns(): array
    {
        return ['codigo','nome','nif','pais','morada_1','morada_2','codigo_postal','telefone','telemovel','email','contacto','vendedor','site','desconto','plafond','certificado_pefc','controlo_stocks_pedidos','incluir_osaft','observacoes','ativo'];
    }

    private static function convertSpreadsheetXmlToCsv(string $source): string
    {
        $xml=simplexml_load_file($source);
        if($xml===false){throw new RuntimeException('O ficheiro .xls não é um modelo Excel XML válido.');}
        $xml->registerXPathNamespace('ss','urn:schemas-microsoft-com:office:spreadsheet');
        $rows=$xml->xpath('//ss:Worksheet/ss:Table/ss:Row');
        if(!$rows){throw new RuntimeException('O ficheiro .xls não contém uma folha de dados.');}
        $tmp=tempnam(sys_get_temp_dir(),'suppliers_xls_');$handle=fopen($tmp,'wb');
        foreach($rows as $row){$values=[];foreach($row->xpath('./ss:Cell') as $cell){$data=$cell->xpath('./ss:Data');$values[]=(string)($data[0]??'');}fputcsv($handle,$values,';');}
        fclose($handle);return $tmp;
    }
}
