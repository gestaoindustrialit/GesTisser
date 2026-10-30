<?php
$inkShellUser=current_user($pdo);
$showInkModal=$inkShellUser&&((int)($inkShellUser['is_admin']??0)===1||(function_exists('gt_erp_user_can')&&gt_erp_user_can($pdo,$inkShellUser,'ink.prepare')));
if($showInkModal):?>
<link rel="stylesheet" href="assets/colorimetry.css?v=<?=h((string)filemtime(__DIR__.'/../assets/colorimetry.css'))?>">
<div class="modal fade ink-modal" id="colorimetryModal" tabindex="-1" aria-labelledby="colorimetryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header"><div class="ink-modal-brand"><span class="ink-modal-icon"><i class="bi bi-palette-fill" aria-hidden="true"></i></span><div><h2 class="modal-title" id="colorimetryModalTitle">Tintas / Cores</h2><p>Preparação, stock e rastreabilidade</p></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar Tintas / Cores"></button></div>
            <div class="modal-body" id="colorimetryModalBody" aria-live="polite"><div class="ink-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> A carregar…</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-ink-back disabled><i class="bi bi-arrow-left" aria-hidden="true"></i> Voltar</button><span class="ink-modal-status" role="status"></span><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>
<script src="assets/js/colorimetry.js?v=<?=h((string)filemtime(__DIR__.'/../assets/js/colorimetry.js'))?>"></script>
<?php endif;?>
