<?php
require_once __DIR__.'/SalesOrderService.php';
require_once __DIR__.'/SalesOrderConfirmationPdf.php';
/** Explicit issuance: caller owns authorization, CSRF and the database transaction. */
final class SalesOrderConfirmation
{
    const TYPE='Confirmação de encomenda';
    const MODEL='DOC-COM-001';
    const DEFAULT_TERMS='';
    private $pdo;
    private $uploads;
    public function __construct(PDO $pdo,string $uploads){$this->pdo=$pdo;$this->uploads=rtrim($uploads,'/\\');}
    public static function company(PDO $pdo,string $root): array
    {
        $company=[];foreach(['name','address','email','phone','tax_number','fax'] as $key)$company[$key]=(string)app_setting($pdo,'company_'.$key,$key==='name'?'TISSER':'');
        $company['terms']=(string)app_setting($pdo,'sales_order_confirmation_terms',self::DEFAULT_TERMS);
        $company['logo_data']='';$configured=(string)app_setting($pdo,'logo_report_dark','');
        $path=$configured!==''?realpath($root.'/'.ltrim($configured,'/')):false;$realRoot=realpath($root);
        if($path&&$realRoot&&strpos($path,$realRoot.DIRECTORY_SEPARATOR)===0&&is_file($path)&&filesize($path)<=4*1024*1024&&in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['png','jpg','jpeg','webp'],true)){
            $bytes=file_get_contents($path);if(is_string($bytes))$company['logo_data']='data:image/'.pathinfo($path,PATHINFO_EXTENSION).';base64,'.base64_encode($bytes);
        }
        return $company;
    }
    public function issue(int $orderId,int $revision,int $userId,array $company,string $issuer): array
    {
        if(!$this->pdo->inTransaction())throw new RuntimeException('A emissão deve ser transacional.');
        $service=new SalesOrderService($this->pdo);$order=$service->order($orderId);
        if(!$order)throw new RuntimeException('Encomenda não encontrada.');
        if($revision!==(int)$order['revision'])throw new RuntimeException('A encomenda foi alterada entretanto. Reabra a ficha antes de emitir.');
        if($order['source_system']!=='GesTISSER'||$order['original_id']!==null)throw new RuntimeException('Não é possível emitir uma nova confirmação para esta encomenda histórica.');
        $lines=$service->all('SELECT * FROM erp_sales_order_lines WHERE sales_order_id=? ORDER BY line_number',[$orderId]);
        if(!$lines)throw new RuntimeException('A encomenda não tem artigos para emitir.');
        $customer=$service->row('SELECT * FROM erp_customers WHERE id=?',[(int)$order['customer_id']])?:[];
        foreach($lines as &$line){$article=$service->row('SELECT * FROM erp_finished_products WHERE id=?',[(int)$line['finished_product_id']])?:[];$line['technical_description']=self::technicalDescription($article);}unset($line);
        // Existing sequence service reserves the number atomically; rollback preserves it.
        $this->pdo->prepare('INSERT OR IGNORE INTO erp_number_sequences(code,prefix,next_number,padding) VALUES (?, ?,1,5)')->execute(['sales_order_confirmation','CONF-']);
        $number=NumberSequenceService::take($this->pdo,'sales_order_confirmation');
        $snapshot=['number'=>$number,'model'=>self::MODEL,'issued_at'=>date('Y-m-d H:i:s'),'issuer'=>$issuer,'currency'=>'EUR','company'=>$company,'customer'=>$customer,'order'=>$order,'lines'=>$lines];
        $pdf=SalesOrderConfirmationPdf::render($snapshot);
        $directory=$this->uploads.'/sales-orders/'.$orderId.'/confirmations';
        if(!is_dir($directory)&&!@mkdir($directory,0750,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível preparar a pasta de confirmações.');
        $fileName=preg_replace('/[^A-Za-z0-9._-]/','_',$number).'.pdf';$path=$directory.'/'.$fileName;
        $handle=@fopen($path,'xb');if(!$handle)throw new RuntimeException('Não foi possível criar o PDF. Verifique a pasta de documentos.');
        try{
            $offset=0;$length=strlen($pdf);while($offset<$length){$written=fwrite($handle,substr($pdf,$offset));if(!$written)throw new RuntimeException('Não foi possível escrever o PDF completo.');$offset+=$written;}
            fclose($handle);$handle=null;@chmod($path,0640);
            unset($snapshot['company']['logo_data']);$snapshot['sha256']=hash('sha256',$pdf);
            $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Não foi possível guardar os dados da confirmação.');
            $url='storage/uploads/sales-orders/'.$orderId.'/confirmations/'.$fileName;
            $this->pdo->prepare('INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title,file_url,version,author_user_id,status,notes) VALUES("sales_order",?,?,?,?,?, ?,"Ativo",?)')->execute([$orderId,self::TYPE,'Confirmação '.$number.' — '.$order['order_number'],$url,$number,$userId,$json]);
            $documentId=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO erp_audit_log(user_id,action,entity,entity_id,new_values_json) VALUES (?,"issue_confirmation","erp_sales_orders",?,?)')->execute([$userId,$orderId,json_encode(['document_id'=>$documentId,'number'=>$number,'revision'=>$revision],JSON_UNESCAPED_UNICODE)]);
            // Register the model in the existing catalogue when installed; no new schema.
            if($service->row('SELECT 1 FROM sqlite_master WHERE type="table" AND name="erp_document_catalog"'))$this->pdo->prepare('INSERT OR IGNORE INTO erp_document_catalog(code,document_number,name,module,output_format,generation_route) VALUES(?,?,?,?,?,?)')->execute(['sales_order_confirmation',self::MODEL,self::TYPE,'Comercial','PDF','erp.php?page=sales_orders&tab=documents']);
            return ['id'=>$documentId,'number'=>$number,'path'=>$path,'pdf'=>$pdf];
        }catch(Throwable $e){if(is_resource($handle))fclose($handle);@unlink($path);throw $e;}
    }
    private static function technicalDescription(array $article): string
    {
        $parts=[];foreach(['composition'=>'Composição','material'=>'Material','bag_color'=>'Cor','grammage'=>'Gramagem','colors_per_face'=>'Cores por face'] as $key=>$label)if(isset($article[$key])&&trim((string)$article[$key])!=='')$parts[]=$label.': '.$article[$key].($key==='grammage'?' g/m²':'');
        foreach(['has_gusset'=>['Com fole','Sem fole'],'microperforation'=>['Com microperfuração','Sem microperfuração']] as $key=>$labels)if(isset($article[$key]))$parts[]=$labels[(int)$article[$key]?0:1];
        return implode(' · ',$parts);
    }
}
