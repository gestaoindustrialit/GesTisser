document.addEventListener('DOMContentLoaded',function(){
  var form=document.querySelector('[data-sales-order-form]');if(!form)return;
  var body=form.querySelector('[data-sales-lines]'),template=form.querySelector('[data-sales-line-template]'),customer=form.querySelector('[name=customer_id]');
  var next=body.children.length;
  function filter(){body.querySelectorAll('select[name$="[finished_product_id]"]').forEach(function(select){
    if(!select._salesOptions)select._salesOptions=Array.prototype.map.call(select.options,function(option){return option.cloneNode(true);});
    var previous=select.value;
    var allowed=select._salesOptions.filter(function(option){return option.value===''||option.getAttribute('data-customer')===customer.value;});
    select.textContent='';allowed.forEach(function(option){select.appendChild(option.cloneNode(true));});
    select.value=allowed.some(function(option){return option.value===previous;})?previous:'';
    select.disabled=!customer.value;
    if(previous&&select.value!==previous)select.closest('tr').querySelector('[name$="[description]"]').value='';
    if(select._gtSearchableSelect)select._gtSearchableSelect.refresh();
  });}
  form.querySelector('[data-add-sales-line]').addEventListener('click',function(){body.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,String(next++)));filter();});
  body.addEventListener('change',function(event){var select=event.target.closest('select[name$="[finished_product_id]"]');if(!select)return;var option=select.options[select.selectedIndex];select.closest('tr').querySelector('[name$="[description]"]').value=option?option.getAttribute('data-description')||'':'';});
  body.addEventListener('click',function(event){var button=event.target.closest('[data-remove-sales-line]');if(button&&body.children.length>1)button.closest('tr').remove();});
  customer.addEventListener('change',filter);filter();
});
