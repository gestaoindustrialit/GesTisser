<?php
$source = (string) file_get_contents(__DIR__ . '/../erp.php');

function article_current_artwork_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

article_current_artwork_check(strpos($source, 'Usar o primeiro ficheiro como imagem principal de produção') === false, 'A opção antiga ainda está visível.');
article_current_artwork_check(strpos($source, 'name="artwork_document_id"') !== false, 'Os anexos não permitem selecionar a maquete actual.');
article_current_artwork_check(strpos($source, "radio.value='new:'+index") !== false, 'Os novos anexos não permitem selecionar a maquete actual.');
article_current_artwork_check(strpos($source, 'SELECT id FROM erp_product_documents WHERE id=? AND entity_type="finished_product" AND entity_id=?') !== false, 'A maquete selecionada não é validada contra o artigo.');
article_current_artwork_check(strpos($source, 'WHEN id=? THEN "production_main"') !== false, 'A maquete selecionada não é persistida.');

echo "article_current_artwork_test: OK\n";
