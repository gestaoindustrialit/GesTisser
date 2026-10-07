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
shopfloor_feedback_check(strpos($shopfloor, "'article_artwork.php?id=' . \$articleArtworkId") !== false, 'A pré-visualização segura do PDF está em falta.');
shopfloor_feedback_check(strpos($styles, '.shopfloor-artwork-pdf-preview') !== false, 'O visualizador PDF não ocupa a área útil da janela.');
shopfloor_feedback_check(strpos($styles, '100dvh') !== false, 'O visualizador PDF não foi adaptado aos ecrãs dos tablets.');
shopfloor_feedback_check(strpos($shopfloor, 'data-artwork-zoom-in') !== false && strpos($shopfloor, 'data-artwork-zoom-out') !== false && strpos($shopfloor, 'data-artwork-zoom-reset') !== false, 'Os controlos de zoom da maquete estão em falta.');
shopfloor_feedback_check(strpos($styles, '.shopfloor-artwork-stage') !== false && strpos($styles, 'overflow: auto') !== false, 'A maquete ampliada não permite deslocação dentro do modal.');
$pdfViewer = (string) file_get_contents(__DIR__ . '/../assets/artwork-pdf-viewer.js');
shopfloor_feedback_check(strpos($shopfloor, 'assets/artwork-pdf-viewer.js') !== false && strpos($shopfloor, 'data-pdf-url=') !== false, 'O visualizador PDF.js não foi ligado à maquete.');
shopfloor_feedback_check(strpos($shopfloor, 'article_artwork.php?id=') !== false, 'A maquete ainda depende do caminho interno do ERP.');
shopfloor_feedback_check(strpos($shopfloor, 'ArticleDocument::pageCount') !== false, 'A contagem de páginas da maquete está em falta.');
shopfloor_feedback_check(strpos($shopfloor, 'data-artwork-page-previous') !== false && strpos($shopfloor, 'data-artwork-page-next') !== false, 'Os comandos habituais de navegação do PDF estão em falta.');
shopfloor_feedback_check(strpos($shopfloor, '<canvas class="shopfloor-artwork-canvas"') !== false && strpos($shopfloor, 'data-artwork-image') === false, 'O preview não usa exclusivamente canvas.');
shopfloor_feedback_check(strpos($pdfViewer, "fetch(pdfUrl, { credentials: 'same-origin'") !== false && strpos($pdfViewer, 'response.arrayBuffer()') !== false, 'O PDF não é carregado de forma autenticada para memória.');
shopfloor_feedback_check(strpos($pdfViewer, "../node_modules/pdfjs-dist/legacy/build/pdf.min.mjs") !== false && strpos($pdfViewer, "../node_modules/pdfjs-dist/legacy/build/pdf.worker.min.mjs") !== false, 'O PDF.js ou o worker local não foi configurado.');
shopfloor_feedback_check(strpos($pdfViewer, 'loadPdfJsWithTimeout().then((pdfjsLib)') !== false && strpos($pdfViewer, 'reportStartupError') !== false, 'Uma falha ao carregar o módulo deixa o preview eternamente em preparação.');
shopfloor_feedback_check(strpos($pdfViewer, 'pdfjs-dist@4.10.38/legacy/build/pdf.min.mjs') !== false, 'O fallback de arranque do PDF.js está em falta.');
shopfloor_feedback_check(strpos($pdfViewer, 'disableWorker: true') !== false, 'O fallback sem worker do PDF.js está em falta.');
shopfloor_feedback_check(strpos($pdfViewer, 'Math.min(window.devicePixelRatio || 1, 2)') !== false, 'O devicePixelRatio do canvas não está limitado.');
shopfloor_feedback_check(strpos($pdfViewer, 'maxCanvasDimension = 4096') !== false && strpos($pdfViewer, 'maxCanvasPixels = 16777216') !== false, 'Os limites de dimensão do canvas estão em falta.');
shopfloor_feedback_check(strpos($pdfViewer, 'task.cancel()') !== false && strpos($pdfViewer, 'pdfDocument.destroy()') !== false, 'O render/documento PDF anterior não é libertado.');
foreach (['URL', 'fetch status', 'bytes', 'PDF loaded', 'pages', 'rendering page', 'canvas WxH', 'render complete'] as $log) {
    shopfloor_feedback_check(strpos($pdfViewer, "[PDF Preview] {$log}") !== false, "Log temporário em falta: {$log}.");
}
echo "shopfloor feedback and preview ok\n";
