<?php
if (!isset($editCustomer, $id, $editing)) { http_response_code(404); exit; }
// Uses the same customer fields and shared writer as the existing ERP form.
$customerFields=[
    'code'=>'Código *','name'=>'Nome fiscal *','tax_number'=>'NIF','country'=>'País',
    'country_prefix'=>'Prefixo país','address'=>'Morada','address_2'=>'Morada 2',
    'postal_code'=>'Código postal','city'=>'Localidade','contact_name'=>'Contacto principal',
    'phone'=>'Telefone','mobile'=>'Telemóvel','fax'=>'Fax','email'=>'Email',
    'salesperson'=>'Vendedor','discount_percent'=>'Desconto (%)','balance'=>'Saldo registado (€)',
    'credit_limit'=>'Plafond (€)'
];
function cp_delivery_row(array $address) {
    ?><div class="border rounded p-3 mb-2" data-cp-delivery-row>
    <input type="hidden" name="delivery_id[]" value="<?= (int)($address['id']??0) ?>">
    <div class="row g-3">
    <?php foreach(['label'=>'Designação','address'=>'Morada *','postal_code'=>'Código postal','city'=>'Localidade','country'=>'País','transporter'=>'Transportador *'] as $field=>$label): ?>
    <div class="<?= in_array($field,['address','transporter'],true)?'col-12 col-md-6':'col-12 col-sm-6 col-md-3' ?>"><label class="form-label d-block"><span><?= h($label) ?></span><input class="form-control mt-1" name="delivery_<?= h($field) ?>[]" value="<?= h($address[$field]??'') ?>" <?= in_array($field,['address','transporter'],true)?'required':'' ?>></label></div>
    <?php endforeach; ?>
    </div><button type="button" class="btn btn-sm btn-outline-danger mt-2" data-cp-remove-delivery data-cp-edit-only><i class="bi bi-trash me-1" aria-hidden="true"></i>Remover morada</button>
    </div><?php
}
?>
<form method="post" action="<?= h(cp_url($id,'general')) ?>" class="cp-customer-form <?= $editing?'':'is-readonly' ?>" data-customer-form data-reload-on-cancel="<?= $flashError!==''?'1':'0' ?>">
<?= csrf_input() ?><input type="hidden" name="action" value="save_customer"><input type="hidden" name="customer_id" value="<?= $id ?>">
<fieldset <?= $editing?'':'disabled' ?> data-customer-fields>
<div class="row g-3">
<?php foreach($customerFields as $field=>$label): $numeric=in_array($field,['discount_percent','balance','credit_limit'],true); ?>
<div class="<?= in_array($field,['name','address','address_2','email'],true)?'col-12 col-md-6':'col-12 col-sm-6 col-lg-3' ?>"><label class="form-label d-block" for="customer-<?= h($field) ?>"><?= h($label) ?></label><input id="customer-<?= h($field) ?>" class="form-control" name="<?= h($field) ?>" <?= $numeric?'type="number" step="0.01"':'' ?> <?= in_array($field,['code','name'],true)?'required':'' ?> value="<?= h($editCustomer[$field]??'') ?>"></div>
<?php endforeach; ?>
<div class="col-12"><label class="form-label" for="customer-notes">Observações</label><textarea class="form-control" id="customer-notes" name="notes" rows="3"><?= h($editCustomer['notes']??'') ?></textarea></div>
<div class="col-12"><input type="hidden" name="is_active" value="0"><div class="form-check"><input class="form-check-input" id="customer-active" type="checkbox" name="is_active" value="1" <?= !empty($editCustomer['is_active'])?'checked':'' ?>><label class="form-check-label" for="customer-active">Cliente ativo</label></div></div>
</div>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4 mb-3"><h3 class="h6 mb-0">Moradas de entrega</h3><button type="button" class="btn btn-sm btn-outline-primary" data-cp-add-delivery data-cp-edit-only><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adicionar morada</button></div>
<div data-cp-deliveries><?php foreach($addresses as $address)cp_delivery_row($address); ?></div>
<?php if(!$addresses): ?><p class="text-secondary" data-cp-empty-deliveries>Sem moradas de entrega registadas.</p><?php endif; ?>
<template data-cp-delivery-template><?php cp_delivery_row(['id'=>0,'country'=>'Portugal']); ?></template>
<div class="d-flex flex-wrap gap-2 border-top pt-3 mt-3" data-cp-edit-only><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar alterações</button><button class="btn btn-outline-secondary" type="button" data-cp-cancel-edit>Cancelar</button></div>
</fieldset>
</form>
