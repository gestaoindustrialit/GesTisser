document.addEventListener('DOMContentLoaded',function(){
  var form=document.querySelector('[data-sales-order-form]');if(!form)return;
  var body=form.querySelector('[data-sales-lines]'),template=form.querySelector('[data-sales-line-template]'),customer=form.querySelector('[name=customer_id]');
  var next=body.children.length;
  function filter(){body.querySelectorAll('select[name$="[finished_product_id]"]').forEach(function(select){Array.prototype.forEach.call(select.options,function(o){var owner=o.getAttribute('data-customer');o.hidden=!!owner&&owner!=='0'&&owner!==customer.value&&!o.selected;});});}
  form.querySelector('[data-add-sales-line]').addEventListener('click',function(){body.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,String(next++)));filter();});
  body.addEventListener('click',function(event){var button=event.target.closest('[data-remove-sales-line]');if(button&&body.children.length>1)button.closest('tr').remove();});
  customer.addEventListener('change',filter);filter();
});
