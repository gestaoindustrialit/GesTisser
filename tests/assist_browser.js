const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
(async function () {
    const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
    try {
        for (const mobile of [false,true]) {
            const context = await browser.newContext({viewport:mobile?{width:800,height:1280}:{width:1440,height:1000},isMobile:mobile,hasTouch:mobile,userAgent:mobile?'Mozilla/5.0 (Linux; Android 13; Tablet) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36':undefined});
            await context.addCookies(fixture.cookies);
            const page = await context.newPage(), errors=[];
            page.on('pageerror',e=>errors.push(e.message));
            // Bootstrap does not affect the panel, which has no JS framework dependency.
            await page.route('https://cdn.jsdelivr.net/**', route=>{
                const name=route.request().url().endsWith('.js')?'bootstrap.bundle.min.js':'bootstrap.min.css';
                const local='/workspace/gestisser-env/'+name;
                return fs.existsSync(local)?route.fulfill({contentType:name.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(local)}):route.abort();
            });
            await page.goto(fixture.url);const url=page.url();
            await page.locator('#assist-open').click();await page.locator('#assist-panel').waitFor({state:'visible'});
            await page.waitForFunction(()=>document.querySelector('#assist-messages').children.length>0);
            await page.locator('#assist-new').click();
            await page.locator('#assist-question').fill('viscosidade');await page.locator('#assist-form [type=submit]').click();
            await page.waitForFunction(()=>document.querySelector('#assist-messages').innerText.includes('Consultar a ficha interna.'));
            assert.equal(page.url(),url,'chat preserves operation URL');
            await page.locator('#assist-messages .assist-source').last().click();
            await page.locator('#assist-document').waitFor({state:'visible'});
            assert((await page.locator('#assist-document-content').innerText()).includes('v1'),'approved source version visible');
            assert(!(await page.evaluate(()=>window.assistPwned)), 'source scripts remain inert text');
            assert.equal(await page.locator('#assist-document-content script').count(),0,'no injected DOM script');
            await page.locator('#assist-document-close').click();
            await page.locator('#assist-messages button').filter({hasText:'Útil'}).last().click();
            await page.locator('#assist-report-open').click();await page.locator('#assist-report textarea').fill('Problema reportado no tablet');
            await page.locator('#assist-report [name=equipment]').fill('Equipamento teste');
            await page.locator('#assist-report [type=submit]').click();await page.waitForFunction(()=>document.querySelector('#assist-status').textContent.includes('Problema reportado'));
            const sizes=await page.locator('#assist-panel').evaluate(el=>({width:el.getBoundingClientRect().width,right:el.getBoundingClientRect().right,overflow:document.documentElement.scrollWidth>window.innerWidth}));
            assert(sizes.right<= (mobile?800:1440)+1,'panel fits viewport');
            const minimum=await page.locator('#assist-close').evaluate(el=>el.getBoundingClientRect().height);assert(minimum>=44,'touch targets');
            await page.locator('#assist-close').click();assert(await page.locator('#assist-panel').isHidden());
            await page.locator('#assist-open').click();await page.locator('#assist-question').fill('Quanto solvente adicionar?');await page.locator('#assist-form [type=submit]').click();
            await page.waitForFunction(()=>document.querySelector('#assist-messages').innerText.includes('Não encontrei um procedimento técnico validado'));
            assert(!errors.length,JSON.stringify(errors));
            await page.screenshot({path:fixture.source+'/work/assist-http/'+(mobile?'tablet':'desktop')+'.png',fullPage:false});
            console.log('OK browser '+(mobile?'Android tablet':'desktop')+': chat, source, feedback, report, abstention, viewport, touch, no navigation, no JS errors');
            await context.close();
        }
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
