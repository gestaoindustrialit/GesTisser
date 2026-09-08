<?php
declare(strict_types=1);

final class OperationChecklistService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function items(int $templateId): array
    {
        if ($templateId <= 0) return [];
        $stmt = $this->pdo->prepare('SELECT id, content, field_type, options_json, is_required FROM checklist_template_items WHERE template_id=? ORDER BY position,id');
        $stmt->execute([$templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isRequired(array $operation, int $userId, string $phase): bool
    {
        $templateId = (int) ($operation['checklist_template_id'] ?? 0);
        $timing = (string) ($operation['checklist_timing'] ?? '');
        if (!$templateId) return false;
        if ($timing === $phase) return true;
        if ($timing !== 'first' || $phase !== 'start') return false;
        $stmt = $this->pdo->prepare('SELECT 1 FROM erp_operation_checklist_responses r JOIN erp_production_order_operations opo ON opo.id=r.production_order_operation_id WHERE opo.production_order_id=? AND opo.operation_id=? AND r.user_id=? AND r.phase="first" LIMIT 1');
        $stmt->execute([(int) $operation['production_order_id'], (int) $operation['operation_id'], $userId]);
        return !$stmt->fetchColumn();
    }

    public function validateAndEncode(int $templateId, array $submitted): string
    {
        $responses = [];
        foreach ($this->items($templateId) as $item) {
            $id = (int) $item['id'];
            $type = (string) ($item['field_type'] ?: 'checkbox');
            $value = $type === 'checkbox' ? !empty($submitted[$id]) : trim((string) ($submitted[$id] ?? ''));
            if ((int) $item['is_required'] === 1 && ($value === '' || $value === false)) {
                throw new InvalidArgumentException('Preencha o campo obrigatório: ' . $item['content']);
            }
            if ($type === 'number' && $value !== '' && !is_numeric($value)) throw new InvalidArgumentException('Indique um número válido em: ' . $item['content']);
            if ($type === 'date' && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new InvalidArgumentException('Indique uma data válida em: ' . $item['content']);
            if ($type === 'select' && $value !== '') {
                $options = json_decode((string) $item['options_json'], true) ?: [];
                if (!in_array($value, $options, true)) throw new InvalidArgumentException('Escolha uma opção válida em: ' . $item['content']);
            }
            $responses[] = ['item_id' => $id, 'label' => (string) $item['content'], 'type' => $type, 'value' => $value];
        }
        $json = json_encode($responses, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Não foi possível codificar as respostas da checklist: ' . json_last_error_msg());
        }
        return $json;
    }

    /** @param int|null $timeEntryId */
    public function save(array $operation, int $userId, string $phase, array $submitted, $timeEntryId)
    {
        $templateId = (int) $operation['checklist_template_id'];
        $storedPhase = (string) $operation['checklist_timing'] === 'first' ? 'first' : $phase;
        $json = $this->validateAndEncode($templateId, $submitted);
        $stmt = $this->pdo->prepare('INSERT INTO erp_operation_checklist_responses(production_order_operation_id,time_entry_id,checklist_template_id,user_id,phase,response_json) VALUES (?,?,?,?,?,?)');
        $stmt->execute([(int) $operation['id'], $timeEntryId, $templateId, $userId, $storedPhase, $json]);
    }
}
