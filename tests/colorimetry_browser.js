const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert/strict');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
function check(value,label){assert.ok(value,label);console.log('PASS (modal): '+label);}
(async function(){
    const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
    try{
        const context=await browser.newContext({viewport:{width:1280,height:960},hasTouch:true,userAgent:'Mozilla/5.0 (Linux; Android 13; Tablet) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36'});
        await context.addCookies(fixture.cookies);
        async function assets(ctx){await ctx.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/**',route=>route.fulfill({contentType:route.request().url().endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync('/workspace/gestisser-env/'+(route.request().url().endsWith('.js')?'bootstrap.bundle.min.js':'bootstrap.min.css'))}));}
        await assets(context);
        const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',dialog=>dialog.accept());
        await page.goto(fixture.url+'shopfloor.php');await page.waitForFunction(()=>!!window.bootstrap);
        const shopfloorUrl=page.url();let navigations=0;page.on('framenavigated',frame=>{if(frame===page.mainFrame())navigations++;});
        const modal=page.locator('#colorimetryModal'), root=modal.locator('#ink-module');
        const form=action=>modal.locator('form[data-ink-form="'+action+'"]');
        const fill=(f,name,value)=>f.locator('[name="data['+name+']"]').fill(String(value));
        const waitResponse=()=>page.waitForResponse(r=>r.request().resourceType()==='fetch'&&r.url().includes('modal=1')&&!r.request().isNavigationRequest());
        async function click(locator){const response=waitResponse();await locator.click();await response;await page.waitForFunction(()=>!document.querySelector('#colorimetryModal [aria-busy="true"]'));await page.waitForTimeout(100);}
        async function submit(f){await click(f.locator('button[type="submit"]').last());}
        async function tab(name,view){await click(modal.getByRole('navigation',{name:'Tintas e cores'}).getByRole('link',{name,exact:true}));await page.waitForFunction(v=>document.querySelector('#ink-module').dataset.inkView===v,view);}
        const trigger=page.locator('.shopfloor-header-shortcuts [data-ink-open]');
        check((await trigger.innerText()).trim()==='','Atalho do Shopfloor só com ícone, sem texto a quebrar');
        await click(trigger);await modal.waitFor({state:'visible'});await root.waitFor();await page.waitForTimeout(400);
        check(await root.getAttribute('data-ink-view')==='search','Tintas abre numa modal sobre o Shopfloor');
        check(page.url()===shopfloorUrl,'Abrir a modal conserva a URL e o Shopfloor');
        await page.screenshot({path:'work/colorimetry-modal-search.png'});
        await tab('Preparar tinta','prepare');
        const selector=modal.locator('form.ink-select-form');await selector.locator('select[name="formula_id"]').selectOption(String(fixture.formula_id));await submit(selector);await form('prepare').waitFor();
        let prepare=form('prepare');await fill(prepare,'quantity',5);await prepare.locator('[name="data[unit]"]').selectOption('kg');
        check((await modal.locator('[data-calculate-mg]').first().innerText()).replace(/\s/g,'').includes('3250,000'),'Cálculo proporcional em kg dentro da modal');
        check(await prepare.locator('[name="data[components][0][quantity]"]').inputValue()==='','Pesagens reais continuam por preencher');
        for(const [i,quantity]of[3300,1200,500].entries()){
            await prepare.locator('[name="data[components]['+i+'][quantity]"]').fill(String(quantity));await prepare.locator('[name="data[components]['+i+'][source_balance_id]"]').selectOption(String(fixture.sources[i]));
        }
        check((await modal.locator('[data-weighed-total]').innerText()).replace(/\s/g,'')==='5,000kg','Total das pesagens atualizado em tempo real');
        await prepare.locator('[data-add-container]').click();
        for(let i=0;i<2;i++){
            await prepare.locator('[name="data[containers]['+i+'][quantity]"]').fill('2500');await prepare.locator('[name="data[containers]['+i+'][lot]"]').fill('MODAL-L1');await prepare.locator('[name="data[containers]['+i+'][warehouse_id]"]').selectOption(String(fixture.warehouse_id));
        }
        check((await modal.locator('[data-container-difference]').innerText()).includes('conferidas'),'Conferência da distribuição por recipientes');
        await page.screenshot({path:'work/colorimetry-modal-prepare.png'});
        await submit(prepare);await page.waitForFunction(()=>document.querySelector('#ink-module').dataset.inkView==='container');
        const containerId=await root.getAttribute('data-container-id');check(Number(containerId)>0,'Preparação confirmada sem sair da modal');
        check((await modal.locator('.ink-stock-number').innerText()).replace(/\s/g,'').includes('2,500kg'),'Saldo individual do recipiente');
        await click(modal.getByRole('link',{name:'Avaliar',exact:true}));await submit(form('result'));
        check((await modal.locator('.ink-container-hero').innerText()).includes('Disponível'),'Avaliação disponibiliza recipiente na modal');
        await click(modal.getByRole('link',{name:'Consumir',exact:true}));await fill(form('consume'),'quantity',4000);await fill(form('consume'),'notes','Quantidade excessiva para testar validação');await submit(form('consume'));
        check((await modal.getByRole('alert').innerText()).includes('Stock insuficiente'),'Erro de stock apresentado na modal');
        check(await form('consume').locator('[name="data[quantity]"]').inputValue()==='4000','Erro conserva os dados introduzidos');
        await fill(form('consume'),'quantity',1000);await submit(form('consume'));
        check((await modal.locator('.ink-stock-number').innerText()).replace(/\s/g,'').includes('1,500kg'),'Consumo parcial sem recarregar o Shopfloor');
        await click(modal.getByRole('link',{name:'Corrigir',exact:true}));const correction=form('correct');
        const material=correction.locator('[name="data[components][0][material_id]"]');await material.selectOption(await material.locator('option').filter({hasText:'AZUL'}).first().getAttribute('value'));await correction.locator('[name="data[components][0][quantity]"]').fill('100');await correction.locator('[name="data[components][0][source_balance_id]"]').selectOption(String(fixture.sources[0]));await fill(correction,'notes','Afinação dentro da modal');await submit(correction);
        check((await modal.locator('.ink-stock-number').innerText()).replace(/\s/g,'').includes('1,600kg'),'Correção aplica-se ao restante físico');
        await click(modal.getByRole('link',{name:'Imprimir etiqueta',exact:true}));await submit(modal.locator('form').filter({has:page.locator('input[name="request_key"]')}));
        await modal.locator('iframe.ink-pdf').waitFor();check(await root.getAttribute('data-ink-view')==='pdf','Etiqueta PDF visualizada dentro da modal');
        await click(modal.locator('[data-ink-back]'));check(await root.getAttribute('data-ink-view')==='print','Voltar da etiqueta mantém a modal aberta');
        await click(modal.getByRole('link',{name:'Voltar ao recipiente'}));
        await tab('Stock de tintas','stock');
        const search=modal.locator('.ink-searchbar');await search.locator('input[name="q"]').fill('MODAL-L1');await submit(search);check(await modal.locator('.ink-stock-card').count()===2,'Pesquisa de stock submetida dentro da modal');
        await tab('Histórico','history');await submit(modal.locator('form.ink-filter-form'));check(await modal.locator('.ink-event').count()>0,'Histórico e filtros carregam dentro da modal');
        await page.setViewportSize({width:800,height:1100});await page.screenshot({path:'work/colorimetry-modal-tablet.png'});
        check(await modal.evaluate(el=>el.getBoundingClientRect().right<=window.innerWidth),'Modal cabe no tablet');
        await page.setViewportSize({width:390,height:844});await tab('Stock de tintas','stock');
        check(await modal.locator('.modal-content').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Conteúdo sem overflow horizontal a 390 px');await page.screenshot({path:'work/colorimetry-modal-mobile.png'});
        await modal.getByRole('button',{name:'Fechar',exact:true}).click();await modal.waitFor({state:'hidden'});
        check(page.url()===shopfloorUrl&&navigations===0,'Todo o fluxo decorre sem navegação da página principal');
        await click(trigger);await root.waitFor();await page.waitForTimeout(400);await page.keyboard.press('Escape');await modal.waitFor({state:'hidden'});check(true,'Modal fecha por Escape e reabre normalmente');
        // A bookmarked legacy route opens the same modal automatically.
        await page.goto(fixture.url+'colorimetry.php?tab=stock');await modal.waitFor({state:'visible'});await page.waitForFunction(()=>document.querySelector('#ink-module')?.dataset.inkView==='stock');check(page.url().includes('shopfloor.php'),'Link antigo encaminha para a modal no Shopfloor');
        // Administrative editors use the same modal and compact component table.
        const adminContext=await browser.newContext({viewport:{width:1440,height:1080}});await adminContext.addCookies(fixture.admincookies);await assets(adminContext);
        const admin=await adminContext.newPage();admin.on('pageerror',e=>errors.push(e.message));await admin.goto(fixture.url+'colorimetry.php?tab=library');const adminModal=admin.locator('#colorimetryModal');await adminModal.waitFor({state:'visible'});await adminModal.getByRole('link',{name:'Nova fórmula',exact:true}).click();const editor=adminModal.locator('form[data-ink-form="formula"]');await editor.waitFor();
        check(await editor.locator('[data-component-row]').count()===3,'Editor apresenta componentes em tabela compacta');
        const row=editor.locator('[data-component-row]').first(),unit= row.locator('[name$="[unit]"]');check((await unit.boundingBox()).width<130,'Unidade fica junto da massa, sem linha a toda a largura');
        await editor.locator('[data-add-component]').click();check(await editor.locator('[data-component-row]').count()===4,'Adicionar componente funciona no editor da modal');await editor.locator('[data-remove-row]').last().click();
        await admin.screenshot({path:'work/colorimetry-modal-library.png'});
        for(const [i,code] of ['AZUL','BRANCO','PRETO'].entries()){
            const select=editor.locator('[name="data[components]['+i+'][material_id]"]');await select.selectOption(await select.locator('option').filter({hasText:code}).first().getAttribute('value'));
            await editor.locator('[name="data[components]['+i+'][quantity]"]').fill(String([650,250,100][i]));
        }
        const color=editor.locator('[name="data[color_id]"]');await color.selectOption(await color.locator('option').filter({hasText:'TEST-001'}).first().getAttribute('value'));
        await editor.locator('button[type="submit"]').click();await adminModal.locator('.ink-badge').filter({hasText:'Em desenvolvimento'}).waitFor();
        check(true,'Criar fórmula funciona sem sair da modal');
        await adminModal.getByRole('button',{name:'Aprovar fórmula',exact:true}).click();await adminModal.locator('.ink-badge').filter({hasText:'Aprovada'}).waitFor();
        check(true,'Aprovação funciona na vista de detalhe da modal');
        await adminModal.getByRole('navigation',{name:'Tintas e cores'}).getByRole('link',{name:'Biblioteca',exact:true}).click();await adminModal.getByRole('link',{name:'Nova cor',exact:true}).click();
        const newColor=adminModal.locator('form[data-ink-form="color"]');await newColor.waitFor();await newColor.locator('[name="data[code]"]').fill('UI-MODAL');await newColor.locator('[name="data[name]"]').fill('Cor criada na modal');await newColor.locator('button[type="submit"]').click();
        await adminModal.getByRole('link',{name:'Nova fórmula',exact:true}).waitFor();await adminModal.getByRole('link',{name:'Nova fórmula',exact:true}).click();
        await adminModal.locator('form[data-ink-form="formula"]').waitFor();
        check(await adminModal.locator('[name="data[color_id]"] option').filter({hasText:'UI-MODAL'}).count()===1,'Nova cor fica disponível nas fórmulas da modal');
        check(errors.length===0,'Sem erros JavaScript nos fluxos operativo e administrativo');await adminContext.close();
    }finally{await browser.close();}
}()).catch(error=>{console.error(error);process.exitCode=1;});
