// Optional integration browser checks, launched by customer_profile_http.py --browser.
const {chromium} = require('playwright');
const fs = require('fs');
const assert = require('assert');
const path = require('path');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args:['--no-sandbox','--disable-dev-shm-usage']});
    try {
        const context = await browser.newContext();
        await context.addCookies([{name:'gestisser_test_session',value:fixture.cookie,url:fixture.url}]);
        const bootstrap = process.env.BOOTSTRAP_ASSETS;
        if (bootstrap) await context.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/**', route => route.fulfill({contentType:route.request().url().endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(bootstrap+'/'+(route.request().url().endsWith('.js')?'bootstrap.bundle.min.js':'bootstrap.min.css'))}));
        const page = await context.newPage();
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        const profile=fixture.url+'/erp.php?page=customer_profile&id='+fixture.id;
        for (const width of [1440,768,390]) {
            await page.setViewportSize({width,height:900});
            for (const tab of ['overview','articles','ofs','costs','trace','general']) {
                await page.goto(profile+'&tab='+tab);
                await page.locator('.customer-profile').waitFor();
                assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Page horizontal overflow at '+width+' / '+tab);
                assert(await page.locator('#gtSidebar').count()===1,'Native sidebar retained');
                assert(await page.locator('#gtSidebar a.is-active[href="erp.php?page=sales"]').count()===1,'Clientes remains selected in native sidebar');
            }
            console.log('PASS (browser): native layout, tabs and no page overflow at '+width+'px');
        }
        await page.goto(profile);
        await page.locator('.cp-tabs').getByRole('link',{name:'Dados gerais',exact:true}).click();
        assert(page.url().includes('tab=general'));
        await page.goBack();assert(!page.url().includes('tab=general'));
        assert(await page.getByRole('button',{name:'Nova encomenda',exact:true}).isDisabled());
        await page.getByRole('switch',{name:'Editar cliente',exact:true}).check();
        await page.waitForURL('**tab=general&edit=1');
        const name=page.locator('[data-customer-form] [name="name"]');
        assert(await name.isEnabled(),'Toggle opens editable fields in same profile');
        const initialName=await name.inputValue();
        await name.fill('Rascunho cancelado');
        await page.getByRole('button',{name:'Adicionar morada',exact:true}).click();
        assert(await page.locator('[data-cp-delivery-row]').count()===2);
        await page.getByRole('button',{name:'Cancelar',exact:true}).click();
        assert(await name.isDisabled() && await name.inputValue()===initialName,'Cancel resets edits without saving');
        assert(await page.locator('[data-cp-delivery-row]').count()===1,'Cancel restores original delivery rows');
        await page.getByRole('switch',{name:'Editar cliente',exact:true}).check();
        await name.fill('Nome gravado no browser');
        await page.locator('[name="delivery_transporter[]"]').fill('Transportador browser');
        await Promise.all([page.waitForURL('**tab=general&saved=1'),page.getByRole('button',{name:'Guardar alterações',exact:true}).click()]);
        assert(await name.inputValue()==='Nome gravado no browser' && await name.isDisabled(),'Save returns to read mode in same profile');
        assert(await page.locator('[name="delivery_transporter[]"]').inputValue()==='Transportador browser');
        await page.getByRole('switch',{name:'Editar cliente',exact:true}).check();
        await name.fill('Não deve guardar');
        await page.locator('[name="delivery_transporter[]"]').fill('');
        await page.locator('[data-customer-form]').evaluate(form=>form.noValidate=true);
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Guardar alterações',exact:true}).click()]);
        assert((await page.locator('[role="alert"]').innerText()).includes('transportadora'),'Invalid form remains editable with validation error');
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Cancelar',exact:true}).click()]);
        assert(await name.isDisabled() && await name.inputValue()==='Nome gravado no browser','Cancel after failed save reloads persisted customer');
        await page.screenshot({path:path.join(path.dirname(process.argv[2]),'customer-profile-mobile.png'),fullPage:true});
        assert(errors.length===0,errors.join('\n'));
        console.log('PASS (browser): browser back, inline toggle, cancel, save and delivery editing');
        await page.goto(fixture.url+'/erp.php?page=sales');
        await page.locator('.customer-list').waitFor();
        let row=page.locator('.customer-list tr[data-customer-url]:visible').first();
        const rowUrl=new URL(await row.getAttribute('data-customer-url'),fixture.url).href;
        assert(await row.locator('.customer-name-link').evaluate(a=>getComputedStyle(a).textDecorationLine==='none'&&getComputedStyle(a).color===getComputedStyle(a.parentElement).color),'Customer name uses normal text, no underline');
        await Promise.all([page.waitForURL(rowUrl),row.locator('td').nth(3).click()]);
        await page.goBack();
        row=page.locator('.customer-list tr[data-customer-url]:visible').first();
        await row.focus();
        await Promise.all([page.waitForURL(new URL(await row.getAttribute('data-customer-url'),fixture.url).href),row.press('Enter')]);
        console.log('PASS (browser): whole customer row opens by click or keyboard; neutral name styling');
        await page.setViewportSize({width:1440,height:960});
        await page.goto(profile+'&tab=general');
        await page.screenshot({path:path.join(path.dirname(process.argv[2]),'customer-profile-desktop.png'),fullPage:true});
        assert(errors.length===0,errors.join('\n'));
        if (fixture.order) {
            await page.goto(fixture.url+'/erp.php?page=customer_profile&id='+fixture.order.customer_id+'&tab=ofs');
            const dossier=page.locator('.customer-profile a[href="production_dossier.php?id='+fixture.order.id+'"]');
            assert(await dossier.count()===1,'Exact OF link');
            await dossier.click();assert(page.url().includes('production_dossier.php?id='+fixture.order.id));
            assert(await page.locator('body').innerText().then(t=>!t.includes('Documento não encontrado')&&!t.includes('Fatal error')));
            await page.goBack();
            const article=page.locator('.customer-profile a[href="erp.php?page=articles&article_id='+fixture.order.finished_product_id+'#article-editor"]').first();
            await Promise.all([page.waitForURL('**/erp.php?page=articles&article_id='+fixture.order.finished_product_id+'#article-editor'),article.click()]);
            await page.locator('#article-editor').waitFor({state:'visible'});
            assert(page.url().includes('article_id='+fixture.order.finished_product_id),'Exact article form opens');
            console.log('PASS (browser): native OF dossier and article links open exact records');
        }
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
