<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/erp_migrations.php';
require_once __DIR__ . '/app/Services/ArticleDocument.php';

require_login();
erp_run_phase1_migrations($pdo);

$stmt = $pdo->prepare('SELECT * FROM erp_technical_sheets WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$sheet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sheet) {
    http_response_code(404);
    exit('Ficha Técnica não encontrada.');
}

$a = json_decode($sheet['snapshot_json'], true) ?: [];
$o = $a['_order'] ?? [];
$documents = is_array($a['_documents'] ?? null) ? $a['_documents'] : [];
$mainDocument = ArticleDocument::mainArtwork($documents);
$mainDocumentThumbnail = '';
if ($mainDocument) {
    if (isset($mainDocument['id'])) {
        $mainDocumentThumbnail = ArticleDocument::thumbnailUrl((int) $mainDocument['id']);
    } else {
        $path = ArticleDocument::absolutePath(__DIR__, (string) ($mainDocument['file_url'] ?? ''));
        if ($path !== '') {
            $mainDocumentThumbnail = 'data:image/jpeg;base64,' . base64_encode(ArticleDocument::thumbnail($path));
        }
    }
}

function sheet_value(array $data, string $key): string
{
    return trim((string) ($data[$key] ?? ''));
}

function technical_sheet_date(string $value, string $fallback = ''): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $fallback;
}

function technical_sheet_logo_src(string $configuredPath): string
{
    $configuredPath = trim($configuredPath);
    if ($configuredPath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $configuredPath)) {
        return $configuredPath;
    }
    $absolutePath = __DIR__ . '/' . ltrim($configuredPath, '/');
    if (!is_file($absolutePath)) {
        return '';
    }
    $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
    $contents = file_get_contents($absolutePath);
    return $contents === false ? '' : 'data:' . ($mimeTypes[$extension] ?? 'application/octet-stream') . ';base64,' . base64_encode($contents);
}

$companyName = trim((string) app_setting($pdo, 'company_name', 'TISSER')) ?: 'TISSER';
$companyAddress = trim((string) app_setting($pdo, 'company_address', ''));
$companyPhone = trim((string) app_setting($pdo, 'company_phone', ''));
$companyEmail = trim((string) app_setting($pdo, 'company_email', ''));
$companyLogo = technical_sheet_logo_src((string) app_setting($pdo, 'logo_report_dark', ''));
$documentDate = technical_sheet_date((string) ($sheet['created_at'] ?? ''), date('d/m/Y'));
$contacts = array_filter([
    $companyPhone !== '' ? 'Tel.: ' . $companyPhone : '',
    $companyEmail,
]);
$analysis = [
    'analysis_grammage' => 'Gramagem média do saco',
    'analysis_total_weight' => 'Peso total do saco',
    'analysis_apparent_width' => 'Largura média aparente',
    'analysis_gusset_width' => 'Largura média do fole',
    'analysis_bag_height' => 'Altura média do saco',
    'analysis_break_height' => 'Rotura / alongamento — altura',
    'analysis_break_length' => 'Rotura / alongamento — comprimento',
    'analysis_seam_strength' => 'Resistência da costura',
    'analysis_static_friction' => 'Fricção — estático',
    'analysis_dynamic_friction' => 'Fricção — dinâmico',
    'analysis_air_permeability' => 'Permeabilidade do ar',
];
$filledAnalysis = array_filter($analysis, function ($label, $key) use ($a) {
    return sheet_value($a, $key) !== '';
}, ARRAY_FILTER_USE_BOTH);
$materialFields = [
    'material' => 'Material', 'composition' => 'Composição', 'bag_color' => 'Cor', 'grammage' => 'Gramagem',
    'width' => 'Largura', 'length' => 'Comprimento', 'width_tolerance' => 'Tolerância largura',
    'length_tolerance' => 'Tolerância comprimento', 'theoretical_weight' => 'Peso teórico',
    'proof_reference' => 'N.º prova', 'front_colors' => 'Cores usadas na impressão — frente',
    'back_colors' => 'Cores usadas na impressão — verso',
    'thread_color' => 'Cor do fio', 'perforation_type' => 'Perfuração', 'seam_type' => 'Costura', 'gusset_length' => 'Fole',
];
?><!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ficha Técnica do Produto</title>
<style>
    *{box-sizing:border-box} html,body{margin:0;padding:0;background:#e5e7eb;color:#111;font-family:Arial,Helvetica,sans-serif;font-size:10px}
    .actions{width:210mm;margin:12px auto 8px;display:flex;justify-content:flex-end}.actions button{border:0;border-radius:5px;background:#1f2937;color:#fff;font-weight:700;padding:9px 16px;cursor:pointer}
    .page{width:210mm;height:297mm;margin:0 auto 20px;padding:9mm 10mm 8mm;background:#fff;box-shadow:0 2px 12px #0003;display:flex;flex-direction:column;gap:2.2mm;overflow:hidden}
    .report-header{height:27mm;display:grid;grid-template-columns:48mm 1fr 51mm;align-items:center;border-bottom:2px solid #111;padding-bottom:2.5mm}
    .brand{height:20mm;display:flex;align-items:center}.brand img{display:block;max-width:45mm;max-height:19mm}.brand-fallback{font-size:22px;font-weight:900;letter-spacing:1px}
    .title{text-align:center}.title h1{font-size:19px;line-height:1.05;margin:0;text-transform:uppercase}
    .company{text-align:right;font-size:8.5px;line-height:1.35}.company strong{display:block;font-size:10px}.company .date{margin-top:1.2mm;font-weight:700}
    .section{border:1.5px solid #111;break-inside:avoid;page-break-inside:avoid}.section-title{font-size:11px;text-align:center;text-transform:uppercase;background:#e6e7e8;border-bottom:1px solid #777;margin:0;padding:1.2mm 2mm;line-height:1.1}
    .grid{display:grid;grid-template-columns:repeat(4,1fr)}.cell{border-right:1px solid #999;border-bottom:1px solid #999;padding:1.3mm 1.8mm;min-height:10.5mm;line-height:1.18;overflow-wrap:anywhere}.cell:nth-child(4n){border-right:0}.cell b{display:block;font-size:7.5px;line-height:1;text-transform:uppercase;margin-bottom:.7mm}.wide{grid-column:span 2}.identification .cell{min-height:11.5mm}.grid .cell.no-bottom{border-bottom:0}
    .material-grid .cell{min-height:9.5mm}.material-grid .cell:nth-last-child(-n+4){border-bottom:0}
    .features{display:grid;grid-template-columns:repeat(5,1fr)}.features .cell{min-height:8.5mm}.features .cell:nth-child(5n){border-right:0}.features .cell:nth-last-child(-n+2){border-bottom:0}
    .analysis-grid{display:grid;grid-template-columns:repeat(2,1fr)}.analysis-item{display:flex;justify-content:space-between;gap:3mm;border-right:1px solid #999;border-bottom:1px solid #999;padding:1mm 1.7mm;min-height:6mm}.analysis-item:nth-child(2n){border-right:0}.analysis-item:nth-last-child(-n+2){border-bottom:0}.analysis-item b{font-size:7.5px;text-transform:uppercase}.analysis-item span{text-align:right}
    .artwork{flex:1;min-height:43mm;display:flex;flex-direction:column}.artwork-body{flex:1;min-height:0;padding:2mm;text-align:center;display:flex;flex-direction:column}.document-title{font-weight:700;font-size:9px;margin-bottom:1mm}.artwork img{display:block;flex:1;min-height:0;max-width:100%;width:100%;height:100%;object-fit:contain;margin:auto}
    .empty-artwork{flex:1;display:flex;align-items:center;justify-content:center;color:#777;font-style:italic}
    .order-grid{display:grid;grid-template-columns:1fr 1fr 2fr}.order-grid .cell{border-bottom:0;min-height:12mm}.order-grid .cell:last-child{border-right:0}
    @page{size:A4 portrait;margin:0}
    @media print{html,body{width:210mm;height:297mm;background:#fff}.actions{display:none}.page{margin:0;box-shadow:none;break-after:avoid;page-break-after:avoid}}
</style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div>
<main class="page">
    <header class="report-header">
        <div class="brand"><?php if ($companyLogo !== ''): ?><img src="<?= h($companyLogo) ?>" alt="Logótipo <?= h($companyName) ?>"><?php else: ?><span class="brand-fallback"><?= h($companyName) ?></span><?php endif; ?></div>
        <div class="title"><h1>Ficha Técnica do Produto</h1></div>
        <div class="company"><strong><?= h($companyName) ?></strong><?php if ($companyAddress !== ''): ?><div><?= nl2br(h($companyAddress)) ?></div><?php endif; ?><?php if ($contacts): ?><div><?= h(implode(' · ', $contacts)) ?></div><?php endif; ?><div class="date">Data: <?= h($documentDate) ?></div></div>
    </header>

    <section class="section identification">
        <h2 class="section-title">Identificação</h2>
        <div class="grid">
            <div class="cell wide"><b>Cliente</b><?= h(sheet_value($a, 'customer_name')) ?></div><div class="cell"><b>Artigo / N. Ref.</b><?= h(sheet_value($a, 'code')) ?></div><div class="cell"><b>Ref. cliente</b><?= h(sheet_value($a, 'customer_product_code')) ?></div>
            <div class="cell wide no-bottom"><b>Descrição</b><?= h(sheet_value($a, 'description')) ?></div><div class="cell no-bottom"><b>Encomenda</b><?= h(sheet_value($o, 'customer_order')) ?></div><div class="cell no-bottom"><b>Data prevista</b><?= h(technical_sheet_date(sheet_value($o, 'due_date'))) ?></div>
        </div>
    </section>

    <section class="section">
        <h2 class="section-title">Especificações do material e dimensões do saco</h2>
        <div class="grid material-grid"><?php foreach ($materialFields as $key => $label): ?><div class="cell"><b><?= h($label) ?></b><?= sheet_value($a, $key) !== '' ? nl2br(h(sheet_value($a, $key))) : '—' ?></div><?php endforeach; ?></div>
    </section>

    <section class="section">
        <h2 class="section-title">Especificações do saco e empaletização</h2>
        <div class="features"><?php foreach (['microperforation' => 'Microperfuração', 'has_handle' => 'Asa', 'has_holes' => 'Furo', 'has_gusset' => 'Fole', 'centered_gusset' => 'Fole centrado'] as $key => $label): ?><div class="cell"><b><?= h($label) ?></b><?= !empty($a[$key]) ? '☑ Sim&nbsp;&nbsp;☐ Não' : '☐ Sim&nbsp;&nbsp;☑ Não' ?></div><?php endforeach; ?><?php foreach (['pallet_dimensions' => 'Medidas da palete', 'pallet_lid' => 'Tampa', 'pallet_straps' => 'Fitas', 'pallet_film' => 'Filme', 'pallet_quantity' => 'Qtd. ~ Palete', 'pallet_weight' => 'Kg ~ Palete'] as $key => $label): ?><div class="cell"><b><?= h($label) ?></b><?= sheet_value($a, $key) !== '' ? h(sheet_value($a, $key)) : '—' ?></div><?php endforeach; ?><div class="cell"><b>Observação</b>—</div></div>
    </section>

    <?php if ($filledAnalysis): ?><section class="section"><h2 class="section-title">Boletim de análise</h2><div class="analysis-grid"><?php foreach ($filledAnalysis as $key => $label): ?><div class="analysis-item"><b><?= h($label) ?></b><span><?= h(sheet_value($a, $key)) ?></span></div><?php endforeach; ?></div></section><?php endif; ?>

    <section class="section artwork">
        <h2 class="section-title">MAQUETA DO ARTIGO</h2>
        <div class="artwork-body"><?php if ($mainDocumentThumbnail !== ''): ?><div class="document-title"><?= h((string) ($mainDocument['title'] ?? 'Maqueta do artigo')) ?></div><img src="<?= h($mainDocumentThumbnail) ?>" alt="Maqueta do artigo"><?php else: ?><div class="empty-artwork">Sem maqueta associada ao artigo</div><?php endif; ?></div>
    </section>

    <section class="section"><h2 class="section-title">Dados da encomenda</h2><div class="order-grid"><div class="cell"><b>Quantidade</b><?= h(sheet_value($o, 'quantity')) ?></div><div class="cell"><b>Lote</b><?= h(sheet_value($o, 'lot')) ?></div><div class="cell wide"><b>Observações</b><?= h(sheet_value($o, 'notes')) ?></div></div></section>
</main>
</body>
</html>
