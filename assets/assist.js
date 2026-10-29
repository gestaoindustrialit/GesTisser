(function () {
    'use strict';
    var panel = document.getElementById('assist-panel');
    if (!panel) return;
    var open = document.getElementById('assist-open'), messages = document.getElementById('assist-messages'), form = document.getElementById('assist-form'), question = document.getElementById('assist-question'), status = document.getElementById('assist-status');
    var conversation = 0, lastMessage = 0, loaded = false, busy = false;
    var language = panel.dataset.language || 'pt';
    // A failed/missing PNG must never leave the touch launcher invisible.
    var mascot = open.querySelector('img');
    function updateMascot() { open.classList.toggle('has-mascot', Boolean(mascot && mascot.complete && mascot.naturalWidth > 0)); }
    if (mascot) { mascot.addEventListener('load', updateMascot); mascot.addEventListener('error', updateMascot); }
    updateMascot();
    var strings = {
        pt: {search:'Pesquisar', report:'Reportar problema', useful:'Útil', unhelpful:'Não útil', safety:'Segurança', conditions:'Condições', verification:'Verificação'},
        en: {search:'Search', report:'Report a problem', useful:'Useful', unhelpful:'Not useful', safety:'Safety', conditions:'Conditions', verification:'Verification'},
        hi: {search:'खोजें', report:'समस्या की रिपोर्ट करें', useful:'उपयोगी', unhelpful:'उपयोगी नहीं', safety:'सुरक्षा', conditions:'शर्तें', verification:'सत्यापन'}
    };
    function words() { return strings[language]; }
    function request(data) {
        var options = {credentials:'same-origin', cache:'no-store'};
        if (data) { data.append('_token', panel.dataset.token); options.method = 'POST'; options.body = data; }
        return fetch('assist_api.php?language='+encodeURIComponent(language)+'&context[equipment]='+encodeURIComponent(panel.dataset.equipment || ''), options).then(function (r) { return r.json().then(function (json) { if (!r.ok) throw new Error(json.error || 'Pedido indisponível.'); return json; }); });
    }
    function action(name, values) { var data = new FormData(); data.append('action', name); Object.keys(values).forEach(function (key) { data.append(key, values[key]); }); return request(data); }
    function element(tag, text, className) { var node = document.createElement(tag); node.textContent = text; if(className) node.className=className; return node; }
    function documentView(source) {
        status.textContent = '';
        fetch('assist_article.php?embedded=1&id=' + source.id + '&version=' + source.version, {credentials:'same-origin', cache:'no-store'}).then(function (r) { if(!r.ok) throw new Error('Fonte indisponível.'); return r.text(); }).then(function (html) {
            // Endpoint renders escaped plain text with static markup. Never insert document HTML.
            var parser = new DOMParser(), doc = parser.parseFromString(html, 'text/html'), target = document.getElementById('assist-document-content');
            target.textContent = '';var article=doc.querySelector('article');if(!article)throw new Error('Fonte indisponível.');
            target.appendChild(document.importNode(article,true));document.getElementById('assist-document').hidden=false;messages.hidden=true;
        }).catch(fail);
    }
    function render(row) {
        lastMessage = row.id; conversation = row.conversation_id;
        messages.appendChild(element('div',row.question,'assist-message assist-message-question'));
        var answer=row.answer, block=element('div','','assist-message');
        if (answer.conditions) { block.appendChild(element('strong',words().conditions));block.appendChild(element('p',answer.conditions)); }
        if (answer.safety) block.appendChild(element('p',words().safety + ': ' + answer.safety,'alert alert-warning'));
        block.appendChild(element('p',answer.text));
        if(answer.verification){block.appendChild(element('strong',words().verification));block.appendChild(element('p',answer.verification));}
        (answer.sources || []).forEach(function (source) {
            var button=element('button','#'+source.id+' · v'+source.version+' · '+source.title+(source.material?' · '+source.material:'')+(source.equipment?' · '+source.equipment:''),'btn btn-outline-primary assist-source');button.type='button';
            button.addEventListener('click',function(){documentView(source);});block.appendChild(button);
        });
        [1,0].forEach(function(value){var button=element('button',value?words().useful:words().unhelpful,'btn btn-sm btn-light');button.type='button';button.addEventListener('click',function(){action('feedback',{message_id:row.id,useful:value}).then(function(){status.textContent='Feedback registado.';}).catch(fail);});block.appendChild(button);});
        messages.appendChild(block); messages.scrollTop=messages.scrollHeight;
    }
    function suggestions(rows) { var target=document.getElementById('assist-suggestions');target.textContent='';(rows||[]).forEach(function(row){var button=element('button',row.title,'btn btn-sm btn-outline-secondary');button.type='button';button.addEventListener('click',function(){question.value=row.title;question.focus();});target.appendChild(button);}); }
    function fail(error) { status.textContent = error.message || 'Não foi possível concluir o pedido.'; }
    function close() { panel.hidden=true;open.setAttribute('aria-expanded','false');open.focus(); }
    open.addEventListener('click',function(){panel.hidden=false;open.setAttribute('aria-expanded','true');question.focus();if(!loaded){request().then(function(data){messages.textContent='';data.messages.forEach(render);suggestions(data.suggestions);loaded=true;}).catch(fail);}});
    document.getElementById('assist-close').addEventListener('click',close);
    panel.addEventListener('keydown',function(e){if(e.key==='Escape'){e.stopPropagation();close();}});
    document.getElementById('assist-new').addEventListener('click',function(){if(busy)return;conversation=0;lastMessage=0;messages.textContent='';question.value='';status.textContent='';document.getElementById('assist-document').hidden=true;document.getElementById('assist-report').hidden=true;messages.hidden=false;question.focus();});
    document.getElementById('assist-document-close').addEventListener('click',function(){document.getElementById('assist-document').hidden=true;messages.hidden=false;});
    form.addEventListener('submit',function(e){e.preventDefault();if(busy)return;busy=true;status.textContent='';var button=form.querySelector('[type=submit]');button.disabled=true;
        action('ask',{question:question.value,language:language,conversation_id:conversation,'context[equipment]':panel.dataset.equipment || '', 'context[order]':panel.dataset.order || ''}).then(function(row){render(row);question.value='';}).catch(fail).then(function(){busy=false;button.disabled=false;question.focus();});
    });
    var report=document.getElementById('assist-report');
    document.getElementById('assist-report-open').addEventListener('click',function(){report.style.top=panel.querySelector('.assist-header').offsetHeight+'px';report.hidden=false;report.querySelector('textarea').focus();});
    document.getElementById('assist-report-cancel').addEventListener('click',function(){report.hidden=true;question.focus();});
    report.addEventListener('submit',function(e){e.preventDefault();var data=new FormData(report);data.append('action','report');data.append('message_id',lastMessage);var button=report.querySelector('[type=submit]');button.disabled=true;request(data).then(function(){report.reset();report.hidden=true;status.textContent='Problema reportado ao responsável.';}).catch(fail).then(function(){button.disabled=false;});});
}());
