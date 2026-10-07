<?php
require_once __DIR__.'/Spreadsheet.php';
require_once __DIR__.'/Compatibility.php';
/** No INSERT/UPDATE/DDL. Can be run with PRAGMA query_only=ON. */
final class HistoricalValidator
{
    private $pdo;
    private $schema;
    private $cache = [];
    public function __construct(PDO $pdo) { $this->pdo=$pdo; $this->schema=self::inspect($pdo); }

    public static function inspect(PDO $pdo)
    {
        $schema=[];
        foreach ($pdo->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $name=$table['name']; $quoted='"'.str_replace('"','""',$name).'"';
            $schema[$name]=['sql'=>$table['sql'],'columns'=>$pdo->query('PRAGMA table_info('.$quoted.')')->fetchAll(PDO::FETCH_ASSOC),'foreign_keys'=>$pdo->query('PRAGMA foreign_key_list('.$quoted.')')->fetchAll(PDO::FETCH_ASSOC)];
        }
        return $schema;
    }
    public static function schemaHash(PDO $pdo)
    {
        $schema=self::inspect($pdo);
        foreach(array_keys($schema) as $table) if(strpos($table,'erp_legacy_import_')===0 || $table==='backup_runs') unset($schema[$table]);
        $objects=[];
        foreach($pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE type IN ('index','trigger','view') AND sql IS NOT NULL ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC) as $object) {
            if(strpos($object['tbl_name'],'erp_legacy_import_')!==0 && $object['tbl_name']!=='backup_runs') $objects[]=$object;
        }
        return hash('sha256', json_encode(['tables'=>$schema,'objects'=>$objects],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    private function column($table,$column)
    {
        foreach($this->schema[$table]['columns']??[] as $c) if($c['name']===$column) return true;
        return false;
    }
    private function find($table,$column,$value)
    {
        if(!$this->column($table,$column) || trim((string)$value)==='') return [];
        $key=$table.'|'.$column.'|'.$value;
        if(!isset($this->cache[$key])) {
            $s=$this->pdo->prepare('SELECT * FROM "'.$table.'" WHERE "'.$column.'"=? COLLATE NOCASE LIMIT 3');
            $s->execute([$value]); $this->cache[$key]=$s->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->cache[$key];
    }
    private function match($table,$id,$code,$tax,$reference,$name,$nameColumn,&$errors,&$warnings)
    {
        if(!isset($this->schema[$table])) { $errors[]='Tabela de referência indisponível: '.$table; return null; }
        $candidates=[];
        if($code!=='') $candidates=$this->find($table,'code',$code);
        if(count($candidates)>1) { $errors[]='Código ambíguo em '.$table; return null; }
        if(!$candidates && $tax!=='') $candidates=$this->find($table,'tax_number',$tax);
        if(!$candidates && $reference!=='') {
            foreach(['reference','customer_product_code','proof_reference'] as $field) {
                $found=$this->find($table,$field,$reference);
                foreach($found as $item) $candidates[$item['id']]=$item;
            }
            $candidates=array_values($candidates);
        }
        if(count($candidates)>1) { $errors[]='Referência/NIF ambíguo em '.$table; return null; }
        if($id!=='') {
            if(!ctype_digit((string)$id) || (int)$id<=0) { $errors[]='ID manual inválido em '.$table; return null; }
            $manual=$this->find($table,'id',$id);
            if(count($manual)!==1) { $errors[]='ID manual inexistente em '.$table; return null; }
            if($candidates && (int)$manual[0]['id']!==(int)$candidates[0]['id']) { $errors[]='ID manual contradiz código/NIF/referência em '.$table; return null; }
            return (int)$manual[0]['id'];
        }
        if(count($candidates)===1) return (int)$candidates[0]['id'];
        if($name!=='') {
            $normalized=self::normalize($name); $suggestions=[];
            // Suggestions never become automatic references, even for suppliers.
            $nameKey='names|'.$table;
            if(!isset($this->cache[$nameKey])) {
                $this->cache[$nameKey]=[];
                foreach($this->pdo->query('SELECT id,"'.$nameColumn.'" AS name FROM "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $this->cache[$nameKey][self::normalize($item['name'])][]=$item['id'];
                }
            }
            $suggestions=array_slice($this->cache[$nameKey][$normalized]??[],0,5);
            if($suggestions) $warnings[]='Sugestão de nome em '.$table.': IDs '.implode(', ',$suggestions).' (confirmar manualmente).';
        }
        $errors[]='Sem correspondência segura em '.$table.'. Preencher o ID GesTISSER.';
        return null;
    }
    private static function normalize($value)
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        return preg_replace('/[^a-z0-9]/','',strtolower($ascii===false?$value:$ascii));
    }
    public static function number($value)
    {
        $text=trim((string)$value);
        if(!preg_match('/^[+-]?[0-9]+(?:[.,][0-9]+)?$/D',$text)) throw new InvalidArgumentException('Número inválido: '.$text);
        $n=(float)str_replace(',','.',$text);
        if(!is_finite($n)) throw new InvalidArgumentException('Número fora do intervalo.');
        return $n;
    }
    public static function date($value)
    {
        $value=trim((string)$value);
        if(preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$value)) {
            $n=(float)$value;
            if($n<61 || $n>2958465) throw new InvalidArgumentException('Data Excel fora do intervalo.');
            return gmdate('Y-m-d',(int)round(($n-25569)*86400));
        }
        foreach(['Y-m-d','d/m/Y'] as $format) {
            $d=DateTimeImmutable::createFromFormat('!'.$format,$value);
            if($d && $d->format($format)===$value) return $d->format('Y-m-d');
        }
        throw new InvalidArgumentException('Data inválida: '.$value.'. Usar AAAA-MM-DD.');
    }
    public static function barcode($entity,$legacy)
    {
        $prefix=$entity==='rolos_rafia'?'R':'T';
        if(ctype_digit($legacy) && strlen($legacy)<=16) return $prefix.'-LEGACY-'.str_pad($legacy,8,'0',STR_PAD_LEFT);
        return $prefix.'-LEGACY-'.strtoupper(substr(hash('sha256','Bobinas|'.$entity.'|'.$legacy),0,24));
    }
    private function priorMap($entity,$legacy)
    {
        if(!isset($this->schema['erp_legacy_import_map'])) return null;
        $s=$this->pdo->prepare('SELECT * FROM erp_legacy_import_map WHERE source_system=? AND entity_type=? AND legacy_id=?');
        $s->execute(['Bobinas',$entity,$legacy]); return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function validate(array $batch)
    {
        $contracts=HistoricalSpreadsheet::contracts(); $report=['entities'=>[],'schema_hash'=>self::schemaHash($this->pdo),'errors'=>0,'warnings'=>0];
        $report['destinations']=HistoricalCompatibility::inspect($this->pdo);
        $indices=[];
        foreach($batch as $entity=>$input) {
            if(!isset($contracts[$entity])) throw new InvalidArgumentException('Entidade desconhecida.');
            foreach($input['rows'] as $row) {
                $d=$row['data'];
                if(($d['importar']??'')!=='1') continue;
                foreach(['legacy_id',$contracts[$entity]['natural']] as $field) if($field!=='' && ($d[$field]??'')!=='') $indices[$entity][$field][$d[$field]][]=$d;
            }
        }
        foreach($batch as $entity=>$input) {
            $result=['total'=>count($input['rows']),'validas'=>0,'ignoradas'=>0,'duplicadas'=>0,'referencias_encontradas'=>0,'referencias_nao_encontradas'=>0,'erros'=>0,'avisos'=>0,'rows'=>[]];
            $seenBarcodes=[];
            foreach($input['rows'] as $row) {
                $d=$row['data']; $errors=[];$warnings=[];$refs=[];$found=0;$missing=0;
                $status='Válida'; $duplicateInBatch=false; $legacy=$d['legacy_id']??'';
                if(($d['importar']??'')==='' || ($d['importar']??'')==='0') {
                    $result['ignoradas']++; $result['rows'][]=['line'=>$row['line'],'legacy_id'=>$legacy,'status'=>'Ignorada','errors'=>[],'warnings'=>[],'data'=>$d,'refs'=>[]]; continue;
                }
                if(($d['importar']??'')!=='1') $errors[]='importar deve ser 0 ou 1.';
                foreach($contracts[$entity]['required'] as $field) if(($d[$field]??'')==='') $errors[]='Campo obrigatório: '.$field;
                foreach(['legacy_id',$contracts[$entity]['natural']] as $field) if($field!=='' && isset($indices[$entity][$field][$d[$field]??'']) && count($indices[$entity][$field][$d[$field]])>1) { $errors[]='Chave repetida no lote: '.$field; $duplicateInBatch=true; }
                if($report['destinations'][$entity]['target']!==null && !$report['destinations'][$entity]['ready']) foreach($report['destinations'][$entity]['reasons'] as $reason) $errors[]='Destino incompatível: '.$reason;
                if(($d['erro_validacao']??'')!=='') $errors[]='Erro comunicado pelo normalizador: '.$d['erro_validacao'];
                foreach($d as $field=>$value) {
                    if($value==='') continue;
                    try {
                        if(strpos($field,'data_')===0) $d[$field]=self::date($value);
                        if(in_array($field,['quantidade','quantidade_planeada','quantidade_produzida','preco','peso_kg','peso_inicial_kg','peso_atual_kg','metros_iniciais','metros_atuais','largura','gramagem','horas_previstas','horas_reais','sequencia'],true)) {
                            $d[$field]=self::number($value);
                            if($d[$field]<0 && !($entity==='movimentos_historicos' && in_array($field,['quantidade','peso_kg'],true))) $errors[]=$field.' não pode ser negativo.';
                            if(in_array($field,['quantidade','quantidade_planeada'],true) && $d[$field]==0) $errors[]=$field.' não pode ser zero.';
                            if($field==='sequencia' && floor($d[$field])!=$d[$field]) $errors[]='sequencia deve ser inteira.';
                        }
                    } catch(Throwable $e) { $errors[]=$field.': '.$e->getMessage(); }
                }
                foreach([['peso_atual_kg','peso_inicial_kg'],['metros_atuais','metros_iniciais']] as $pair) if(isset($d[$pair[0]],$d[$pair[1]]) && is_numeric($d[$pair[0]]) && is_numeric($d[$pair[1]]) && $d[$pair[0]]>$d[$pair[1]]) $errors[]=$pair[0].' superior ao valor inicial.';
                if(($d['data_inicio']??'')!=='' && ($d['data_fim']??'')!=='' && $d['data_inicio']>$d['data_fim']) $errors[]='Data de fim anterior ao início.';
                if($entity==='ofs' && !in_array($d['estado']??'', ['Concluída','Encerrada','Fechada','Cancelada'],true)) $errors[]='OF histórica exige estado final: Concluída/Encerrada/Fechada/Cancelada.';
                if($entity==='of_operacoes' && !in_array($d['estado']??'', ['Concluída','Cancelada'],true)) $errors[]='Operação histórica exige estado final: Concluída/Cancelada.';
                $matches=[];
                if(in_array($entity,['encomendas','ofs'],true)) $matches[]=['customer','erp_customers','gestisser_customer_id','cliente_codigo','cliente_nif','','cliente_nome','name'];
                if(in_array($entity,['encomendas_linhas','ofs'],true)) $matches[]=['product','erp_finished_products','gestisser_product_id','artigo_codigo','','artigo_referencia','artigo_designacao','description'];
                if(in_array($entity,['entradas_mp','rolos_rafia','lotes_tintas','movimentos_historicos'],true)) $matches[]=['raw_material','erp_raw_materials','gestisser_raw_material_id','materia_prima_codigo','','materia_prima_referencia','materia_prima_designacao','description'];
                if($entity==='entradas_mp' || (!empty($d['fornecedor_codigo']) && in_array($entity,['rolos_rafia','lotes_tintas'],true))) $matches[]=['supplier','erp_suppliers','gestisser_supplier_id','fornecedor_codigo','fornecedor_nif','','fornecedor_nome','name'];
                foreach($matches as $m) {
                    $id=$this->match($m[1],(string)($d[$m[2]]??''),(string)($d[$m[3]]??''),(string)($d[$m[4]]??''),(string)($d[$m[5]]??''),(string)($d[$m[6]]??''),$m[7],$errors,$warnings);
                    $refs[$m[0]]=$id; if($id!==null) $found++; else $missing++;
                }
                if($entity==='ofs' && !empty($refs['product'])) {
                    $product=$this->find('erp_finished_products','id',$refs['product'])[0];
                    $bridge=$this->find('erp_products','code',$product['code']);
                    if(count($bridge)!==1) $errors[]='OF exige artigo compatível já existente em erp_products; não será criado automaticamente.';
                    else $refs['legacy_product']=(int)$bridge[0]['id'];
                }
                $dependencies=[];
                if($entity==='encomendas_linhas' || ($entity==='ofs' && (($d['encomenda_legacy_id']??'')!=='' || ($d['numero_encomenda']??'')!==''))) $dependencies[]=['encomendas','encomenda_legacy_id','numero_encomenda','numero_encomenda'];
                if($entity==='of_operacoes') $dependencies[]=['ofs','of_legacy_id','numero_of','numero_of'];
                if($entity==='movimentos_historicos') {
                    if(($d['of_numero']??'')!=='') $dependencies[]=['ofs','','of_numero','numero_of'];
                    if(($d['encomenda_numero']??'')!=='') $dependencies[]=['encomendas','','encomenda_numero','numero_encomenda'];
                }
                foreach($dependencies as $dep) {
                    $parent=$dep[1]!==''?($d[$dep[1]]??''):''; $number=$d[$dep[2]]??''; $key=$parent!==''?'legacy_id':$dep[3]; $value=$parent!==''?$parent:$number;
                    $map=$parent!==''?$this->priorMap($dep[0],$parent):null;
                    if($parent!=='' && $number!=='') {
                        $parentData=$indices[$dep[0]]['legacy_id'][$parent][0]??null;
                        if(!$parentData && $map) { $stored=json_decode($map['original_data_json'],true); $parentData=$stored['normalized']??$stored; }
                        if($parentData && ($parentData[$dep[3]]??'')!==$number) $errors[]='ID legacy e número do documento pai são contraditórios: '.$dep[0];
                    }
                    if(!$map && $value!=='' && isset($indices[$dep[0]][$key][$value]) && count($indices[$dep[0]][$key][$value])===1) { $refs[$dep[0]]=['batch_field'=>$key,'value'=>$value]; $found++; }
                    elseif($map) { $refs[$dep[0]]=['id'=>(int)$map['gestisser_id']]; $found++; }
                    elseif($dep[0]==='ofs' && $number!=='' && count($existing=$this->find('erp_production_orders','order_number',$number))===1) { $refs[$dep[0]]=['id'=>(int)$existing[0]['id']]; $found++; }
                    else { $errors[]='Referência ao histórico não encontrada: '.$dep[0].' '.$value; $missing++; }
                }
                if($entity==='of_operacoes') {
                    $operation=$this->find('erp_operations','code',$d['operacao']??'');
                    if(count($operation)!==1) { $errors[]='Código de operação não encontrado/ambíguo.'; $missing++; } else { $refs['operation']=(int)$operation[0]['id']; $found++; }
                }
                if(in_array($entity,['rolos_rafia','lotes_tintas'],true)) {
                    $d['barcode']=($d['barcode']??'')!==''?$d['barcode']:self::barcode($entity,$legacy);
                    if(!preg_match('/^[A-Za-z0-9._-]{1,80}$/D',$d['barcode'])) $errors[]='Barcode incompatível; usar letras, números, ponto, hífen ou underscore.';
                    if(isset($seenBarcodes[$d['barcode']])) $errors[]='Barcode repetido no lote.';
                    $seenBarcodes[$d['barcode']]=true;
                    foreach(['erp_raw_material_roll_labels','erp_raw_material_ink_labels'] as $table) if($this->find($table,'barcode',$d['barcode']) && !$this->priorMap($entity,$legacy)) $errors[]='Barcode já existe: resolver o mapeamento, sem criar outra etiqueta.';
                    foreach(['warehouse_id'=>'erp_warehouses','location_id'=>'erp_locations'] as $field=>$table) if(($d[$field]??'')!=='') {
                        if(!ctype_digit((string)$d[$field]) || count($this->find($table,'id',$d[$field]))!==1) $errors[]=$field.' inexistente.';
                        else $refs[$field]=(int)$d[$field];
                    }
                    if(!empty($refs['location_id'])) {
                        $location=$this->find('erp_locations','id',$refs['location_id'])[0];
                        if(empty($refs['warehouse_id']) || (int)$location['warehouse_id']!==$refs['warehouse_id']) $errors[]='Localização não pertence ao armazém indicado.';
                    }
                    if(($d['localizacao']??'')!=='' && empty($refs['location_id'])) $errors[]='Resolver localizacao através de warehouse_id e location_id; não será assumida.';
                    if(($d['estado']??'')!=='' && !in_array($d['estado'],['AVAILABLE','CONSUMED','BLOCKED','CANCELLED'],true)) $errors[]='Estado de etiqueta não mapeado: usar AVAILABLE/CONSUMED/BLOCKED/CANCELLED.';
                }
                if($entity==='movimentos_historicos') {
                    $flag=$d['afeta_stock_atual']??'';
                    if($flag!=='' && $flag!=='0') $errors[]='afeta_stock_atual=1 exige regra de stock explícita; bloqueado nesta fase.';
                    $d['afeta_stock_atual']='0';
                    $warnings[]='Movimento documental; não altera saldos de stock.';
                }
                if(in_array($entity,['encomendas','encomendas_linhas','entradas_mp'],true)) $warnings[]='Destino de escrita aguarda validação da base atual; importação real bloqueada para esta entidade.';
                $hash=hash('sha256',json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                $map=$this->priorMap($entity,$legacy);
                if($duplicateInBatch) $result['duplicadas']++;
                if($map) {
                    if(!isset($this->schema[$map['target_table']]) || !preg_match('/^[a-z_]+$/D',$map['target_table']) || count($this->find($map['target_table'],'id',$map['gestisser_id']))!==1) $errors[]='Mapeamento legacy aponta para registo de destino inexistente.';
                    if($map['source_hash']!==$hash) $errors[]='legacy_id já importado com conteúdo diferente; exige revisão, sem atualização automática.';
                    else { $status='Já importado'; $result['duplicadas']++; }
                } elseif($entity==='ofs' && $this->find('erp_production_orders','order_number',$d['numero_of']??'')) {
                    $errors[]='Número de OF já existente sem mapeamento legacy; rever manualmente.';
                }
                if($errors) { $status='Erro'; $result['erros']++; }
                elseif($status==='Válida') $result['validas']++;
                $result['avisos']+=count($warnings);$result['referencias_encontradas']+=$found;$result['referencias_nao_encontradas']+=$missing;
                $result['rows'][]=['line'=>$row['line'],'legacy_id'=>$legacy,'status'=>$status,'errors'=>$errors,'warnings'=>$warnings,'data'=>$d,'refs'=>$refs,'source_hash'=>$hash];
            }
            $report['entities'][$entity]=$result; $report['errors']+=$result['erros'];$report['warnings']+=$result['avisos'];
        }
        // Validate dependency rows, not just their presence in the workbook.
        $changed=true;
        while($changed) {
            $changed=false;
            foreach($report['entities'] as $entity=>&$result) foreach($result['rows'] as &$row) {
                if($row['status']!=='Válida') continue;
                foreach($row['refs'] as $parent=>$reference) if(is_array($reference) && isset($reference['batch_field'])) {
                    $ok=false;
                    foreach($report['entities'][$parent]['rows']??[] as $candidate) if((string)($candidate['data'][$reference['batch_field']]??'')===(string)$reference['value'] && in_array($candidate['status'],['Válida','Já importado'],true)) $ok=true;
                    if(!$ok) { $row['errors'][]='Registo pai inválido: '.$parent; $row['status']='Erro';$result['validas']--;$result['erros']++;$report['errors']++;$changed=true;break; }
                }
            }
            unset($row,$result);
        }
        return $report;
    }
}
