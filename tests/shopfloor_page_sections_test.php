<?php
$source = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
$styles = (string) file_get_contents(__DIR__ . '/../assets/styles.css');

function shopfloor_sections_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

shopfloor_sections_assert(strpos($source, 'Validação Nível 1 (Chefe do departamento)') === false, 'A validação de Nível 1 continua visível.');
shopfloor_sections_assert(strpos($source, 'id="humanResourcesPanel"') !== false, 'Falta o painel de recursos humanos.');
shopfloor_sections_assert(strpos($source, 'class="collapse mt-4" id="humanResourcesPanel"') !== false, 'O painel de recursos humanos não começa colapsado.');
shopfloor_sections_assert(strpos($source, 'aria-expanded="false"') !== false, 'O controlo do painel de recursos humanos não comunica o estado inicial.');

$hrStart = strpos($source, 'id="humanResourcesPanel"');
$historyStart = strpos($source, 'id="dailyHistoryHeading"');
foreach (['Pedidos de ausência', 'Acompanhamento RH', 'Pedidos de férias', 'Comunicados da chefia / RH'] as $label) {
    $position = strpos($source, $label);
    shopfloor_sections_assert($position !== false && $position > $hrStart && $position < $historyStart, $label . ' não está dentro do setor de recursos humanos.');
}

$pointHistory = strpos($source, 'Histórico de pontos');
$breakHistory = strpos($source, 'Histórico de pausas e paragens');
shopfloor_sections_assert($historyStart !== false && $pointHistory > $historyStart && $breakHistory > $pointHistory, 'Os históricos do dia não estão agrupados no fundo da página.');
shopfloor_sections_assert(strpos($styles, '.shopfloor-collapsible-trigger[aria-expanded="true"] .shopfloor-collapse-icon') !== false, 'Falta o estado visual do controlo colapsável.');

echo "shopfloor page sections ok\n";
