<?php
require_once __DIR__.'/SharePointArticleJob.php';
/** Opt-in scheduling over the existing resumable jobs; no schema migrations. */
final class SharePointSchedule
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    private function read($key)
    {
        $q=$this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);
        return json_decode((string)$q->fetchColumn(),true)?:[];
    }
    private function write($key,array $value)
    {
        $this->pdo->beginTransaction();try{
            $this->pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([$key]);
            $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute([$key,json_encode($value)]);
            $this->pdo->commit();
        }catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }
    public function settings(){return array_merge(['enabled'=>false,'articles_minutes'=>60,'documents_minutes'=>1440],$this->read('sharepoint.schedule.v1'));}
    public function status(){return $this->read('sharepoint.schedule.status.v1');}
    public function hasWork($now=null)
    {
        $now=$now===null?time():(int)$now;$settings=$this->settings();$status=$this->status();
        if(empty($settings['enabled']))return false;
        foreach(['articles','documents']as$kind){
            $job=(new SharePointArticleJob($this->pdo,$kind))->load();
            if(($job['state'] ?? '')==='running')return true;
            if(($job['state'] ?? '')==='paused'){
                if(empty($job['manual_pause']) && !empty($job['retry_at']) && $job['retry_at']<=$now)return true;
            }elseif(($status['next_'.$kind] ?? 0)<=$now)return true;
        }
        return false;
    }
    public function save(array $input)
    {
        $s=['enabled'=>!empty($input['enabled'])];
        foreach(['articles_minutes','documents_minutes']as$key){
            $n=filter_var($input[$key] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1440]]);
            if($n===false)throw new RuntimeException('Intervalos devem ser entre 1 e 1440 minutos.');$s[$key]=$n;
        }
        $previous=$this->settings();$this->write('sharepoint.schedule.v1',$s);
        $status=$this->status();
        foreach(['articles','documents']as$kind)if($previous[$kind.'_minutes']!==$s[$kind.'_minutes'] || $previous['enabled']!==$s['enabled'])unset($status['next_'.$kind]);$this->write('sharepoint.schedule.status.v1',$status);
    }
    /** Caller holds the same per-list lock used by the web handlers. */
    public function run(array $integration,callable $batchFactory,$budget=45,callable $clock=null)
    {
        $clock=$clock?:function(){return microtime(true);};$begin=call_user_func($clock);
        $s=$this->settings();$status=$this->status();
        if(empty($s['enabled']))return ['state'=>'disabled'];
        $status['last_tick']=gmdate('c');$fingerprint=hash('sha256',json_encode($integration));$done=[];
        while(call_user_func($clock)-$begin<$budget){
            $s=$this->settings();if(empty($s['enabled']))break;
            $progress=false;
            foreach(['articles','documents']as$kind){
                if(isset($done[$kind]))continue;
                $jobs=new SharePointArticleJob($this->pdo,$kind);$job=$jobs->load();$now=(int)call_user_func($clock);
                if(!in_array($job['state'] ?? '',['running','paused'],true)){
                    if(($status['next_'.$kind] ?? 0)>$now){$done[$kind]=true;continue;}
                    $job=$jobs->start($fingerprint);
                    $status['next_'.$kind]=$now+60*(int)$s[$kind.'_minutes'];
                }
                if($job['configuration']!==$fingerprint){$status[$kind.'_notice']='Configuração alterada: encerrar ou rever a execução pendente.';$done[$kind]=true;continue;}
                if($job['state']==='paused'){
                    if(!empty($job['manual_pause']) || empty($job['retry_at']) || $job['retry_at']>$now){$status[$kind.'_notice']='Execução pausada: consultar diagnóstico ou retomar manualmente.';$done[$kind]=true;continue;}
                    $job=$jobs->resume($job['run'],$fingerprint);
                }
                $batch=call_user_func($batchFactory,$kind);
                $job=$jobs->step($job['run'],$fingerprint,$batch);$progress=true;
                $status[$kind]=['state'=>$job['state'],'processed'=>$job['processed'],'total'=>$job['total'],'blocked'=>$job['blocked']];
                unset($status[$kind.'_notice']);
                if($job['state']==='complete'){
                    $status['last_'.$kind.'_completed']=gmdate('c');
                    $status['next_'.$kind]=(int)call_user_func($clock)+60*(int)$s[$kind.'_minutes'];$done[$kind]=true;
                }elseif($job['state']==='paused')$done[$kind]=true;
                $this->write('sharepoint.schedule.status.v1',$status);
                if(call_user_func($clock)-$begin>=$budget)break;
            }
            if(!$progress || count($done)===2)break;
        }
        $status['state']='idle';$this->write('sharepoint.schedule.status.v1',$status);return $status;
    }
}
