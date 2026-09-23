<?php
declare(strict_types=1);

$shopfloor = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
$label = (string) file_get_contents(__DIR__ . '/../production_label.php');

function work_center_check(bool $condition, string $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

work_center_check(strpos($shopfloor, 'select_work_center') !== false, 'Falta a ação de seleção do centro de trabalho.');
work_center_check(strpos($shopfloor, 'gestisser_shopfloor_work_center_id') !== false, 'A escolha do dispositivo não é persistida no armazenamento local.');
work_center_check(strpos($shopfloor, "\$_SESSION['shopfloor_work_center_id']") !== false, 'O centro do dispositivo não é validado na sessão.');
work_center_check(strpos($shopfloor, 'center_op.work_center_id = ?') !== false, 'As OF não são filtradas pelo centro selecionado.');
work_center_check(strpos($shopfloor, 'Esta operação não pertence ao centro de trabalho selecionado.') !== false, 'O arranque não protege operações de outros centros.');
work_center_check(strpos($label, 'wc.default_printer_id') !== false, 'A etiqueta não resolve a impressora através do centro de trabalho.');
work_center_check(strpos($label, 'machine_id') === false, 'A etiqueta não deve resolver a impressora através da máquina.');

echo "Seleção persistente do centro de trabalho validada.\n";
