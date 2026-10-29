<?php
require_once dirname(__DIR__).'/assist_migrations.php';
require_once dirname(__DIR__).'/app/Services/AssistService.php';
assist_run_migrations($pdo);
$assistShellUser=current_user($pdo);
$assistShell=AssistService::fromSession($pdo,$assistShellUser?:[]);
$assistMascotPath=dirname(__DIR__).'/assets/assist-saco.png';
$assistMascotVersion=(string)(@filemtime($assistMascotPath)?:'1');
if($assistShell->enabled() && $assistShell->can('assist.use')):
?>
<link rel="stylesheet" href="assets/assist.css?v=<?=h((string)(@filemtime(dirname(__DIR__).'/assets/assist.css')?:'1'))?>">
<button type="button" class="assist-launcher" id="assist-open" aria-label="Abrir GesTISSER Assist" title="Abrir GesTISSER Assist" aria-controls="assist-panel" aria-expanded="false">
<svg class="assist-launcher-fallback" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" focusable="false"><path d="M22 16 17 7l12 3 13-3-5 9M23 16h14M24 20C17 28 9 39 12 50c2 8 38 8 40 0 3-11-5-22-12-30Z" fill="#fff" stroke="#084c8a" stroke-width="3" stroke-linejoin="round"/><circle cx="26" cy="36" r="2.5" fill="#084c8a"/><circle cx="38" cy="36" r="2.5" fill="#084c8a"/><path d="M25 44q7 7 14 0" fill="none" stroke="#084c8a" stroke-width="2.5" stroke-linecap="round"/></svg>
<?php if(is_file($assistMascotPath)):?><img src="assets/assist-saco.png?v=<?=h($assistMascotVersion)?>" width="72" height="72" alt=""><?php endif;?>
</button>
<section id="assist-panel" class="assist-panel" aria-label="GesTISSER Assist" hidden data-language="pt" data-token="<?=h(csrf_token())?>" data-equipment="<?= isset($selectedWorkCenterMachineId) && $selectedWorkCenterMachineId>0 ? (int)$selectedWorkCenterMachineId : '' ?>" data-order="<?=isset($selectedOf) && $selectedOf ? (int)$selectedOf['id'] : ''?>">
<header class="assist-header"><div class="d-flex align-items-center gap-2">
<?php if(is_file($assistMascotPath)):?><img src="assets/assist-saco.png?v=<?=h($assistMascotVersion)?>" width="52" height="52" alt="SACO, mascote GesTISSER" style="object-fit:contain"><?php endif;?>
<div class="assist-heading"><strong>GesTISSER Assist</strong><div class="small">SACO · Sistema de Apoio a Conhecimento Operacional</div></div></div><button type="button" class="btn btn-light" id="assist-close" aria-label="Fechar Assist">×</button></header>
<div id="assist-suggestions" class="d-flex gap-1 flex-wrap px-3 py-1" aria-label="Sugestões de procedimentos publicados"></div>
<div id="assist-messages" class="assist-messages" role="log" aria-live="polite"></div>
<div id="assist-document" class="assist-document" hidden><button type="button" class="btn btn-outline-secondary" id="assist-document-close">Voltar ao chat</button><div id="assist-document-content"></div></div>
<div id="assist-status" role="status" class="px-3 text-danger"></div>
<form id="assist-form" class="assist-composer"><label for="assist-question">Pergunta</label><textarea class="form-control" id="assist-question" maxlength="2000" rows="2" required placeholder="Descreva o problema ou pesquise por palavras-chave"></textarea><div class="assist-composer-actions"><button type="submit" class="btn btn-primary">Pesquisar</button><button type="button" id="assist-report-open" class="btn btn-outline-secondary">Reportar problema</button><button type="button" id="assist-new" class="btn btn-outline-secondary">Nova conversa</button></div></form>
<form id="assist-report" class="assist-report" hidden><h2 class="h5">Reportar problema</h2><label>Descrição<textarea class="form-control" name="description" maxlength="5000" required></textarea></label><label>Máquina / equipamento<input class="form-control" name="equipment" maxlength="150"></label><label>Operação / OF (referência)<input class="form-control" name="operation_ref" maxlength="150"></label><label>Prioridade<select data-searchable-select="off" class="form-select" name="priority"><option value="normal">Normal</option><option value="low">Baixa</option><option value="high">Alta</option></select></label><button type="submit" class="btn btn-primary">Enviar</button><button type="button" class="btn btn-light" id="assist-report-cancel">Cancelar</button></form>
</section>
<script src="assets/assist.js?v=<?=h((string)(@filemtime(dirname(__DIR__).'/assets/assist.js')?:'1'))?>" defer></script>
<?php endif;?>
