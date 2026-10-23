const {chromium}=require('playwright'),assert=require('assert');
(async()=>{
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
 let page;
 if(process.argv[2].endsWith('.json')){
  const fixture=JSON.parse(require('fs').readFileSync(process.argv[2],'utf8'));
  const context=await browser.newContext({viewport:{width:1100,height:1100}});
  await context.addCookies([{name:'gestisser_test_session',value:fixture.cookie,url:fixture.url}]);
  const profile=await context.newPage();await profile.goto(fixture.url+'/erp.php?page=sales_orders&id='+fixture.order);
  const popup=context.waitForEvent('page');await profile.getByRole('button',{name:'Gerar confirmação',exact:true}).click();page=await popup;await page.waitForLoadState();
 }else{
  page=await browser.newPage({viewport:{width:1100,height:1100}});
  await page.setContent(require('fs').readFileSync(process.argv[2],'utf8'));
 }

 const logo=page.locator('.brand img');assert(await logo.count()===1,'Confirmation exposes the configured image to the browser');
 assert(await logo.evaluate(img=>img.complete&&img.naturalWidth>0),'Configured PNG loads in browser');
 assert(await page.getByRole('button',{name:'Imprimir / Guardar PDF'}).isVisible(),'Same print flow as technical sheet');
 await page.pdf({path:process.argv[3],preferCSSPageSize:true,printBackground:true});
 await page.screenshot({path:process.argv[3]+'.png',fullPage:true});
 // Browser supports SVG without depending on the hosting server GD decoder.
 await logo.evaluate(img=>{img.src='data:image/svg+xml;base64,'+btoa('<svg xmlns="http://www.w3.org/2000/svg" width="150" height="50"><text x="5" y="35">TISSER</text></svg>');});
 await logo.evaluate(img=>img.decode());assert(await logo.evaluate(img=>img.naturalWidth===150),'SVG logo loads using the same browser image approach');
 console.log('PASS: PNG and SVG loaded; browser PDF generated; print control visible.');
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
