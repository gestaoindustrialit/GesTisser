(function () {
    'use strict';
    var modal = document.getElementById('colorimetryModal');
    if (!modal || !window.bootstrap) return;
    var body = modal.querySelector('.modal-body'), back = modal.querySelector('[data-ink-back]');
    var status = modal.querySelector('.ink-modal-status'), instance = bootstrap.Modal.getOrCreateInstance(modal);
    var lastTrigger = null;
    var currentUrl = '', history = [], dirty = false, pending = false, writing = false, controller = null, blobUrls = [];
    var appBase = new URL('.', window.location.href), allowed = ['colorimetry.php','prepared_ink_label.php','print_label.php','ink_formula_attachment.php'];
    function route(value) {
        var url = new URL(value, currentUrl || appBase.href), file = url.pathname.split('/').pop();
        if (url.origin !== appBase.origin || url.pathname.substring(0, appBase.pathname.length) !== appBase.pathname || allowed.indexOf(file) < 0) return null;
        if (file !== 'ink_formula_attachment.php') url.searchParams.set('modal','1');
        var order = currentUrl && new URL(currentUrl).searchParams.get('production_order_id');
        if (order && !url.searchParams.has('production_order_id')) url.searchParams.set('production_order_id', order);
        return url;
    }
    function message(text, danger) {
        var old = body.querySelector('[data-ink-client-message]'); if (old) old.remove();
        var box = document.createElement('div'); box.className = 'alert '+(danger?'alert-danger':'alert-info'); box.setAttribute('role','alert'); box.setAttribute('data-ink-client-message',''); box.style.margin = '16px'; box.textContent = text;
        body.prepend(box);
    }
    function leave() { return !dirty || window.confirm('Tem alterações por guardar. Pretende sair desta vista?'); }
    function releaseBlobs() { blobUrls.forEach(function (url) { URL.revokeObjectURL(url); }); blobUrls = []; }
    function controls() { back.disabled = !history.length || pending; status.textContent = pending ? (writing ? 'A guardar…' : 'A carregar…') : (dirty ? 'Alterações por guardar' : ''); }
    function number(value) { return Number(String(value || '').replace(',','.')) || 0; }
    function mass(form, path) {
        var input = form.querySelector('[name="data['+path+'][quantity]"]'), unit = form.querySelector('[name="data['+path+'][unit]"]');
        return Math.round(number(input && input.value) * (unit && unit.value === 'kg' ? 1000000 : 1000));
    }
    function format(mg, grams) { return (mg/(grams?1000:1000000)).toLocaleString('pt-PT',{minimumFractionDigits:3,maximumFractionDigits:3})+(grams?'':' kg'); }
    function calculate() {
        body.querySelectorAll('[data-ink-form="prepare"],[data-ink-form="formula"]').forEach(function (form) {
            var target = form.querySelector('[name="data[quantity]"]'), unit = form.querySelector('[name="data[unit]"]');
            var targetMg = Math.round(number(target && target.value)*(unit && unit.value === 'kg'?1000000:1000)), used = 0, cumulative = 0;
            form.querySelectorAll('[data-calculate-mg]').forEach(function (cell) {
                cumulative += number(cell.dataset.calculateMg); var base = number(cell.dataset.baseMg), next = base ? Math.round(targetMg*(cumulative/base)) : 0;
                cell.textContent = targetMg>0 ? format(next-used,true) : '—'; used = next;
            });
            var total = 0, packed = 0;
            form.querySelectorAll('[data-component-row]').forEach(function (row) {
                var input = row.querySelector('[name$="[quantity]"]'), match = input && input.name.match(/data\[(components)\]\[(\d+)\]/);
                if (match) total += mass(form,'components]['+match[2]);
            });
            form.querySelectorAll('[data-container-row]').forEach(function (row) {
                var input = row.querySelector('[name$="[quantity]"]'), match = input && input.name.match(/data\[(containers)\]\[(\d+)\]/);
                if (match) packed += mass(form,'containers]['+match[2]);
            });
            form.querySelectorAll('[data-weighed-total]').forEach(function (el) { el.textContent = format(total); });
            form.querySelectorAll('[data-container-total]').forEach(function (el) { el.textContent = format(packed); });
            var difference = form.querySelector('[data-container-difference]');
            if (difference) {
                difference.className = total && packed === total ? 'is-balanced' : 'is-mismatch';
                difference.textContent = total ? (packed===total ? 'Quantidades conferidas' : 'Diferença: '+format(packed-total)) : '';
            }
        });
    }
    function filterSources(row, changed) {
        var material = row.querySelector('[name$="[material_id]"]'); if (!material) return;
        row.querySelectorAll('[name$="[source_balance_id]"],[name$="[source_label_id]"]').forEach(function (select) {
            if (changed) select.value = '';
            Array.from(select.options).forEach(function (option) {
                var mismatch = !!option.value && !!material.value && option.dataset.materialId !== material.value;
                option.hidden = mismatch; option.disabled = mismatch;
            });
        });
    }
    function init() {
        body.querySelectorAll('[data-component-row]').forEach(function (row) { filterSources(row, false); });
        calculate();
        var error = body.querySelector('[role="alert"]');
        if (error) { error.tabIndex = -1; error.focus({preventScroll:true}); }
        else { var focus = body.querySelector('[autofocus]'); if (focus) focus.focus({preventScroll:true}); }
        controls();
    }
    function clone(button, group, selector) {
        var box = button.closest(group==='components'?'[data-components]':'[data-containers]'), rows = box.querySelectorAll(selector);
        if (!rows.length) return;
        var copy = rows[rows.length-1].cloneNode(true), index = 0;
        rows.forEach(function (row) { var field = row.querySelector('[name]'), match = field && field.name.match(/\]\[(\d+)\]/); if (match) index = Math.max(index,Number(match[1])+1); });
        copy.querySelectorAll('input,select,textarea').forEach(function (field) {
            field.name = field.name.replace(new RegExp('data\\['+group+'\\]\\[\\d+\\]'),'data['+group+']['+index+']');
            field.value = /\[unit\]$/.test(field.name) ? 'g' : ''; field.removeAttribute('id');
        });
        box.querySelector(group==='components'?'[data-component-rows]':'[data-container-rows]').appendChild(copy);
        if (group==='containers') box.querySelectorAll('[data-container-number]').forEach(function(el,i){el.textContent='Recipiente '+(i+1);});
        else filterSources(copy,false);
        dirty = true; controls(); calculate();
    }
    function download(blob, filename) {
        var url = URL.createObjectURL(blob), a = document.createElement('a'); blobUrls.push(url); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove();
    }
    async function load(value, options) {
        options = options || {}; var url = route(value); if (!url || (pending && writing)) return;
        if (controller) controller.abort(); controller = new AbortController();
        var requestController = controller, form = options.form, oldUrl = currentUrl;
        var capturedBody = form ? new FormData(form) : null;
        pending = true; writing = !!form; controls();
        if (form) form.setAttribute('aria-busy','true');
        if (!currentUrl && !form) body.innerHTML = '<div class="ink-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> A carregar…</div>';
        try {
            var response = await fetch(url.href,{method:form?'POST':'GET',body:capturedBody,credentials:'same-origin',signal:requestController.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});
            if (response.url.split('?')[0].endsWith('/login.php')) throw new Error('A sessão terminou. Atualize o Shopfloor e volte a iniciar sessão.');
            var contentType = response.headers.get('Content-Type') || '', disposition = response.headers.get('Content-Disposition') || '';
            if (response.ok && (contentType.indexOf('application/pdf')>=0 || /attachment/i.test(disposition))) {
                var blob = await response.blob(), match = disposition.match(/filename="?([^";]+)/i);
                if (/attachment/i.test(disposition) || url.pathname.endsWith('/ink_formula_attachment.php')) { download(blob,match?match[1]:'referencia'); return; }
                releaseBlobs(); var blobUrl = URL.createObjectURL(blob); blobUrls.push(blobUrl);
                if (oldUrl) history.push(oldUrl); currentUrl = url.href; dirty = false;
                body.innerHTML = '<section id="ink-module" data-ink-view="pdf"><div class="ink-content"><h2>Etiqueta de tinta preparada</h2><p class="ink-note">Formato 100 × 50 mm. Imprima à escala de 100%.</p><iframe class="ink-pdf" title="Pré-visualização da etiqueta"></iframe><div class="ink-toolbar"><button class="btn btn-primary" type="button" data-print-pdf>Imprimir etiqueta</button><a class="btn btn-outline-primary" data-pdf-download>Descarregar PDF</a></div></div></section>';
                body.querySelector('iframe').src=blobUrl; var link=body.querySelector('[data-pdf-download]'); link.href=blobUrl; link.download=match?match[1]:'etiqueta.pdf';
                body.querySelector('[data-print-pdf]').onclick=function(){try{var frame=body.querySelector('iframe');frame.contentWindow.focus();frame.contentWindow.print();}catch(error){message('Utilize o botão de impressão da pré-visualização ou descarregue o PDF para imprimir.',false);}};
                body.scrollTop=0; return;
            }
            var html = await response.text(), parsed = new DOMParser().parseFromString(html,'text/html'), root = parsed.getElementById('ink-module');
            if (!root) throw new Error(response.status===403?'Sem permissão para esta operação.':(response.ok?'Não foi possível carregar esta vista.':'Não foi possível concluir o pedido. Os dados introduzidos foram mantidos.'));
            root.querySelectorAll('script').forEach(function (script) { script.remove(); });
            releaseBlobs();
            if (!options.replace && oldUrl && response.ok && oldUrl !== response.url) history.push(oldUrl);
            currentUrl=response.url; body.replaceChildren(document.importNode(root,true));
            dirty = root.dataset.inkError==='1'; body.scrollTop=0; init();
        } catch (error) {
            if (error.name!=='AbortError') message(error.message || 'Falha de ligação. Tente novamente; os dados foram mantidos.',true);
        } finally {
            if (requestController===controller) { pending=false; writing=false; if(form)form.removeAttribute('aria-busy');controls(); }
        }
    }
    document.addEventListener('click',function(event){
        var trigger=event.target.closest('[data-ink-open]'); if(!trigger)return;
        event.preventDefault(); if(pending&&writing)return;
        if(modal.classList.contains('show')&&!leave())return;
        lastTrigger=trigger;instance.show();load(trigger.getAttribute('href')||'colorimetry.php');
    });
    body.addEventListener('click',function(event){
        var add=event.target.closest('[data-add-component],[data-add-container]');if(add){event.preventDefault();clone(add,add.hasAttribute('data-add-component')?'components':'containers',add.hasAttribute('data-add-component')?'[data-component-row]':'[data-container-row]');return;}
        var remove=event.target.closest('[data-remove-row],[data-remove-container]');if(remove){
            var row=remove.closest('[data-component-row],[data-container-row]'), siblings=row.parentNode.children;
            if(siblings.length===1){message('Mantenha pelo menos uma linha. Pode alterar os valores existentes.',false);return;}
            row.remove();dirty=true;controls();calculate();return;
        }
        var link=event.target.closest('a');if(!link||link.hasAttribute('data-pdf-download'))return;
        var url=route(link.getAttribute('href'));if(!url)return;
        event.preventDefault();if(!leave())return;load(url.href);
    });
    body.addEventListener('submit',function(event){
        var form=event.target;if(!(form instanceof HTMLFormElement))return;event.preventDefault();if(pending)return;
        if(form.method.toLowerCase()==='get'){
            if(!leave())return;
            var url=route(form.getAttribute('action')||currentUrl);url.search='';new FormData(form).forEach(function(value,key){url.searchParams.append(key,value);});url.searchParams.set('modal','1');
            var order=currentUrl&&new URL(currentUrl).searchParams.get('production_order_id');if(order)url.searchParams.set('production_order_id',order);load(url.href);
        }else load(form.getAttribute('action')||currentUrl,{form:form});
    });
    body.addEventListener('input',function(event){if(event.target.closest('form[method="post"]')){dirty=true;controls();}calculate();});
    body.addEventListener('change',function(event){if(event.target.closest('form[method="post"]')){dirty=true;controls();}if(/\[material_id\]$/.test(event.target.name||''))filterSources(event.target.closest('[data-component-row]'),true);calculate();});
    back.addEventListener('click',function(){if(!pending&&history.length&&leave())load(history.pop(),{replace:true});});
    modal.addEventListener('hide.bs.modal',function(event){if((pending&&writing)||!leave()){event.preventDefault();return;}if(controller)controller.abort();});
    modal.addEventListener('hidden.bs.modal',function(){releaseBlobs();currentUrl='';history=[];dirty=false;pending=false;writing=false;controls();body.innerHTML='';if(lastTrigger&&lastTrigger.isConnected)lastTrigger.focus({preventScroll:true});});
    var initial=new URL(window.location.href).searchParams.get('ink_view');
    if(initial!==null){
        var initialUrl=new URL('colorimetry.php',appBase);initialUrl.search=initial;instance.show();load(initialUrl.href);
        var clean=new URL(window.location.href);clean.searchParams.delete('ink_view');window.history.replaceState(window.history.state,'',clean.href);
    }
}());
