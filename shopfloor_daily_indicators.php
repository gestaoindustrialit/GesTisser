<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app/Services/ShopfloorDailyIndicators.php';

require_login();
header('Content-Type: application/json; charset=UTF-8');

$indicators = (new ShopfloorDailyIndicators($pdo))->forUser((int) $_SESSION['user_id']);
echo json_encode([
    'presence' => ShopfloorDailyIndicators::formatDuration($indicators['presence_seconds']),
    'worked' => ShopfloorDailyIndicators::formatDuration($indicators['worked_seconds']),
    'pauses' => ShopfloorDailyIndicators::formatDuration($indicators['pause_seconds']),
    'pause_count' => $indicators['pause_count'],
    'stoppages' => ShopfloorDailyIndicators::formatDuration($indicators['stoppage_seconds']),
    'stoppage_count' => $indicators['stoppage_count'],
    'dead' => ShopfloorDailyIndicators::formatDuration($indicators['dead_seconds']),
    'production' => ShopfloorDailyIndicators::formatDuration($indicators['production_seconds']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
