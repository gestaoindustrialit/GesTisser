<?php
/** Bounded manual preview and resumable create/update batches. No deletes. PHP 7.0. */
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
            'Estado'=>'boolean','DataAtualizacao'=>'dateTime','NumeroDocumentos'=>'text','EstadoSincronizacao'=>'text','GesTISSER_ID'=>'text','DataUltimaProducao'=>'dateTime'];
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
        return $this->collection('/items?$expand=fields($select=Title,GesTISSER_ID,Descri_x00e7__x00e3_o,CodigoCliente,Cliente,Estado,DataAtualizacao,NumeroDocumentos,EstadoSincronizacao,DataUltimaProducao)&$top=200');
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
            'EstadoSincronizacao'=>'Importado do GesTISSER','DataUltimaProducao'=>null];
        if (!empty($row['last_production'])) {
            $last=substr($row['last_production'],0,10);
            $lastDate=DateTime::createFromFormat('!Y-m-d',$last,new DateTimeZone('UTC'));
            if (!$lastDate || $lastDate->format('Y-m-d')!==$last) throw new RuntimeException('Data de última produção inválida.');
            $fields['DataUltimaProducao']=$last.'T00:00:00Z';
        }
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
    private function productionExpression()
    {
        $tables=$this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('erp_production_orders',$tables,true)) return 'NULL';
        $parts=['NULL'];
        if (in_array('erp_operation_time_entries',$tables,true) && in_array('erp_production_order_operations',$tables,true)) {
            $parts[]='(SELECT MAX(t.ended_at) FROM erp_operation_time_entries t
                JOIN erp_production_order_operations op ON op.id=t.production_order_operation_id
                JOIN erp_production_orders o ON o.id=op.production_order_id
                WHERE o.finished_product_id=a.id AND t.quantity_good>0
                AND julianday(t.ended_at) IS NOT NULL AND julianday(t.started_at) IS NOT NULL)';
        }
        if (in_array('erp_production_order_closures',$tables,true)) {
            $parts[]='(SELECT MAX(c.closed_at) FROM erp_production_order_closures c
                JOIN erp_production_orders o ON o.id=c.production_order_id
                WHERE o.finished_product_id=a.id AND o.produced_quantity>0 AND julianday(c.closed_at) IS NOT NULL)';
        }
        if (count($parts)===1) return 'NULL';
        return "NULLIF(MAX(".implode(',',array_map(function ($sql) { return "COALESCE(".$sql.",'')"; },$parts))."),'')";
    }
    private function source($after,$limit=5)
    {
        $q=$this->pdo->prepare('SELECT a.id,a.code,a.description,a.status,a.updated_at,c.code customer_code,c.name customer_name,'. $this->productionExpression().' last_production,
            (SELECT COUNT(*) FROM erp_product_documents d WHERE d.entity_type="finished_product" AND d.entity_id=a.id AND d.status="Ativo") document_count
            FROM erp_finished_products a LEFT JOIN erp_customers c ON c.id=a.customer_id WHERE a.id>? ORDER BY a.id LIMIT '.(int)$limit);
        $q->execute([(int)$after]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function preview($after=0, $updates=false, $maxId=PHP_INT_MAX)
    {
        if (!is_int($after) || $after<0) throw new RuntimeException('ID inicial inválido.');
        $columns=$this->schema(); $remote=$this->remote(); $rows=[];
        foreach ($this->source($after,$updates?20:5) as $row) {
            if ((int)$row['id']>$maxId) break;
            $entry=['id'=>(int)$row['id'],'code'=>$row['code'],'fields'=>[], 'action'=>'Criar','error'=>null];
            try {
                $entry['fields']=$this->fields($row,$columns);
                foreach ($remote as $item) {
                    $f=$item['fields'] ?? [];
                    if ((string)($f['GesTISSER_ID'] ?? '')===(string)$row['id']) {
                        $entry['action']='Já existe — ignorar';
                        if ($updates) {
                            $changed=false;
                            foreach ($entry['fields'] as $name=>$value) {
                                $existing=$f[$name] ?? null;
                                if ($name==='Estado') $equal=$existing!==null && filter_var($existing,FILTER_VALIDATE_BOOLEAN)===$value;
                                elseif (in_array($name,['DataAtualizacao','DataUltimaProducao'],true)) $equal=substr((string)$existing,0,10)===substr($value,0,10);
                                else $equal=(string)$existing===(string)$value;
                                if (!$equal) $changed=true;
                            }
                            $entry['action']=$changed ? 'Atualizar' : 'Sem alterações';
                            $entry['remote_id']=(string)($item['id'] ?? '');
                            $entry['etag']=$item['eTag'] ?? $f['@odata.etag'] ?? null;
                            if ($changed && (!$entry['etag'] || !preg_match('/^[0-9]+$/D',$entry['remote_id']))) throw new RuntimeException('Identidade ou ETag remoto em falta; atualização bloqueada.');
                        }
                        break;
                    }
                    if (strcasecmp(trim((string)($f['Title'] ?? '')),trim($row['code']))===0) throw new RuntimeException('Código já existente com outra chave; rever manualmente.');
                }
            } catch (RuntimeException $e) { $entry['action']='Bloqueado'; $entry['error']=$e->getMessage(); }
            $rows[]=$entry;
        }
        return ['after'=>$after,'rows'=>$rows,'prepared_at'=>time(),'updates'=>$updates,'max_id'=>$maxId];
    }
    public function execute(array $preview)
    {
        if (empty($preview['prepared_at']) || time()-$preview['prepared_at']>900 || $preview['prepared_at']>time()) throw new RuntimeException('Pré-visualização expirada. Prepare novamente.');
        $current=$this->preview($preview['after'],!empty($preview['updates']),$preview['max_id'] ?? PHP_INT_MAX);
        if (json_encode($current['rows'])!==json_encode($preview['rows'])) throw new RuntimeException('Os dados locais, colunas ou registos remotos mudaram. Prepare novamente.');
        return $this->apply($current);
    }
    public function syncBatch($after,$maxId)
    {
        return $this->apply($this->preview((int)$after,true,(int)$maxId));
    }
    private function apply(array $current)
    {
        $deadline=microtime(true)+8;
        $results=[];
        foreach ($current['rows'] as $row) {
            if ($results && microtime(true)>$deadline) break;
            $result=['id'=>$row['id'],'code'=>$row['code'],'result'=>$row['action'],'error'=>$row['error']];
            if (in_array($row['action'],['Criar','Atualizar'],true)) {
                try {
                    if ($row['action']==='Atualizar') {
                        call_user_func($this->api,'PATCH','/items/'.$row['remote_id'].'/fields',['fields'=>$row['fields'],'etag'=>$row['etag']]);
                        $result['result']='Atualizado'; $result['sharepoint_id']=$row['remote_id'];
                        $results[]=$result; continue;
                    }
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
