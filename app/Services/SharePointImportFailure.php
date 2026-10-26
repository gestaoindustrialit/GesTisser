<?php
/** Allowlisted operational diagnostics; never retain bodies, tokens or remote prose. */
final class SharePointImportFailure extends RuntimeException
{
    private $details;
    public function __construct($endpoint,array $response)
    {
        $data=json_decode((string)($response['body'] ?? ''),true);
        $status=(int)($response['status'] ?? 0);
        $code=is_array($data['error'] ?? null)?($data['error']['code'] ?? null):($data['error'] ?? null);
        if(!is_string($code)||!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,79}$/D',$code))$code=null;
        $headers=array_change_key_case((array)($response['headers'] ?? []),CASE_LOWER);
        $id=$headers['request-id'] ?? $data['error']['innerError']['request-id'] ?? null;
        if(!is_string($id)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$id))$id=null;
        $retry=$headers['retry-after'] ?? null;
        $retry=is_scalar($retry)&&ctype_digit((string)$retry)?min(3600,max(1,(int)$retry)):null;
        $this->details=['endpoint'=>$endpoint,'http_status'=>$status?:null,'graph_error_code'=>$code,'request_id'=>$id,
            'retry_after'=>$retry,'transport_error'=>!empty($response['connection_error'])||!$status];
        parent::__construct('Falha em '.$endpoint.' (HTTP '.($status?:'sem resposta').').');
    }
    public function diagnostic(){return $this->details;}
    public static function safe(Throwable $e)
    {
        if($e instanceof self)return $e->diagnostic();
        if($e instanceof PDOException)return ['local_error'=>'Erro SQLite. Verifique disponibilidade e bloqueios da base de dados.'];
        $message=$e->getMessage();
        if($e instanceof RuntimeException && preg_match('/^(Coluna incompatível:|Coluna obrigatória sem mapeamento:|GesTISSER_ID deve|Paginação excedeu|Coleção SharePoint inválida|Lista excedeu|Pré-visualização expirada|Os dados locais, colunas|Confirmação de criação incompleta)/u',$message))return ['local_error'=>substr($message,0,240)];
        return ['local_error'=>'Falha local ou de transporte; nenhuma resposta sensível foi guardada.'];
    }
}
