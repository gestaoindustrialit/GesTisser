<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Services/ArticlePalletWeight.php';

function pallet_weight_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

pallet_weight_check(ArticlePalletWeight::kilograms('58,0', '6000,0') === 348.0, 'O peso da palete deve converter o produto de gramas para quilogramas.');
pallet_weight_check(ArticlePalletWeight::kilograms('12.345', '125') === 1.543, 'O peso calculado deve ser arredondado a três casas decimais.');
pallet_weight_check(ArticlePalletWeight::kilograms('', '100') === null, 'Um peso teórico vazio não deve produzir peso de palete.');
pallet_weight_check(ArticlePalletWeight::kilograms('10', '') === null, 'Uma quantidade vazia não deve produzir peso de palete.');

$source=file_get_contents(dirname(__DIR__).'/erp.php');
pallet_weight_check(strpos($source, "\$values['pallet_weight']=ArticlePalletWeight::kilograms") !== false, 'A gravação do artigo não impõe o cálculo no servidor.');
pallet_weight_check(strpos($source, 'data-pallet-weight readonly') !== false, 'O peso calculado ainda pode ser editado manualmente.');

echo "Cálculo do peso teórico da palete validado.\n";
