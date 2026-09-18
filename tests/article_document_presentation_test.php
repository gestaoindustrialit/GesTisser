<?php
require_once __DIR__ . '/../app/Services/ArticleDocument.php';

$cases = [
    ['storage/uploads/spec.PDF', 'pdf', 'PDF', 'bi-file-earmark-pdf'],
    ['storage/uploads/front.jpg?version=2', 'image', 'JPG', 'bi-file-earmark-image'],
    ['storage/uploads/artwork.JPEG', 'image', 'JPG', 'bi-file-earmark-image'],
    ['storage/uploads/layout.png', 'image', 'PNG', 'bi-file-earmark-image'],
    ['storage/uploads/mockup.webp', 'image', 'WEBP', 'bi-file-earmark-image'],
];

foreach ($cases as $case) {
    $actual = ArticleDocument::presentation($case[0]);
    if ($actual['kind'] !== $case[1] || $actual['label'] !== $case[2] || $actual['icon'] !== $case[3]) {
        throw new RuntimeException('Apresentação incorreta para ' . $case[0]);
    }
}

if (ArticleDocument::url(42) !== 'erp.php?page=article_document&id=42') {
    throw new RuntimeException('URL autenticado do documento incorreto.');
}

$root = sys_get_temp_dir() . '/article-document-' . bin2hex(random_bytes(4));
mkdir($root . '/storage/uploads', 0777, true);
file_put_contents($root . '/storage/uploads/test.pdf', '%PDF-1.4');
$resolved = ArticleDocument::absolutePath($root, 'storage/uploads/test.pdf');
if ($resolved === '' || basename($resolved) !== 'test.pdf') {
    throw new RuntimeException('Não foi possível resolver um upload existente.');
}
if (ArticleDocument::absolutePath($root, 'storage/uploads/missing.pdf') !== '') {
    throw new RuntimeException('Um upload inexistente não pode ser resolvido.');
}
if (ArticleDocument::absolutePath($root, 'storage/uploads/../../../etc/passwd') !== '') {
    throw new RuntimeException('O resolvedor permitiu sair da pasta de uploads.');
}
unlink($root . '/storage/uploads/test.pdf');
rmdir($root . '/storage/uploads');
rmdir($root . '/storage');
rmdir($root);

echo "Article document presentation tests passed.\n";
