<?php
$shopfloor = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
$styles = (string) file_get_contents(__DIR__ . '/../assets/styles.css');
function shopfloor_feedback_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
shopfloor_feedback_check(strpos($shopfloor, 'data-shopfloor-notification') !== false, 'A notificação flutuante não foi configurada.');
shopfloor_feedback_check(strpos($shopfloor, 'data-bs-delay="10000"') !== false, 'A notificação não desaparece ao fim de dez segundos.');
shopfloor_feedback_check(strpos($shopfloor, 'data-bs-dismiss="toast"') !== false, 'A notificação não dispõe de botão para fechar.');
shopfloor_feedback_check(strpos($shopfloor, '<iframe src="<?= h(ArticleDocument::url') !== false, 'O PDF da maquete não abre diretamente no visualizador.');
shopfloor_feedback_check(strpos($styles, '.shopfloor-artwork-pdf') !== false, 'O visualizador PDF não ocupa a área útil da janela.');
echo "shopfloor feedback and preview ok\n";
