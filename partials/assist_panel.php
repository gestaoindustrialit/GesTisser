<?php
require_once dirname(__DIR__).'/assist_migrations.php';
require_once dirname(__DIR__).'/app/Services/AssistService.php';
assist_run_migrations($pdo);
$assistShellUser=current_user($pdo);
$assistShell=new AssistService($pdo,$assistShellUser?:[]);
if($assistShell->enabled() && $assistShell->can('assist.use')):
?>
<link rel="stylesheet" href="assets/assist.css">
<button type="button" class="btn btn-primary assist-launcher" id="assist-open" aria-controls="assist-panel" aria-expanded="false">SACO · Assist</button>
<section id="assist-panel" class="assist-panel" aria-label="GesTISSER Assist" hidden data-token="<?=h(csrf_token())?>" data-equipment="<?= isset($selectedWorkCenterMachineId) && $selectedWorkCenterMachineId>0 ? (int)$selectedWorkCenterMachineId : '' ?>" data-order="<?=isset($selectedOf) && $selectedOf ? (int)$selectedOf['id'] : ''?>">
<header class="assist-header"><div class="d-flex align-items-center gap-2">
<?php if(is_file(dirname(__DIR__).'/assets/assist-saco.png')):?><img src="assets/assist-saco.png" width="52" height="52" alt="SACO, mascote GesTISSER" style="object-fit:contain"><?php endif;?>
<div><strong>GesTISSER Assist</strong><div class="small">SACO · Conhecimento interno validado</div></div></div><button type="button" class="btn btn-light" id="assist-close" aria-label="Fechar Assist">×</button></header>
<div class="assist-toolbar"><label for="assist-language">Idioma / Language / भाषा</label><select data-searchable-select="off" id="assist-language" class="form-select form-select-sm"><option value="pt">Português</option><option value="en">English</option><option value="hi">हिन्दी</option></select><small>Fontes no idioma original. Sem tradução automática de instruções.</small>
<?php if($assistShell->can('assist.edit')):?><a href="assist_admin.php" target="_blank" rel="noopener">Base de conhecimento ↗</a><?php endif;?>
<button type="button" id="assist-new" class="btn btn-sm btn-outline-secondary">Nova conversa</button></div>
<div id="assist-suggestions" class="d-flex gap-1 flex-wrap px-3 py-1" aria-label="Sugestões de procedimentos publicados"></div>
<div id="assist-messages" class="assist-messages" role="log" aria-live="polite"></div>
<div id="assist-document" class="assist-document" hidden><button type="button" class="btn btn-outline-secondary" id="assist-document-close">Voltar ao chat</button><div id="assist-document-content"></div></div>
<div id="assist-status" role="status" class="px-3 text-danger"></div>
<form id="assist-form" class="assist-composer"><label for="assist-question">Pergunta / Question / प्रश्न</label><textarea class="form-control" id="assist-question" maxlength="2000" rows="2" required placeholder="Descreva o problema ou pesquise por palavras-chave"></textarea><button type="submit" class="btn btn-primary">Pesquisar</button><button type="button" id="assist-report-open" class="btn btn-outline-secondary">Reportar problema</button></form>
<form id="assist-report" class="assist-report" hidden><h2 class="h5">Reportar problema</h2><label>Descrição<textarea class="form-control" name="description" maxlength="5000" required></textarea></label><label>Máquina / equipamento<input class="form-control" name="equipment" maxlength="150"></label><label>Operação / OF (referência)<input class="form-control" name="operation_ref" maxlength="150"></label><label>Prioridade<select data-searchable-select="off" class="form-select" name="priority"><option value="normal">Normal</option><option value="low">Baixa</option><option value="high">Alta</option></select></label><button type="submit" class="btn btn-primary">Enviar</button><button type="button" class="btn btn-light" id="assist-report-cancel">Cancelar</button></form>
</section>
<script src="assets/assist.js" defer></script>
<?php endif;?>
