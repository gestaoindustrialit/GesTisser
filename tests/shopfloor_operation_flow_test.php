<?php
declare(strict_types=1);

$shopfloor = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
$header = (string) file_get_contents(__DIR__ . '/../partials/header.php');

function operation_flow_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

operation_flow_check(strpos($shopfloor, 'Registe quantidades na operação anterior antes de iniciar esta operação.') !== false, 'O arranque não valida quantidades na operação anterior.');
operation_flow_check(strpos($shopfloor, 'Já existe uma operação em curso.') !== false, 'O colaborador pode arrancar duas operações em simultâneo.');
operation_flow_check(strpos($shopfloor, 'shopfloor_pause_active_operation') !== false, 'A paragem da produção não está ligada ao ponto e às pausas.');
operation_flow_check(strpos($header, 'name="stop_machine"') !== false, 'Falta a aprovação de paragem da máquina.');
operation_flow_check(strpos($shopfloor, 'data-production-timer') !== false, 'Falta a contagem visível do tempo de produção.');
operation_flow_check(strpos($shopfloor, 'Retomar produção') !== false, 'Falta a ação para retomar uma operação pausada.');
operation_flow_check(strpos($shopfloor, 'shopfloor-checklist-modal') !== false, 'A checklist de arranque não abre numa pop-up responsiva.');
operation_flow_check(strpos($shopfloor, 'Confirmar e iniciar produção') !== false, 'A pop-up não confirma o arranque da produção.');
operation_flow_check(strpos($shopfloor, 'data-productivity-quantity') !== false, 'A quantidade produzida não alimenta o indicador de produtividade.');
operation_flow_check(strpos($shopfloor, 'data-productivity-value') !== false, 'Falta o indicador previsto/real de produtividade.');

echo "Fluxo de operações do Shopfloor validado.\n";
