<?php
declare(strict_types=1);

interface SupplierSpecificParserInterface
{
    public function supports(array $supplier, string $text): bool;
    public function parse(string $text, array $context = []): array;
}
