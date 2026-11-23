<?php
require_once __DIR__ . '/../hr_organization_lib.php';
require_once __DIR__ . '/../app/Services/HrEpiSharePoint.php';
require_once __DIR__ . '/../app/Services/HrEpiPdf.php';

function epi_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function epi_reject(callable $callback, $message)
{
    try {
        $callback();
    } catch (HrEpiInputException $error) {
        epi_check(strpos($error->getMessage(), $message) !== false, $error->getMessage());
        return;
    }
    throw new RuntimeException('Era esperada uma rejeição: ' . $message);
}

$epi = new HrEpi($pdo);
$epi->migrate();
$epi->migrate();
$actors = [];
foreach (['Operador', 'Colaborador', 'Outra pessoa'] as $index => $name) {
    $epi->query('INSERT INTO users(name,email,password,user_number,is_active,pin_code) VALUES (?,?,?,?,1,?)',
        [$name, 'epi' . $index . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT), '00' . $index, $index === 1 ? '123456' : '']);
    $actors[] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
}
list($issuer, $employee, $other) = $actors;
$gloves = $epi->saveCatalog(['name' => 'Luvas de proteção', 'category' => 'Mãos', 'size' => 'L',
    'replacement_days' => '15', 'active' => '1'], $issuer);
$glasses = $epi->saveCatalog(['name' => 'Óculos de proteção', 'category' => 'Olhos', 'active' => '1'], $issuer);
$items = [
    ['epi_id' => $gloves, 'quantity' => '2', 'reason' => 'Primeira entrega'],
    ['epi_id' => $glasses, 'quantity' => '1', 'reason' => 'Reposição']
];
$invalid = $items;
$invalid[1]['quantity'] = '0';
epi_reject(function () use ($epi, $employee, $issuer, $invalid) {
    $epi->deliver($employee['id'], $invalid, $issuer);
}, 'Número inválido');
epi_check((int) $epi->query('SELECT COUNT(*) FROM hr_epi_operations')->fetchColumn() === 0, 'O cabeçalho ficou gravado após o rollback.');
epi_check((int) $epi->query('SELECT COUNT(*) FROM hr_epi_items')->fetchColumn() === 0, 'Uma linha ficou gravada após o rollback.');

$operation = $epi->deliver($employee['id'], $items, $issuer);
$original = $epi->history(['employee' => $employee['id']]);
epi_check(count($original) === 2 && $original[0]['operation_id'] === $original[1]['operation_id'], 'A entrega devia agrupar duas linhas.');
epi_check($original[0]['size'] === 'L' && $original[0]['replacement_date'] !== null && $original[1]['replacement_date'] === null, 'Dados da periodicidade ou tamanhos incorretos.');
epi_check($epi->operation($operation)['confirmed_at'] === null, 'A entrega devia ficar pendente.');
epi_check(count($epi->history(['employee' => $employee['id'], 'category' => 'Olhos', 'equipment' => $glasses])) === 1, 'Filtros do histórico.');
epi_check(!$epi->history(['employee' => $other['id']]), 'O histórico de outro colaborador devia estar vazio.');
epi_reject(function () use ($epi, $operation, $other) {
    $epi->confirm($operation, $other, true, true, '123456');
}, 'Entrega indisponível');
epi_reject(function () use ($epi, $operation, $employee) {
    $epi->confirm($operation, $employee, false, false, '123456');
}, 'Aceite a declaração');

$lists = $columns = $remote = [];
$writes = 0;
$loseResponse = true;
$connect = function ($operation) use (&$lists, &$columns, &$remote, &$writes, &$loseResponse) {
    return $operation(function ($method, $path, $body = null) use (&$lists, &$columns, &$remote, &$writes, &$loseResponse) {
        if ($path === '') {
            return ['displayName' => 'Tisser Central'];
        }
        if ($method === 'GET' && strpos($path, '/lists?') === 0) {
            return ['value' => array_values($lists)];
        }
        if ($method === 'POST' && $path === '/lists') {
            $id = (string) (count($lists) + 1);
            $lists[$id] = ['id' => $id, 'displayName' => $body['displayName']];
            $columns[$id] = $body['columns'];
            if ($id === '1') {
                $columns[$id][0]['id'] = '123abc';
                $columns[$id][0]['enforceUniqueValues'] = false;
            }
            return $lists[$id];
        }
        preg_match('~^/lists/([0-9]+)/([^/?]+)~', $path, $parts);
        $id = $parts[1];
        if ($parts[2] === 'columns') {
            if ($method === 'PATCH') {
                epi_check($path === '/lists/1/columns/123abc' && $body['enforceUniqueValues'] === true, 'Ajuste da coluna existente.');
                $columns[$id][0]['enforceUniqueValues'] = true;
                return $columns[$id][0];
            }
            return ['value' => $columns[$id]];
        }
        if ($method === 'GET') {
            preg_match("/'([a-f0-9]{32})'/", rawurldecode($path), $key);
            return ['value' => isset($remote[$id][$key[1]]) ? [$remote[$id][$key[1]]] : []];
        }
        $fields = $body['fields'];
        $key = $fields['EntregaID'] ?? $fields['EPI_ID'];
        if ($method === 'POST') {
            epi_check(!isset($remote[$id][$key]), 'Criação duplicada.');
            $remote[$id][$key] = ['id' => (string) (count($remote[$id] ?? []) + 1), 'eTag' => 'fixture', 'fields' => $fields];
            $writes++;
            if ($loseResponse) {
                $loseResponse = false;
                throw new SharePointImportFailure('POST /items', ['status' => 503, 'headers' => ['Retry-After' => '10']]);
            }
            return $remote[$id][$key];
        }
        epi_check($method === 'PATCH' && $body['etag'] === 'fixture', 'Atualização Graph devia usar ETag.');
        $remote[$id][$key]['fields'] = $fields;
        return [];
    });
};
$sync = new HrEpiSharePoint($pdo);
epi_check($sync->process($connect) === 0 && $writes === 1, 'Simulação de perda de resposta.');
epi_check($sync->process($connect) === 0, 'Tentativa automática demasiado cedo.');
epi_check(HrEpi::syncStatus($epi->history(['operation' => $operation])[0]) === 'Erro', 'O erro de sincronização não aparece.');
$epi->query('UPDATE hr_epi_outbox SET retry_at=0');
epi_check($sync->process($connect) === 4 && $writes === 4, 'Repetição devia recuperar sem duplicar.');
epi_check($sync->process($connect, true) === 0, 'Uma fila em dia devia ser ignorada.');

for ($attempt = 0; $attempt < 5; $attempt++) {
    epi_reject(function () use ($epi, $operation, $issuer) {
        $epi->confirm($operation, $issuer, true, true, '123457');
    }, 'PIN inválido');
}
epi_reject(function () use ($epi, $operation, $employee) {
    $epi->confirm($operation, $employee, false, true, '123456');
}, 'Limite de tentativas');
$epi->query('UPDATE hr_epi_pin_attempts SET expires_at=0');
$epi->confirm($operation, $issuer, true, true, '123456');
$confirmed = $epi->operation($operation);
epi_check((int) $confirmed['confirmed_by'] === $employee['id'] && strlen($confirmed['proof_id']) === 32
    && $confirmed['declaration'] === HrEpi::DECLARATION, 'Metadados do comprovativo.');
$credentials = $epi->query('SELECT pin_code,pin_code_hash FROM users WHERE id=?', [$employee['id']])->fetch(PDO::FETCH_ASSOC);
epi_check($credentials['pin_code'] === '' && password_verify('123456', $credentials['pin_code_hash']), 'Atualização de PIN legado.');
$epi->confirm($operation, $employee, false, true, '123456');
epi_check($epi->operation($operation)['proof_id'] === $confirmed['proof_id'], 'Confirmação repetida alterou o comprovativo.');
epi_check($sync->process($connect) === 2 && $writes === 4, 'A confirmação devia atualizar as duas linhas remotas.');
foreach ($original as $item) {
    $fields = $remote[2][$item['id']]['fields'];
    epi_check($fields['EstadoConfirmacao'] === 'Confirmado' && $fields['ComprovativoID'] === $confirmed['proof_id'], 'Mapeamento remoto da confirmação.');
}

$epi->recordEvent($original[0]['id'], 'correction', 'Correção da referência em ficha complementar.', null, $issuer);
$epi->recordEvent($original[0]['id'], 'return', 'Equipamento devolvido.', 2, $issuer);
epi_reject(function () use ($epi, $original, $issuer) {
    $epi->recordEvent($original[0]['id'], 'return', 'Outra devolução.', 1, $issuer);
}, 'excede');
$epi->saveCatalog(['id' => $gloves, 'name' => 'Novo nome', 'category' => 'Mãos'], $issuer);
$history = $epi->history(['employee' => $employee['id']]);
epi_check($history[0]['name'] === 'Luvas de proteção' && (int) $history[0]['quantity'] === 2 && (int) $history[0]['returned'] === 2,
    'O histórico original mudou após desativação, retificação ou devolução.');
epi_check((int) $epi->query('SELECT COUNT(*) FROM hr_epi_events')->fetchColumn() === 2, 'Histórico de retificações e devoluções.');
foreach ([HrEpiPdf::create($history), HrEpiPdf::create($history, $confirmed)] as $pdf) {
    epi_check(strpos($pdf, '%PDF-') === 0 && strpos($pdf, '123456') === false, 'PDF inválido ou inclui PIN.');
}

$graphSettings = ['tenant_id' => 'tenant', 'client_id' => 'client', 'site_id' => 'fixture.example,site,collection'];
$graphClient = new SharePointGraphClient($graphSettings, function ($url, $payload, $accessToken, $method = 'GET') {
    if (strpos($url, 'https://login.microsoftonline.com/') === 0) {
        return ['status' => 200, 'body' => '{"access_token":"fixture-token"}'];
    }
    epi_check($accessToken === 'fixture-token' && strpos($url, 'https://graph.microsoft.com/v1.0/sites/') === 0, 'Pedido Graph sem autenticação ou com destino incorreto.');
    if ($method === 'PATCH_JSON') {
        epi_check(strpos($url, '/lists/44/columns/abc') !== false && $payload['indexed'], 'Pedido de índice inválido.');
        return ['status' => 204, 'body' => ''];
    }
    return ['status' => 200, 'body' => '{"value":[]}'];
});
$graphClient->withEpiSite('fixture-secret', function ($api) {
    $api('GET', '');
    $api('PATCH', '/lists/44/columns/abc', ['indexed' => true]);
    $api('GET', 'https://graph.microsoft.com/v1.0/sites/fixture.example,site,collection/lists/44/items?skip=1');
    try {
        $api('GET', 'https://graph.microsoft.com/v1.0/sites/another-site/lists/44/items?skip=1');
    } catch (RuntimeException $error) {
        epi_check(strpos($error->getMessage(), 'Paginação Graph rejeitada') === 0, 'Erro ao restringir paginação.');
        return;
    }
    throw new RuntimeException('A paginação Graph saiu do site autorizado.');
});

echo "hr_epi_test: OK\n";
