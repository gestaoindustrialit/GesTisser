<?php
declare(strict_types=1);

$shopfloor = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
$header = (string) file_get_contents(__DIR__ . '/../partials/header.php');
$styles = (string) file_get_contents(__DIR__ . '/../assets/styles.css');

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
operation_flow_check(strpos($shopfloor, 'data-bs-target="#operationChecklistModal-') === false, 'O botão Arrancar ainda abre a checklist antes de iniciar a produção.');
operation_flow_check(strpos($shopfloor, 'data-auto-show-checklist') !== false, 'A checklist não abre automaticamente depois do arranque.');
operation_flow_check(strpos($shopfloor, 'Validar checklist e continuar') !== false, 'A pop-up não permite validar a checklist e continuar.');
operation_flow_check(strpos($shopfloor, 'data-productivity-quantity') !== false, 'A quantidade produzida não alimenta o indicador de produtividade.');
operation_flow_check(strpos($shopfloor, 'data-productivity-value') !== false, 'Falta o indicador previsto/real de produtividade.');
operation_flow_check(strpos($shopfloor, "json_each(CASE WHEN json_valid(center_op.allowed_machine_ids_json)") !== false, 'As OF não são disponibilizadas nos centros das máquinas alternativas.');
operation_flow_check(strpos($shopfloor, "AND opo.work_center_id=?") === false, 'A lista da OF continua a ocultar operações de outros centros de trabalho.');
operation_flow_check(strpos($shopfloor, 'Apenas acompanhamento') !== false, 'As operações não executáveis não são apresentadas em modo de acompanhamento.');
operation_flow_check(strpos($shopfloor, 'shopfloor_operation_can_run_at_work_center') !== false, 'O arranque não valida máquinas alternativas do centro de trabalho.');
operation_flow_check(strpos($shopfloor, 'shopfloor-operation-grid') !== false, 'As operações não são apresentadas numa grelha de cards responsiva.');
operation_flow_check(strpos($shopfloor, 'shopfloor-operation-card') !== false, 'Falta o card individual de operação para tablet.');
operation_flow_check(strpos($shopfloor, '<table class="table table-sm shopfloor-table"') === false, 'As operações continuam a ser apresentadas em tabela.');
operation_flow_check(strpos($styles, '.shopfloor-operation-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }') !== false, 'A grelha de operações não apresenta três cards por linha em tablet horizontal.');

echo "Fluxo de operações do Shopfloor validado.\n";
