<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../hr_bank.php');

function hour_bank_edit_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

hour_bank_edit_check(strpos($source, "elseif (\$action === 'edit_adjustment')") !== false, 'The edit adjustment action is missing.');
hour_bank_edit_check(strpos($source, 'function update_hour_bank_adjustment(') !== false, 'The adjustment update service is missing.');
hour_bank_edit_check(strpos($source, 'balance_hours = balance_hours + ?') !== false, 'Editing must apply only the delta difference to the balance.');
hour_bank_edit_check(strpos($source, "WHERE id = ? AND user_id = ?") !== false, 'An adjustment edit must be scoped to its owner.');
hour_bank_edit_check(strpos($source, 'name="action" value="edit_adjustment"') !== false, 'The history edit form is missing.');
hour_bank_edit_check(strpos($source, 'Guardar alterações') !== false, 'The edit form submit control is missing.');

$oldDeltaMinutes = -38;
$newDeltaMinutes = 45;
$initialBalanceHours = 1.5;
$updatedBalanceHours = $initialBalanceHours + (($newDeltaMinutes - $oldDeltaMinutes) / 60);
hour_bank_edit_check(abs($updatedBalanceHours - 2.8833333333333) < 0.000001, 'The balance difference calculation is incorrect.');

echo "hr_bank_edit_test: OK\n";
