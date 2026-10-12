document.addEventListener('DOMContentLoaded',function(){
  var source=document.querySelector('[data-of-sales-order]');if(!source)return;
  var form=source.closest('form'),line=form.querySelector('[data-of-sales-line]'),article=form.querySelector('[data-of-article]');
  var quantity=form.querySelector('[name=planned_quantity]'),due=form.querySelector('[name=due_date]'),reference=form.querySelector('[name=customer_order]');
  var lineOptions=Array.from(line.options).map(function(option){return option.cloneNode(true);});
  var articleOptions=Array.from(article.options).map(function(option){return option.cloneNode(true);});
  function refresh(select){if(select._gtSearchableSelect)select._gtSearchableSelect.refresh();}
  function rebuild(select,options,value){select.textContent='';options.forEach(function(option){select.appendChild(option.cloneNode(true));});select.value=value;refresh(select);}
  function syncArticle(initial){
    var noOrder=source.value==='none',selected=line.options[line.selectedIndex];
    var selectedId=selected&&selected.dataset.articleId||'';
    var previous=article.value;
    var options=articleOptions.filter(function(option){return !option.value||noOrder||(selectedId&&option.value===selectedId);});
    article.disabled=!noOrder&&!selectedId;
    rebuild(article,options,noOrder?(initial?previous:''):selectedId);
    quantity.removeAttribute('max');
    if(selectedId&&!noOrder){quantity.max=selected.dataset.pending;if(!initial||!quantity.value)quantity.value=selected.dataset.pending;if(!initial||!due.value)due.value=selected.dataset.due||'';reference.value=selected.dataset.orderNumber||'';}
    else{reference.value=noOrder?'Sem Encomenda':'';if(!initial){quantity.value='';due.value='';}}
    refresh(article);article.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function syncOrder(initial){
    var previous=initial?line.value:'',noOrder=source.value==='none';
    line.disabled=!source.value||noOrder;line.required=!noOrder;
    var options=lineOptions.filter(function(option){return !option.value||option.dataset.orderId===source.value;});
    rebuild(line,options,previous);syncArticle(initial);refresh(line);
  }
  source.addEventListener('change',function(){syncOrder(false);});
  line.addEventListener('change',function(){syncArticle(false);});
  syncOrder(true);
});
