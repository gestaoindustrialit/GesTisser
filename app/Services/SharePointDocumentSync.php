<?php
require_once __DIR__.'/SharePointImportFailure.php';
require_once __DIR__.'/ArticleDocument.php';
/** Explicit document copies, immutable content-addressed names, no remote deletes. */
final class SharePointDocumentSync
{
    private $pdo;private $api;private $root;private $drive;
    public function __construct(PDO $pdo,callable $api,$root,$drive){$this->pdo=$pdo;$this->api=$api;$this->root=$root;$this->drive=$drive;}
    private function get($path)
    {
        try{return call_user_func($this->api,'GET',$path,null);}
        catch(SharePointImportFailure $e){if($e->diagnostic()['http_status']===404)return null;throw $e;}
    }
    private function folder($articleId)
    {
        $path='';$parent='/root';
        foreach(['Artigos','Maquetes',(string)$articleId]as$name){
            $path.='/'.$name;$item=$this->get('/root:'.$path);
            if(!$item){
                try{$item=call_user_func($this->api,'POST',$parent==='/root'?'/root/children':$parent.':/children',
                    ['name'=>$name,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);}
                catch(SharePointImportFailure $e){if($e->diagnostic()['http_status']!==409)throw $e;$item=$this->get('/root:'.$path);}
            }
            if(!is_array($item) || !isset($item['folder']))throw new RuntimeException('Documento: destino existente não é uma pasta.');
            $parent='/root:'.$path;
        }
        return $parent;
    }
    private function manifestKey($id){return 'sharepoint.document.v1.'.hash('sha256',$this->drive.'/'.$id);}
    private function manifest($id)
    {
        $q=$this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$this->manifestKey($id)]);
        return json_decode((string)$q->fetchColumn(),true)?:[];
    }
    private function save($id,array $data)
    {
        $this->pdo->beginTransaction();try{
            $this->pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([$this->manifestKey($id)]);
            $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute([$this->manifestKey($id),json_encode($data)]);
            $this->pdo->commit();
        }catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }
    private function copy(array $row)
    {
        $file=ArticleDocument::absolutePath($this->root,(string)$row['file_url']);
        if($file==='')throw new RuntimeException('Documento: ficheiro local não encontrado ou fora do armazenamento permitido.');
        $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
        if(!in_array($ext,['pdf','jpg','jpeg','png','webp'],true))throw new RuntimeException('Documento: formato não suportado (PDF/JPG/PNG/WebP).');
        $in=@fopen($file,'rb');$snapshot=tmpfile();
        if(!$in || !$snapshot){if($in)fclose($in);if($snapshot)fclose($snapshot);throw new RuntimeException('Documento: não foi possível abrir o ficheiro.');}
        try{
            $size=stream_copy_to_stream($in,$snapshot,20971521);
            if(!$size || $size>20971520)throw new RuntimeException('Documento: tamanho fora do limite de 20 MiB.');
            rewind($snapshot);$h=hash_init('sha256');hash_update_stream($h,$snapshot);$sha=hash_final($h);
            rewind($snapshot);$h=hash_init('sha1');hash_update_stream($h,$snapshot);$sha1=hash_final($h);
            $stem=trim(preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['title']),'.-');
            $name=$row['id'].'-'.$sha.'-'.substr($stem?:'documento',0,80).'.'.$ext;
            $folder=$this->folder((int)$row['entity_id']);$path=$folder.'/'.$name;
            $old=$this->get($path);$manifest=$this->manifest($row['id']);
            if($old){
                $known=($manifest['sha256'] ?? '')===$sha && ($manifest['remote_id'] ?? '')===($old['id'] ?? '')
                    && !empty($manifest['etag']) && $manifest['etag']===($old['eTag'] ?? null);
                $verifiedHash=strcasecmp($old['file']['hashes']['sha1Hash'] ?? '',$sha1)===0;
                if((int)($old['size'] ?? -1)!==$size || (!$known && !$verifiedHash))throw new RuntimeException('Documento: ficheiro remoto existente sem integridade confirmada; rever antes de substituir.');
                $item=$old;$result='Sem alterações';
            }else{
                rewind($snapshot);$item=call_user_func($this->api,'PUT',$path.':/content',$snapshot);
                if(empty($item['id']) || (int)($item['size'] ?? -1)!==$size || empty($item['eTag']))throw new RuntimeException('Documento: confirmação do upload incompleta.');
                $result='Criado';
            }
            $this->save($row['id'],['article_id'=>(int)$row['entity_id'],'sha256'=>$sha,'remote_id'=>$item['id'],'etag'=>$item['eTag'] ?? null,
                'size'=>$size,'name'=>$name,'folder'=>'Artigos/Maquetes/'.$row['entity_id'],'checked_at'=>gmdate('c')]);
            return $result;
        }finally{fclose($in);fclose($snapshot);}
    }
    public function batch($after,$maxId)
    {
        $q=$this->pdo->prepare('SELECT d.* FROM erp_product_documents d JOIN erp_finished_products a ON a.id=d.entity_id
            WHERE d.entity_type="finished_product" AND d.status="Ativo" AND d.id>? AND d.id<=? ORDER BY d.id LIMIT 3');
        $q->execute([(int)$after,(int)$maxId]);$results=[];$deadline=microtime(true)+10;
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){
            if($results && microtime(true)>$deadline)break;
            $out=['id'=>(int)$row['id'],'code'=>'Artigo '.$row['entity_id'].' · documento '.$row['id'],'error'=>null];
            try{$out['result']=$this->copy($row);}
            catch(SharePointImportFailure $e){$out['result']='Não confirmado — reconciliar documento';$out['diagnostic']=$e->diagnostic();$results[]=$out;break;}
            catch(RuntimeException $e){
                if(strpos($e->getMessage(),'Documento:')!==0)throw $e;
                $out['result']='Bloqueado';$out['error']=$e->getMessage();
            }
            $results[]=$out;
        }
        return $results;
    }
}
