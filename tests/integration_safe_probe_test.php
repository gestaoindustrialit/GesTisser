<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/integrations/IntegrationTester.php';

function safe_probe_assert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$preview = IntegrationTester::requestPreview([
    'http_method'=>'DELETE',
    'payload_type'=>'json',
    'payload_template'=>'{"id":123}',
], 'https://api.example.com/customers/123');

safe_probe_assert($preview['probe_method'] === 'OPTIONS', 'The probe must only use OPTIONS.');
safe_probe_assert($preview['configured_request']['method'] === 'DELETE', 'The intended method must remain visible in the preview.');
safe_probe_assert($preview['configured_request']['payload_preview'] === '{"id":123}', 'The intended payload must remain visible in the preview.');
safe_probe_assert(strpos($preview['integrity_notice'], 'nenhum payload foi enviado') !== false, 'The preview must explicitly state that no payload was sent.');

$testerSource = file_get_contents(dirname(__DIR__).'/includes/integrations/IntegrationTester.php');
safe_probe_assert(strpos($testerSource, "request(\$integration, 'OPTIONS'") !== false, 'The network probe must use OPTIONS.');
safe_probe_assert(strpos($testerSource, "request(\$integration, 'POST'") === false, 'The safe tester must never issue POST.');
safe_probe_assert(strpos($testerSource, "request(\$integration, 'DELETE'") === false, 'The safe tester must never issue DELETE.');

echo "Integration safe probe: OK\n";
