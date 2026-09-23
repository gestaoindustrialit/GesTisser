<?php

$source = (string) file_get_contents(__DIR__ . '/../shopfloor.php');

function shopfloor_optional_calculator_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

shopfloor_optional_calculator_assert(
    strpos($source, "if (is_file(\$validatedHourBankCalculatorPath))") !== false,
    'O Shopfloor deve arrancar quando o calculador de BH ainda não foi publicado.'
);
shopfloor_optional_calculator_assert(
    strpos($source, "class_exists('ValidatedHourBankCalculator')") !== false,
    'A utilização do calculador de BH deve confirmar que a classe está disponível.'
);
shopfloor_optional_calculator_assert(
    strpos($source, "require_once __DIR__ . '/app/Services/ValidatedHourBankCalculator.php';") === false,
    'O Shopfloor não deve importar obrigatoriamente o calculador opcional.'
);

echo "shopfloor_optional_hour_bank_calculator_test: OK\n";
