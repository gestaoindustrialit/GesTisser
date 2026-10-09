<?php
require_once dirname(__DIR__) . '/app/Services/ArticleDocumentChunkUpload.php';

if (ArticleDocumentChunkUpload::CHUNK_BYTES !== 524288 || ArticleDocumentChunkUpload::MAX_CHUNKS !== 40) {
    throw new RuntimeException('A política de partes deve cobrir 20 MiB sem ultrapassar limites PHP comuns.');
}

$source = (string) file_get_contents(dirname(__DIR__) . '/erp.php');
foreach(['app/Services/ArticleEditor.php','app/Services/ArticleFormSupport.php','partials/article-profile-form.php','assets/article-editor.js'] as $file) $source.=(string)file_get_contents(dirname(__DIR__).'/'.$file);
foreach (['article_upload', 'article_document_uploads[]', '512*1024', 'ArticleDocumentChunkUpload::persist'] as $expected) {
    if (strpos($source, $expected) === false) {
        throw new RuntimeException('Falta a integração do upload por partes: ' . $expected);
    }
}
if (strpos($source, 'data-article-form') === false) {
    throw new RuntimeException('O formulário dos artigos não ativa o upload por partes.');
}

echo "Article document chunk upload tests passed.\n";
