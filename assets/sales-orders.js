document.addEventListener('DOMContentLoaded',function(){
  var history=document.querySelector('.so-history');
  if(history){
    var items=Array.from(history.querySelectorAll('[data-order-history]'));
    var tools=history.querySelector('[data-history-tools]');if(tools)tools.hidden=!items.length;
    var remembered=[];try{remembered=JSON.parse(sessionStorage.getItem('gestisser.salesOrders.expanded')||'[]');if(!Array.isArray(remembered))remembered=[];}catch(error){}
    items.forEach(function(item){
      if(remembered.indexOf(item.dataset.orderId)!==-1||location.hash==='#'+item.id)item.open=true;
      var summary=item.querySelector('summary');
      function label(){summary.setAttribute('aria-expanded',String(item.open));summary.setAttribute('aria-label',(item.open?'Recolher':'Expandir')+' encomenda '+item.querySelector('.so-order-title strong').textContent);}
      label();item.addEventListener('toggle',function(){label();try{sessionStorage.setItem('gestisser.salesOrders.expanded',JSON.stringify(items.filter(function(record){return record.open;}).map(function(record){return record.dataset.orderId;})));}catch(error){}});
    });
    history.querySelector('[data-expand-orders]').addEventListener('click',function(){items.forEach(function(item){item.open=true;});});
    history.querySelector('[data-collapse-orders]').addEventListener('click',function(){items.forEach(function(item){item.open=false;});});
  }
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
  body.addEventListener('change',function(event){var select=event.target.closest('select[name$="[finished_product_id]"]');if(!select)return;var option=select.options[select.selectedIndex];select.closest('tr').querySelector('[name$="[description]"]').value=option?option.getAttribute('data-description')||'':'';});
  body.addEventListener('click',function(event){var add=event.target.closest('[data-add-sales-line]');if(add){body.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,String(next++)));filter();return;}var button=event.target.closest('[data-remove-sales-line]');if(button&&body.children.length>1)button.closest('tr').remove();});
  customer.addEventListener('change',filter);filter();
});
