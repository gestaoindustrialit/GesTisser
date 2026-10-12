<?php
require_once __DIR__.'/SalesOrderService.php';
require_once __DIR__.'/SalesOrderConfirmationPrint.php';
/** Explicit issuance: caller owns authorization, CSRF and the database transaction. */
final class SalesOrderConfirmation
{
    const TYPE='Confirmação de encomenda';
    const MODEL='DOC-COM-001';
    const TEMPLATE_VERSION=2;
    const DEFAULT_TERMS="As quantidades produzidas podem variar (±) 3% na totalidade da encomenda.\nPodem verificar-se variações na largura e/ou comprimento de (±) 10 mm.";
    private $pdo;
    private $uploads;
    public function __construct(PDO $pdo,string $uploads){$this->pdo=$pdo;$this->uploads=rtrim($uploads,'/\\');}
    public static function company(PDO $pdo,string $root): array
    {
        $company=[];foreach(['name','address','email','phone','tax_number','fax'] as $key)$company[$key]=(string)app_setting($pdo,'company_'.$key,$key==='name'?'TISSER':'');
        $company['terms']=trim((string)app_setting($pdo,'sales_order_confirmation_terms',self::DEFAULT_TERMS))?:self::DEFAULT_TERMS;
        $configured=trim((string)app_setting($pdo,'logo_report_dark',''));
        if($configured==='')$configured=trim((string)app_setting($pdo,'logo_navbar_light',''));
        $company['logo_data']=self::logoData($configured,$root);
        return $company;
    }
    /** Resolve branding locally, including URLs retained from another installation. */
    public static function logoData(string $configured,string $root): string
    {
        $configured=trim($configured);if($configured==='')return '';
        if(preg_match('#^data:image/[^;]+;base64,#',$configured))return $configured;
        $urlPath=parse_url($configured,PHP_URL_PATH);
        $normalized=str_replace('\\','/',rawurldecode(is_string($urlPath)?$urlPath:$configured));
        $rootPath=realpath($root);if(!$rootPath)return '';
        $candidates=[$rootPath.'/'.ltrim($normalized,'/'),$normalized];
        $assets=strpos($normalized,'assets/');if($assets!==false)$candidates[]=$rootPath.'/'.substr($normalized,$assets);
        $basename=basename($normalized);if($basename!==''&&$basename!=='.'&&$basename!=='..')$candidates[]=$rootPath.'/assets/uploads/'.$basename;
        foreach(array_unique($candidates) as $candidate){
            $path=realpath($candidate);
            if(!$path||strpos($path,$rootPath.DIRECTORY_SEPARATOR)!==0||!is_file($path)||!is_readable($path)||filesize($path)>4*1024*1024)continue;
            $bytes=@file_get_contents($path);if(!is_string($bytes)||$bytes==='')continue;
            $info=@getimagesizefromstring($bytes);$mime=is_array($info)?(string)($info['mime']??''):'';
            if($mime===''&&strtolower(pathinfo($path,PATHINFO_EXTENSION))==='svg')$mime='image/svg+xml';
            if(!in_array($mime,['image/png','image/jpeg','image/webp','image/svg+xml'],true))continue;
            $data='data:'.$mime.';base64,'.base64_encode($bytes);
            return $data;
        }
        // The technical sheet and accompanying sheet retain configured HTTP URLs.
        // The browser displays them directly when a local copy is unavailable.
        return preg_match('#^https?://#i',$configured)?$configured:'';
    }
    public function issue(int $orderId,int $revision,int $userId,array $company,string $issuer): array
    {
        if(!$this->pdo->inTransaction())throw new RuntimeException('A emissão deve ser transacional.');
        $service=new SalesOrderService($this->pdo);$order=$service->order($orderId);
        if(!$order)throw new RuntimeException('Encomenda não encontrada.');
        // Reuse one indexed document and its number; refresh after saved order changes.
        $existing=$service->row('SELECT * FROM erp_product_documents WHERE entity_type="sales_order" AND entity_id=? AND document_type=? ORDER BY id LIMIT 1',[$orderId,self::TYPE]);
        $metadata=$existing?(json_decode((string)$existing['notes'],true)?:[]):[];
        if($existing&&(int)($metadata['order']['revision']??0)===(int)$order['revision']&&(int)($metadata['template_version']??0)===self::TEMPLATE_VERSION){
            $prefix='storage/uploads/';$url=(string)$existing['file_url'];
            if(strpos($url,$prefix)!==0)throw new RuntimeException('O documento arquivado não está disponível.');
            $root=realpath($this->uploads);$path=realpath($this->uploads.'/'.substr($url,strlen($prefix)));
            if(!$root||!$path||strpos($path,$root.DIRECTORY_SEPARATOR)!==0||!is_file($path))throw new RuntimeException('O documento arquivado não está disponível.');
            $metadata=json_decode((string)$existing['notes'],true);$content=file_get_contents($path);
            if(!is_string($content)||!is_string($metadata['sha256']??null)||!hash_equals($metadata['sha256'],hash('sha256',$content)))throw new RuntimeException('O documento arquivado não corresponde à confirmação emitida.');
            return ['id'=>(int)$existing['id'],'number'=>$existing['version'],'path'=>$path,'content'=>$content,'mime'=>strtolower(pathinfo($path,PATHINFO_EXTENSION))==='pdf'?'application/pdf':'text/html; charset=UTF-8','existing'=>true,'created_file'=>false];
        }
        if($revision!==(int)$order['revision'])throw new RuntimeException('A encomenda foi alterada entretanto. Reabra a ficha antes de emitir.');
        if($order['source_system']!=='GesTISSER'||$order['original_id']!==null)throw new RuntimeException('Não é possível emitir uma nova confirmação para esta encomenda histórica.');
        $lines=$service->all('SELECT * FROM erp_sales_order_lines WHERE sales_order_id=? ORDER BY line_number',[$orderId]);
        if(!$lines)throw new RuntimeException('A encomenda não tem artigos para emitir.');
        $customer=$service->row('SELECT * FROM erp_customers WHERE id=?',[(int)$order['customer_id']])?:[];
        foreach($lines as &$line){$article=$service->row('SELECT * FROM erp_finished_products WHERE id=?',[(int)$line['finished_product_id']])?:[];$line['technical_description']=self::technicalDescription($article);}unset($line);
        if($existing){$number=(string)$existing['version'];if($number==='')throw new RuntimeException('A confirmação existente não tem número registado.');}
        else{
            $this->pdo->prepare('INSERT OR IGNORE INTO erp_number_sequences(code,prefix,next_number,padding) VALUES (?, ?,1,5)')->execute(['sales_order_confirmation','CONF-']);
            $number=NumberSequenceService::take($this->pdo,'sales_order_confirmation');
            if($service->row('SELECT id FROM erp_product_documents WHERE entity_type="sales_order" AND document_type=? AND version=? LIMIT 1',[self::TYPE,$number]))throw new RuntimeException('Este número de confirmação já foi utilizado. Altere o próximo número na Configuração ERP.');
        }
        $snapshot=['number'=>$number,'model'=>self::MODEL,'template_version'=>self::TEMPLATE_VERSION,'issued_at'=>$metadata['issued_at']??date('Y-m-d H:i:s'),'issuer'=>$issuer,'currency'=>'EUR','company'=>$company,'customer'=>$customer,'order'=>$order,'lines'=>$lines];
        $content=SalesOrderConfirmationPrint::render($snapshot);
        $directory=$this->uploads.'/sales-orders/'.$orderId.'/confirmations';
        if(!is_dir($directory)&&!@mkdir($directory,0750,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível preparar a pasta de confirmações.');
        $fileName=preg_replace('/[^A-Za-z0-9._-]/','_',$number).'-r'.$order['revision'].'-'.substr(hash('sha256',$content),0,12).'.html';$path=$directory.'/'.$fileName;
        $handle=@fopen($path,'xb');if(!$handle)throw new RuntimeException('Não foi possível criar o documento. Verifique a pasta de documentos.');
        try{
            $offset=0;$length=strlen($content);while($offset<$length){$written=fwrite($handle,substr($content,$offset));if(!$written)throw new RuntimeException('Não foi possível escrever o documento completo.');$offset+=$written;}
            fclose($handle);$handle=null;@chmod($path,0640);
            unset($snapshot['company']['logo_data']);$snapshot['sha256']=hash('sha256',$content);
            $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Não foi possível guardar os dados da confirmação.');
            $url='storage/uploads/sales-orders/'.$orderId.'/confirmations/'.$fileName;
            $title='Confirmação '.$number.' — '.$order['order_number'];
            if($existing){
                $documentId=(int)$existing['id'];
                $this->pdo->prepare('UPDATE erp_product_documents SET title=?,file_url=?,author_user_id=?,status="Ativo",notes=? WHERE id=? AND entity_type="sales_order" AND entity_id=?')->execute([$title,$url,$userId,$json,$documentId,$orderId]);
            }else{
                $this->pdo->prepare('INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title,file_url,version,author_user_id,status,notes) VALUES("sales_order",?,?,?,?,?, ?,"Ativo",?)')->execute([$orderId,self::TYPE,$title,$url,$number,$userId,$json]);
                $documentId=(int)$this->pdo->lastInsertId();
            }
            $action=$existing?'refresh_confirmation':'issue_confirmation';
            $this->pdo->prepare('INSERT INTO erp_audit_log(user_id,action,entity,entity_id,old_values_json,new_values_json) VALUES (?, ?,"erp_sales_orders",?,?,?)')->execute([$userId,$action,$orderId,$existing?json_encode(['document_id'=>$documentId,'file_url'=>$existing['file_url'],'snapshot'=>$metadata],JSON_UNESCAPED_UNICODE):null,json_encode(['document_id'=>$documentId,'number'=>$number,'revision'=>$revision,'file_url'=>$url],JSON_UNESCAPED_UNICODE)]);
            // Register the model in the existing catalogue when installed; no new schema.
            if($service->row('SELECT 1 FROM sqlite_master WHERE type="table" AND name="erp_document_catalog"'))$this->pdo->prepare('INSERT OR IGNORE INTO erp_document_catalog(code,document_number,name,module,output_format,generation_route) VALUES(?,?,?,?,?,?)')->execute(['sales_order_confirmation',self::MODEL,self::TYPE,'Comercial','PDF','erp.php?page=sales_orders&tab=documents']);
            return ['id'=>$documentId,'number'=>$number,'path'=>$path,'content'=>$content,'mime'=>'text/html; charset=UTF-8','existing'=>(bool)$existing,'created_file'=>true];
        }catch(Throwable $e){if(is_resource($handle))fclose($handle);@unlink($path);throw $e;}
    }
    private static function technicalDescription(array $article): string
    {
        $parts=[];foreach(['composition'=>'Composição','material'=>'Material','bag_color'=>'Cor','grammage'=>'Gramagem','colors_per_face'=>'Cores por face'] as $key=>$label)if(isset($article[$key])&&trim((string)$article[$key])!=='')$parts[]=$label.': '.$article[$key].($key==='grammage'?' g/m²':'');
        foreach(['has_gusset'=>['Com fole','Sem fole'],'microperforation'=>['Com microperfuração','Sem microperfuração']] as $key=>$labels)if(isset($article[$key]))$parts[]=$labels[(int)$article[$key]?0:1];
        return implode(' · ',$parts);
    }
}
