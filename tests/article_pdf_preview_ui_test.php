<?php
$source = (string) file_get_contents(__DIR__ . '/../erp.php');

function article_pdf_preview_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

article_pdf_preview_check(strpos($source, 'class="btn btn-sm btn-light border article-pdf-preview"') !== false, 'article PDF trigger missing');
article_pdf_preview_check(strpos($source, 'id="articlePdfModal"') !== false, 'article PDF preview modal missing');
article_pdf_preview_check(strpos($source, "fetch(url,{credentials:'same-origin'})") !== false, 'authenticated PDF fetch missing');
article_pdf_preview_check(strpos($source, 'URL.createObjectURL(blob)') !== false, 'blob preview URL missing');
article_pdf_preview_check(strpos($source, "articlePdfModal.addEventListener('hidden.bs.modal'") !== false, 'preview cleanup missing');
article_pdf_preview_check(strpos($source, 'id="articlePdfOpen"') !== false, 'new-tab fallback missing');

echo "article_pdf_preview_ui_test: OK\n";
