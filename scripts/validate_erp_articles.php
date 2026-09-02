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
    $rawCode='TEST-MP-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO erp_raw_materials(code,description,min_stock,reorder_point,alert_email,alert_enabled,status) VALUES (?,?,?,?,?,?,?)')->execute([$rawCode,'Matéria-prima de validação',10,15,'compras@example.com',1,'Ativo']);
    $rawId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO erp_article_materials(finished_product_id,raw_material_id,quantity_per_unit,waste_percent,notes) VALUES (?,?,?,?,?)')->execute([$id,$rawId,0.125,2.5,'Consumo standard']);
    $bom=$pdo->prepare('SELECT quantity_per_unit,waste_percent FROM erp_article_materials WHERE finished_product_id=? AND raw_material_id=?');$bom->execute([$id,$rawId]);$consumption=$bom->fetch(PDO::FETCH_ASSOC);
    if(!$consumption || (float)$consumption['quantity_per_unit']!==0.125 || (float)$consumption['waste_percent']!==2.5){throw new RuntimeException('Falhou a lista de consumos do artigo.');}
    $raw=$pdo->prepare('SELECT alert_email,alert_enabled,reorder_point FROM erp_raw_materials WHERE id=?');$raw->execute([$rawId]);$raw=$raw->fetch(PDO::FETCH_ASSOC);
    if(!$raw || $raw['alert_email']!=='compras@example.com' || (int)$raw['alert_enabled']!==1 || (float)$raw['reorder_point']!==15.0){throw new RuntimeException('Falhou a configuração de alertas da matéria-prima.');}
    $pdo->prepare('INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title,file_url,status) VALUES ("finished_product",?,?,?,?,"Ativo")')->execute([$id,'Identificação do artigo','Desenho técnico','storage/uploads/teste.pdf']);
    $document=$pdo->prepare('SELECT title,file_url FROM erp_product_documents WHERE entity_type="finished_product" AND entity_id=?');$document->execute([$id]);$document=$document->fetch(PDO::FETCH_ASSOC);
    if(!$document || $document['title']!=='Desenho técnico' || $document['file_url']!=='storage/uploads/teste.pdf'){throw new RuntimeException('Falhou a associação de documentos ao artigo.');}
    $copyCode=$code.'-COPIA';$pdo->prepare('INSERT INTO erp_finished_products(code,description,status,proof_status) SELECT ?,description||" (cópia)",status,proof_status FROM erp_finished_products WHERE id=?')->execute([$copyCode,$id]);$copyId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO erp_article_materials(finished_product_id,raw_material_id,quantity_per_unit,waste_percent,notes) SELECT ?,raw_material_id,quantity_per_unit,waste_percent,notes FROM erp_article_materials WHERE finished_product_id=?')->execute([$copyId,$id]);
    if((int)$pdo->query('SELECT COUNT(*) FROM erp_article_materials WHERE finished_product_id='.(int)$copyId)->fetchColumn()!==1){throw new RuntimeException('Falhou a duplicação dos consumos do artigo.');}
    $pdo->rollBack(); echo "Artigos ERP validados: criação, edição, duplicação, documentos, consumos e alertas de matéria-prima OK.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR,$e->getMessage()."\n"); exit(1);
}
