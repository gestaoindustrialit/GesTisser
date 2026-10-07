<?php
require_once dirname(__DIR__) . '/bootstrap/config.php';
require_once dirname(__DIR__) . '/app/Services/UploadService.php';
require_once dirname(__DIR__) . '/app/Services/ArticleDocument.php';
require_once dirname(__DIR__) . '/app/Services/ArticleFolderSync.php';

if (!extension_loaded('pdo_sqlite')) {
    echo "Article folder sync test skipped (pdo_sqlite unavailable).\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_product_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, document_type TEXT, title TEXT, file_url TEXT, author_user_id INTEGER, status TEXT)');
$articleId = 987654321;
$directory = ArticleFolderSync::directory($articleId);
@mkdir($directory, 0750, true);
$pdf = $directory . '/maquete final.pdf';
file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
file_put_contents($directory . '/ignorar.txt', 'not a supported document');

try {
    $first = ArticleFolderSync::sync($pdo, [['id' => $articleId]], 7);
    $second = ArticleFolderSync::sync($pdo, [['id' => $articleId]], 7);
    $rows = $pdo->query('SELECT * FROM erp_product_documents')->fetchAll(PDO::FETCH_ASSOC);
    if ($first !== 1 || $second !== 0 || count($rows) !== 1) throw new RuntimeException('A sincronização deve importar cada ficheiro suportado uma só vez.');
    if ($rows[0]['title'] !== 'maquete final' || strpos($rows[0]['file_url'], 'maquete%20final.pdf') === false) throw new RuntimeException('O nome ou URL do documento importado está incorreto.');
    if (!is_dir($directory)) throw new RuntimeException('A pasta do artigo não foi criada.');
    if (!ArticleFolderSync::removeManagedFile($articleId, $rows[0]['file_url']) || is_file($pdf)) throw new RuntimeException('A remoção deve apagar ficheiros geridos para impedir a reassociação.');

    $legacy = rtrim((string) app_config('paths.uploads'), '/\\') . '/legacy-artwork-' . $articleId . '.pdf';
    file_put_contents($legacy, "%PDF-1.4\nlegacy\n%%EOF\n");
    $legacyUrl = 'storage/uploads/' . basename($legacy);
    $insert = $pdo->prepare('INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title,file_url,author_user_id,status) VALUES ("finished_product",?,"production_main","Maquete teste",?,7,"Ativo")');
    $insert->execute([$articleId, $legacyUrl]);
    $documentId = (int) $pdo->lastInsertId();
    if (!ArticleFolderSync::copyArtworkToFolder($pdo, $articleId)) throw new RuntimeException('A maquete antiga deveria ser copiada para a pasta do artigo.');
    $managedUrl = (string) $pdo->query('SELECT file_url FROM erp_product_documents WHERE id=' . $documentId)->fetchColumn();
    $managedPath = ArticleDocument::absolutePath((string) app_config('paths.root'), $managedUrl);
    if ($managedPath === '' || !is_file($managedPath) || !is_file($legacy)) throw new RuntimeException('A cópia gerida e o original da maquete devem existir.');
    if (ArticleFolderSync::copyArtworkToFolder($pdo, $articleId)) throw new RuntimeException('Uma maquete já gerida não deve voltar a ser copiada.');
    @unlink($managedPath);
    @unlink($legacy);
} finally {
    @unlink($pdf);
    @unlink($directory . '/ignorar.txt');
    @rmdir($directory);
}

echo "Article folder sync tests passed.\n";
