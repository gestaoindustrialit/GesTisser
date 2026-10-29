<?php
declare(strict_types=1);
require_once __DIR__.'/SimpleXlsx.php';
require_once __DIR__.'/ArticleSpreadsheet.php';

/** Spreadsheet intake only; approval remains an explicit AssistService transition. */
final class AssistKnowledgeSpreadsheet
{
    const MAX_BYTES = 5242880;
    const MAX_ROWS = 200;
    public static function columns(): array
    {
        return ['titulo'=>'title','categoria'=>'category_name','idioma'=>'language','descricao'=>'description',
            'sintomas'=>'symptoms','causas'=>'causes','condicoes'=>'conditions','procedimento'=>'procedure',
            'seguranca'=>'safety','materiais_ferramentas'=>'tools','resultado_esperado'=>'expected',
            'verificacao'=>'verification','palavras_chave'=>'keywords','sinonimos'=>'synonyms',
            'equipamento'=>'equipment','material'=>'material','operacao'=>'operation',
            'validador_id'=>'validator_id','revisao_ate'=>'expires_on'];
    }
    public static function template(array $categories): string
    {
        $path=SimpleXlsx::create([array_keys(self::columns()),array_fill(0,count(self::columns()),'')],'Conhecimento');
        $instructions=[['SACO','Sistema de Apoio a Conhecimento Operacional'],['Preenchimento','Uma linha por artigo; preencher na folha Conhecimento.'],
            ['Obrigatório','titulo, categoria e procedimento. Não alterar os nomes das colunas.'],
            ['Categoria','Copiar o nome de uma categoria autorizada da folha Categorias.'],
            ['Idioma','pt (predefinição), en ou hi. O painel de chat usa português.'],
            ['Procedimento','Colocar passos internos conhecidos, um por linha dentro da célula. Não inventar dados.'],
            ['Opcionais','Restantes campos podem ficar vazios. validador_id é o ID do utilizador no ERP.'],
            ['Revisão','revisao_ate: AAAA-MM-DD (texto) ou data Excel.'],
            ['Limites','Até 200 artigos e 5 MB por ficheiro. Não usar fórmulas, macros ou ligações externas.'],
            ['Validação','O upload permite pré-visualizar. Confirmar cria apenas rascunhos, nunca aprova nem publica.'],
            ['Repetições','Não substitui artigos existentes. Título repetido na mesma categoria/idioma cancela o lote.'],
            ['Modelo vazio','Não contém exemplos técnicos nem conhecimento pré-aprovado.']];
        $categoryRows=[['categoria','categoria_superior']];$names=[];
        foreach($categories as $c)$names[(int)$c['id']]=$c['name'];
        foreach($categories as $c)$categoryRows[]=[$c['name'],$names[(int)$c['parent_id']]??''];
        $extra=[];
        try {
            // Reuse the existing writer for every sheet; retain its inline-string safety.
            foreach([2=>$instructions,3=>$categoryRows] as $number=>$rows){
                $tmp=SimpleXlsx::create($rows);$extra[]=$tmp;$sheet=new ZipArchive();
                if($sheet->open($tmp)!==true)throw new RuntimeException('Não foi possível preparar o modelo.');
                $xml=$sheet->getFromName('xl/worksheets/sheet1.xml');$sheet->close();
                $extra['xml'.$number]=$xml;
            }
            $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Não foi possível preparar o modelo.');
            try {
                
                $main=$zip->getFromName('xl/worksheets/sheet1.xml');
                $columns='<cols>';foreach(array_keys(self::columns()) as $i=>$name){$width=in_array($name,['procedimento','descricao','seguranca'],true)?55:25;$columns.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';}$columns.='</cols>';
                $zip->addFromString('xl/worksheets/sheet1.xml',str_replace('<sheetData>','<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'.$columns.'<sheetData>',$main));
                $zip->addFromString('xl/worksheets/sheet2.xml',$extra['xml2']);$zip->addFromString('xl/worksheets/sheet3.xml',$extra['xml3']);
                $workbook=$zip->getFromName('xl/workbook.xml');$rels=$zip->getFromName('xl/_rels/workbook.xml.rels');$types=$zip->getFromName('[Content_Types].xml');
                $zip->addFromString('xl/workbook.xml',str_replace('</sheets>','<sheet name="Instrucoes" sheetId="2" r:id="rId2"/><sheet name="Categorias" sheetId="3" r:id="rId3"/></sheets>',$workbook));
                $relationships='';$overrides='';foreach([2,3] as $n){
                    $relationships.='<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                    $overrides.='<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                }
                $zip->addFromString('xl/_rels/workbook.xml.rels',str_replace('</Relationships>',$relationships.'</Relationships>',$rels));
                $zip->addFromString('[Content_Types].xml',str_replace('</Types>',$overrides.'</Types>',$types));
            } finally { $zip->close(); }
            return $path;
        } catch(Throwable $e){@unlink($path);throw $e;}
        finally {foreach($extra as $key=>$tmp)if(is_int($key))@unlink($tmp);}
    }
    public static function read(string $path,string $extension,array $categories): array
    {
        if(!is_file($path) || filesize($path)<=0 || filesize($path)>self::MAX_BYTES)throw new InvalidArgumentException('O ficheiro deve ter até 5 MB.');
        $extension=strtolower($extension);
        if(!in_array($extension,['xlsx','csv'],true))throw new InvalidArgumentException('Use o modelo .xlsx ou um CSV UTF-8.');
        $date1904=false;
        if($extension==='xlsx')$date1904=self::inspectXlsx($path);
        else {
            $text=file_get_contents($path);
            if(strpos($text,"\0")!==false || preg_match('//u',$text)!==1 || substr_count($text,"\n")>5000)throw new InvalidArgumentException('Use CSV UTF-8 até 5000 linhas de texto.');
        }
        $previousErrors=null;$previousEntities=null;
        if($extension==='xlsx'){$previousErrors=libxml_use_internal_errors(true);$previousEntities=libxml_disable_entity_loader(true);}
        try { $records=ArticleSpreadsheet::readWithColumns($path,$extension,self::columns(),['titulo','categoria','procedimento']); }
        catch(RuntimeException $e){throw new InvalidArgumentException($e->getMessage());}
        finally {if($extension==='xlsx'){libxml_clear_errors();libxml_use_internal_errors($previousErrors);libxml_disable_entity_loader($previousEntities);}}
        if(!$records)throw new InvalidArgumentException('O ficheiro não contém artigos. Preencha a folha Conhecimento do modelo.');
        if(count($records)>self::MAX_ROWS)throw new InvalidArgumentException('O limite é 200 artigos por importação.');
        $result=[];$total=0;
        foreach($records as $index=>$record){
            $data=[];foreach(self::columns() as $column=>$field)$data[$field]=trim((string)($record[$column]??''));
            $categoryName=$data['category_name'];$matches=[];
            foreach($categories as $category){if($category['name']===$categoryName){$matches=[$category];break;}if(AssistService::normalize($category['name'])===AssistService::normalize($categoryName))$matches[]=$category;}
            $prefix='Registo '.($index+1).': ';
            if(count($matches)!==1)throw new InvalidArgumentException($prefix.'categoria não encontrada ou ambígua. Copie o nome da folha Categorias do seu modelo.');
            $data['category_id']=(int)$matches[0]['id'];$data['category_name']=$matches[0]['name'];
            if($data['title']===''||strlen($data['title'])>250||$data['procedure']==='')throw new InvalidArgumentException($prefix.'preencha título (até 250 caracteres) e procedimento.');
            if($data['language']==='')$data['language']='pt';
            if(!in_array($data['language'],['pt','en','hi'],true))throw new InvalidArgumentException($prefix.'idioma deve ser pt, en ou hi.');
            foreach(AssistService::FIELDS as $field)if(strlen($data[$field])>30000)throw new InvalidArgumentException($prefix.'campo demasiado extenso: '.$field.'.');
            if($data['validator_id']!==''&&!preg_match('/^[1-9][0-9]*$/D',$data['validator_id']))throw new InvalidArgumentException($prefix.'validador_id deve ser um ID de utilizador.');
            $expiry=$data['expires_on'];
            if(preg_match('/^[0-9]+$/D',$expiry)){
                $serial=(int)$expiry;
                if($serial<61||$serial>2957000)throw new InvalidArgumentException($prefix.'data Excel fora do intervalo.');
                $base=new DateTimeImmutable($date1904?'1904-01-01':'1899-12-30',new DateTimeZone('UTC'));
                $expiry=$base->modify('+'.$serial.' days')->format('Y-m-d');
            }
            if($expiry!==''&&(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$expiry)||!checkdate((int)substr($expiry,5,2),(int)substr($expiry,8,2),(int)substr($expiry,0,4))))throw new InvalidArgumentException($prefix.'revisao_ate deve ser uma data AAAA-MM-DD ou data Excel válida.');
            $data['expires_on']=$expiry;
            $total+=strlen(json_encode($data,JSON_UNESCAPED_UNICODE));if($total>2097152)throw new InvalidArgumentException('O conteúdo dos artigos ultrapassa 2 MB. Divida o ficheiro.');
            $result[]=$data;
        }
        return $result;
    }
    private static function inspectXlsx(string $path): bool
    {
        if(!class_exists('ZipArchive')||!function_exists('simplexml_load_string'))throw new RuntimeException('O servidor precisa das extensões ZIP e SimpleXML para importar Excel. Em alternativa, use CSV UTF-8.');
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('O ficheiro não é um Excel .xlsx válido.');
        $size=0;$rowCount=0;$cellCount=0;$date1904=false;
        $previousErrors=libxml_use_internal_errors(true);$previousEntities=libxml_disable_entity_loader(true);
        try {
            if($zip->numFiles>200)throw new InvalidArgumentException('O Excel tem demasiadas entradas. Use o modelo fornecido.');
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$name=$stat['name'];$size+=(int)$stat['size'];
                if((int)$stat['size']>4194304||$size>16777216)throw new InvalidArgumentException('O Excel descomprimido é demasiado extenso.');
                if(strpos($name,'..')!==false||preg_match('#(^/|\\\\|vbaProject|externalLinks)#i',$name))throw new InvalidArgumentException('Use um Excel sem macros nem ligações externas.');
                if(!preg_match('/\.(xml|rels)$/i',$name))continue;
                $body=$zip->getFromIndex($i);
                if($body===false||strpos($body,"\0")!==false||preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i',$body))throw new InvalidArgumentException('O Excel contém XML não permitido.');
                $xml=simplexml_load_string($body,'SimpleXMLElement',LIBXML_NONET);
                if($xml===false)throw new InvalidArgumentException('O Excel contém XML inválido.');
                if($xml->xpath('//*[local-name()="f"]')||$xml->xpath('//*[@TargetMode="External" or @TargetMode="external"]'))throw new InvalidArgumentException('Remova fórmulas e ligações externas; importe apenas texto e valores.');
                if($name==='xl/workbook.xml')foreach($xml->xpath('//*[local-name()="workbookPr"]') as $pr)$date1904=in_array((string)$pr['date1904'],['1','true'],true);
                $rowCount+=count($xml->xpath('//*[local-name()="row"]'));
                if($rowCount>1000)throw new InvalidArgumentException('O Excel tem demasiadas linhas. Use o modelo fornecido.');
                foreach($xml->xpath('//*[local-name()="c"]') as $cell){
                    if(++$cellCount>30000)throw new InvalidArgumentException('O Excel tem demasiadas células.');
                    $ref=(string)$cell['r'];
                    if($ref==='')continue;
                    if(!preg_match('/^([A-Z]{1,2})([1-9][0-9]{0,6})$/iD',$ref,$match))throw new InvalidArgumentException('Referência de célula inválida.');
                    $column=0;foreach(str_split(strtoupper($match[1])) as $letter)$column=$column*26+ord($letter)-64;
                    if($column>64)throw new InvalidArgumentException('O Excel tem colunas fora do modelo.');
                }
            }
            return $date1904;
        } finally {$zip->close();libxml_clear_errors();libxml_use_internal_errors($previousErrors);libxml_disable_entity_loader($previousEntities);}
    }
}
