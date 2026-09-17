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

echo "Article document presentation tests passed.\n";
