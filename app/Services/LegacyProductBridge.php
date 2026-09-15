<?php
declare(strict_types=1);

/**
 * Keeps new finished products usable by the legacy Shopfloor foreign key.
 *
 * Production orders still require erp_production_orders.product_id, while the
 * current article catalogue lives in erp_finished_products.  A compatibility
 * row with the same article code lets both parts of the application refer to
 * the article without requiring an administrator to create an unrelated
 * placeholder first.
 */
class LegacyProductBridge
{
    public static function productIdForFinishedProduct(PDO $pdo, array $article): int
    {
        $code = trim((string) ($article['code'] ?? ''));
        $description = trim((string) ($article['description'] ?? ''));
        if ($code === '' || $description === '') {
            throw new RuntimeException('O artigo não tem código ou descrição para integração com o Shopfloor.');
        }

        $find = $pdo->prepare('SELECT id FROM erp_products WHERE code=? COLLATE NOCASE LIMIT 1');
        $find->execute([$code]);
        $id = $find->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $insert = $pdo->prepare(
            'INSERT INTO erp_products(code,customer_code,description,customer_id,product_type_id,material_type_id,unit_id,width,length,grammage,min_stock,unit_cost,unit_price,is_active) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $code,
            trim((string) ($article['customer_product_code'] ?? '')) ?: null,
            $description,
            (int) ($article['customer_id'] ?? 0) ?: null,
            (int) ($article['product_type_id'] ?? 0) ?: null,
            (int) ($article['material_type_id'] ?? 0) ?: null,
            (int) ($article['unit_id'] ?? 0) ?: null,
            $article['width'] ?? null,
            $article['length'] ?? null,
            $article['grammage'] ?? null,
            (float) ($article['min_stock'] ?? 0),
            (float) ($article['standard_cost'] ?? 0),
            (float) ($article['sale_price'] ?? 0),
            1,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
