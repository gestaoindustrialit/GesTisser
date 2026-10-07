<?php
/** Exact matching against the CURRENT article catalogue; no DDL or catalogue writes. */
final class HistoricalArticleMatcher
{
    private $articles=[];
    private $refs=[];
    private $names=[];
    private $customers=[];
    public function __construct(PDO $pdo)
    {
        foreach($pdo->query('SELECT * FROM erp_customers')->fetchAll(PDO::FETCH_ASSOC) as $customer) $this->customers[(int)$customer['id']]=$customer;
        foreach($pdo->query('SELECT * FROM erp_finished_products')->fetchAll(PDO::FETCH_ASSOC) as $article) {
            $id=(int)$article['id'];$this->articles[$id]=$article;
            foreach(['code','customer_product_code','reference'] as $column) {
                $key=self::exact($article[$column]??'');
                if($key!=='') $this->refs[$key][$id]=true;
            }
            $key=self::exact($article['description']??'');
            if($key!=='') $this->names[$key][$id]=true;
        }
    }
    private static function exact($value)
    {
        // Case and whitespace only. Accents, punctuation and digits are preserved.
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$value)),'UTF-8');
    }
    public function analyze(array $rows)
    {
        $summary=array_fill_keys(['MATCH_EXACT_REF','MATCH_EXACT_NAME','MATCH_REF_AND_NAME','MULTIPLE_MATCHES','NO_MATCH'],0);
        $result=[];$seen=[];$duplicates=0;
        foreach($rows as $i=>$source) {
            $legacy=trim((string)($source['legacy_id']??''));$ref=self::exact($source['artigo_codigo']??'');$name=self::exact($source['artigo_designacao']??'');
            $reference=$this->refs[$ref]??[];$refConflict=false;$referenceSets=[];if($reference)$referenceSets[]=$reference;
            foreach(['artigo_referencia','referencia'] as $column) {$set=$this->refs[self::exact($source[$column]??'')]??[];if($set)$referenceSets[]=$set;foreach($set as $id=>$unused)$reference[$id]=true;}
            if(count($referenceSets)>1){$common=$referenceSets[0];foreach($referenceSets as $set)$common=array_intersect_key($common,$set);$refConflict=!$common;}
            $names=$this->names[$name]??[];$error='';$customerIds=[];$hasCustomer=false;
            $customerCode=self::exact($source['cliente_codigo']??'');$customerName=self::exact($source['cliente_nome']??'');$manual=(string)($source['gestisser_customer_id']??'');
            if($customerCode!=='' || $customerName!=='' || $manual!=='') {
                $hasCustomer=true;
                foreach($this->customers as $id=>$customer) {
                    if($customerCode!=='' ? self::exact($customer['code'])===$customerCode : self::exact($customer['name'])===$customerName) $customerIds[$id]=true;
                }
                if($manual!=='') {
                    if(!ctype_digit($manual) || !isset($this->customers[(int)$manual])) $error='ID de cliente inexistente.';
                    elseif(($customerCode!=='' || $customerName!=='') && !isset($customerIds[(int)$manual])) $error='ID de cliente contradiz o código/nome exato.';
                    else $customerIds=[(int)$manual=>true];
                }
                if(!$customerIds && $error==='') $error='Cliente associado não resolvido por correspondência exata.';
                if(count($customerIds)>1) $error='Cliente associado ambíguo.';
                foreach($reference as $id=>$unused) if(!isset($customerIds[(int)($this->articles[$id]['customer_id']??0)])) unset($reference[$id]);
                foreach($names as $id=>$unused) if(!isset($customerIds[(int)($this->articles[$id]['customer_id']??0)])) unset($names[$id]);
            }
            $intersection=array_intersect_key($reference,$names);$union=$reference+$names;$classification='NO_MATCH';$id=null;$automatic=false;
            if(count($intersection)===1) { $classification='MATCH_REF_AND_NAME';$id=(int)key($intersection);$automatic=true; }
            elseif(count($intersection)>1 || count($union)>1) $classification='MULTIPLE_MATCHES';
            elseif(count($reference)===1) { $classification='MATCH_EXACT_REF';$id=(int)key($reference);$automatic=true; }
            elseif(count($names)===1) { $classification='MATCH_EXACT_NAME';$id=(int)key($names);$automatic=true; }
            // Never overrule an explicitly supplied contradictory reference by name.
            if($classification==='MATCH_EXACT_NAME' && $ref!=='') { $automatic=false;$error='Nome exato, mas referência fornecida não coincide; validar manualmente.'; }
            if($classification==='MATCH_EXACT_REF' && $name!=='' && self::exact($this->articles[$id]['description'])!==$name) { $automatic=false;$error='Referência exata, mas designação fornecida diverge; validar manualmente.'; }
            if($refConflict){$classification='MULTIPLE_MATCHES';$automatic=false;$error='Referências fornecidas apontam para artigos distintos.';}
            if($error!=='') $automatic=false;
            if($legacy==='') { $automatic=false;$error='legacy_id obrigatório para rastrear o artigo antigo.'; }
            if(isset($seen[$legacy])) { $duplicates++;$automatic=false;$error='legacy_id repetido no staging; consolidar o artigo antigo.'; }
            $seen[$legacy]=true;
            $summary[$classification]++;
            $result[]=['line'=>$i+2,'source'=>$source,'classification'=>$classification,'article_id'=>$automatic?$id:null,'suggested_article_id'=>$id,'candidate_ids'=>array_map('intval',array_keys($union)),'confirmed_customer_id'=>$hasCustomer && count($customerIds)===1?(int)key($customerIds):null,'automatic'=>$automatic,'error'=>$error];
        }
        // Both copies of a repeated source ID must be exceptions.
        $frequencies=[];foreach($rows as $source){$key=(string)($source['legacy_id']??'');$frequencies[$key]=($frequencies[$key]??0)+1;}
        foreach($result as &$row) if($frequencies[(string)($row['source']['legacy_id']??'')]>1) {$row['automatic']=false;$row['article_id']=null;$row['error']='legacy_id repetido no staging; consolidar o artigo antigo.';}unset($row);
        return ['source'=>'Excel de staging; comparação com Bobinas.mdb só confirmada após extração real','rows_total'=>count($rows),'articles_total'=>count(array_filter(array_keys($seen),function($value){return (string)$value!=='';})),'duplicate_rows'=>$duplicates,'classifications'=>$summary,'confirmed'=>count(array_filter($result,function($row){return $row['automatic'];})),'exceptions'=>count(array_filter($result,function($row){return !$row['automatic'];})),'rows'=>$result];
    }
    public static function reportZip(array $report)
    {
        require_once __DIR__.'/Spreadsheet.php';
        $headers=['legacy_id','artigo_codigo','artigo_designacao','cliente_codigo','cliente_nome','gestisser_customer_id','artigo_referencia','classification','article_id','suggested_article_id','candidate_ids','automatic','erro_validacao'];
        $all=[$headers];$exceptions=[$headers];
        foreach($report['rows'] as $row) {
            $source=$row['source'];$values=[];
            foreach(array_slice($headers,0,7) as $column) $values[]=$source[$column]??'';
            $values=array_merge($values,[$row['classification'],$row['article_id']??'',$row['suggested_article_id']??'',implode(',',$row['candidate_ids']),$row['automatic']?'1':'0',$row['error']]);
            $all[]=$values;if(!$row['automatic']) $exceptions[]=$values;
        }
        $path=tempnam(sys_get_temp_dir(),'article_report_');$zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Falha ao criar relatório ZIP.');
        try {
            foreach(['correspondencias_artigos.xlsx'=>$all,'excecoes_artigos.xlsx'=>$exceptions] as $filename=>$matrix) {
                $xlsx=HistoricalSpreadsheet::workbook($matrix,'Artigos');
                try {$zip->addFromString($filename,file_get_contents($xlsx));} finally {unlink($xlsx);}
            }
            $zip->addFromString('relatorio.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$zip->close();return $path;
        } catch(Throwable $e) {$zip->close();@unlink($path);throw $e;}
    }
}
