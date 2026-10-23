<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
$dir=sys_get_temp_dir().'/gestisser-sp-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    $integration=new SharePointIntegration($pdo,$dir);
    check($integration->overview()['state']==='Por configurar','initial listing state');
    $settings=$integration->settings(); $settings['tenant_id']=$settings['client_id']='11111111-1111-1111-1111-111111111111'; $settings['drive_id']='drive';
    $integration->save($settings); check($integration->settings()['drive_id']==='drive','settings roundtrip');
    check($integration->overview()['state']==='Não testado','saved listing state');
    $before=scandir($dir); $result=$integration->testStorage(); check($result['state']==='Ligado','local read/write'); check(scandir($dir)===$before,'temporary cleanup');
    $bad=$settings; $bad['site_url']='https://localhost';
    try { $integration->save($bad); throw new LogicException('SSRF accepted'); } catch (RuntimeException $e) { check(!($e instanceof LogicException),'SSRF rejected'); }
    $result=$integration->diagnostic('graph',function () { throw new RuntimeException('permission failure'); });
    check($result['state']==='Erro' && $integration->lastDiagnostic('graph')['error']==='permission failure','persist failure');
    check($integration->overview()['state']==='Erro','error visible in listing');
    $settings['site_id']='changed'; $integration->save($settings);
    check($integration->lastDiagnostic('graph')===[] && $integration->overview()['state']==='Não testado','configuration change invalidates results');
    echo "PASS: configuration, URL validation, local integrity and cleanup, safe diagnostic persistence and configuration invalidation. Graph scenarios are covered by sharepoint_diagnostics_test.php.\n";
} finally { rmdir($dir); }
