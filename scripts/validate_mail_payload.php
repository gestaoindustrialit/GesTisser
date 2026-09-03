<?php

require_once dirname(__DIR__) . '/helpers.php';

function assert_mail_payload(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$textBody = "Olá,\n\nSegue o documento em anexo.";
$htmlBody = '<html><body><img src="data:image/png;base64,' . str_repeat('A', 4096) . '"></body></html>';
$attachment = str_repeat("conteúdo binário\0", 512);

$payload = taskforce_build_mail_payload(
    'Teste de linhas MIME',
    $textBody,
    $htmlBody,
    [['name' => 'avaliacao.pdf', 'mime' => 'application/pdf', 'content' => $attachment]]
);

$rawMessage = $payload['headers'] . "\r\n" . $payload['body'];
$lines = preg_split('/\r\n|\r|\n/', $rawMessage);
$maximumLength = max(array_map('strlen', $lines));

assert_mail_payload($maximumLength <= 998, 'A mensagem contém uma linha superior ao limite de 998 caracteres do RFC 5322.');
assert_mail_payload($maximumLength <= 76, 'As linhas MIME geradas devem ter no máximo 76 caracteres.');
assert_mail_payload(substr_count($rawMessage, 'Content-Transfer-Encoding: base64') === 3, 'Todos os conteúdos devem ser codificados em base64.');
assert_mail_payload(strpos($rawMessage, $htmlBody) === false, 'O HTML não deve ser incluído sem codificação.');

$plainPayload = taskforce_build_mail_payload('Teste simples', str_repeat('x', 4096));
$plainLines = preg_split('/\r\n|\r|\n/', $plainPayload['headers'] . "\r\n" . $plainPayload['body']);
assert_mail_payload(max(array_map('strlen', $plainLines)) <= 76, 'O email de texto simples também deve respeitar o limite de linha.');
assert_mail_payload(base64_decode(str_replace(["\r", "\n"], '', $plainPayload['body']), true) === str_repeat('x', 4096), 'O conteúdo simples deve continuar intacto depois da descodificação.');

echo "OK: payloads de email respeitam os limites de linha MIME.\n";
