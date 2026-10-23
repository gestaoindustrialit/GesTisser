<?php
/** Server-only secret reader. Never include/execute the private file. */
final class SharePointClientSecret
{
    public static function read($path = '/home/tisser/gestisser-config/sharepoint-client-secret')
    {
        $value = getenv('GESTISSER_SHAREPOINT_CLIENT_SECRET');
        if ($value !== false && trim($value) !== '') return $value;
        // Fail closed for symlinks, non-regular files and shared permissions.
        clearstatcache(true, $path);
        $directory = dirname($path);
        $dir = @lstat($directory);
        $file = @lstat($path);
        if (!$dir || !$file || ($dir['mode'] & 0170000) !== 0040000
            || ($file['mode'] & 0170000) !== 0100000
            || ($dir['mode'] & 0077) !== 0 || ($file['mode'] & 0077) !== 0
            || $dir['uid'] !== $file['uid']
            || (function_exists('posix_geteuid') && $file['uid'] !== posix_geteuid())) return '';
        $handle = @fopen($path, 'rb');
        if (!$handle) return '';
        try {
            $opened = @fstat($handle);
            if (!$opened || $opened['ino'] !== $file['ino'] || $opened['dev'] !== $file['dev']
                || $opened['uid'] !== $file['uid'] || ($opened['mode'] & 0077) !== 0) return '';
            $value = @stream_get_contents($handle, 8193);
        } finally { fclose($handle); }
        if ($value === false || strlen($value) > 8192) return '';
        $value = rtrim($value, "\r\n");
        if (trim($value) === '' || strpbrk($value, "\r\n\0") !== false) return '';
        return $value;
    }
    public static function available()
    {
        return self::read() !== '';
    }
}

/** Phase 1: configuration and read-only diagnostics. PHP 7.0, no SDK. */
final class SharePointIntegration
{
    private $pdo;
    private $defaults;
    public function __construct(PDO $pdo, $storage)
    {
        $this->pdo = $pdo;
        $this->defaults = ['tenant_id'=>'', 'client_id'=>'', 'site_url'=>'https://tisserpt.sharepoint.com', 'site_id'=>'', 'list_id'=>'', 'drive_id'=>'', 'storage_path'=>$storage, 'max_bytes'=>20971520, 'automatic'=>false];
    }
    public function settings()
    {
        $s = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $s->execute(['sharepoint.configuration.v1']);
        return array_merge($this->defaults, (array) json_decode((string) $s->fetchColumn(), true));
    }
    /** Read-only listing entry: installing/viewing the connector never activates it. */
    public function overview()
    {
        $settings = $this->settings();
        $remote = $this->lastDiagnostic('graph');
        $local = $this->lastDiagnostic('cpanel');
        $result = ['state'=>'Por configurar', 'style'=>'secondary', 'checked_at'=>null,
            'message'=>'Configure o acesso e execute os testes de ligação.'];
        $dates = array_filter([$remote['checked_at'] ?? null, $local['checked_at'] ?? null]);
        if ($dates) $result['checked_at'] = max($dates);
        if (($local['state'] ?? '') === 'Erro' || ($remote['state'] ?? '') === 'Erro') {
            $result['state'] = 'Erro'; $result['style'] = 'danger';
            $result['message'] = ($remote['state'] ?? '') === 'Erro' ? $remote['error'] : $local['error'];
        } elseif (!empty($remote['list_id']) && !empty($remote['drive_id'])) {
            $result['state'] = 'Leitura validada'; $result['style'] = 'warning';
            $result['message'] = 'Escrita não testada. Sincronização indisponível.';
        } elseif ($settings['tenant_id'] !== '' && $settings['client_id'] !== '') {
            $result['state'] = 'Não testado';
            $result['message'] = 'Configuração guardada; acesso Microsoft por validar.';
        }
        return $result;
    }
    public function save(array $input)
    {
        $s = $this->defaults;
        foreach ($s as $key=>$default) {
            if (isset($input[$key])) $s[$key] = trim((string) $input[$key]);
        }
        foreach (['tenant_id','client_id'] as $key) {
            if ($s[$key] !== '' && !preg_match('/^[0-9a-f-]{36}$/iD', $s[$key])) throw new RuntimeException('Tenant ID e Client ID devem ser UUID.');
        }
        $url = parse_url($s['site_url']);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || !preg_match('/^[a-z0-9-]+\.sharepoint\.com$/iD', $url['host'] ?? '') || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment'])) throw new RuntimeException('URL SharePoint inválido. Utilize HTTPS num domínio sharepoint.com.');
        $path = realpath($s['storage_path']);
        if (!$path || !is_dir($path)) throw new RuntimeException('O diretório local deve existir. A configuração não move os anexos existentes.');
        $s['storage_path'] = $path;
        $s['max_bytes'] = filter_var($s['max_bytes'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$s['max_bytes']) throw new RuntimeException('Limite de tamanho inválido.');
        // Automatic processing is deliberately unavailable until worker acceptance.
        $s['automatic'] = false;
        $this->pdo->beginTransaction();
        try {
            $previous = $this->settings();
            $this->pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute(['sharepoint.configuration.v1']);
            $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES (?,?)')->execute(['sharepoint.configuration.v1', json_encode($s)]);
            if ($previous != $s) {
                $this->pdo->prepare('DELETE FROM app_settings WHERE setting_key IN (?,?)')->execute(['sharepoint.diagnostic.graph', 'sharepoint.diagnostic.cpanel']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
    public function testStorage()
    {
        $s = $this->settings(); $path = realpath($s['storage_path']);
        if (!$path || !is_dir($path) || !is_readable($path) || !is_writable($path)) throw new RuntimeException('Diretório inexistente ou sem permissões de leitura/escrita.');
        $file = $path . '/.gestisser-diagnostic-' . bin2hex(random_bytes(16));
        $handle = @fopen($file, 'x+b');
        if (!$handle) throw new RuntimeException('Não foi possível criar o ficheiro temporário.');
        try {
            $content = random_bytes(64);
            if (fwrite($handle, $content) !== 64 || !fflush($handle) || !rewind($handle) || fread($handle, 65) !== $content) throw new RuntimeException('Falha na verificação de integridade local.');
        } finally { fclose($handle); if (!@unlink($file)) throw new RuntimeException('Não foi possível apagar o ficheiro temporário de diagnóstico.'); }
        $free = @disk_free_space($path);
        return ['state'=>'Ligado', 'free_bytes'=>$free === false ? null : $free, 'max_bytes'=>$s['max_bytes'], 'checks'=>['Diretório', 'Leitura', 'Escrita', 'Integridade', 'Remoção']];
    }
    public function diagnostic($service, callable $test)
    {
        $key = 'sharepoint.diagnostic.' . $service;
        try { $result = call_user_func($test); }
        catch (Throwable $e) { $result = ['state'=>'Erro', 'error'=>$e->getMessage()]; }
        $result['checked_at'] = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([$key]);
            $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES (?,?)')->execute([$key,json_encode($result)]);
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return $result;
    }
    public function lastDiagnostic($service)
    {
        $s=$this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $s->execute(['sharepoint.diagnostic.'.$service]);
        return (array) json_decode((string)$s->fetchColumn(), true);
    }
}

final class SharePointGraphClient
{
    private $settings;
    private $transport;
    private $token = '';
    public function __construct(array $settings, callable $transport = null)
    { $this->settings = $settings; $this->transport = $transport; }
    private function request($url, $form = null)
    {
        if ($this->transport) return call_user_func($this->transport, $url, $form, $this->token);
        if (!function_exists('curl_init')) return ['status'=>0, 'body'=>'', 'headers'=>[], 'connection_error'=>true];
        $headers = [];
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>function ($handle, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'request-id') $headers['request-id'] = trim($parts[1]);
                return strlen($line);
            }
        ]);
        if ($form !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($form, '', '&'));
            curl_setopt($c, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        } else curl_setopt($c, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.$this->token, 'Accept: application/json']);
        $body = curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return ['status'=>$status, 'body'=>$body === false ? '' : $body, 'headers'=>$headers, 'connection_error'=>$body === false];
    }
    private function safeId($value)
    {
        return is_string($value) && preg_match('/^[0-9a-f-]{36}$/iD', $value) ? $value : null;
    }
    private function result($endpoint, array $response)
    {
        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        $code = is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;
        $oauthCode = is_string($data['error'] ?? null) ? $data['error'] : null;
        if (!is_string($oauthCode) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $oauthCode)) $oauthCode = null;
        // Only structured machine codes and UUID correlation IDs may leave this class.
        if (!is_string($code) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/D', $code)) $code = null;
        $messages = [401=>'Autenticação recusada: token inválido, expirado ou com destinatário incorreto.',
            403=>'Acesso recusado: verificar concessão Sites.Selected para esta aplicação e este Site ID.',
            404=>'Recurso não encontrado ou ocultado por falta de acesso; verificar os IDs e a associação ao site.'];
        $ok = $status >= 200 && $status < 300 && empty($response['connection_error']);
        return ['endpoint'=>$endpoint, 'http_status'=>$status ?: null, 'graph_error_code'=>$code, 'oauth_error_code'=>$oauthCode,
            'request_id'=>$this->safeId($response['headers']['request-id'] ?? ($data['error']['innerError']['request-id'] ?? null)),
            'ok'=>$ok, 'message'=>!empty($response['connection_error']) || !$status
                ? 'Erro de ligação: verificar DNS, rede, TLS e disponibilidade do cURL.'
                : ($ok ? 'Pedido concluído.' : ($messages[$status] ?? 'Erro HTTP na comunicação com a Microsoft.'))];
    }
    private function claims($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        $part = strtr($parts[1], '-_', '+/');
        $decoded = base64_decode($part.str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        $claims = $decoded === false ? null : json_decode($decoded, true);
        return is_array($claims) ? $claims : null;
    }
    public function diagnose($secret)
    {
        $this->token = '';
        $s = $this->settings;
        $report = ['state'=>'Erro', 'error'=>'Diagnóstico incompleto.', 'write_verified'=>false,
            'requests'=>[], 'token_checks'=>[]];
        foreach (['tenant_id', 'client_id'] as $key) {
            if (!$this->safeId($s[$key] ?? null)) throw new RuntimeException('Configure Tenant ID e Client ID válidos.');
        }
        if (!is_string($secret) || trim($secret) === '') throw new RuntimeException('Client Secret indisponível no servidor.');
        try {
            try { $response = $this->request('https://login.microsoftonline.com/'.rawurlencode($s['tenant_id']).'/oauth2/v2.0/token',
                ['client_id'=>$s['client_id'], 'client_secret'=>$secret,
                'scope'=>'https://graph.microsoft.com/.default', 'grant_type'=>'client_credentials']); }
            catch (Throwable $e) { $response = ['connection_error'=>true]; }
            $oauth = $this->result('POST /{tenantId}/oauth2/v2.0/token', $response);
            $data = json_decode((string) ($response['body'] ?? ''), true);
            if (!$oauth['ok'] || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
                $oauth['ok'] = false;
                if ($oauth['http_status'] && $oauth['http_status'] >= 200 && $oauth['http_status'] < 300) $oauth['message'] = 'Resposta OAuth2 sem access_token válido.';
                $report['requests'][] = $oauth;
                foreach (['GET /sites/{siteId}', 'GET /sites/{siteId}/lists/{listId}', 'GET /sites/{siteId}/drives/{driveId}'] as $endpoint) {
                    $report['requests'][] = ['endpoint'=>$endpoint, 'http_status'=>null, 'graph_error_code'=>null, 'request_id'=>null, 'ok'=>false, 'message'=>'Não executado: obtenção do token falhou.'];
                }
                $report['error'] = 'Falha na obtenção do token OAuth2.';
                return $report;
            }
            $report['requests'][] = $oauth;
            $this->token = $data['access_token'];
            unset($data, $response);
            $c = $this->claims($this->token);
            $aud = $c['aud'] ?? '';
            $clientClaims = [];
            foreach (['appid', 'azp'] as $key) if (isset($c[$key])) $clientClaims[] = $c[$key];
            $clientOk = count($clientClaims) > 0;
            foreach ($clientClaims as $id) if (!is_string($id) || strcasecmp($id, $s['client_id']) !== 0) $clientOk = false;
            $checks = [
                'jwt_legivel'=>is_array($c),
                'destinatario_graph'=>in_array($aud, ['https://graph.microsoft.com', 'https://graph.microsoft.com/', '00000003-0000-0000-c000-000000000000'], true),
                'tenant_configurado'=>is_string($c['tid'] ?? null) && strcasecmp($c['tid'], $s['tenant_id']) === 0,
                'aplicacao_configurada'=>$clientOk,
                'sites_selected'=>is_array($c['roles'] ?? null) && in_array('Sites.Selected', $c['roles'], true),
                'sem_contexto_delegado'=>is_array($c) && !array_key_exists('scp', $c) && (!isset($c['idtyp']) || $c['idtyp'] === 'app'),
                'validade_temporal'=>is_numeric($c['exp'] ?? null) && $c['exp'] > time() && (!isset($c['nbf']) || (is_numeric($c['nbf']) && $c['nbf'] <= time()))
            ];
            $report['token_checks'] = $checks;
            $report['token_origin'] = 'Token novo obtido por client_credentials com o Client ID e segredo configurados; sem token de sessão ou do conector.';
            $report['claims_note'] = 'Inspeção local das claims; não constitui validação criptográfica da assinatura. O Graph valida o token nos pedidos.';
            if (in_array(false, $checks, true)) {
                $report['error'] = 'Token sem as características esperadas. Pedidos Graph não executados.';
            }
            $base = '/sites/'.rawurlencode($s['site_id'] ?? '');
            $targets = [
                ['GET /sites/{siteId}', $base, ['site_id']],
                ['GET /sites/{siteId}/lists/{listId}', $base.'/lists/'.rawurlencode($s['list_id'] ?? ''), ['site_id','list_id']],
                ['GET /sites/{siteId}/drives/{driveId}', $base.'/drives/'.rawurlencode($s['drive_id'] ?? ''), ['site_id','drive_id']]
            ];
            $allOk = !in_array(false, $checks, true);
            foreach ($targets as $target) {
                $missing = false;
                foreach ($target[2] as $key) if (empty($s[$key]) || !is_string($s[$key])) $missing = true;
                if (in_array(false, $checks, true)) {
                    $row = ['endpoint'=>$target[0], 'http_status'=>null, 'graph_error_code'=>null, 'request_id'=>null, 'ok'=>false, 'message'=>'Não executado: verificação do token falhou.'];
                } elseif ($missing) {
                    $row = ['endpoint'=>$target[0], 'http_status'=>null, 'graph_error_code'=>null, 'request_id'=>null, 'ok'=>false, 'message'=>'Não executado: ID não configurado.'];
                } else {
                    try { $row = $this->result($target[0], $this->request('https://graph.microsoft.com/v1.0'.$target[1])); }
                    catch (Throwable $e) { $row = $this->result($target[0], ['connection_error'=>true]); }
                }
                $report['requests'][] = $row;
                if (!$row['ok']) $allOk = false;
            }
            if ($allOk) {
                $report['state'] = 'Leitura validada; escrita não testada';
                unset($report['error']);
                foreach (['site_id','list_id','drive_id'] as $key) $report[$key] = $s[$key];
            } elseif (!in_array(false, $checks, true)) $report['error'] = 'Um ou mais pedidos Graph falharam. Consulte cada resultado.';
            return $report;
        } catch (Throwable $e) {
            // Do not persist exception messages: transports can include sensitive values.
            $report['error'] = 'Falha de ligação ou resposta inválida durante o diagnóstico.';
            return $report;
        } finally { $this->token = ''; }
    }
}

