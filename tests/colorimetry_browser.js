const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert/strict');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
function check(value, label) { assert.ok(value, label); console.log('PASS (tablet): ' + label); }
(async function () {
    const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox','--disable-dev-shm-usage'] });
    try {
        const context = await browser.newContext({ viewport: {width:800,height:1280}, isMobile:true, hasTouch:true,
            userAgent:'Mozilla/5.0 (Linux; Android 13; Tablet) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36' });
        await context.addCookies(fixture.cookies);
        await context.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/**', route => route.fulfill({
            contentType: route.request().url().endsWith('.js') ? 'application/javascript' : 'text/css',
            body: fs.readFileSync('/workspace/gestisser-env/'+(route.request().url().endsWith('.js')?'bootstrap.bundle.min.js':'bootstrap.min.css'))
        }));
        const page = await context.newPage(), errors = [];
        page.on('pageerror', e => errors.push(e.message));
        const form = action => page.locator('form').filter({has:page.locator('input[name="action"][value="'+action+'"]')});
        const fill = (f,name,value) => f.locator('[name="data['+name+']"]').fill(String(value));
        const submit = async f => { await Promise.all([page.waitForNavigation(), f.locator('button[type="submit"],button:not([type])').last().click()]); };
        await page.goto(fixture.url+'colorimetry.php?tab=prepare&formula_id='+fixture.formula_id);
        let prepare=form('prepare');
        await fill(prepare,'quantity',5000);
        check((await page.locator('[data-calculate-mg]').first().innerText()).replace(/\s/g,'').includes('3250,000'),'Cálculo automático ao toque');
        check(await prepare.locator('[name="data[components][0][quantity]"]').inputValue()==='','Pesagens reais não são preenchidas automaticamente');
        for (const [i, quantity] of [3300,1200,500].entries()) {
            await prepare.locator('[name="data[components]['+i+'][quantity]"]').fill(String(quantity));
            await prepare.locator('[name="data[components]['+i+'][source_balance_id]"]').selectOption(String(fixture.sources[i]));
        }
        await prepare.locator('[data-add-container]').click();
        for (let i=0; i<2; i++) {
            await prepare.locator('[name="data[containers]['+i+'][quantity]"]').fill('2500');
            await prepare.locator('[name="data[containers]['+i+'][lot]"]').fill('TABLET-L1');
            await prepare.locator('[name="data[containers]['+i+'][warehouse_id]"]').selectOption(String(fixture.warehouse_id));
        }
        await submit(prepare);
        if(!page.url().includes('container_id=')){console.log('RESPONSE:',await page.locator('#ink-module').innerText());}
        check(page.url().includes('container_id='),'Preparação com dois recipientes confirmada no navegador');
        check((await page.locator('#ink-module').innerText()).replace(/\s/g,'').includes('2500,000gdisponíveis'),'Saldo físico individual apresentado');
        const containerUrl=page.url();
        await submit(form('result'));
        check((await page.locator('#ink-module').innerText()).includes('Disponível'),'Avaliação disponibiliza o recipiente');
        await fill(form('consume'),'quantity',1000);await fill(form('consume'),'notes','Teste de consumo tablet');await submit(form('consume'));
        check((await page.locator('#ink-module').innerText()).replace(/\s/g,'').includes('1500,000gdisponíveis'),'Consumo parcial por toque');
        let correction=form('correct');
        await correction.locator('[name="data[components][0][material_id]"]').selectOption(await page.locator('[name="data[components][0][material_id]"] option').filter({hasText:'AZUL'}).first().getAttribute('value'));
        await correction.locator('[name="data[components][0][quantity]"]').fill('100');
        await correction.locator('[name="data[components][0][source_balance_id]"]').selectOption(String(fixture.sources[0]));
        await fill(correction,'notes','Afinação no restante');await submit(correction);
        check((await page.locator('#ink-module').innerText()).replace(/\s/g,'').includes('1600,000gdisponíveis'),'Correção parcial preserva o consumido');
        for (const tab of ['search','stock','history']) {
            await page.goto(fixture.url+'colorimetry.php?tab='+tab);
            check(!((await page.locator('body').innerText()).includes('Erro interno')),'Navegação '+tab+' sem erro PHP');
            check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Layout '+tab+' cabe no tablet');
        }
        await page.goto(containerUrl);
        await page.getByRole('link',{name:'Imprimir / reimprimir etiqueta'}).click();
        check((await page.locator('main').last().innerText()).includes('100 × 50 mm'),'Etiqueta acessível a partir do recipiente');
        const labelForm=page.locator('form').filter({has:page.locator('input[name="request_key"]')});
        const labelData=await labelForm.evaluate(f=>Object.fromEntries(new FormData(f)));
        const firstPdf=await context.request.post(page.url(),{form:labelData});
        check(firstPdf.status()===200&&(await firstPdf.body()).subarray(0,5).toString()==='%PDF-','Etiqueta PDF produzida por endpoint autenticado');
        const secondPdf=await context.request.post(page.url(),{form:labelData});
        check(secondPdf.status()===200&&(await secondPdf.body()).subarray(0,5).toString()==='%PDF-','Repetição da impressão com a mesma chave');
        await page.goto(fixture.url+'colorimetry.php?tab=stock');
        await page.setViewportSize({width:390,height:844});
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Stock também cabe num ecrã de 390 px');
        await page.screenshot({path:'work/colorimetry-mobile.png',fullPage:true});
        check(errors.length===0,'Sem erros JavaScript');
    } finally { await browser.close(); }
}()).catch(e=>{console.error(e);process.exitCode=1;});
