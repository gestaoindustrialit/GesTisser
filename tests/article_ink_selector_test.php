<?php
$source=(string)file_get_contents(__DIR__.'/../erp.php');

function article_ink_selector_check($condition,$message)
{
    if(!$condition)throw new RuntimeException($message);
}

article_ink_selector_check(strpos($source,"product_category=\"subsidiary\"")!==false,'As cores não são validadas contra as matérias-primas subsidiárias.');
article_ink_selector_check(strpos($source,"status=\"Ativo\"")!==false,'O seletor permite tintas inativas.');
article_ink_selector_check(strpos($source,'for($line=0;$line<8;$line++)')!==false,'Não são apresentadas oito linhas por face.');
article_ink_selector_check(strpos($source,'name="<?=h($field)?>[]"')!==false,'As linhas de cores não são enviadas como listas.');
article_ink_selector_check(strpos($source,"count(\$colors)>8")!==false,'O limite individual de oito tintas não é validado no servidor.');
article_ink_selector_check(strpos($source,'array_slice($selected,0,8)')!==false,'A edição não recupera até oito tintas previamente selecionadas.');
article_ink_selector_check(strpos($source,'data-color-group="<?=h($group)?>"')!==false,'Os grupos de cores da ficha e da OF não estão identificados.');
article_ink_selector_check(strpos($source,"face.querySelectorAll('select[data-color-line]')")!==false,'As tintas repetidas não são validadas dentro de cada face.');
article_ink_selector_check(strpos($source,'Selecione até 8 tintas existentes por cada face.')!==false,'O limite por face não é explicado no formulário.');
article_ink_selector_check(strpos($source,"preg_match('/^[0-8](?:\\+[0-8])?$/',\$value)")!==false,'O campo de cores por face não valida formatos simples e compostos como 2+0.');
article_ink_selector_check(strpos($source,'pattern="[0-8](\+[0-8])?"')!==false,'O formulário não aceita cores distintas para frente e verso.');
article_ink_selector_check(strpos($source,'placeholder="Ex.: 2+0"')!==false,'O formato frente+verso não é explicado no formulário.');

echo "article_ink_selector_test: OK\n";
