<?php if(gt_erp_user_can($pdo,$user,'erp.master_data')): ?>
<div class="modal fade" id="purchase-item-modal" tabindex="-1" aria-labelledby="purchase-item-title" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<form action="erp_purchase_item.php" method="post" data-quick-item-form>
<div class="modal-header"><h3 class="modal-title h5" id="purchase-item-title">Criar artigo sem sair da encomenda</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
<div class="modal-body"><?=csrf_input()?><input type="hidden" name="supplier_id" value="">
<p class="text-muted small">Os dados da encomenda ficam preservados. O novo artigo será selecionado na linha onde abriu esta janela.</p>
<div class="alert alert-danger" role="alert" data-item-error hidden></div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label" for="quick-item-type">Tipo de artigo</label><select class="form-select" id="quick-item-type" name="item_type"><option value="raw_material">Matéria-prima / tinta / outros</option><option value="finished_product">Artigo acabado</option></select></div>
<div class="col-md-6"><label class="form-label" for="quick-item-category">Categoria</label><select class="form-select" id="quick-item-category" name="product_category"><option value="raw_material">Matéria-prima</option><option value="subsidiary">Subsidiário / tinta</option><option value="merchandise">Mercadoria</option><option value="packaging">Embalagem</option><option value="other">Outro</option></select></div>
<div class="col-md-6"><label class="form-label" for="quick-item-code">Código</label><input class="form-control" id="quick-item-code" name="code" maxlength="100" placeholder="Automático se ficar vazio"></div>
<div class="col-md-6"><label class="form-label" for="quick-item-unit">Unidade *</label><select class="form-select" id="quick-item-unit" name="unit_id" required><option value="">Selecione…</option><?php foreach($units as $unit):?><option value="<?=(int)$unit['id']?>"><?=h($unit['code'].' — '.$unit['name'])?></option><?php endforeach;?></select></div>
<div class="col-12"><label class="form-label" for="quick-item-description">Descrição *</label><input class="form-control" id="quick-item-description" name="description" maxlength="1000" required></div>
<div class="col-md-6"><label class="form-label" for="quick-item-price">Preço unitário / último preço *</label><input class="form-control" id="quick-item-price" type="number" step="0.0001" min="0" name="price" value="0" required></div>
<div class="col-12 border-top pt-3" data-quick-ink-section><label class="form-check"><input class="form-check-input" type="checkbox" name="is_ink" value="1" data-quick-is-ink> <span class="form-check-label">Este artigo é uma tinta</span></label><div class="row g-2 mt-1" data-quick-ink-fields hidden>
<div class="col-md-6"><label class="form-label" for="quick-ink-color">Cor *</label><input class="form-control" id="quick-ink-color" name="ink_color" maxlength="120"></div>
<div class="col-md-6"><label class="form-label" for="quick-ink-pantone">Pantone *</label><input class="form-control" id="quick-ink-pantone" name="ink_pantone" maxlength="120" placeholder="000, 072, 130…"></div>
<div class="col-md-6"><label class="form-label" for="quick-ink-type">Tipo de tinta *</label><select class="form-select" id="quick-ink-type" name="ink_type_id"><option value="">Selecione…</option><?php foreach($inkTypes as $inkType):if(!(int)$inkType['is_active'])continue;?><option value="<?=(int)$inkType['id']?>" data-ink-code="<?=h($inkType['code'])?>"><?=h($inkType['name'])?></option><?php endforeach;?></select><div class="form-text">Tipos e ícones definidos nas configurações existentes.</div></div><div class="col-md-6"><label class="form-label">Tinta de água</label><p class="form-control-plaintext" data-quick-water>Não</p></div>
<div class="col-md-6"><label class="form-label" for="quick-ink-legacy">ID no ERP antigo</label><input class="form-control" id="quick-ink-legacy" name="legacy_ink_id" maxlength="120"></div>
<p class="form-text col-12">Mantém o código original e os zeros de Pantone e ID antigo. O preço acima é guardado como último preço.</p></div></div>
</div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Criar e selecionar artigo</button></div>
</form></div></div></div>
<?php endif; ?>
<script defer src="assets/purchase-order-editor.js?v=<?=h((string)filemtime(__DIR__.'/../assets/purchase-order-editor.js'))?>"></script>
