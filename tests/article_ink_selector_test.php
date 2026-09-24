<?php
$source=(string)file_get_contents(__DIR__.'/../erp.php');

function article_ink_selector_check($condition,$message)
{
    if(!$condition)throw new RuntimeException($message);
}

article_ink_selector_check(strpos($source,"product_category=\"subsidiary\"")!==false,'As cores não são validadas contra as matérias-primas subsidiárias.');
article_ink_selector_check(strpos($source,"status=\"Ativo\"")!==false,'O seletor permite tintas inativas.');
article_ink_selector_check(substr_count($source,'data-max-selections="8"')===2,'Os seletores de frente e verso não permitem até oito cores.');
article_ink_selector_check(strpos($source,'name="<?=$face?>_colors[]"')!==false,'As cores da ficha técnica não são seletores múltiplos.');
article_ink_selector_check(strpos($source,"count(\$colors)>8")!==false,'O limite individual de oito tintas não é validado no servidor.');
article_ink_selector_check(strpos($source,'array_slice($selected,0,8)')!==false,'A edição não recupera até oito tintas previamente selecionadas.');
article_ink_selector_check(substr_count($source,'data-color-group=')===2,'As cores da frente e do verso não são validadas em conjunto.');
article_ink_selector_check(strpos($source,"count(erp_article_color_list(\$values['front_colors']))+count(erp_article_color_list(\$values['back_colors']))>8")!==false,'O limite total de oito tintas da ficha técnica não é validado no servidor.');
article_ink_selector_check(strpos($source,"count(erp_article_color_list(\$values['of_front_colors']))+count(erp_article_color_list(\$values['of_back_colors']))>8")!==false,'O limite total de oito tintas da OF não é validado no servidor.');
article_ink_selector_check(strpos($source,"\$values['colors_per_face']>8")!==false,'O campo de número de cores por face não aceita o intervalo de 0 a 8 no servidor.');
article_ink_selector_check(strpos($source,'min="0" max="8" step="1"')!==false,'O campo de número de cores por face não aceita o intervalo de 0 a 8 no formulário.');

echo "article_ink_selector_test: OK\n";
