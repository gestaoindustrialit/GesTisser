<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Services/ShopfloorAttachment.php';

$fixtures = [
    'standard PDF' => ["%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n", 'pdf'],
    'PDF reported as generic data' => [str_repeat("\0", 8) . "%PDF-1.4\n%%EOF\n", 'pdf'],
    'invalid text file' => ['not a supported attachment', null],
];

foreach ($fixtures as $label => [$contents, $expectedExtension]) {
    $path = tempnam(sys_get_temp_dir(), 'gestisser_attachment_');
    if ($path === false) {
        throw new RuntimeException('Não foi possível criar o ficheiro temporário.');
    }

    file_put_contents($path, $contents);
    $actualExtension = ShopfloorAttachment::detectExtension($path);
    unlink($path);

    if ($actualExtension !== $expectedExtension) {
        throw new RuntimeException(sprintf(
            '%s: esperado %s, recebido %s.',
            $label,
            var_export($expectedExtension, true),
            var_export($actualExtension, true)
        ));
    }
}

echo "Shopfloor attachment validation passed.\n";
