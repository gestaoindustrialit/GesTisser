<?php
require_once __DIR__.'/SharePointImportFailure.php';
/** Durable cursor, counts and bounded errors. Call mutations under the per-list lock. */
final class SharePointArticleJob
{
    private $pdo;
    private $key='sharepoint.article_job.v1';
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }
    public function load()
    {
        $q=$this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$this->key]);
        return json_decode((string)$q->fetchColumn(),true) ?: [];
    }
    private function save(array $job)
    {
        $job['updated_at']=gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([$this->key]);
            $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute([$this->key,json_encode($job)]);
            $this->pdo->commit();
        } catch(Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return $job;
    }
    public function start($configuration)
    {
        $old=$this->load();
        if (in_array($old['state'] ?? '',['running','paused'],true)) throw new RuntimeException('Existe uma execução pendente. Retome-a ou termine-a antes de iniciar outra.');
        $stats=$this->pdo->query('SELECT COUNT(*) total,COALESCE(MAX(id),0) max_id FROM erp_finished_products')->fetch(PDO::FETCH_ASSOC);
        return $this->save(['run'=>bin2hex(random_bytes(16)),'configuration'=>$configuration,'state'=>'running','cursor'=>0,
            'max_id'=>(int)$stats['max_id'],'total'=>(int)$stats['total'],'processed'=>0,'created'=>0,'updated'=>0,'unchanged'=>0,'blocked'=>0,'errors'=>[]]);
    }
    public function cancel($run)
    {
        $job=$this->load();
        if (!$job || !hash_equals($job['run'],(string)$run)) throw new RuntimeException('Execução inválida.');
        $job['state']='cancelled';$job['message']='Execução encerrada. Registos já enviados foram preservados.';
        return $this->save($job);
    }
    public function pause($run,$configuration)
    {
        $job=$this->checked($run,$configuration);$job['state']='paused';return $this->save($job);
    }
    public function resume($run,$configuration)
    {
        $job=$this->checked($run,$configuration);
        if (!empty($job['retry_at']) && time()<$job['retry_at']) throw new RuntimeException('Aguarde '.($job['retry_at']-time()).' segundos antes de retomar (limite Microsoft Graph).');
        if($job['state']==='paused') {$job['state']='running';unset($job['message']);}return $this->save($job);
    }
    private function checked($run,$configuration)
    {
        $job=$this->load();
        if (!$job || !hash_equals($job['run'],(string)$run) || $job['configuration']!==$configuration) throw new RuntimeException('Execução inválida ou configuração alterada.');
        return $job;
    }
    public function step($run,$configuration,callable $batch)
    {
        $job=$this->checked($run,$configuration);
        if ($job['state']==='paused' && !empty($job['retry_at']) && time()>=$job['retry_at']) $job['state']='running';
        if ($job['state']!=='running') return $job;
        try {
            $results=call_user_func($batch,$job['cursor'],$job['max_id']);
            if (!$results) {$job['state']='complete'; return $this->save($job);}
            foreach ($results as $row) {
                if (strpos($row['result'],'Não confirmado')===0) {
                    $job['last_error']=array_merge($row['diagnostic'] ?? [],['article_id'=>$row['id'],'article_code'=>$row['code'],'at'=>gmdate('c')]);
                    $job['state']='paused';$job['message']='Pedido não confirmado. Retomar consulta a chave e o estado remoto antes de tentar de novo.'; break;
                }
                $job['cursor']=(int)$row['id'];++$job['processed'];
                if($row['result']==='Criado')++$job['created'];
                elseif($row['result']==='Atualizado')++$job['updated'];
                elseif($row['result']==='Bloqueado') {
                    ++$job['blocked'];
                    if(count($job['errors'])<100)$job['errors'][]=['id'=>$row['id'],'code'=>$row['code'],'error'=>$row['error']];
                } else ++$job['unchanged'];
            }
            if($job['state']==='running') {unset($job['last_error']);$job['retry_count']=0;}
            if ($job['cursor']>=$job['max_id'])$job['state']='complete';
        } catch(Throwable $e) { $job['last_error']=array_merge(SharePointImportFailure::safe($e),['after_id'=>$job['cursor'],'at'=>gmdate('c')]);$job['state']='paused';$job['message']='Execução interrompida. Verifique a ligação e as colunas e retome. Os pedidos já concluídos serão reconciliados.'; }
        $status=$job['last_error']['http_status'] ?? null;
        if($job['state']==='paused' && (in_array($status,[429,503,504],true) || !empty($job['last_error']['transport_error'])) && ($job['retry_count'] ?? 0)<3) {
            $job['retry_count']=($job['retry_count'] ?? 0)+1;
            $job['retry_at']=time()+max(10*(int)pow(2,$job['retry_count']-1),(int)($job['last_error']['retry_after'] ?? 0));
            $job['message'].=' Nova tentativa automática após a espera indicada, até três tentativas.';
        } else unset($job['retry_at']);
        return $this->save($job);
    }
}
