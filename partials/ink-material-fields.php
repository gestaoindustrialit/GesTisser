<div class="col-12 border-top pt-3"><h4 class="h6">Dados da tinta — referências do ERP antigo</h4><div class="row g-2">
<div class="col-md-3"><label class="form-label">É tinta</label><select class="form-select" name="is_ink"><option value="0">Não</option><option value="1" <?=!empty($selectedRawMaterial['is_ink'])?'selected':''?>>Sim</option></select></div>
<div class="col-md-3"><label class="form-label">Cor</label><input class="form-control" name="ink_color" maxlength="120" value="<?=h((string)($selectedRawMaterial['ink_color']??''))?>"></div>
<div class="col-md-3"><label class="form-label">Pantone</label><input class="form-control" name="ink_pantone" maxlength="120" placeholder="000, 072, 130…" value="<?=h((string)($selectedRawMaterial['ink_pantone']??''))?>"></div>
<div class="col-md-3"><label class="form-label">Tinta de água</label><select class="form-select" name="is_water_based_ink"><option value="0">Não</option><option value="1" <?=!empty($selectedRawMaterial['is_water_based_ink'])?'selected':''?>>Sim</option></select></div>
<div class="col-md-3"><label class="form-label">Último preço</label><input class="form-control" name="last_price" type="number" step="0.0001" min="0" value="<?=h((string)($selectedRawMaterial['last_price']??0))?>"></div>
<div class="col-md-3"><label class="form-label">ID no ERP antigo</label><input class="form-control" name="legacy_ink_id" maxlength="120" value="<?=h((string)($selectedRawMaterial['legacy_ink_id']??''))?>"></div>
<div class="col-12 form-text">O código do artigo mantém a referência original. Pantone e ID antigo são texto, preservando zeros à esquerda. O ID interno deste ERP é independente.</div>
</div></div>
<script>
(function(){var form=document.querySelector('#raw-material-editor form');if(!form)return;var water=form.querySelector('[name="is_water_based_ink"]'),kind=form.querySelector('[name="is_ink"]');var types=<?=json_encode(array_column($inkTypes,'code','id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
form.querySelectorAll('[name="ink_type_id"]').forEach(function(radio){radio.addEventListener('change',function(){water.value=types[radio.value]==='AGUA'?'1':'0';if(radio.value)kind.value='1';if(water._gtSearchableSelect)water._gtSearchableSelect.refresh('');if(kind._gtSearchableSelect)kind._gtSearchableSelect.refresh('');});});
water.addEventListener('change',function(){form.querySelectorAll('[name="ink_type_id"]').forEach(function(radio){if(water.value==='1')radio.checked=types[radio.value]==='AGUA';else if(radio.checked&&types[radio.value]==='AGUA'){radio.checked=false;form.querySelector('[name="ink_type_id"][value=""]').checked=true;}});});})();
</script>
