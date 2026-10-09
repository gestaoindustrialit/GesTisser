// Optional integration browser checks, launched by customer_profile_http.py --browser.
const {chromium} = require('playwright');
const fs = require('fs');
const assert = require('assert');
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
        assert((await page.getByRole('link',{name:'Editar cliente',exact:true}).getAttribute('href')).includes('customer_id='+fixture.id));
        assert(errors.length===0,errors.join('\n'));
        console.log('PASS (browser): browser back, existing edit URL, prepared order action and no runtime errors');
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
