(function(){
'use strict';
var box=document.querySelector('[data-purchase-editor]');if(!box)return;
var body=box.querySelector('[data-order-lines]'),template=box.querySelector('template'),modal=document.getElementById('purchase-item-modal'),targetRow=null;
function enhance(row){if(window.GesTisserSearchableSelect)window.GesTisserSearchableSelect.init(row);}
function add(line){
 var row=template.content.firstElementChild.cloneNode(true),select=row.querySelector('[data-item]');
 function update(fill){var parts=select.value.split(':'),option=select.options[select.selectedIndex];row.querySelector('[name="line_item_type[]"]').value=parts[0]||'';row.querySelector('[name="line_item_id[]"]').value=parts[1]||'';row.querySelector('[data-line-unit]').textContent=option?option.dataset.unit||'':'';if(fill)row.querySelector('[name="line_unit_cost[]"]').value=option?option.dataset.price||0:0;}
 select.addEventListener('change',function(){update(true);});
 if(line){select.value=line.item_type+':'+line.item_id;row.querySelector('[name="line_quantity[]"]').value=line.quantity;row.querySelector('[name="line_unit_cost[]"]').value=line.unit_price;row.querySelector('[data-received]').textContent=line.received_quantity+' / '+line.quantity;update(false);}
 else{row.querySelector('[name="line_quantity[]"]').value=1;row.querySelector('[name="line_unit_cost[]"]').value=0;}
 var remove=row.querySelector('[data-remove]');if(remove)remove.onclick=function(){row.remove();};
 var create=row.querySelector('[data-create-item]');if(create)create.onclick=function(){
  if(!modal||!window.bootstrap)return;targetRow=row;var form=modal.querySelector('form');form.reset();form.querySelector('[data-item-error]').hidden=true;form.elements.supplier_id.value=box.closest('form').elements.supplier_id.value;bootstrap.Modal.getOrCreateInstance(modal).show();
 };
 body.appendChild(row);enhance(row);
}
var lines=JSON.parse(box.querySelector('[data-purchase-initial-lines]').textContent);lines.forEach(add);if(!lines.length)add();
var button=box.querySelector('[data-add-line]');if(button)button.onclick=function(){add();};
if(!modal)return;
var form=modal.querySelector('form'),error=form.querySelector('[data-item-error]');
form.addEventListener('submit',function(event){
 event.preventDefault();var submit=form.querySelector('[type=submit]');if(submit.disabled)return;submit.disabled=true;error.hidden=true;
 fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}}).then(function(response){return response.json().then(function(data){if(!response.ok||!data.item)throw new Error(data.error||'Não foi possível criar o artigo.');return data.item;});}).then(function(item){
  var key=item.item_type+':'+item.id;
  var selects=Array.prototype.slice.call(body.querySelectorAll('[data-item]'));selects.push(template.content.querySelector('[data-item]'));
  selects.forEach(function(select){var group=select.querySelector(item.item_type==='raw_material'?'optgroup:first-of-type':'optgroup:last-of-type');var option=new Option(item.code+' — '+item.description,key);option.dataset.unit=item.unit_code;option.dataset.price=item.price;group.appendChild(option);});
  if(targetRow&&targetRow.isConnected){var select=targetRow.querySelector('[data-item]');select.value=key;select.dispatchEvent(new Event('change',{bubbles:true}));if(select._gtSearchableSelect)select._gtSearchableSelect.refresh('');}
  bootstrap.Modal.getOrCreateInstance(modal).hide();
 }).catch(function(e){error.textContent=e.message;error.hidden=false;}).then(function(){submit.disabled=false;});
});
})();
