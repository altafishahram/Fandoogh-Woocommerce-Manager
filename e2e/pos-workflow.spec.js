// @ts-check
const { test, expect } = require('@playwright/test');

const product = { id: 101, name: 'کالای صندوق', sku: '00123', type: 'simple', price: '200', available: true, quantity: 5 };
const snapshot = { id: 91, number: '91', channel: 'pos', date: '2026-10-02T10:00:00Z', status: 'تکمیل‌شده', paid: true, store: { name: 'فروشگاه آزمایشی', address: 'تهران، خیابان نمونه', website: 'https://example.test' }, customer: { display: 'مشتری تست', phone: '09120000000' }, billing: {}, currency: { code: 'IRT', label: 'تومان' }, lines: [{ name: '<img src=x onerror=alert(1)> کالای صندوق', sku: '00123', quantity: 2, unit_price: '180', discount: '10', total: '350' }], fees: [], totals: { subtotal: '360', discount: '10', tax: '0', shipping: '0', total: '350', refunded: '0', net: '350' }, payment: { title: 'نقدی و کارت‌خوان', tenders: { cash_received: '200', card_amount: '200', cash_applied: '150', change: '50', reference: '12345' } } };
async function boot(page, options = {}) {
  const requests = [], sales = [], orderWrites = [];
  await page.route('**/wp-json/fandoogh-manager/v1/**', async route => {
    const req = route.request(), u = new URL(req.url()), p = u.pathname;
    requests.push({ path:p, query:u.search, method:req.method() });
    if(p.endsWith('/config')&&options.analytics) {const root='/wp-json/fandoogh-manager/v1/';return route.fulfill({json:{analytics:{enabled:true},currency:{code:'IRT',label:'تومان'},api:{me:root+'auth/me',csrf:root+'auth/csrf',products:root+'products',orders:root+'orders',customers:root+'customers',analytics:root+'analytics/summary',barcode:root+'operations/barcode'}}});}
    if(p.endsWith('/analytics/summary')) {const source=u.searchParams.get('channel')||'all',pos=source!=='online',online=source!=='pos';return route.fulfill({json:{data:{range:{label:'۳۰ روز اخیر'},currency:{label:'تومان'},sales:{gross:String((pos?350:0)+(online?100:0)),net:String((pos?300:0)+(online?100:0)),refunded:pos?'50':'0'},orders:{total:source==='all'?2:1,successful:1,statuses:[]},products:{total:2,top:[]},customers:{unique:1},channels:{pos:{orders:pos?1:0,gross:pos?'350':'0',refunded:pos?'50':'0',net:pos?'300':'0'},online:{orders:online?1:0,gross:online?'100':'0',refunded:'0',net:online?'100':'0'}}}}});}
    if(p.endsWith('/auth/me')) return route.fulfill({json:{data:{user:{id:options.userId || 7,display_name:'فروشنده تست'},scopes: options.denied ? { products:{read:true},orders:{read:true} } : {products:{read:true},orders:{read:true,create:true},customers:{read:true,write:true},analytics:{read:true}}}}});
    if(p.endsWith('/auth/logout')) return route.fulfill({status:401,json:{message:'نشست منقضی شده'}});
    if(p.endsWith('/auth/csrf')) return route.fulfill({json:{data:{csrf_token:'pos-test-csrf'}}});
    if(p.endsWith('/pos/catalog')) {
      const data = u.searchParams.has('parent_id') ? [{...product,id:103,parent_id:102,type:'variation',name:'کالای آبی'}] : u.searchParams.get('id') === '103' ? [{...product,id:103,parent_id:102,type:'variation',name:'کالای آبی'}] : [product,{...product,id:102,type:'variable',name:'کالای متغیر'}];
      return route.fulfill({json:{data,meta:{page:1,total_pages:1}}});
    }
    if(p.endsWith('/operations/barcode')) return route.fulfill({json:{data:{id:103,parent_id:102,name:'کالای آبی',search:'VAR-103'}}});
    if(p.endsWith('/pos/quote')) { const b=req.postDataJSON(); return route.fulfill({json:{data:{lines:b.items.map(i=>({name:'کالای صندوق',quantity:i.quantity,unit_price:i.unit_price || '200'})),customer:b.customer,currency:{label:'تومان'},subtotal:'360',discount_total:'10',tax:'0',total:'350',quote_token:'signed-test',expires:Math.floor(Date.now()/1000)+300}}}); }
    if(p.endsWith('/pos/sales') && req.method()==='POST') { sales.push({body:req.postDataJSON(),csrf:req.headers()['x-fandoogh-csrf']}); if(options.holdSale) await options.holdSale; if(options.uncertain) return route.fulfill({status:504,json:{message:'پاسخ ثبت قطع شد'}}); if(options.changed) return route.fulfill({status:409,json:{code:'fandoogh_pos_quote_changed',message:'محاسبات تغییر کرد'}}); return route.fulfill({json:{data:snapshot}}); }
    if(/\/pos\/sales\//.test(p)) return route.fulfill({json:{data:snapshot,idempotent_replay:true}});
    if(p.endsWith('/orders/91/invoice')) return route.fulfill({json:{data:{...snapshot,channel:'online',totals:{...snapshot.totals,refunded:'50',net:'300'}}}});
    if(p.endsWith('/customers')) return route.fulfill({json:{data:[{id:77,display:'مشتری قبلی',phone:'09123456789'}],meta:{total_pages:1}}});
    if(p.endsWith('/products')&&options.manualOrder) return route.fulfill({json:{data:[{...product,status:'publish',stock_status:'instock'}],meta:{page:1,total_pages:1,total:1}}});
    if(p.endsWith('/orders')&&req.method()==='POST') {orderWrites.push(req.postDataJSON());return route.fulfill({status:409,json:{code:'fandoogh_order_rollback_failed',message:'حذف سفارش تأیید نشد؛ سفارش را بررسی کنید'}});}
    if(/\/orders\/91\/?$/.test(p)) return route.fulfill({json:{data:{id:91,number:'91',status:'completed',customer:{display:'مشتری تست'},total:'350',line_items:[],billing:{},shipping:{}}}});
    if(p.endsWith('/orders')) return route.fulfill({json:{data:[{id:91,number:'91',status:'completed',total:'350',customer:{display:'مشتری تست'},items:[]}],meta:{page:1,total_pages:1,total:1}}});
    return route.fulfill({status:404,json:{}});
  });
  await page.goto('/#pos'); await expect(page.locator('#dashboardView')).toBeVisible();
  await expect(page.locator('#posPanel')).toBeVisible();
  return {requests,sales,orderWrites};
}
async function addProduct(page) { await page.locator('#posProducts').getByRole('button',{name:/کالای صندوق/}).click(); }
async function paid(page, method = 'mixed') {
  await page.locator('#posReview').click(); await expect(page.locator('#posPaymentDialog')).toBeVisible();
  await page.locator('#posPaymentMethod').selectOption(method);
  if(method==='mixed') { await page.locator('#posCardAmount').fill('200'); await page.locator('#posCashReceived').fill('200'); }
  await page.locator('#posPaymentReceived').check(); await page.locator('#posSubmit').click();
}

test('public preview supports category and variation checkout without writing to APIs',async({page})=>{
  const writes=[];page.on('request',r=>{if(r.method()==='POST'&&r.url().includes('/wp-json/'))writes.push(r.url());});
  await page.goto('/?preview=1#pos');await expect(page.locator('#posMessage')).toContainText('حالت نمونه');await page.locator('#posCategory').selectOption('3');await expect(page.locator('#posProducts .pos-product')).toHaveCount(1);await page.locator('#posProducts').getByRole('button',{name:/بسته هدیه شب یلدا/}).click();await expect(page.locator('#posProducts')).toContainText('تنوع نمونه');await page.locator('#posProducts .pos-product').click();await paid(page,'card');await expect(page.locator('#invoicePreview')).toContainText('فاکتور نمونه');await expect(page.locator('#invoicePreview')).toContainText('۹۸۰٬۰۰۰ تومان');expect(writes).toEqual([]);
});

test('POS price override, new customer, manual discount and mixed payment produce saved invoice',async({page})=>{
  const {sales}=await boot(page); await addProduct(page);
  await page.getByRole('spinbutton',{name:'تعداد کالای صندوق'}).fill('2'); await page.getByRole('spinbutton',{name:'تعداد کالای صندوق'}).press('Tab');
  await page.getByRole('textbox',{name:'قیمت کالای صندوق',exact:true}).fill('180'); await page.getByRole('textbox',{name:'قیمت کالای صندوق',exact:true}).press('Tab');
  await page.locator('.pos-customer summary').click(); await page.locator('#posCustomerMode').selectOption('new'); await page.locator('#posCustomerFirst').fill('مشتری'); await page.locator('#posCustomerPhone').fill('09120000000'); await page.locator('#posDiscountValue').fill('10');
  await paid(page); await expect(page.locator('#invoiceDialog')).toBeVisible();
  expect(sales).toHaveLength(1); expect(sales[0].body.items).toEqual([{id:101,quantity:2,unit_price:'180'}]); expect(sales[0].body.discount).toEqual({type:'amount',value:'10'}); expect(sales[0].body.customer.mode).toBe('new'); expect(sales[0].body.payment.method).toBe('mixed'); expect(sales[0].csrf).toBe('pos-test-csrf');
  await expect(page.locator('#invoicePreview')).toContainText('فروشگاه آزمایشی'); await expect(page.locator('#invoicePreview')).toContainText('باقی‌مانده: ۵۰');
  await expect(page.locator('#invoicePreview img')).toHaveCount(0); await expect(page.locator('#posCartCount')).toHaveText('۰ کالا');
});
test('variable selection and hardware barcode add exact variation',async({page})=>{
  const {requests}=await boot(page); await page.locator('#posProducts').getByRole('button',{name:/کالای متغیر/}).click(); await expect(page.locator('#posVariationHeading')).toBeVisible(); await expect(page.locator('#posProducts')).toContainText('کالای آبی'); await page.locator('#posProducts').getByRole('button',{name:/کالای آبی/}).click();
  await page.locator('#posProductSearch').fill('00123'); await page.locator('#posProductSearch').press('Enter'); await expect(page.locator('#posCartCount')).toHaveText('۲ کالا'); expect(requests.some(r=>r.path.endsWith('/operations/barcode')&&r.query.includes('00123'))).toBeTruthy();
});
test('registered customer lookup selects without updating their profile',async({page})=>{
  const {sales}=await boot(page); await addProduct(page); await page.locator('.pos-customer summary').click(); await page.locator('#posCustomerMode').selectOption('registered'); await page.locator('#posCustomerSearch').fill('0912'); await page.locator('#posCustomerResults').getByRole('button',{name:/مشتری قبلی/}).click(); await expect(page.locator('#posSelectedCustomer')).toContainText('مشتری قبلی'); await paid(page,'card'); await expect(page.locator('#invoiceDialog')).toBeVisible(); expect(sales[0].body.customer.id).toBe(77);
});
test('uncertain response locks checkout and recovers the same claim',async({page})=>{
  const {sales,requests}=await boot(page,{uncertain:true}); await addProduct(page); await paid(page,'cash'); await expect(page.locator('#posRecovery')).toBeVisible(); await expect(page.locator('#posReview')).toBeDisabled(); await expect(page.locator('#posNewSale')).toBeDisabled(); const key=sales[0].body.idempotency_key;
  await page.locator('#posRecover').click(); await expect(page.locator('#invoiceDialog')).toBeVisible(); expect(sales).toHaveLength(1); expect(requests.some(r=>r.path.endsWith('/pos/sales/'+key)&&r.method==='GET')).toBeTruthy();
});

test('pending claim survives reload and recovers without another POST',async({page})=>{
  const {sales}=await boot(page,{uncertain:true});await addProduct(page);await paid(page,'cash');await expect(page.locator('#posRecovery')).toBeVisible();expect(await page.evaluate(()=>sessionStorage.getItem('fandoogh-pos-pending:7'))).toBe(sales[0].body.idempotency_key);await page.reload();await expect(page.locator('#invoiceDialog')).toBeVisible();expect(sales).toHaveLength(1);expect(await page.evaluate(()=>sessionStorage.getItem('fandoogh-pos-pending:7'))).toBeNull();
});

test('expired session preserves unknown sale only for its cashier',async({page})=>{
  const options={uncertain:true,userId:7};const {sales,requests}=await boot(page,options);await addProduct(page);await paid(page,'cash');await expect(page.locator('#posRecovery')).toBeVisible();
  const key=sales[0].body.idempotency_key;await page.evaluate(()=>document.getElementById('logoutButton').click());await expect(page.locator('#pairingView')).toBeVisible();
  expect(await page.evaluate(()=>sessionStorage.getItem('fandoogh-pos-pending:7'))).toBe(key);
  options.userId=8;await page.reload();await expect(page.locator('#posPanel')).toBeVisible();await expect(page.locator('#posProducts .pos-product')).toHaveCount(2);
  await expect(page.locator('#posRecovery')).not.toBeVisible();await expect(page.locator('#posCartCount')).toHaveText('۰ کالا');expect(requests.filter(r=>r.path.endsWith('/pos/sales/'+key))).toHaveLength(0);
  options.userId=7;await page.reload();await expect(page.locator('#invoiceDialog')).toBeVisible();expect(sales).toHaveLength(1);expect(requests.filter(r=>r.path.endsWith('/pos/sales/'+key)&&r.method==='GET')).toHaveLength(1);expect(await page.evaluate(()=>sessionStorage.getItem('fandoogh-pos-pending:7'))).toBeNull();
});

test('late sale success after session expiry keeps recovery until same-user lookup',async({page})=>{
  let release;const holdSale=new Promise(resolve=>{release=resolve;});const {sales}=await boot(page,{holdSale});await addProduct(page);await paid(page,'cash');await expect.poll(()=>sales.length).toBe(1);
  const key=sales[0].body.idempotency_key;await page.evaluate(()=>document.getElementById('logoutButton').click());await expect(page.locator('#pairingView')).toBeVisible();
  const response=page.waitForResponse(r=>r.url().endsWith('/pos/sales')&&r.request().method()==='POST');release();await response;
  await expect(page.locator('#invoiceDialog')).not.toBeVisible();expect(await page.evaluate(()=>sessionStorage.getItem('fandoogh-pos-pending:7'))).toBe(key);
  await page.reload();await expect(page.locator('#invoiceDialog')).toBeVisible();expect(sales).toHaveLength(1);expect(await page.evaluate(()=>sessionStorage.getItem('fandoogh-pos-pending:7'))).toBeNull();
});

test('ordinary order retry after rollback failure reuses the original claim',async({page})=>{
  const {orderWrites}=await boot(page,{manualOrder:true});await page.evaluate(()=>location.hash='#orders');await page.locator('#newOrderButton').click();await page.locator('#manualOrderNext').click();await page.locator('.manual-order-add-item').click();await page.locator('#manualOrderNext').click();await page.locator('#manualOrderNext').click();
  await page.locator('#submitManualOrder').click();await expect(page.locator('#manualOrderMessage')).toContainText('حذف سفارش تأیید نشد');await page.locator('#submitManualOrder').click();await expect.poll(()=>orderWrites.length).toBe(2);expect(orderWrites[1]).toEqual(orderWrites[0]);
});

test('voice and camera search in POS add the scanned variation',async({page})=>{
  await page.addInitScript(()=>{window.__speech=null;window.__code='';window.__stops=0;window.SpeechRecognition=class{constructor(){window.__speech=this;}start(){}abort(){}};window.BarcodeDetector=class{static getSupportedFormats(){return Promise.resolve(['code_128']);}detect(){return Promise.resolve(window.__code?[{rawValue:window.__code}]:[]);}};Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){window.__stops++;}}]})}});Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{configurable:true,get(){return this.__stream;},set(v){this.__stream=v;}});HTMLMediaElement.prototype.play=async function(){};HTMLMediaElement.prototype.pause=function(){};});
  await boot(page);const wrapper=page.locator('#posProductSearch').locator('..');await wrapper.getByRole('button',{name:/جست‌وجوی صوتی/}).click();expect(await page.evaluate(()=>window.__speech.lang)).toBe('fa-IR');await page.evaluate(()=>window.__speech.onresult({resultIndex:0,results:[[{transcript:'کالای آبی'}]]}));await expect(page.locator('#posProductSearch')).toHaveValue('کالای آبی');
  await wrapper.getByRole('button',{name:/اسکن بارکد/}).click();await page.evaluate(()=>window.__code='00123');await expect(page.locator('#barcodeDialog')).not.toBeVisible();await expect(page.locator('#posCart')).toContainText('کالای آبی');expect(await page.evaluate(()=>window.__stops)).toBeGreaterThan(0);
});

test('financial report filters source and shows refund and net totals',async({page})=>{
  const {requests}=await boot(page,{analytics:true});await page.evaluate(()=>location.hash='#analytics');await expect(page.locator('#analyticsChannel')).toBeEnabled();await page.locator('#analyticsChannel').selectOption('pos');await expect(page.locator('#analyticsNetSales')).toHaveText('۳۰۰ تومان');await expect(page.locator('#analyticsRefunded')).toHaveText('۵۰ تومان');await expect(page.locator('#analyticsChannels')).toContainText('فروش حضوری');await expect(page.locator('#analyticsChannel')).toBeEnabled();await page.locator('#analyticsChannel').selectOption('online');await expect(page.locator('#analyticsGrossSales')).toHaveText('۱۰۰ تومان');expect(requests.some(r=>r.path.endsWith('/analytics/summary')&&r.query.includes('channel=online'))).toBeTruthy();
});
test('changed quote leaves cart editable for a fresh review',async({page})=>{
  await boot(page,{changed:true}); await addProduct(page); await paid(page,'cash'); await expect(page.locator('#posPaymentMessage')).toHaveText('محاسبات تغییر کرد'); await page.locator('#posPaymentClose').click(); await expect(page.locator('#posReview')).toBeEnabled(); await expect(page.locator('#posCartCount')).toHaveText('۱ کالا');
});
test('insufficient permissions cannot open or mutate POS checkout',async({page})=>{
  const {requests}=await boot(page,{denied:true}); await expect(page.locator('#posMessage')).toContainText('مجوز'); await expect(page.locator('#posReview')).toBeDisabled(); expect(requests.some(r=>r.path.includes('/pos/'))).toBeFalsy();
});
test('saved invoice previews A4, A5 and 80mm without overflow in paper document',async({page},testInfo)=>{
  await boot(page); await addProduct(page); await paid(page,'cash'); await expect(page.locator('#invoiceDialog')).toBeVisible();
  for(const paper of ['a4','a5','receipt']) { await page.locator('#invoicePaper').selectOption(paper); await expect(page.locator('.fm-invoice')).toHaveClass(new RegExp('paper-'+paper)); const over=await page.locator('.fm-invoice').evaluate(n=>n.scrollWidth>n.clientWidth+2); expect(over).toBeFalsy(); }
  await page.locator('#invoiceClose').click(); const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+2); expect(overflow).toBeFalsy();
  await page.evaluate(()=>window.scrollTo(0,0));
  if(testInfo.project.name==='desktop-1280') await page.screenshot({path:'prototypes/pos-implemented-desktop.png',fullPage:true});
  if(testInfo.project.name==='mobile-320') await page.screenshot({path:'prototypes/pos-implemented-mobile.png',fullPage:true});
});
test('existing orders use private saved invoice including refund',async({page})=>{
  const {requests}=await boot(page); await page.evaluate(()=>location.hash='#orders');
  await page.locator('#ordersGrid').getByRole('button',{name:/مشاهده/}).first().click();
  await expect(page.locator('#orderPrintInvoice')).toBeVisible(); await page.locator('#orderPrintInvoice').click(); await expect(page.locator('#invoicePreview')).toContainText('بازپرداخت'); await expect(page.locator('#invoicePreview')).toContainText('۳۰۰ تومان'); expect(requests.some(r=>r.path.endsWith('/orders/91/invoice'))).toBeTruthy();
});

test('print button uses private iframe with CSP and real paper CSS',async({page,browser},testInfo)=>{
  await page.route('http://127.0.0.1:8124/',async route=>{const response=await route.fetch();await route.fulfill({response,headers:{...response.headers(),'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"}});});
  await page.addInitScript(()=>{
    window.__printed=0;window.__csp=[];
    document.addEventListener('securitypolicyviolation',e=>window.__csp.push(e.violatedDirective));
    new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(n=>{if(n instanceof HTMLIFrameElement){n.contentWindow.print=()=>{window.__printed++;};}}))).observe(document,{childList:true,subtree:true});
  });
  await boot(page);await addProduct(page);await paid(page,'cash');
  for(const [i,paper] of ['a4','a5','receipt'].entries()) {
    await page.locator('#invoicePaper').selectOption(paper);await page.locator('#invoicePrint').click();await expect.poll(()=>page.evaluate(()=>window.__printed)).toBe(i+1);
    const frame=page.frames().find(f=>f.parentFrame());expect(frame).toBeTruthy();await expect(frame.locator('.fm-invoice')).toContainText('فروشگاه آزمایشی');
    if(process.env.POS_EXPORT_PDF==='1'&&testInfo.project.name==='desktop-1280') {
      const fs=require('node:fs');fs.mkdirSync('output/pdf',{recursive:true});
      const html=await frame.evaluate(()=>document.documentElement.outerHTML);
      const exported=await browser.newPage();await exported.goto('http://127.0.0.1:8124/pos.css');await exported.setContent(html,{waitUntil:'networkidle'});await exported.emulateMedia({media:'print'});
      await exported.evaluate(()=>{document.querySelector('.invoice-table tbody td').firstChild.textContent='کالای صندوق';document.querySelector('.fm-invoice h2').textContent='فاکتور نمونهٔ فروش #۹۱';});
      // Preserve the dynamic receipt @page rule inserted through CSSOM.
      if(paper==='receipt') {const rule=await frame.evaluate(()=>Array.from(document.styleSheets[0].cssRules).at(-1).cssText);await exported.evaluate(rule=>document.styleSheets[0].insertRule(rule,document.styleSheets[0].cssRules.length),rule);}
      await exported.pdf({path:`output/pdf/invoice-sample-${paper}.pdf`,preferCSSPageSize:true,printBackground:true});await exported.close();
    }
  }
  expect(await page.evaluate(()=>window.__csp)).toEqual([]);await page.locator('#invoiceClose').click();await expect(page.locator('iframe.pos-print-frame')).toHaveCount(0);
});
