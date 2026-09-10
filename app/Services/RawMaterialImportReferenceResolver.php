<?php

/**
 * Resolves the master-data codes used exclusively by the raw-material importer.
 *
 * Types and features may be planned during preview and created during import.
 * All other reference data remains lookup-only in erp.php.
 */
class RawMaterialImportReferenceResolver
{
    private $pdo;
    private $previewOnly;
    private $planned = [];

    public function __construct(PDO $pdo, $previewOnly)
    {
        $this->pdo = $pdo;
        $this->previewOnly = (bool) $previewOnly;
    }

    public function resolveType($value)
    {
        return $this->resolveCreatable('erp_material_types', 'name', $value);
    }

    public function resolveFeature($value)
    {
        return $this->resolveCreatable('erp_material_features', 'description', $value);
    }

    private function resolveCreatable($table, $nameColumn, $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return ['id' => null, 'create' => false, 'value' => ''];
        }

        $key = $table . ':' . $this->normalize($value);
        if (isset($this->planned[$key])) {
            return ['id' => $this->planned[$key]['id'], 'create' => false, 'value' => $this->planned[$key]['value']];
        }

        $id = $this->findIdByCode($table, $value);
        if ($id !== null) {
            $this->planned[$key] = ['id' => $id, 'value' => $value];
            return ['id' => $id, 'create' => false, 'value' => $value];
        }

        if ($this->previewOnly) {
            $this->planned[$key] = ['id' => null, 'value' => $value];
            return ['id' => null, 'create' => true, 'value' => $value];
        }

        $stmt = $this->pdo->prepare('INSERT INTO ' . $table . '(code,' . $nameColumn . ',is_active) VALUES (?,?,1)');
        $stmt->execute([$value, $value]);
        $id = (int) $this->pdo->lastInsertId();
        $this->planned[$key] = ['id' => $id, 'value' => $value];

        return ['id' => $id, 'create' => true, 'value' => $value];
    }

    private function findIdByCode($table, $value)
    {
        $rows = $this->pdo->query('SELECT id,code FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        $wanted = $this->normalize($value);
        foreach ($rows as $row) {
            if ($this->normalize($row['code']) === $wanted) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function normalize($value)
    {
        $value = trim((string) $value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
