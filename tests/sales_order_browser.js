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
const articleSelect=page.locator('[data-sales-lines] select[name$="[finished_product_id]"]').first();
assert(await articleSelect.locator('option[value]:not([value=""])').evaluateAll((options,customer)=>options.every(o=>o.dataset.customer===String(customer)),fixture.customer),'Only selected customer articles remain in the actual select');
await articleSelect.locator('..').locator('button[role=combobox]').click();
await page.getByRole('searchbox',{name:'Pesquisar pela descrição ou código do artigo…',exact:true}).fill('Artigo de teste');
await page.locator('.gt-search-select-menu.is-open [role=option]').filter({hasText:'Artigo de teste'}).click();
assert(await articleSelect.inputValue()===String(fixture.article),'Description search selects article');
assert(await page.locator('[name="lines[0][description]"]').inputValue()==='Artigo de teste','Article selection automatically fills description');
await page.getByRole('button',{name:'Adicionar artigo',exact:true}).click();assert(await page.locator('[data-sales-line]').count()===2,'Add line');assert(await page.locator('[data-add-sales-line]:visible').count()===1,'Only one plus button visible');assert(await page.locator('[data-sales-line]').last().locator('[data-add-sales-line]').isVisible(),'Plus moves to last row');
await page.locator('[data-remove-sales-line]').last().click();assert(await page.locator('[data-sales-line]').count()===1,'Remove unsaved line');assert(await page.locator('[data-sales-line]').last().locator('[data-add-sales-line]').isVisible(),'Plus returns to last remaining row');
const customerSelect=page.locator('[name=customer_id]');
const otherCustomer=await customerSelect.locator('option').evaluateAll((options,id)=>options.find(o=>o.value&&o.value!==String(id)).value,fixture.customer);
await customerSelect.selectOption(otherCustomer);
assert(await articleSelect.inputValue()===''&&await page.locator('[name="lines[0][description]"]').inputValue()==='','Changing customer clears foreign article and description');
assert(await articleSelect.locator('option[value]:not([value=""])').evaluateAll((options,id)=>options.every(o=>o.dataset.customer===id),otherCustomer),'Customer change rebuilds search options without foreign or generic articles');
await page.goto(fixture.url+'/erp.php?page=production');
await page.locator('[data-bs-target="#new-work-order-form"]').click();
const orderSource=page.locator('[data-of-sales-order]'),orderLine=page.locator('[data-of-sales-line]'),ofArticle=page.locator('[data-of-article]');
assert(await orderSource.inputValue()===''&&await ofArticle.isDisabled(),'OF requires explicit order or no-order selection');
await orderSource.selectOption(String(fixture.order));
assert(await orderLine.locator('option').evaluateAll((options,id)=>options.every(o=>!o.value||o.dataset.orderId===String(id)),fixture.order),'Only selected order lines offered');
const sourceLine=await orderLine.locator('option').evaluateAll(options=>options.find(o=>o.value).value);
await orderLine.selectOption(sourceLine);
assert(await ofArticle.inputValue()===String(fixture.article),'Order line fixes OF article');
assert(Number(await page.locator('#new-work-order-form [name=planned_quantity]').inputValue())===100,'OF defaults to saved unplanned quantity');
assert(await ofArticle.locator('option').count()===2,'Linked OF cannot select unrelated article');
await orderSource.selectOption('none');
assert(await orderLine.isDisabled()&&!await ofArticle.isDisabled(),'Sem Encomenda enables direct article selection');
assert(await page.locator('#new-work-order-form [name=customer_order]').inputValue()==='Sem Encomenda','No-order reference explicit');
assert(await ofArticle.locator('option').count()>2,'No-order permits active catalogue articles');
console.log('PASS (browser): saved-order OF selection, pending quantity, article restriction and explicit Sem Encomenda');
await page.goto(fixture.url+'/erp.php?page=customer_profile&id='+fixture.customer+'&tab=orders');
await page.locator('.customer-profile a[href="erp.php?page=sales_orders&id='+fixture.order+'"]').click();assert(page.url().includes('page=sales_orders&id='+fixture.order),'Customer-to-order navigation');
await page.goto(profile+'&tab=articles');await page.locator('section a[href*="page=article_profile&id="]').click();assert(page.url().includes('page=article_profile&id='+fixture.article),'Order-to-article navigation');
await page.locator('.cp-tabs').getByRole('link',{name:'Encomendas / OF',exact:true}).click();
await page.locator('section a[href="erp.php?page=sales_orders&id='+fixture.order+'"]').waitFor();
assert(await page.locator('section a[href="erp.php?page=sales_orders&id='+fixture.order+'"]').count()===1,'Article-to-order integration');
assert(errors.length===0,errors.join('\n'));console.log('PASS (browser): line editor, customer and article navigation, no JavaScript errors');
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
