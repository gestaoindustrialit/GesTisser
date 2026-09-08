<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Lisbon');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, user_number TEXT, schedule_id INTEGER)');
$pdo->exec('CREATE TABLE hr_schedules (id INTEGER PRIMARY KEY, start_time TEXT, end_time TEXT, second_start_time TEXT)');
$pdo->exec('CREATE TABLE shopfloor_time_entries (user_id INTEGER, entry_type TEXT, occurred_at TEXT)');
$pdo->exec('INSERT INTO users VALUES (31, "Ricardo Manuel Pinto Pereira", "031", 1)');
$pdo->exec('INSERT INTO hr_schedules VALUES (1, "08:00", "12:00", "14:00")');
$pdo->exec('INSERT INTO shopfloor_time_entries VALUES
    (31, "entrada", "2026-09-08 07:51:00"),
    (31, "saida", "2026-09-08 12:00:00"),
    (31, "entrada", "2026-09-08 14:09:00")');

$row = $pdo->query(
    'SELECT date(te.occurred_at) AS delay_date,
            MIN(CASE
                WHEN time(te.occurred_at) < time(s.end_time)
                THEN datetime(te.occurred_at)
            END) AS first_entry_at,
            MIN(CASE
                WHEN time(te.occurred_at) >= time(s.end_time)
                THEN datetime(te.occurred_at)
            END) AS second_entry_at
     FROM shopfloor_time_entries te
     INNER JOIN users u ON u.id = te.user_id
     INNER JOIN hr_schedules s ON s.id = u.schedule_id
     WHERE te.entry_type = "entrada"
     GROUP BY date(te.occurred_at), te.user_id'
)->fetch(PDO::FETCH_ASSOC);

assert(is_array($row));
assert($row['first_entry_at'] === '2026-09-08 07:51:00');
assert($row['second_entry_at'] === '2026-09-08 14:09:00');

$morningDelay = strtotime((string) $row['first_entry_at']) - strtotime('2026-09-08 08:00:00');
$afternoonDelay = strtotime((string) $row['second_entry_at']) - strtotime('2026-09-08 14:00:00');
assert($morningDelay === -540);
assert($afternoonDelay === 540);

echo "HR late history time: OK\n";
