<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Services/ArticleTheoreticalWeight.php';

function theoretical_weight_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

theoretical_weight_check(ArticleTheoreticalWeight::grams('50,0', '70,0', '60+20') === 58.0, 'O exemplo deve produzir 58 g.');
theoretical_weight_check(ArticleTheoreticalWeight::grams(50, 70, '60 + 20') === 58.0, 'A gramagem composta deve ser somada.');
theoretical_weight_check(ArticleTheoreticalWeight::grams('', 70, 80) === null, 'Dimensões incompletas não devem produzir peso.');
theoretical_weight_check(strpos(file_get_contents(dirname(__DIR__).'/app/Services/ArticleTheoreticalWeight.php'), '?float') === false, 'O serviço deve ser compatível com PHP 7.0.');

$source=file_get_contents(dirname(__DIR__).'/erp.php');
theoretical_weight_check(strpos($source, "\$values['theoretical_weight']=ArticleTheoreticalWeight::grams") !== false, 'A gravação não impõe o cálculo no servidor.');
theoretical_weight_check(strpos($source, 'data-theoretical-weight readonly') !== false, 'O peso teórico ainda pode ser editado manualmente.');

echo "Cálculo do peso teórico do artigo validado.\n";
