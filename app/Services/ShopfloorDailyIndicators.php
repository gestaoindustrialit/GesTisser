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
    public function forUser(int $userId, DateTimeImmutable $now = null): array
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
            'SELECT te.id, te.production_order_operation_id, te.started_at, te.ended_at
             FROM erp_operation_time_entries te
             WHERE (te.user_id = ? OR EXISTS (
                       SELECT 1 FROM erp_operation_execution_operators execution_operator
                       WHERE execution_operator.time_entry_id = te.id AND execution_operator.user_id = ?
                   ))
               AND te.started_at < ? AND (te.ended_at IS NULL OR te.ended_at > ?)'
        );
        $operationStmt->execute([$userId, $userId, $dayEnd->format('Y-m-d H:i:s'), $dayStart->format('Y-m-d H:i:s')]);
        $operations = $operationStmt->fetchAll(PDO::FETCH_ASSOC);
        $workedIntervals = [];
        $workedIntervalsByEntry = [];
        foreach ($operations as $operation) {
            $sessionEnd = $operation['ended_at'] ? (string) $operation['ended_at'] : $now->format('Y-m-d H:i:s');
            $sessionStartTimestamp = max((int) strtotime((string) $operation['started_at']), $dayStart->getTimestamp());
            $sessionEndTimestamp = min((int) strtotime($sessionEnd), $now->getTimestamp());
            $activeIntervals = $sessionEndTimestamp > $sessionStartTimestamp
                ? [[$sessionStartTimestamp, $sessionEndTimestamp]]
                : [];
            $stoppageStmt = $this->pdo->prepare(
                'SELECT started_at, ended_at FROM erp_operation_stoppages
                 WHERE time_entry_id = ? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)'
            );
            $stoppageStmt->execute([(int) $operation['id'], $now->format('Y-m-d H:i:s'), $dayStart->format('Y-m-d H:i:s')]);
            foreach ($stoppageStmt->fetchAll(PDO::FETCH_ASSOC) as $stoppage) {
                $stoppageStart = max((int) strtotime((string) $stoppage['started_at']), $dayStart->getTimestamp());
                $stoppageEndValue = $stoppage['ended_at'] ? (string) $stoppage['ended_at'] : $now->format('Y-m-d H:i:s');
                $stoppageEnd = min((int) strtotime($stoppageEndValue), $now->getTimestamp());
                $activeIntervals = $this->subtractInterval($activeIntervals, $stoppageStart, $stoppageEnd);
            }
            $workedIntervals = array_merge($workedIntervals, $activeIntervals);
            $workedIntervalsByEntry[(int) $operation['id']] = $activeIntervals;
        }
        // Union all active fragments so simultaneous operations never count the
        // same chronological second more than once.
        $workedSeconds = $this->mergedIntervalSeconds($workedIntervals);

        $activeOperationStmt = $this->pdo->prepare(
            'SELECT te.id, te.production_order_operation_id FROM erp_operation_time_entries te
             WHERE (te.user_id = ? OR EXISTS (
                       SELECT 1 FROM erp_operation_execution_operators execution_operator
                       WHERE execution_operator.time_entry_id = te.id AND execution_operator.user_id = ?
                   ))
               AND te.ended_at IS NULL ORDER BY te.started_at DESC LIMIT 1'
        );
        $activeOperationStmt->execute([$userId, $userId]);
        $activeOperation = $activeOperationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $activeEntryId = (int) ($activeOperation['id'] ?? 0);
        $activeOperationId = (int) ($activeOperation['production_order_operation_id'] ?? 0);
        $productionSeconds = $activeEntryId > 0
            ? $this->mergedIntervalSeconds($workedIntervalsByEntry[$activeEntryId] ?? [])
            : 0;

        $pauseSeconds = $breaks['Pausa']['seconds'];
        $stoppageSeconds = $breaks['Paragem']['seconds'];
        return [
            'presence_seconds' => $presenceSeconds,
            'worked_seconds' => $workedSeconds,
            'pause_seconds' => $pauseSeconds,
            'pause_count' => $breaks['Pausa']['count'],
            'stoppage_seconds' => $stoppageSeconds,
            'stoppage_count' => $breaks['Paragem']['count'],
            'dead_seconds' => max(0, $presenceSeconds - $workedSeconds - $pauseSeconds - $stoppageSeconds),
            'production_seconds' => $productionSeconds,
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

    private function subtractInterval(array $intervals, int $cutStart, int $cutEnd): array
    {
        if ($cutEnd <= $cutStart) {
            return $intervals;
        }
        $remaining = [];
        foreach ($intervals as $interval) {
            if ($cutEnd <= $interval[0] || $cutStart >= $interval[1]) {
                $remaining[] = $interval;
                continue;
            }
            if ($cutStart > $interval[0]) {
                $remaining[] = [$interval[0], min($cutStart, $interval[1])];
            }
            if ($cutEnd < $interval[1]) {
                $remaining[] = [max($cutEnd, $interval[0]), $interval[1]];
            }
        }
        return $remaining;
    }

    private function mergedIntervalSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }
        usort($intervals, function ($left, $right) {
            return $left[0] <=> $right[0];
        });
        $seconds = 0;
        $current = array_shift($intervals);
        foreach ($intervals as $interval) {
            if ($interval[0] <= $current[1]) {
                $current[1] = max($current[1], $interval[1]);
                continue;
            }
            $seconds += $current[1] - $current[0];
            $current = $interval;
        }
        return $seconds + ($current[1] - $current[0]);
    }
}
