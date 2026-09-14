<?php
require_once __DIR__.'/IntegrationClient.php';
require_once __DIR__.'/IntegrationSecurity.php';

/**
 * Performs a non-mutating integration probe.
 *
 * The configured POST/PUT/PATCH/DELETE is deliberately never executed here.
 * OPTIONS is defined by HTTP as a safe method and lets us validate DNS, TLS,
 * authentication, routing and the remote response without changing its data.
 */
class IntegrationTester
{
    private $pdo;
    private $client;

    public function __construct(PDO $pdo, IntegrationClient $client = null)
    {
        $this->pdo = $pdo;
        $this->client = $client ?: new IntegrationClient($pdo);
    }

    public static function requestPreview(array $flow, string $url): array
    {
        return [
            'mode' => 'non_mutating_probe',
            'probe_method' => 'OPTIONS',
            'configured_request' => [
                'method' => strtoupper((string)$flow['http_method']),
                'url' => $url,
                'payload_type' => (string)$flow['payload_type'],
                'payload_preview' => (string)$flow['payload_template'],
            ],
            'integrity_notice' => 'A requisição configurada foi apenas pré-visualizada; nenhum payload foi enviado.',
        ];
    }

    public function probeFlow(int $flowId, int $user = 0): array
    {
        $statement = $this->pdo->prepare('SELECT f.*,i.base_url,i.auth_type,i.timeout,i.retries,i.retry_delay,i.verify_ssl,i.id AS integration_pk FROM integration_flows f JOIN integrations i ON i.id=f.integration_id WHERE f.id=?');
        $statement->execute([$flowId]);
        $flow = $statement->fetch();
        if (!$flow) {
            throw new RuntimeException('Fluxo não encontrado.');
        }

        $url = rtrim((string)$flow['base_url'], '/').'/'.ltrim((string)$flow['endpoint'], '/');
        $preview = self::requestPreview($flow, $url);
        $payload = IntegrationSecurity::redact(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->pdo->prepare('INSERT INTO integration_runs(integration_id,flow_id,operation,status,dry_run,payload,executed_by)VALUES(?,?,?,?,?,?,?)')->execute([$flow['integration_id'],$flowId,'PROBE','running',1,$payload,$user?:null]);
        $runId = (int)$this->pdo->lastInsertId();
        $started = microtime(true);

        try {
            $integration = $flow;
            $integration['id'] = $flow['integration_pk'];
            // A single probe avoids retries adding noise and never includes a body.
            $integration['retries'] = 0;
            $response = $this->client->request($integration, 'OPTIONS', (string)$flow['endpoint'], null, [
                'X-GesTisser-Probe' => 'non-mutating',
            ]);
            $duration = (int)((microtime(true)-$started)*1000);
            $communicated = $response['error'] === '' && (int)$response['status'] > 0;
            $accepted = $communicated && (int)$response['status'] >= 200 && (int)$response['status'] < 400;
            $status = $accepted ? 'success' : 'error';
            $message = $accepted
                ? 'Comunicação e autenticação aceites por OPTIONS (HTTP '.(int)$response['status'].'); nenhum dado foi alterado.'
                : ($communicated
                    ? 'O destino respondeu ao teste sem alterar dados, mas recusou OPTIONS (HTTP '.(int)$response['status'].'). Consulte a resposta.'
                    : ($response['error'] ?: 'O destino não devolveu uma resposta HTTP.'));
            $received = "Cabeçalhos recebidos:\n".(string)$response['headers']."\nCorpo recebido:\n".(string)$response['body'];
            $preview['probe_request_sent'] = [
                'method'=>'OPTIONS',
                'url'=>$response['url'],
                'header_names'=>$response['request_header_names'],
                'body'=>null,
            ];
            $payload = IntegrationSecurity::redact(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->pdo->prepare('UPDATE integration_runs SET status=?,duration_ms=?,request_url=?,http_method="OPTIONS",http_status=?,payload=?,response=?,error_message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?')->execute([
                $status,
                $duration,
                IntegrationSecurity::redact($response['url']),
                (int)$response['status'],
                $payload,
                IntegrationSecurity::redact(substr($received,0,20000)),
                $status === 'error' ? IntegrationSecurity::redact($message) : null,
                $runId,
            ]);
            if ($accepted) {
                $this->pdo->prepare('UPDATE integration_flows SET dry_run_passed_at=CURRENT_TIMESTAMP,last_result=?,last_duration_ms=? WHERE id=?')->execute(['probe_success',$duration,$flowId]);
            }

            return [
                'run_id'=>$runId,'status'=>$status,'read'=>0,'sent'=>0,'errors'=>$accepted?0:1,
                'duration'=>$duration,'message'=>$message,'http_status'=>(int)$response['status'],
                'request_header_names'=>$response['request_header_names'],'preview'=>$preview,
            ];
        } catch (Throwable $exception) {
            $this->pdo->prepare('UPDATE integration_runs SET status="error",error_count=1,error_message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?')->execute([IntegrationSecurity::redact($exception->getMessage()),$runId]);
            throw $exception;
        }
    }
}
