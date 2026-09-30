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

operation_flow_check(strpos($shopfloor, 'allowed_work_center_ids_json') !== false, 'Os vários postos de arranque não são considerados no Shopfloor.');
operation_flow_check(strpos($shopfloor, "require_once __DIR__ . '/app/Services/RoutingService.php';") !== false, 'O Shopfloor utiliza o RoutingService sem carregar a respetiva classe.');
operation_flow_check(strpos($shopfloor, 'Esta operação precisa de uma máquina') !== false, 'O requisito de máquina não é validado no arranque.');
operation_flow_check(strpos($shopfloor, 'Indique a quantidade produzida antes de concluir a operação.') === false, 'A conclusão ainda exige quantidade superior a zero.');
operation_flow_check(strpos($shopfloor, 'COALESCE(te.quantity_good, 0) + COALESCE(te.quantity_rejected, 0)') !== false, 'A quantidade anterior deixa de ser reconhecida quando uma das parcelas é nula.');
operation_flow_check(strpos($shopfloor, "['requires_good_quantity']") !== false, 'A configuração de recolha de quantidade da operação não é respeitada.');
operation_flow_check(strpos($shopfloor, 'COALESCE(opo.requires_good_quantity,op.requires_good_quantity) requires_good_quantity') !== false, 'A configuração de quantidades específica do artigo não prevalece no Shopfloor.');
operation_flow_check(strpos((string) file_get_contents(__DIR__ . '/../erp_routing.php'), 'name="requires_good_quantity"') !== false, 'O routing do artigo não permite configurar se a operação recolhe quantidades.');
operation_flow_check(strpos($shopfloor, "'100%'") !== false && strpos($shopfloor, 'shopfloor_format_quantity') !== false, 'O estado concluído não apresenta quantidade ou percentagem.');
operation_flow_check(strpos($shopfloor, '$allowedMachineIds[0]') !== false, 'O arranque não recupera uma máquina autorizada quando não existe máquina principal.');
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
operation_flow_check(strpos($shopfloor, "percentage >= 90") !== false && strpos($shopfloor, "percentage >= 70 && percentage < 90") !== false, 'As cores da produtividade não respeitam os intervalos definidos.');
operation_flow_check(strpos($shopfloor, 'register_material_consumption') !== false && strpos($shopfloor, 'data-consumption-unit') !== false, 'Falta o registo de consumo com unidade dinâmica.');
operation_flow_check(strpos($shopfloor, 'operação retomada automaticamente') !== false, 'A operação não é retomada automaticamente no fim da pausa.');
operation_flow_check(strpos($shopfloor, "json_each(CASE WHEN json_valid(center_op.allowed_machine_ids_json)") !== false, 'As OF não são disponibilizadas nos centros das máquinas alternativas.');
operation_flow_check(strpos($shopfloor, "AND opo.work_center_id=?") === false, 'A lista da OF continua a ocultar operações de outros centros de trabalho.');
operation_flow_check(strpos($shopfloor, 'Apenas acompanhamento') !== false, 'As operações não executáveis não são apresentadas em modo de acompanhamento.');
operation_flow_check(strpos($shopfloor, 'shopfloor_operation_can_run_at_work_center') !== false, 'O arranque não valida máquinas alternativas do centro de trabalho.');
operation_flow_check(strpos($shopfloor, '$machineId = 0') !== false && strpos($shopfloor, "['labour_time_only']") !== false, 'O arranque não permite contabilizar exclusivamente o tempo do colaborador.');
operation_flow_check(strpos((string) file_get_contents(__DIR__ . '/../erp_operations.php'), 'select-all-operation-machines') !== false, 'A edição da operação não permite selecionar todas as máquinas.');
operation_flow_check(strpos($shopfloor, 'shopfloor-operation-grid') !== false, 'As operações não são apresentadas numa grelha de cards responsiva.');
operation_flow_check(strpos($shopfloor, 'shopfloor-operation-card') !== false, 'Falta o card individual de operação para tablet.');
operation_flow_check(strpos($shopfloor, '<table class="table table-sm shopfloor-table"') === false, 'As operações continuam a ser apresentadas em tabela.');
operation_flow_check(strpos($styles, '.shopfloor-operation-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }') !== false, 'A grelha de operações não apresenta três cards por linha em tablet horizontal.');

echo "Fluxo de operações do Shopfloor validado.\n";
