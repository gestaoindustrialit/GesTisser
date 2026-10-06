<?php
$shopfloor = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
$styles = (string) file_get_contents(__DIR__ . '/../assets/styles.css');
function shopfloor_feedback_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
shopfloor_feedback_check(strpos($shopfloor, 'data-shopfloor-notification') !== false, 'A notificação flutuante não foi configurada.');
shopfloor_feedback_check(strpos($shopfloor, 'data-bs-delay="7000"') !== false, 'A notificação não desaparece ao fim de sete segundos.');
shopfloor_feedback_check(strpos($shopfloor, 'data-bs-dismiss="toast"') !== false, 'A notificação não dispõe de botão para fechar.');
shopfloor_feedback_check(strpos($shopfloor, 'position-fixed shopfloor-toast-container') !== false, 'A notificação não utiliza o posicionamento inferior personalizado.');
shopfloor_feedback_check(strpos($styles, 'bottom: calc(20px + env(safe-area-inset-bottom, 0px))') !== false, 'A notificação não fica a 20 píxeis do fundo do ecrã.');
shopfloor_feedback_check(strpos($styles, 'left: 50%') !== false && strpos($styles, 'translateX(-50%)') !== false, 'A notificação não está centrada horizontalmente.');
shopfloor_feedback_check(strpos($styles, 'translateY(2rem) scale(.96)') !== false && strpos($styles, '.toast.show') !== false, 'A animação da notificação não foi configurada.');
shopfloor_feedback_check(strpos($shopfloor, '<iframe') === false, 'O PDF continua dependente de um iframe bloqueado pelas políticas de segurança.');
shopfloor_feedback_check(strpos($shopfloor, 'ArticleDocument::thumbnailUrl((int) $articleArtwork[\'id\'])') !== false, 'A pré-visualização segura do PDF está em falta.');
shopfloor_feedback_check(strpos($styles, '.shopfloor-artwork-pdf-preview') !== false, 'O visualizador PDF não ocupa a área útil da janela.');
shopfloor_feedback_check(strpos($styles, '100dvh') !== false, 'O visualizador PDF não foi adaptado aos ecrãs dos tablets.');
echo "shopfloor feedback and preview ok\n";
