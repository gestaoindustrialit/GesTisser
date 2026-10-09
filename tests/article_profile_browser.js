const {chromium}=require('playwright');
const fs=require('fs');
const assert=require('assert');
const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
(async()=>{
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const context=await browser.newContext();
    await context.addCookies([{name:'gestisser_test_session',value:fixture.cookie,url:fixture.url}]);
    if(process.env.BOOTSTRAP_ASSETS)await context.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/**',route=>route.fulfill({contentType:route.request().url().endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(process.env.BOOTSTRAP_ASSETS+'/'+(route.request().url().endsWith('.js')?'bootstrap.bundle.min.js':'bootstrap.min.css'))}));
    const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const profile=fixture.url+'/erp.php?page=article_profile&id='+fixture.article;
    for(const width of [1440,1024,768,390]){
      await page.setViewportSize({width,height:900});
      for(const tab of ['overview','technical','routing','history','costs','trace','documents']){
        const response=await page.goto(profile+'&tab='+tab);assert(response.status()===200);
        await page.locator('.customer-profile').waitFor();
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Page overflow at '+width+' / '+tab);
        assert(await page.locator('#gtSidebar a.is-active[href="erp.php?page=articles"]').count()===1,'Native articles sidebar selected');
        assert(await page.locator('.cp-metric').count()===6,'Six metric cards');
      }
      console.log('PASS (browser): seven tabs and responsive layout at '+width+'px');
    }
    await page.setViewportSize({width:1440,height:1000});
    await page.goto(fixture.url+'/erp.php?page=articles');
    const row=page.locator('[data-article-url="erp.php?page=article_profile&id='+fixture.article+'"]');
    const code=row.locator('.article-profile-link').first();
    assert(await code.evaluate(el=>getComputedStyle(el).color===getComputedStyle(el.parentElement).color),'Article code inherits row text color');
    assert(await code.evaluate(el=>getComputedStyle(el).textDecorationLine==='none'),'Article code has no underline');
    await row.locator('td').nth(2).click();
    assert(page.url().includes('page=article_profile'),'List opens article profile');
    for(const key of ['Enter','Space']){
      await page.goto(fixture.url+'/erp.php?page=articles');
      await row.focus();await page.keyboard.press(key);
      await page.waitForURL('**page=article_profile&id='+fixture.article);
    }
    await page.goto(profile);

    await page.locator('.customer-profile header a[href="erp.php?page=customer_profile&id='+fixture.customer+'"]').click();
    await page.locator('.cp-tabs').getByRole('link',{name:'Artigos',exact:true}).click();
    await page.locator('.customer-profile table a[href="erp.php?page=article_profile&id='+fixture.article+'"]').first().click();
    assert(page.url().includes('page=article_profile'),'Customer article returns to article profile');
    await page.getByRole('link',{name:'Editar artigo',exact:true}).click();
    assert(page.url().includes('page=article_profile')&&page.url().includes('edit=1'),'Editing stays within profile');
    assert(await page.locator('#article-editor [name="article_id"]').inputValue()===String(fixture.article),'Shared article editor ID retained');
    await page.locator('#article-editor [name="width"]').fill('50');
    assert(await page.locator('[data-theoretical-weight]').inputValue()==='50','Existing theoretical weight updates after dimension edits');
    await page.goto(profile+'&tab=history');
    await page.getByRole('link',{name:'Seguinte',exact:true}).click();
    assert(page.url().includes('p=2'),'History pagination navigates');
    await page.goto(profile+'&tab=routing');
    assert(page.url().includes('page=article_profile'),'Routing editor stays inside article profile');
    assert(await page.getByText('Histórico de versões',{exact:true}).count()===1,'Version management present inside article profile');
    await page.getByRole('button',{name:'Duplicar / nova versão',exact:true}).click();
    assert(page.url().includes('page=article_profile')&&page.url().includes('tab=routing'),'New routing version created within profile');
    await page.getByText('Nova versão em rascunho criada.',{exact:true}).waitFor();
    await page.goto(profile);
    await page.getByRole('button',{name:'Duplicar artigo',exact:true}).click();
    await page.waitForURL('**duplicated=1');
    assert(page.url().includes('page=article_profile')&&!page.url().includes('id='+fixture.article+'&'),'Duplication opens the new article profile');
    await page.goto(fixture.url+'/erp.php?page=article_profile&id='+fixture.empty+'&tab=documents');
    assert(await page.getByText('Sem documentos associados a este artigo.',{exact:true}).count()===1,'Empty documents message');
    assert(errors.length===0,'No JavaScript errors: '+errors.join('; '));
    console.log('PASS (browser): list/client/article/editor navigation, routing link, pagination and empty states');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
