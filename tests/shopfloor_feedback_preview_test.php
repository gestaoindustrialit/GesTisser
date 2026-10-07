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
shopfloor_feedback_check(strpos($shopfloor, 'assets/artwork-pdf-viewer.js') !== false && strpos($shopfloor, 'data-page-url=') !== false, 'O visualizador de páginas não foi ligado à maquete.');
shopfloor_feedback_check(strpos($shopfloor, 'article_artwork.php?id=') !== false, 'A maquete ainda depende do caminho interno do ERP.');
shopfloor_feedback_check(strpos($shopfloor, 'ArticleDocument::pageCount') !== false, 'A contagem de páginas da maquete está em falta.');
shopfloor_feedback_check(strpos($shopfloor, 'data-artwork-page-previous') !== false && strpos($shopfloor, 'data-artwork-page-next') !== false, 'Os comandos habituais de navegação do PDF estão em falta.');
shopfloor_feedback_check(strpos($shopfloor, 'data-artwork-image') !== false && strpos($shopfloor, '<canvas') === false, 'O preview não usa uma imagem compatível com tablets.');
shopfloor_feedback_check(strpos($shopfloor, 'type="module"') === false, 'O visualizador ainda depende de módulos JavaScript não suportados por tablets antigos.');
shopfloor_feedback_check(strpos($pdfViewer, 'urlForPage') !== false && strpos($pdfViewer, "'page='") !== false, 'O visualizador não pede cada página ao endpoint seguro.');
shopfloor_feedback_check(strpos($pdfViewer, 'pdfjs-dist') === false && strpos($pdfViewer, 'fetch(') === false, 'O visualizador ainda depende do PDF.js, CDN ou do PDF completo em memória.');
shopfloor_feedback_check(strpos($pdfViewer, "image.addEventListener('error'") !== false && strpos($pdfViewer, 'Abrir PDF original') !== false, 'A falha de geração da página não é explicada ao operador.');
echo "shopfloor feedback and preview ok\n";
