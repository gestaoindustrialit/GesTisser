<?php
// Copy OUTSIDE the public web root and wire configuration() to that path,
// or protect storage/historical-import-config.php from HTTP access.
// Do not enable before reviewing the REAL database and confirming reporting behavior.
return [
    'enabled' => false,
    'reviewed_schema_hash' => '',
    'reviewed_article_report_hash' => '', // SHA-256 do staging real revisto antes dos schemas/testes.
    'entities' => [],
    // Available prepared adapters: ofs, of_operacoes, rolos_rafia,
    // lotes_tintas, movimentos_historicos. Sales orders/lines and receipts
    // need the real schema and a reviewed adapter, not guessed tables.
];
