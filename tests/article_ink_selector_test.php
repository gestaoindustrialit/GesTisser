<?php
$source=(string)file_get_contents(__DIR__.'/../erp.php');
foreach(['app/Services/ArticleEditor.php','app/Services/ArticleFormSupport.php','partials/article-profile-form.php','assets/article-editor.js'] as $file) $source.=(string)file_get_contents(dirname(__DIR__).'/'.$file);
$migrations=(string)file_get_contents(__DIR__.'/../erp_migrations.php');

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
article_ink_selector_check(strpos($source,'function erp_colors_per_face($value):')===false,'A validação de cores por face usa uma declaração incompatível com PHP 7.0.');
article_ink_selector_check(strpos($source,'pattern="[0-8](\+[0-8])?"')!==false,'O formulário não aceita cores distintas para frente e verso.');
article_ink_selector_check(strpos($source,'placeholder="Ex.: 2+0"')!==false,'O formato frente+verso não é explicado no formulário.');
article_ink_selector_check(strpos($source,"preg_match('/^\\d+(?:\\.\\d+)?(?:\\+\\d+(?:\\.\\d+)?)*$/',\$value)")!==false,'A gramagem não valida valores compostos como 60+20.');
article_ink_selector_check(substr_count($source,"erp_article_grammage(\$_POST['grammage'")===1 && substr_count($source,"erp_article_grammage(\$input['grammage'")===1,'A criação e a edição não preservam a gramagem composta.');
article_ink_selector_check(strpos($source,'placeholder="Ex.: 60+20"')!==false,'O formato de gramagem composta não é explicado no formulário.');
article_ink_selector_check(strpos($migrations,'grammage TEXT, colors_per_face TEXT')!==false,'A base de dados não está preparada para guardar gramagem composta.');

echo "article_ink_selector_test: OK\n";
