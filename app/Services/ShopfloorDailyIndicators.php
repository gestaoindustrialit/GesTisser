<?php

class ShopfloorDailyIndicators
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build the live indicators from the existing clock, break and operation
     * records. Durations are kept in seconds until they reach the view.
     */
    public function forUser(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $dayStart = $now->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $clockStmt = $this->pdo->prepare(
            'SELECT entry_type, occurred_at FROM shopfloor_time_entries
             WHERE user_id = ? AND occurred_at >= ? AND occurred_at < ?
             ORDER BY occurred_at ASC, id ASC'
        );
        $clockStmt->execute([$userId, $dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s')]);
        $presenceSeconds = $this->clockedSeconds($clockStmt->fetchAll(PDO::FETCH_ASSOC), $now);

        $breakStmt = $this->pdo->prepare(
            'SELECT break_type, started_at, ended_at FROM shopfloor_break_entries
             WHERE user_id = ? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)'
        );
        $breakStmt->execute([$userId, $dayEnd->format('Y-m-d H:i:s'), $dayStart->format('Y-m-d H:i:s')]);
        $breaks = ['Pausa' => ['count' => 0, 'seconds' => 0], 'Paragem' => ['count' => 0, 'seconds' => 0]];
        foreach ($breakStmt->fetchAll(PDO::FETCH_ASSOC) as $break) {
            $type = (string) ($break['break_type'] ?? 'Pausa');
            if (!isset($breaks[$type])) {
                continue;
            }
            $breaks[$type]['count']++;
            $breaks[$type]['seconds'] += $this->overlapSeconds(
                (string) $break['started_at'],
                $break['ended_at'] ? (string) $break['ended_at'] : $now->format('Y-m-d H:i:s'),
                $dayStart,
                $now
            );
        }

        $operationStmt = $this->pdo->prepare(
            'SELECT id, production_order_operation_id, started_at, ended_at
             FROM erp_operation_time_entries
             WHERE user_id = ? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)'
        );
        $operationStmt->execute([$userId, $dayEnd->format('Y-m-d H:i:s'), $dayStart->format('Y-m-d H:i:s')]);
        $operations = $operationStmt->fetchAll(PDO::FETCH_ASSOC);
        $producedSeconds = 0;
        foreach ($operations as $operation) {
            $sessionEnd = $operation['ended_at'] ? (string) $operation['ended_at'] : $now->format('Y-m-d H:i:s');
            $sessionSeconds = $this->overlapSeconds((string) $operation['started_at'], $sessionEnd, $dayStart, $now);
            $stoppageStmt = $this->pdo->prepare(
                'SELECT started_at, ended_at FROM erp_operation_stoppages
                 WHERE time_entry_id = ? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)'
            );
            $stoppageStmt->execute([(int) $operation['id'], $now->format('Y-m-d H:i:s'), $dayStart->format('Y-m-d H:i:s')]);
            foreach ($stoppageStmt->fetchAll(PDO::FETCH_ASSOC) as $stoppage) {
                $sessionSeconds -= $this->overlapSeconds(
                    (string) $stoppage['started_at'],
                    $stoppage['ended_at'] ? (string) $stoppage['ended_at'] : $now->format('Y-m-d H:i:s'),
                    $dayStart,
                    $now
                );
            }
            $producedSeconds += max(0, $sessionSeconds);
        }

        $activeOperationStmt = $this->pdo->prepare(
            'SELECT production_order_operation_id FROM erp_operation_time_entries
             WHERE user_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1'
        );
        $activeOperationStmt->execute([$userId]);
        $activeOperationId = (int) ($activeOperationStmt->fetchColumn() ?: 0);
        $productionQuantity = 0.0;
        if ($activeOperationId > 0) {
            $quantityStmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(quantity_good), 0) FROM erp_operation_time_entries
                 WHERE production_order_operation_id = ? AND started_at >= ? AND started_at < ?'
            );
            $quantityStmt->execute([$activeOperationId, $dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s')]);
            $productionQuantity = (float) $quantityStmt->fetchColumn();
        }

        $pauseSeconds = $breaks['Pausa']['seconds'];
        $stoppageSeconds = $breaks['Paragem']['seconds'];
        return [
            'presence_seconds' => $presenceSeconds,
            // This is the same attendance concept used by payroll/BH: paired
            // clock intervals, less all registered pauses and stoppages.
            'worked_seconds' => max(0, $presenceSeconds - $pauseSeconds - $stoppageSeconds),
            'pause_seconds' => $pauseSeconds,
            'pause_count' => $breaks['Pausa']['count'],
            'stoppage_seconds' => $stoppageSeconds,
            'stoppage_count' => $breaks['Paragem']['count'],
            'produced_seconds' => $producedSeconds,
            'dead_seconds' => max(0, $presenceSeconds - $pauseSeconds - $stoppageSeconds - $producedSeconds),
            'production_quantity' => $productionQuantity,
            'active_operation_id' => $activeOperationId,
        ];
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function clockedSeconds(array $entries, DateTimeImmutable $now): int
    {
        $seconds = 0;
        $open = null;
        foreach ($entries as $entry) {
            $timestamp = strtotime((string) $entry['occurred_at']);
            if ($timestamp === false) {
                continue;
            }
            if ((string) $entry['entry_type'] === 'entrada') {
                $open = $timestamp;
            } elseif ((string) $entry['entry_type'] === 'saida' && $open !== null && $timestamp > $open) {
                $seconds += $timestamp - $open;
                $open = null;
            }
        }
        if ($open !== null) {
            $seconds += max(0, $now->getTimestamp() - $open);
        }
        return $seconds;
    }

    private function overlapSeconds(string $start, string $end, DateTimeImmutable $floor, DateTimeImmutable $ceiling): int
    {
        $startTimestamp = max((int) strtotime($start), $floor->getTimestamp());
        $endTimestamp = min((int) strtotime($end), $ceiling->getTimestamp());
        return max(0, $endTimestamp - $startTimestamp);
    }
}
