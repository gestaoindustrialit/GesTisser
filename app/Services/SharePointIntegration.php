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
    private $token;
    private $transport;
    private $requests = [];
    public function __construct(array $settings, callable $transport = null)
    { $this->settings=$settings; $this->transport=$transport; }

    // Persist only allowlisted protocol metadata, never remote messages or bodies.
    private function safeCode($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/D', $value) ? $value : null;
    }
    private function requestId($value)
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) ? $value : null;
    }
    private function request($url, $form = null)
    {
        $entry=['method'=>$form === null ? 'GET' : 'POST', 'endpoint'=>$url,
            'http_status'=>null, 'graph_error_code'=>null, 'request_id'=>null];
        $headers=[];
        try {
            if ($this->transport) {
                // The injectable transport returns the same HTTP envelope as cURL.
                $response=call_user_func($this->transport, $url, $form);
                $code=(int)$response['status'];
                $body=$response['body'];
                foreach (($response['headers'] ?? []) as $key=>$value) $headers[strtolower($key)]=$value;
            } else {
                if (!function_exists('curl_init')) throw new RuntimeException('cURL unavailable');
                $c=curl_init($url);
                $options=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
                    CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_HEADERFUNCTION=>function ($handle, $line) use (&$headers) {
                        $parts=explode(':', $line, 2);
                        if (count($parts)===2 && strtolower(trim($parts[0]))==='request-id') $headers['request-id']=trim($parts[1]);
                        return strlen($line);
                    }];
                if ($form !== null) { $options[CURLOPT_POST]=true; $options[CURLOPT_POSTFIELDS]=http_build_query($form); }
                else $options[CURLOPT_HTTPHEADER]=['Authorization: Bearer '.$this->token, 'Accept: application/json'];
                curl_setopt_array($c,$options);
                $body=curl_exec($c); $code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
                curl_close($c);
            }
            $entry['http_status']=$code;
            $data=is_string($body) ? json_decode($body,true) : null;
            $entry['request_id']=$this->requestId($headers['request-id'] ?? null);
            if ($code < 200 || $code >= 300) {
                $entry['graph_error_code']=$this->safeCode($data['error']['code'] ?? null);
                if (!$entry['request_id']) $entry['request_id']=$this->requestId($data['error']['innerError']['request-id'] ?? $data['error']['innererror']['request-id'] ?? null);
                $entry['outcome']='http_error';
                $this->requests[]=$entry;
                return null;
            }
            if (!is_array($data)) throw new RuntimeException('Invalid response');
            $entry['outcome']='ok'; $this->requests[]=$entry;
            return $data;
        } catch (Throwable $e) {
            // Injected transport exceptions may also contain sensitive content.
            $entry['outcome']='transport_or_response_error'; $this->requests[]=$entry;
            return null;
        }
    }
    private function sameUrl($actual, $expected)
    {
        $a=parse_url((string)$actual); $b=parse_url((string)$expected);
        return $a && $b && ($a['scheme'] ?? '')==='https' && ($b['scheme'] ?? '')==='https'
            && !isset($a['user']) && !isset($a['pass']) && !isset($a['port']) && !isset($a['query']) && !isset($a['fragment'])
            && strcasecmp($a['host'] ?? '', $b['host'] ?? '')===0
            && rtrim(rawurldecode($a['path'] ?? ''),'/')===rtrim(rawurldecode($b['path'] ?? ''),'/');
    }
    private function driveBelongsToSite(array $resource, array $site)
    {
        $ids=$resource['sharepointIds'] ?? [];
        $parts=explode(',', $site['id']);
        if (count($parts)!==3 || empty($ids['siteId']) || empty($ids['webId'])) return false;
        return strcasecmp(trim($ids['siteId'],'{}'),$parts[1])===0
            && strcasecmp(trim($ids['webId'],'{}'),$parts[2])===0
            && $this->sameUrl($ids['siteUrl'] ?? '', $site['webUrl']);
    }
    public function diagnose($secret)
    {
        $this->requests=[]; $this->token=null;
        $result=['state'=>'Erro','write_verified'=>false,'roles_checked'=>false,'sites_selected_present'=>false,
            'first_403_endpoint'=>null];
        try {
            $s=$this->settings;
            if (empty($s['tenant_id']) || empty($s['client_id']) || $secret==='') throw new RuntimeException('Configure Tenant ID, Client ID e o segredo no servidor.');
            if (!preg_match('/^[0-9a-f-]{36}$/iD',$s['tenant_id'])) throw new RuntimeException('Tenant ID inválido.');
            foreach (['site_id','list_id','drive_id'] as $key) {
                if (!isset($s[$key]) || !is_string($s[$key]) || trim($s[$key])==='') throw new RuntimeException('Configure Site ID, List ID e Drive ID antes de testar.');
            }
            $token=$this->request('https://login.microsoftonline.com/'.rawurlencode($s['tenant_id']).'/oauth2/v2.0/token',
                ['client_id'=>$s['client_id'],'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']);
            if (empty($token['access_token']) || !is_string($token['access_token'])) throw new RuntimeException('Não foi possível obter o access token Microsoft.');
            $this->token=$token['access_token']; unset($token);
            // Inspect only roles from the token obtained over TLS. This is not JWT signature validation.
            $parts=explode('.',$this->token);
            $claims=count($parts)===3 ? json_decode(base64_decode(strtr($parts[1],'-_','+/'),true),true) : null;
            $roles=$claims['roles'] ?? null;
            $result['roles_checked']=is_array($roles);
            $result['sites_selected_present']=is_array($roles) && in_array('Sites.Selected',$roles,true);
            unset($parts,$claims,$roles);
            $base='https://graph.microsoft.com/v1.0';
            $sitePath=$base.'/sites/'.rawurlencode($s['site_id']);
            $listPath=$sitePath.'/lists/'.rawurlencode($s['list_id']);
            $drivePath=$base.'/drives/'.rawurlencode($s['drive_id']);
            // Continue independent read-only checks even when an earlier endpoint is forbidden.
            $site=$this->request($sitePath);
            $list=$this->request($listPath);
            $columns=$this->request($listPath.'/columns');
            $drive=$this->request($drivePath.'?$select=id,driveType,sharepointIds');
            $root=$this->request($drivePath.'/root?$select=id,folder,parentReference,sharepointIds');
            foreach ($this->requests as $request) {
                if ($request['outcome']!=='ok') throw new RuntimeException('Falha num pedido Microsoft; consulte os resultados individuais.');
            }
            if (!$result['sites_selected_present']) throw new RuntimeException('Não foi possível confirmar Sites.Selected na claim roles do access token.');
            if (($site['id'] ?? '')!==$s['site_id'] || !$this->sameUrl($site['webUrl'] ?? '',$s['site_url'])) throw new RuntimeException('Site ID não corresponde ao URL configurado.');
            if (($list['id'] ?? '')!==$s['list_id'] || !$this->sameUrl($list['webUrl'] ?? '',rtrim($site['webUrl'],'/').'/Lists/Artigos')) throw new RuntimeException('O List ID configurado não corresponde à lista Artigos deste site.');
            if (!isset($columns['value']) || !is_array($columns['value'])) throw new RuntimeException('Resposta de colunas inválida.');
            if (($drive['id'] ?? '')!==$s['drive_id'] || ($drive['driveType'] ?? '')!=='documentLibrary'
                || empty($root['id']) || !isset($root['folder'])
                || ($root['parentReference']['driveId'] ?? '')!==$s['drive_id']
                || (!empty($drive['sharepointIds']) && !$this->driveBelongsToSite($drive,$site))
                || (!empty($root['sharepointIds']) && !$this->driveBelongsToSite($root,$site))
                || (!$this->driveBelongsToSite($drive,$site) && !$this->driveBelongsToSite($root,$site))) {
                throw new RuntimeException('Não foi possível confirmar que a biblioteca documental pertence ao site configurado.');
            }
            $result['state']='Leitura validada; escrita não testada';
            $result['site_id']=$s['site_id']; $result['list_id']=$s['list_id']; $result['drive_id']=$s['drive_id'];
            // Column definitions can contain defaults and other sensitive values: never persist them.
            $result['column_count']=count($columns['value']);
            $result['columns_has_more']=!empty($columns['@odata.nextLink']);
        } catch (RuntimeException $e) { $result['error']=$e->getMessage(); }
        finally { $this->token=null; }
        foreach ($this->requests as $request) {
            if ($request['http_status']===403) { $result['first_403_endpoint']=$request['endpoint']; break; }
        }
        if ($result['first_403_endpoint']) $result['error']='Primeiro HTTP 403: '.$result['first_403_endpoint'].'. Consulte o código Graph e request-id abaixo.';
        $result['requests']=$this->requests;
        return $result;
    }
}
