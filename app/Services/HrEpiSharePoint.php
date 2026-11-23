<?php

require_once __DIR__ . '/HrEpi.php';
require_once __DIR__ . '/SharePointIntegration.php';

final class HrEpiSharePoint
{
    private $store;
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->store = new HrEpi($pdo);
    }

    public function run($force = false, $actorId = null)
    {
        if (!app_config('external_services_enabled', false)) {
            return 0;
        }
        $settings = (new SharePointIntegration($this->pdo, app_config('paths.uploads')))->settings();
        $secret = SharePointClientSecret::read();
        if (!$secret || !$settings['site_id'] || !$settings['client_id'] || !$settings['tenant_id']) {
            if ($force) {
                throw new HrEpiInputException('Configure a ligação Microsoft Graph ao site Tisser Central.');
            }
            return 0;
        }
        $lock = fopen(sys_get_temp_dir() . '/gestisser-epi-' . hash('sha256', strtolower($settings['site_id'])) . '.lock', 'c');
        if (!$lock) {
            throw new RuntimeException('Bloqueio da sincronização EPI indisponível.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                if ($force) {
                    throw new HrEpiInputException('Já existe uma sincronização de EPI em curso.');
                }
                return 0;
            }
            $client = new SharePointGraphClient($settings);
            return $this->process(function ($operation) use ($client, $secret) {
                return $client->withEpiSite($secret, $operation);
            }, $force, $actorId);
        } finally {
            fclose($lock);
        }
    }

    public function process(callable $connect, $force = false, $actorId = null)
    {
        $jobs = $this->store->query('SELECT * FROM hr_epi_outbox WHERE revision>synced_revision'
            . ($force ? '' : ' AND retry_at<=?') . ' ORDER BY retry_at, kind, rowid LIMIT ' . ($force ? '5' : '25'),
            $force ? [] : [time()])->fetchAll(PDO::FETCH_ASSOC);
        if (!$jobs) {
            return 0;
        }
        $done = 0;
        $deadline = microtime(true) + 45;
        try {
            $connect(function ($api) use (&$jobs, &$done, $actorId, $deadline) {
                $site = $api('GET', '');
                if (strcasecmp(trim((string) ($site['displayName'] ?? '')), 'Tisser Central') !== 0) {
                    throw new HrEpiInputException('A ligação Graph deve apontar para o site Tisser Central.');
                }
                $lists = $listErrors = [];
                foreach ($jobs as $index => $job) {
                    if ($index > 0 && microtime(true) >= $deadline) {
                        break;
                    }
                    $kind = $job['kind'];
                    try {
                        if (isset($listErrors[$kind])) {
                            throw $listErrors[$kind];
                        }
                        if (!isset($lists[$kind])) {
                            try {
                                $lists[$kind] = $this->listPath($api, $kind);
                            } catch (Throwable $error) {
                                $listErrors[$kind] = $error;
                                throw $error;
                            }
                        }
                        $this->upsert($api, $lists[$kind], $kind, $job['entity_id']);
                        $this->store->query('UPDATE hr_epi_outbox SET synced_revision=?,attempts=0,retry_at=0,error=NULL
                            WHERE kind=? AND entity_id=? AND revision=?',
                            [$job['revision'], $kind, $job['entity_id'], $job['revision']]);
                        AuditLog::write($this->pdo, $actorId, 'hr.epi.sync', ['kind' => $kind, 'id' => $job['entity_id']]);
                        $done++;
                    } catch (Throwable $error) {
                        $detail = $error instanceof SharePointImportFailure ? $error->diagnostic() : [];
                        if (in_array($detail['http_status'] ?? null, [429, 503], true)) {
                            throw $error;
                        }
                        $this->fail($job, $error, $actorId);
                    }
                    unset($jobs[$index]);
                }
            });
        } catch (Throwable $error) {
            foreach ($jobs as $job) {
                $this->fail($job, $error, $actorId);
            }
        }
        return $done;
    }

    private function fail(array $job, Throwable $error, $actorId)
    {
        $details = $error instanceof HrEpiInputException ? ['configuration' => $error->getMessage()] : SharePointImportFailure::safe($error);
        $delay = max($details['retry_after'] ?? 0, min(3600, 60 * (2 ** min(6, (int) $job['attempts']))));
        $this->store->query('UPDATE hr_epi_outbox SET error=?, attempts=attempts+1, retry_at=?
            WHERE kind=? AND entity_id=? AND revision=?', [json_encode($details, JSON_UNESCAPED_UNICODE), time() + $delay,
            $job['kind'], $job['entity_id'], $job['revision']]);
        AuditLog::write($this->pdo, $actorId, 'hr.epi.sync_error', ['kind' => $job['kind'], 'id' => $job['entity_id'], 'error' => $details]);
    }

    private function rows(callable $api, $path)
    {
        for ($page = 0; $path && $page < 500; $page++) {
            $result = $api('GET', $path);
            if (!isset($result['value']) || !is_array($result['value'])) {
                throw new HrEpiInputException('Coleção SharePoint inválida.');
            }
            foreach ($result['value'] as $row) {
                yield $row;
            }
            $path = $result['@odata.nextLink'] ?? null;
        }
        if ($path) {
            throw new HrEpiInputException('Paginação SharePoint excedeu o limite.');
        }
    }

    private function schema($kind)
    {
        if ($kind === 'catalog') {
            return ['EPI_ID' => 'text', 'Designacao' => 'text', 'Categoria' => 'text', 'Referencia' => 'text',
                'MarcaModelo' => 'text', 'NormaEN' => 'text', 'PeriodicidadeSubstituicao' => 'number', 'Ativo' => 'boolean'];
        }
        return ['EntregaID' => 'text', 'OperacaoID' => 'text', 'ColaboradorID' => 'text', 'ColaboradorNome' => 'text',
            'EPI_ID' => 'text', 'EPIDesignacao' => 'text', 'Quantidade' => 'number', 'Tamanho' => 'text', 'Motivo' => 'text',
            'DataEntrega' => 'dateTime', 'EntreguePor' => 'text', 'EstadoConfirmacao' => 'text', 'DataConfirmacao' => 'dateTime',
            'MetodoConfirmacao' => 'text', 'ComprovativoID' => 'text', 'Observacoes' => 'multiline'];
    }

    private function listPath(callable $api, $kind)
    {
        $name = $kind === 'catalog' ? 'RH_CatalogoEPI' : 'RH_EntregasEPI';
        $columns = [];
        $schema = $this->schema($kind);
        foreach ($schema as $field => $type) {
            $options = $type === 'multiline' ? ['allowMultipleLines' => true] : new stdClass();
            $columns[$field] = ['name' => $field, 'displayName' => $field, ($type === 'multiline' ? 'text' : $type) => $options];
        }
        $key = key($columns);
        $columns[$key]['indexed'] = true;
        $columns[$key]['enforceUniqueValues'] = true;
        $id = null;
        foreach ($this->rows($api, '/lists?$select=id,displayName') as $list) {
            if (strcasecmp((string) ($list['displayName'] ?? ''), $name) === 0) {
                if ($id !== null) {
                    throw new HrEpiInputException('Existem listas com o mesmo nome no SharePoint.');
                }
                $id = $list['id'];
            }
        }
        if ($id === null) {
            $list = $api('POST', '/lists', ['displayName' => $name, 'list' => ['template' => 'genericList'],
                'columns' => array_values($columns)]);
            $id = $list['id'] ?? '';
        }
        if (!preg_match('/^[0-9a-f-]+$/iD', $id)) {
            throw new HrEpiInputException('Identificador de lista SharePoint inválido.');
        }
        $path = '/lists/' . $id;
        $existing = [];
        foreach ($this->rows($api, $path . '/columns') as $column) {
            $existing[$column['name']] = $column;
        }
        foreach ($columns as $field => $definition) {
            if (!isset($existing[$field])) {
                $existing[$field] = $api('POST', $path . '/columns', $definition);
            }
            $type = $schema[$field];
            $column = $existing[$field];
            if (!isset($column[$type === 'multiline' ? 'text' : $type]) || !empty($column['readOnly'])
                || ($type === 'multiline' && empty($column['text']['allowMultipleLines']))) {
                throw new HrEpiInputException('Verifique o tipo e as propriedades da coluna ' . $field . ' na lista ' . $name . '.');
            }
            if ($field === $key && (empty($column['indexed']) || empty($column['enforceUniqueValues']))) {
                $columnId = $column['id'] ?? '';
                if (!preg_match('/^[0-9a-f-]+$/iD', $columnId)) {
                    throw new HrEpiInputException('Identificador de coluna SharePoint inválido.');
                }
                $api('PATCH', $path . '/columns/' . $columnId, ['indexed' => true, 'enforceUniqueValues' => true]);
                $verified = false;
                foreach ($this->rows($api, $path . '/columns') as $currentColumn) {
                    if (($currentColumn['name'] ?? '') === $field) {
                        $verified = !empty($currentColumn['indexed']) && !empty($currentColumn['enforceUniqueValues']);
                        break;
                    }
                }
                if (!$verified) {
                    throw new HrEpiInputException('A coluna ' . $field . ' precisa de um índice com valores únicos.');
                }
            }
        }
        foreach ($existing as $field => $column) {
            if ($field !== 'Title' && !isset($columns[$field]) && !empty($column['required'])
                && empty($column['readOnly']) && !isset($column['defaultValue'])) {
                throw new HrEpiInputException('A lista tem uma coluna obrigatória sem mapeamento.');
            }
        }
        return $path;
    }

    private function upsert(callable $api, $path, $kind, $id)
    {
        $key = $kind === 'catalog' ? 'EPI_ID' : 'EntregaID';
        if (!preg_match('/^[0-9a-f]{32}$/D', $id)) {
            throw new RuntimeException('Identificador EPI inválido.');
        }
        $fields = $kind === 'catalog' ? $this->catalogFields($id) : $this->deliveryFields($id);
        $remote = null;
        foreach ($this->rows($api, $path . '/items?$filter=' . rawurlencode("fields/{$key} eq '{$id}'")) as $item) {
            if ($remote !== null) {
                throw new HrEpiInputException('O identificador EPI está duplicado no SharePoint.');
            }
            $remote = $item;
        }
        if ($remote === null) {
            $created = $api('POST', $path . '/items', ['fields' => $fields]);
            if (empty($created['id'])) {
                throw new HrEpiInputException('Criação SharePoint sem identificador. Será repetida a pesquisa do registo.');
            }
            return;
        }
        $etag = $remote['eTag'] ?? $remote['@odata.etag'] ?? '';
        if (!preg_match('/^[0-9]+$/D', (string) ($remote['id'] ?? '')) || $etag === '') {
            throw new HrEpiInputException('Registo SharePoint sem identificador ou ETag.');
        }
        $api('PATCH', $path . '/items/' . $remote['id'] . '/fields', ['etag' => $etag, 'fields' => $fields]);
    }

    private function catalogFields($id)
    {
        $row = $this->store->query('SELECT * FROM hr_epi_catalog WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        return ['Title' => $row['name'], 'EPI_ID' => $id, 'Designacao' => $row['name'], 'Categoria' => $row['category'],
            'Referencia' => $row['reference'], 'MarcaModelo' => $row['model'], 'NormaEN' => $row['standard'], 'Ativo' => (bool) $row['active'],
            'PeriodicidadeSubstituicao' => $row['replacement_days'] === null ? null : (int) $row['replacement_days']];
    }

    private function deliveryFields($id)
    {
        $row = $this->store->query('SELECT i.*, o.employee_id,o.employee_name,o.delivered_at,o.actor_name,o.confirmed_at,
                o.confirmation_method,o.proof_id FROM hr_epi_items i JOIN hr_epi_operations o ON o.id=i.operation_id WHERE i.id=?',
            [$id])->fetch(PDO::FETCH_ASSOC);
        return ['Title' => $row['name'], 'EntregaID' => $id, 'OperacaoID' => $row['operation_id'],
            'ColaboradorID' => (string) $row['employee_id'], 'ColaboradorNome' => $row['employee_name'], 'EPI_ID' => $row['epi_id'],
            'EPIDesignacao' => $row['name'], 'Quantidade' => (int) $row['quantity'], 'Tamanho' => $row['size'], 'Motivo' => $row['reason'],
            'DataEntrega' => $row['delivered_at'], 'EntreguePor' => $row['actor_name'], 'DataConfirmacao' => $row['confirmed_at'],
            'EstadoConfirmacao' => $row['confirmed_at'] ? 'Confirmado' : 'Pendente de confirmação', 'MetodoConfirmacao' => $row['confirmation_method'] ?? '',
            'ComprovativoID' => $row['proof_id'] ?? '', 'Observacoes' => $row['notes']];
    }
}
