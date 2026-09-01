<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/helpers.php';
require_once dirname(__DIR__).'/erp_migrations.php';
erp_run_phase1_migrations($pdo);

$pdo->beginTransaction();
try {
    $code='TEST-ARTICLE-'.bin2hex(random_bytes(4));
    $stmt=$pdo->prepare('INSERT INTO erp_finished_products(code,description,status,proof_status,material,analysis_grammage) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$code,'Artigo de validação','Ativo','Pendente','100% PP','60 g/m²']);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE erp_finished_products SET description=?,width=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['Artigo editado',50,$id]);
    $check=$pdo->prepare('SELECT code,description,width,material,analysis_grammage FROM erp_finished_products WHERE id=?'); $check->execute([$id]); $article=$check->fetch(PDO::FETCH_ASSOC);
    if (!$article || $article['code']!==$code || $article['description']!=='Artigo editado' || (float)$article['width']!==50.0) { throw new RuntimeException('Falhou a criação/edição do artigo.'); }
    if ($article['material']!=='100% PP' || $article['analysis_grammage']!=='60 g/m²') { throw new RuntimeException('Falhou a persistência dos dados técnicos.'); }
    $pdo->rollBack(); echo "Artigos ERP validados: criação, edição e dados técnicos OK.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR,$e->getMessage()."\n"); exit(1);
}
