<?php
declare(strict_types=1);

final class WorkCenterCapacityService
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function netDailyMinutes(array $center): float
    {
        return round(max(0, (float) ($center['daily_capacity_minutes'] ?? 0)) * min(100, max(0, (float) ($center['efficiency_percent'] ?? 100))) / 100, 2);
    }

    /** @return DateTimeImmutable|null */
    public static function forecastDate(float $queuedMinutes, float $dailyMinutes, DateTimeImmutable $from = null)
    {
        if ($dailyMinutes <= 0) return null;
        $days = (int) ceil(max(0, $queuedMinutes) / $dailyMinutes);
        $date = $from ?: new DateTimeImmutable('today');
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') < 6) $days--;
        }
        return $date;
    }

    public function forecastsByCenter(): array
    {
        $centers = $this->pdo->query('SELECT id,daily_capacity_minutes,efficiency_percent FROM erp_work_centers WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC);
        $queue = $this->pdo->query('SELECT work_center_id,COALESCE(SUM(planned_minutes),0) queued_minutes FROM erp_production_order_operations WHERE work_center_id IS NOT NULL AND lower(status) NOT IN ("concluída","concluida","completed","cancelada","cancelled") GROUP BY work_center_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $result = [];
        foreach ($centers as $center) {
            $daily = self::netDailyMinutes($center);
            $queued = (float) ($queue[$center['id']] ?? 0);
            $result[(int) $center['id']] = ['daily_minutes'=>$daily,'queued_minutes'=>$queued,'available_on'=>self::forecastDate($queued,$daily)];
        }
        return $result;
    }
}
