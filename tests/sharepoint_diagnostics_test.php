<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
function verify($condition, $label) { if (!$condition) throw new RuntimeException($label); }
$settings = ['tenant_id'=>'11111111-1111-1111-1111-111111111111', 'client_id'=>'22222222-2222-2222-2222-222222222222', 'site_id'=>'host,site,web', 'list_id'=>'list', 'drive_id'=>'drive'];
$claims = ['aud'=>'00000003-0000-0000-c000-000000000000', 'tid'=>$settings['tenant_id'], 'appid'=>$settings['client_id'], 'roles'=>['Sites.Selected'], 'exp'=>time()+3600];
function jwtForTest($claims) { return 'header.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.signature'; }
$token = jwtForTest($claims);
$secret = 'SECRET_SENTINEL_123';
$requestId = '33333333-3333-3333-3333-333333333333';
$run = function ($token, $statuses) use ($settings, $secret, $requestId) {
    $calls = [];
    $transport = function ($url, $form, $bearer) use (&$calls, $token, $statuses, $settings, $secret, $requestId) {
        $calls[] = $url;
        if (count($calls) === 1) {
            verify($form['grant_type'] === 'client_credentials', 'application grant');
            verify($form['scope'] === 'https://graph.microsoft.com/.default', 'Graph scope');
            verify($form['client_id'] === $settings['client_id'] && $form['client_secret'] === $secret, 'configured app credentials');
            verify($bearer === '', 'no reused token');
            return ['status'=>200, 'body'=>json_encode(['access_token'=>$token]), 'headers'=>[]];
        }
        verify($form === null && $bearer === $token, 'GET uses freshly issued app token');
        $status = $statuses[count($calls)-2];
        return ['status'=>$status, 'connection_error'=>$status === 0,
            'headers'=>['request-id'=>$requestId],
            'body'=>json_encode(['error'=>['code'=>'accessDenied', 'message'=>$secret.' '.$token]])];
    };
    $client = new SharePointGraphClient($settings, $transport);
    $result = $client->diagnose($secret);
    verify(strpos(json_encode($result), $secret) === false && strpos(json_encode($result), $token) === false, 'no secret or token persisted');
    $reflection = new ReflectionProperty($client, 'token'); $reflection->setAccessible(true);
    verify($reflection->getValue($client) === '', 'token cleared after diagnosis');
    return [$result, $calls];
};
list($result, $calls) = $run($token, [403,404,401]);
verify(count($calls) === 4, 'all independent requests run after failures');
verify(substr($calls[1], -strlen('/sites/host%2Csite%2Cweb')) === '/sites/host%2Csite%2Cweb', 'configured site ID used');
verify(substr($calls[2], -11) === '/lists/list', 'exact list request');
verify(substr($calls[3], -13) === '/drives/drive', 'exact drive request');
foreach ([403,404,401] as $index=>$status) {
    verify($result['requests'][$index+1]['http_status'] === $status, 'individual HTTP status');
    verify($result['requests'][$index+1]['request_id'] === $requestId, 'request ID captured');
    verify($result['requests'][$index+1]['graph_error_code'] === 'accessDenied', 'Graph machine code');
}
verify(count(array_unique(array_column(array_slice($result['requests'],1), 'message'))) === 3, 'different HTTP messages');
list($success) = $run($token, [200,200,200]);
verify(!isset($success['error']) && $success['write_verified'] === false, 'read success never validates writes');
list($network) = $run($token, [0,200,200]);
verify($network['requests'][1]['http_status'] === null && strpos($network['requests'][1]['message'], 'ligação') !== false, 'connection error distinct');
foreach (['aud'=>'wrong', 'tid'=>'wrong', 'appid'=>'wrong', 'roles'=>[], 'scp'=>'Sites.Read.All', 'idtyp'=>'user', 'exp'=>1] as $key=>$value) {
    $bad = $claims; $bad[$key] = $value;
    list($result, $calls) = $run(jwtForTest($bad), []);
    verify(count($calls) === 1 && count($result['requests']) === 4, 'invalid claims block Graph and mark requests skipped');
}
$bad = $claims; $bad['azp'] = 'wrong';
list($result, $calls) = $run(jwtForTest($bad), []);
verify(count($calls) === 1, 'conflicting app identities rejected');
list($result, $calls) = $run('opaque-token', []);
verify(count($calls) === 1, 'uninspectable token blocks Graph');
$client = new SharePointGraphClient($settings, function () use ($secret) { return ['status'=>401, 'body'=>json_encode(['error'=>'invalid_client','error_description'=>$secret])]; });
$failure = $client->diagnose($secret);
verify($failure['requests'][0]['http_status'] === 401 && strpos(json_encode($failure), $secret) === false, 'OAuth failure safe');
verify($failure['requests'][0]['oauth_error_code'] === 'invalid_client' && count($failure['requests']) === 4, 'OAuth error code and skipped Graph results');
$client = new SharePointGraphClient($settings, function () use ($secret) { throw new RuntimeException($secret); });
$failure = $client->diagnose($secret);
verify(strpos(json_encode($failure), $secret) === false && count($failure['requests']) === 4, 'transport exceptions redacted and reported');
$v2 = $claims; unset($v2['appid']); $v2['azp'] = $settings['client_id'];
list($result, $calls) = $run(jwtForTest($v2), [200,200,200]);
verify(count($calls) === 4 && !isset($result['error']), 'v2 azp application identity supported');
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$integration = new SharePointIntegration($pdo, sys_get_temp_dir());
$integration->diagnostic('graph', function () use ($result) { return $result; });
$saved = $integration->lastDiagnostic('graph');
verify($saved['requests'] === $result['requests'] && strpos(json_encode($saved), $secret) === false, 'safe individual results persisted');
verify($integration->overview()['state'] === 'Leitura validada', 'existing integrations overview supported');
echo "PASS: separate OAuth and Graph requests, configured application identity, claims checks, independent failures, safe output, status messages and token cleanup.\n";
