<?php

class ValidatedHourBankCalculator
{
    /**
     * Rebuilds the hour-bank balance from days whose time entries are all validated.
     * Returns null when the employee does not have a validated day, allowing callers
     * to retain a manually configured opening balance.
     */
    public static function calculateMinutes(PDO $pdo, int $userId, int $dailyObjectiveMinutes = 480): ?int
    {
        $entriesStmt = $pdo->prepare(
            'SELECT entry_type, occurred_at
             FROM shopfloor_time_entries
             WHERE user_id = ?
               AND date(occurred_at) IN (
                   SELECT date(occurred_at)
                   FROM shopfloor_time_entries
                   WHERE user_id = ?
                   GROUP BY date(occurred_at)
                   HAVING COUNT(*) > 0
                      AND SUM(CASE WHEN validated_at IS NULL THEN 1 ELSE 0 END) = 0
               )
             ORDER BY occurred_at ASC'
        );
        $entriesStmt->execute([$userId, $userId]);
        $entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($entries === []) {
            return null;
        }

        $entriesByDate = [];
        foreach ($entries as $entry) {
            $date = date('Y-m-d', strtotime((string) $entry['occurred_at']));
            $entriesByDate[$date][] = $entry;
        }

        $validatedDates = array_keys($entriesByDate);
        $firstDate = (string) reset($validatedDates);
        $lastDate = (string) end($validatedDates);

        $breakStmt = $pdo->prepare(
            'SELECT started_at, COALESCE(ended_at, started_at) AS ended_at
             FROM shopfloor_break_entries
             WHERE user_id = ? AND date(started_at) BETWEEN ? AND ?'
        );
        $breakStmt->execute([$userId, $firstDate, $lastDate]);
        $breaksByDate = [];
        foreach ($breakStmt->fetchAll(PDO::FETCH_ASSOC) as $break) {
            $date = date('Y-m-d', strtotime((string) $break['started_at']));
            $breaksByDate[$date][] = $break;
        }

        $overrideStmt = $pdo->prepare(
            'SELECT work_date, bh_minutes FROM shopfloor_bh_overrides
             WHERE user_id = ? AND work_date BETWEEN ? AND ?'
        );
        $overrideStmt->execute([$userId, $firstDate, $lastDate]);
        $overrides = [];
        foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $override) {
            $overrides[(string) $override['work_date']] = (int) $override['bh_minutes'];
        }

        $totalMinutes = 0;
        foreach ($entriesByDate as $date => $dayEntries) {
            if (array_key_exists($date, $overrides)) {
                $totalMinutes += $overrides[$date];
                continue;
            }

            $workedIntervals = [];
            $openEntry = null;
            foreach ($dayEntries as $entry) {
                $timestamp = strtotime((string) $entry['occurred_at']);
                if ($timestamp === false) {
                    continue;
                }
                if ((string) $entry['entry_type'] === 'entrada') {
                    $openEntry = $timestamp;
                } elseif ((string) $entry['entry_type'] === 'saida' && $openEntry !== null && $timestamp > $openEntry) {
                    $workedIntervals[] = [$openEntry, $timestamp];
                    $openEntry = null;
                }
            }

            $effectiveSeconds = 0;
            foreach ($workedIntervals as $interval) {
                $effectiveSeconds += $interval[1] - $interval[0];
                foreach ($breaksByDate[$date] ?? [] as $break) {
                    $breakStart = strtotime((string) $break['started_at']);
                    $breakEnd = strtotime((string) $break['ended_at']);
                    if ($breakStart === false || $breakEnd === false) {
                        continue;
                    }
                    $effectiveSeconds -= max(0, min($interval[1], $breakEnd) - max($interval[0], $breakStart));
                }
            }

            $totalMinutes += (int) round(($effectiveSeconds - ($dailyObjectiveMinutes * 60)) / 60);
        }

        return $totalMinutes;
    }
}
