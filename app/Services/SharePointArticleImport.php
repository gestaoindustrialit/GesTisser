<?php
/** Manual create-only pilot. No background work and no updates/deletes. PHP 7.0. */
final class SharePointArticleImport
{
    private $pdo;
    private $api;
    public function __construct(PDO $pdo, callable $api) { $this->pdo=$pdo; $this->api=$api; }
    private function collection($path)
    {
        $rows=[]; $seen=[];
        while ($path) {
            if (isset($seen[$path]) || count($seen)>=100) throw new RuntimeException('Paginação excedeu o limite; importação bloqueada.');
            $seen[$path]=true; $data=call_user_func($this->api,'GET',$path,null);
            if (!is_array($data['value'] ?? null)) throw new RuntimeException('Coleção SharePoint inválida.');
            $rows=array_merge($rows,$data['value']);
            if (count($rows)>20000) throw new RuntimeException('Lista excedeu o limite do piloto.');
            $path=$data['@odata.nextLink'] ?? null;
        }
        return $rows;
    }
    private function schema()
    {
        $columns=[];
        foreach ($this->collection('/columns') as $column) $columns[$column['name']]=$column;
        $types=['Title'=>'text','Descri_x00e7__x00e3_o'=>'text','CodigoCliente'=>'text','Cliente'=>'text',
            'Estado'=>'boolean','DataAtualizacao'=>'dateTime','NumeroDocumentos'=>'text','EstadoSincronizacao'=>'text','GesTISSER_ID'=>'text'];
        foreach ($types as $name=>$type) {
            if (!isset($columns[$name][$type]) || !empty($columns[$name]['readOnly'])) throw new RuntimeException('Coluna incompatível: '.$name.'.');
        }
        if (empty($columns['GesTISSER_ID']['enforceUniqueValues']) || empty($columns['GesTISSER_ID']['indexed'])) throw new RuntimeException('GesTISSER_ID deve ser único e indexado.');
        foreach ($columns as $name=>$column) {
            if (!empty($column['required']) && empty($column['readOnly']) && !isset($types[$name]) && empty($column['defaultValue'])) throw new RuntimeException('Coluna obrigatória sem mapeamento: '.$name.'.');
        }
        return $columns;
    }
    private function remote()
    {
        return $this->collection('/items?$expand=fields($select=Title,GesTISSER_ID)&$top=200');
    }
    private function fields(array $row, array $columns)
    {
        if (!in_array($row['status'],['Ativo','Inativo'],true)) throw new RuntimeException('Estado diferente de Ativo/Inativo; rever o mapeamento.');
        $date=substr((string)$row['updated_at'],0,10);
        $parsed=DateTime::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new RuntimeException('Data de atualização inválida.');
        $fields=['GesTISSER_ID'=>(string)$row['id'],'Title'=>(string)$row['code'],
            'Descri_x00e7__x00e3_o'=>(string)$row['description'],'CodigoCliente'=>(string)$row['customer_code'],
            'Cliente'=>(string)$row['customer_name'],'Estado'=>$row['status']==='Ativo',
            'DataAtualizacao'=>$date.'T00:00:00Z','NumeroDocumentos'=>(string)$row['document_count'],
            'EstadoSincronizacao'=>'Importado do GesTISSER'];
        if (trim($fields['Title'])==='') throw new RuntimeException('Código vazio.');
        foreach ($fields as $name=>$value) {
            if (is_string($value) && isset($columns[$name]['text'])) {
                $length=preg_match_all('/./us',$value,$unused);
                if ($length===false || $length>(int)($columns[$name]['text']['maxLength'] ?? 255)) throw new RuntimeException('Texto inválido ou demasiado longo: '.$name.'.');
            }
            if (!empty($columns[$name]['required']) && $value==='') throw new RuntimeException('Campo obrigatório vazio: '.$name.'.');
        }
        return $fields;
    }
    private function source($after)
    {
        $q=$this->pdo->prepare('SELECT a.id,a.code,a.description,a.status,a.updated_at,c.code customer_code,c.name customer_name,
            (SELECT COUNT(*) FROM erp_product_documents d WHERE d.entity_type="finished_product" AND d.entity_id=a.id AND d.status="Ativo") document_count
            FROM erp_finished_products a LEFT JOIN erp_customers c ON c.id=a.customer_id WHERE a.id>? ORDER BY a.id LIMIT 5');
        $q->execute([(int)$after]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function preview($after=0)
    {
        if (!is_int($after) || $after<0) throw new RuntimeException('ID inicial inválido.');
        $columns=$this->schema(); $remote=$this->remote(); $rows=[];
        foreach ($this->source($after) as $row) {
            $entry=['id'=>(int)$row['id'],'code'=>$row['code'],'fields'=>[], 'action'=>'Criar','error'=>null];
            try {
                $entry['fields']=$this->fields($row,$columns);
                foreach ($remote as $item) {
                    $f=$item['fields'] ?? [];
                    if ((string)($f['GesTISSER_ID'] ?? '')===(string)$row['id']) { $entry['action']='Já existe — ignorar'; break; }
                    if (strcasecmp(trim((string)($f['Title'] ?? '')),trim($row['code']))===0) throw new RuntimeException('Código já existente com outra chave; rever manualmente.');
                }
            } catch (RuntimeException $e) { $entry['action']='Bloqueado'; $entry['error']=$e->getMessage(); }
            $rows[]=$entry;
        }
        return ['after'=>$after,'rows'=>$rows,'prepared_at'=>time()];
    }
    public function execute(array $preview)
    {
        if (empty($preview['prepared_at']) || time()-$preview['prepared_at']>900 || $preview['prepared_at']>time()) throw new RuntimeException('Pré-visualização expirada. Prepare novamente.');
        $current=$this->preview($preview['after']);
        if (json_encode($current['rows'])!==json_encode($preview['rows'])) throw new RuntimeException('Os dados locais, colunas ou registos remotos mudaram. Prepare novamente.');
        $results=[];
        foreach ($current['rows'] as $row) {
            $result=['id'=>$row['id'],'code'=>$row['code'],'result'=>$row['action']];
            if ($row['action']==='Criar') {
                try {
                    $item=call_user_func($this->api,'POST','/items',['fields'=>$row['fields']]);
                    if (empty($item['id']) || (string)($item['fields']['GesTISSER_ID'] ?? '')!==(string)$row['id']) throw new RuntimeException('Confirmação de criação incompleta.');
                    $result['result']='Criado'; $result['sharepoint_id']=(string)$item['id'];
                } catch (Throwable $e) {
                    // Never retry an uncertain POST. A new preview reconciles against the unique key.
                    $result['result']='Não confirmado — preparar novamente para reconciliar';
                    $results[]=$result; break;
                }
            }
            $results[]=$result;
        }
        return $results;
    }
}
