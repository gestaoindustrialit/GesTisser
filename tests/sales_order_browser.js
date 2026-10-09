const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert'),path=require('path');
const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
(async()=>{
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
const context=await browser.newContext();await context.addCookies([{name:'gestisser_test_session',value:fixture.cookie,url:fixture.url}]);
await context.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/**',route=>route.fulfill({contentType:route.request().url().endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(process.env.BOOTSTRAP_ASSETS+'/'+(route.request().url().endsWith('.js')?'bootstrap.bundle.min.js':'bootstrap.min.css'))}));
const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
const profile=fixture.url+'/erp.php?page=sales_orders&id='+fixture.order;
for(const width of [1440,1024,768,390]){
await page.setViewportSize({width,height:1000});
for(const tab of ['overview','articles','ofs','delivery','costs','documents']){
const response=await page.goto(profile+'&tab='+tab);assert(response.status()===200);
await page.locator('.customer-profile').waitFor();
assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Overflow '+width+'/'+tab);
assert(await page.locator('#gtSidebar').count()===1,'Native sidebar retained');
}
await page.goto(profile);await page.screenshot({path:path.join(fixture.base,'orders-'+width+'.png'),fullPage:true});
console.log('PASS (browser): all order tabs, native layout and no page overflow at '+width+'px');
}
await page.goto(fixture.url+'/erp.php?page=sales_orders&new=1&customer='+fixture.customer);
assert(await page.locator('[name=customer_id]').inputValue()===String(fixture.customer),'Customer preselection');
await page.getByRole('button',{name:'Adicionar artigo',exact:true}).click();assert(await page.locator('[data-sales-line]').count()===2,'Add line');
await page.locator('[data-remove-sales-line]').last().click();assert(await page.locator('[data-sales-line]').count()===1,'Remove unsaved line');
await page.goto(fixture.url+'/erp.php?page=customer_profile&id='+fixture.customer+'&tab=orders');
await page.locator('.customer-profile a[href="erp.php?page=sales_orders&id='+fixture.order+'"]').click();assert(page.url().includes('page=sales_orders&id='+fixture.order),'Customer-to-order navigation');
await page.goto(profile+'&tab=articles');await page.locator('section a[href*="page=article_profile&id="]').click();assert(page.url().includes('page=article_profile&id='+fixture.article),'Order-to-article navigation');
await page.locator('.cp-tabs').getByRole('link',{name:'Encomendas / OF',exact:true}).click();
await page.locator('section a[href="erp.php?page=sales_orders&id='+fixture.order+'"]').waitFor();
assert(await page.locator('section a[href="erp.php?page=sales_orders&id='+fixture.order+'"]').count()===1,'Article-to-order integration');
assert(errors.length===0,errors.join('\n'));console.log('PASS (browser): line editor, customer and article navigation, no JavaScript errors');
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
