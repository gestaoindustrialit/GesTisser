<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/article_document.php';

require_login();
$user = current_user($pdo) ?: [];
$profile = (string) ($user['access_profile'] ?? 'Utilizador');
if ((int) ($user['is_admin'] ?? 0) !== 1 && !in_array($profile, ['Utilizador', 'Produção', 'Chefias', 'RH'], true)) {
    http_response_code(403);
    exit('Acesso reservado ao Shopfloor.');
}

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId || $documentId < 1) {
    http_response_code(404);
    exit('Maquete não encontrada.');
}

$statement = $pdo->prepare('SELECT id, title, file_url FROM erp_product_documents WHERE id=? AND entity_type="finished_product" AND status="Ativo" LIMIT 1');
$statement->execute([$documentId]);
$document = $statement->fetch(PDO::FETCH_ASSOC);
$absolutePath = $document ? ArticleDocument::absolutePath(__DIR__, (string) ($document['file_url'] ?? '')) : '';
if (!$document || $absolutePath === '') {
    http_response_code(404);
    exit('O ficheiro da maquete não existe no servidor.');
}

if (isset($_GET['original'])) {
    $mime = UploadService::detectMime($absolutePath) ?: 'application/pdf';
    $fileName = trim((string) ($document['title'] ?? 'maquete')) ?: 'maquete';
    $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
    if ($extension !== '' && strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)) !== $extension) $fileName .= '.' . $extension;
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'maquete.pdf';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($absolutePath);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageCount = ArticleDocument::pageCount($absolutePath);
if ($page > $pageCount) {
    http_response_code(404);
    exit('Página da maquete não encontrada.');
}
$thumbnail = ArticleDocument::thumbnail($absolutePath, 2400, 2400, $page - 1);
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($thumbnail));
header('Content-Disposition: inline; filename="maquete-pagina-' . $page . '.jpg"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
echo $thumbnail;
