<div class="col-12 border-top pt-3"><h4 class="h6">Referências da tinta</h4><p class="small text-muted">A classificação é a do <strong>Tipo de tinta</strong> já configurado acima. Cor e Pantone são recuperados da característica selecionada, quando esta os identifica.</p><div class="row g-2">
<div class="col-md-3"><label class="form-label">Cor</label><input class="form-control" name="ink_color" maxlength="120" value="<?=h((string)($selectedRawMaterial['ink_color']??''))?>"></div>
<div class="col-md-3"><label class="form-label">Pantone</label><input class="form-control" name="ink_pantone" maxlength="120" placeholder="000, 072, 130…" value="<?=h((string)($selectedRawMaterial['ink_pantone']??''))?>"></div>
<div class="col-md-3"><label class="form-label">Tinta de água</label><p class="form-control-plaintext" data-master-water><?=!empty($selectedRawMaterial['is_water_based_ink'])?'Sim':'Não'?></p></div>
<div class="col-md-3"><label class="form-label">Último preço</label><input class="form-control" name="last_price" type="number" step="0.0001" min="0" value="<?=h((string)($selectedRawMaterial['last_price']??0))?>"></div>
<div class="col-md-3"><label class="form-label">ID no ERP antigo</label><input class="form-control" name="legacy_ink_id" maxlength="120" value="<?=h((string)($selectedRawMaterial['legacy_ink_id']??''))?>"></div>
<div class="col-12"><button type="button" class="btn btn-sm btn-outline-secondary" data-use-feature>Usar cor e Pantone da característica</button></div><div class="col-12 form-text">Mantêm-se o código e o ID interno do material. O ID antigo é uma referência adicional. O tipo, ícone e característica existentes são reutilizados.</div>
</div></div>
<script>
(function(){var form=document.querySelector('#raw-material-editor form');if(!form)return;var types=<?=json_encode(array_column($inkTypes,'code','id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
function refreshType(){var checked=form.querySelector('[name="ink_type_id"]:checked');form.querySelector('[data-master-water]').textContent=checked&&types[checked.value]==='AGUA'?'Sim':'Não';}
form.querySelectorAll('[name="ink_type_id"]').forEach(function(radio){radio.addEventListener('change',refreshType);});refreshType();
var feature=form.querySelector('[name="material_feature_id"]');function refreshFeature(fill){var found=false;var option=feature.options[feature.selectedIndex],description=option?option.dataset.meta||'':'';['ink_color','ink_pantone'].forEach(function(field){var regex=field==='ink_color'?/(?:^|\|)\s*COR\s*:\s*([^|]+)/i:/(?:^|\|)\s*PANTONE\s*:\s*([^|]+)/i,match=description.match(regex),input=form.elements[field];input.readOnly=!!match;if(match)found=true;if(fill&&match)input.value=match[1].trim();});form.querySelector('[data-use-feature]').hidden=!found;}
form.querySelector('[data-use-feature]').addEventListener('click',function(){refreshFeature(true);});
feature.addEventListener('change',function(){refreshFeature(true);});refreshFeature(false);})();
</script>
