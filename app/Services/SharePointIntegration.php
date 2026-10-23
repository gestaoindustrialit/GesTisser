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
    public function __construct(array $settings, callable $transport = null)
    { $this->settings=$settings; $this->transport=$transport; }
    private function request($url, $form = null)
    {
        if ($this->transport) return call_user_func($this->transport, $url, $form);
        if (!function_exists('curl_init')) throw new RuntimeException('Extensão cURL indisponível.');
        $c=curl_init($url);
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS];
        if ($form !== null) { $options[CURLOPT_POST]=true; $options[CURLOPT_POSTFIELDS]=http_build_query($form); }
        else $options[CURLOPT_HTTPHEADER]=['Authorization: Bearer '.$this->token,'Accept: application/json'];
        curl_setopt_array($c,$options); $body=curl_exec($c); $code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE); $errno=curl_errno($c); curl_close($c);
        if ($body === false) throw new RuntimeException('Falha de rede/TLS (cURL '.$errno.').');
        if ($code < 200 || $code >= 300) {
            $type=$code===401?'autenticação':($code===403?'permissões insuficientes':($code===429?'limite da API':($code===404?'recurso não encontrado':'comunicação')));
            // Never include remote response bodies: they may contain credentials or tokens.
            throw new RuntimeException('Microsoft: '.$type.' (HTTP '.$code.').');
        }
        $data=json_decode($body,true); if (!is_array($data)) throw new RuntimeException('Resposta Microsoft inválida.'); return $data;
    }
    private function collection($path)
    {
        $url='https://graph.microsoft.com/v1.0'.$path; $all=[]; $seen=[];
        while ($url) {
            if (strpos($url,'https://graph.microsoft.com/v1.0/')!==0 || isset($seen[$url]) || count($seen)>=100) throw new RuntimeException('Paginação Graph inválida ou superior ao limite de diagnóstico.');
            $seen[$url]=true; $data=$this->request($url); if (!isset($data['value']) || !is_array($data['value'])) throw new RuntimeException('Coleção Graph inválida.');
            $all=array_merge($all,$data['value']); $url=$data['@odata.nextLink'] ?? null;
        } return $all;
    }
    public function diagnose($secret)
    {
        $s=$this->settings;
        if (empty($s['tenant_id']) || empty($s['client_id']) || $secret==='') throw new RuntimeException('Configure Tenant ID, Client ID e o segredo no servidor.');
        if (!preg_match('/^[0-9a-f-]{36}$/iD',$s['tenant_id'])) throw new RuntimeException('Tenant ID inválido.');
        $token=$this->request('https://login.microsoftonline.com/'.rawurlencode($s['tenant_id']).'/oauth2/v2.0/token',['client_id'=>$s['client_id'],'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']);
        if (empty($token['access_token'])) throw new RuntimeException('Microsoft não devolveu token.'); $this->token=$token['access_token'];
        $url=parse_url($s['site_url']);
        if (($url['scheme'] ?? '')!=='https' || !preg_match('/^[a-z0-9-]+\.sharepoint\.com$/iD',$url['host'] ?? '')) throw new RuntimeException('Site inválido.');
        $site=$this->request('https://graph.microsoft.com/v1.0/sites/'.$url['host'].':'.($url['path'] ?? '/'));
        if (empty($site['id']) || (!empty($s['site_id']) && $site['id']!==$s['site_id'])) throw new RuntimeException('Site ID não corresponde ao URL configurado.');
        $base='/sites/'.rawurlencode($site['id']); $lists=$this->collection($base.'/lists'); $list=null;
        foreach ($lists as $candidate) {
            if (rtrim((string)($candidate['webUrl'] ?? ''),'/')===rtrim($s['site_url'],'/').'/Lists/Artigos') {
                if ($list) throw new RuntimeException('Lista Artigos ambígua.'); $list=$candidate;
            }
        }
        if (!$list || (!empty($s['list_id']) && $s['list_id']!==$list['id'])) throw new RuntimeException('Lista Artigos existente não identificada ou List ID divergente. Nenhuma lista foi criada.');
        $columns=$this->collection($base.'/lists/'.rawurlencode($list['id']).'/columns');
        $drives=$this->collection($base.'/drives'); $drive=null;
        foreach ($drives as $candidate) if (!empty($s['drive_id']) && $candidate['id']===$s['drive_id']) $drive=$candidate;
        if (!$drive) throw new RuntimeException('Configure o Drive ID de uma biblioteca documental acessível.');
        $this->request('https://graph.microsoft.com/v1.0/drives/'.rawurlencode($drive['id']).'/root');
        return ['state'=>'Leitura validada; escrita não testada','site_id'=>$site['id'],'list_id'=>$list['id'],'drive_id'=>$drive['id'],'columns'=>$columns,'write_verified'=>false];
    }
}
