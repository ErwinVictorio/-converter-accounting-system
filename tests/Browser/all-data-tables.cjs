// Production UI against a local fixture server; never reads/writes the application DB.
// Build first. Set PLAYWRIGHT_MODULE to an installed playwright module directory.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
const asset = manifest['resources/js/app.jsx'];
const requests = [];
const source = Array.from({ length: 26 }, (_, index) => ({
    id: `group-${index}`, source_record_id: index + 1, payee_name: `PAYEE ${String(index + 1).padStart(2, '0')}`,
    company_name: 'TEST', payee_type: 'company', payee_tin: '123456789', payee_branch_code: '0000',
    withholding_agent_tin: '008791976', withholding_agent_branch_code: '0000', atc_code: 'WC160',
    tax_rate: '2.00', income_payment: index * 50, tax_withheld: index,
    reporting_period: '2026-09-30', report_type: 'quarterly', invalid_count: 0, merged_rows: index === 0 ? 2 : 1,
}));

const pages = [
 ['/records/purchases','Records/PurchaseRecords','vatInputs','Supplier Name'],
 ['/records/sales','Records/SalesRecords','salesVatInputs','Customer Name'],
 ['/records/importations','Records/ImportationRecords','entries','Name of Seller'],
 ['/customers','ManageCustomer','customerList','Customer Name'],
 ['/suppliers','ManageSupplier','supplierList','Supplier Name'],
 ['/withholding-companies','WithholdingCompanies','companies','Registered Name'],
 ['/brokers','ManageBrokers','brokerList','Broker Name'],
 ['/records/1/edit','EditVatInputRecord','vatInput',null],
 ['/','Dashboard','recent',null],
];
const rows = Array.from({length:26}, (_,i)=>({
 id:i+1, supplier_name:'SUPPLIER '+i, customer_name:'CUSTOMER '+i, supplier:'SELLER '+i, name:'NAME '+i,
 registered_name:'COMPANY '+i, broker_name:'BROKER '+i, tin:'123456789', tin_number:'123456789', customer_tin:'123456789',
 branch_code:'0000', addr:'ADDRESS', city:'CITY', address1:'ADDRESS', address2:'CITY', is_active:true,
 tax_month:'2026-09-01', import_entry_no:'C-'+i, country:'CHINA', sequence_number:i+1,
 taxable_goods:i*100, vat_payable:i*12, filing_company_tin:'008791976', filing_company_branch_code:'0000',
 is_broker:1, is_imported:0, is_adjusted:0, vendor_type:'company', company_name:'COMPANY',
 display_purchase_local_amount:i, display_services_amount:0, display_others_amount:0, display_calculated_total:i*100,
 purchase_local:i*100, purchase_imported:0, services:0, others:0, total:i*100,
}));
function fixture(url) {
 const item=url.pathname==='/records'?['/records','RecordEntry','unused',null]:(pages.find(item=>item[0]===url.pathname) || pages[0]);
 const query=Object.fromEntries(url.searchParams);const size=Number(query.per_page||15),page=Number(query.page||1);
 const data={data:rows.slice((page-1)*size,page*size),total:26,current_page:page,per_page:size,last_page:Math.ceil(26/size)};
 return {component:item[1],url:url.pathname+url.search,version:null,props:{
 auth:{user:{name:'Tester',email:'test@example.test'}},flash:{},errors:{},filters:query,months:[],purchaseCompanies:[],companyFilter:'',
 [item[2]]:item[2]==='brokerList'||item[2]==='recent'?rows:item[2]==='vatInput'?rows[0]:data,
 hasAnyData:true,monthLabel:'September 2026',chartYear:2026,
 }};
}
const server = http.createServer((req,res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/build/')) {
        const file = path.resolve('public', '.' + url.pathname);
        if (!file.startsWith(path.resolve('public/build') + path.sep)) { res.writeHead(404).end(); return; }
        res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream');
        fs.createReadStream(file).pipe(res); return;
    }
    if (url.pathname.startsWith('/view-info/')) {
        requests.push(url.pathname);
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ title: 'Fixture record information', sections: [] })); return;
    }
    const data = fixture(url);
    if (req.headers['x-inertia']) {
        requests.push(url.pathname + url.search);
        res.setHeader('X-Inertia', 'true'); res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(data)); return;
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${asset.css.map(css => `<link rel="stylesheet" href="/build/${css}">`).join('')}</head><body><div id="app" data-page="${JSON.stringify(data).replaceAll('&','&amp;').replaceAll('"','&quot;')}"></div><script type="module" src="/build/${asset.file}"></script></body></html>`);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
 for(const [url,component,prop,label] of pages){
  await page.setViewportSize({width:1440,height:1000});
  await page.goto('http://127.0.0.1:'+server.address().port+url);
  await page.locator('tbody tr').first().waitFor();
  assert.deepEqual(errors,[],component);
  const isPreview=label===null;
  assert.equal(await page.locator('tbody tr').count(),prop==='vatInput'?1:prop==='recent'?26:15,component);
  if(!isPreview){
   await page.getByRole('button',{name:'Columns',exact:true}).click();
   await page.getByRole('menuitemcheckbox').last().click();await page.keyboard.press('Escape');
   await page.getByRole('button',{name:'Next',exact:true}).click();
   await page.waitForFunction(()=>document.querySelectorAll('tbody tr').length===11);
   await page.getByLabel('Rows per page').selectOption('10');
   await page.waitForFunction(()=>document.querySelectorAll('tbody tr').length===10);
   await page.getByRole('button',{name:'Sort by '+label,exact:true}).click();
  }
  await page.getByRole('button',{name:'View Info',exact:true}).first().click();
  await page.getByRole('heading',{name:'Fixture record information'}).waitFor();await page.keyboard.press('Escape');
  await page.setViewportSize({width:390,height:844});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),component+' mobile overflow');
  assert.deepEqual(errors,[],component);
  console.log('PASS '+component+' table, visibility/paging/sort where applicable, View Info and mobile containment');
 }

 await page.setViewportSize({width:1440,height:1000});
 await page.route('**/expanded-dm/review',route=>route.fulfill({json:{
  review_version:4,apply_available:true,token:'fixture',scope:{percentage:'2'},warnings:[],negative_balance_warnings:[],bir_warnings:[],excluded_count:0,excluded_debit:0,
  summary:{file_count:1,file_total:100,eligible_count:1,eligible_total:100,skipped_count:0,skipped_total:0,already_applied_count:0,already_applied_total:0,needs_review_count:0,needs_review_total:0},
  supplier_groups:[{key:'g',target_key:'t',supplier:'DM SUPPLIER',entries:[{key:'e',row:4,reference:'DM#1',amount:100,status:'ready',reason:'Ready to apply'}]}],
  targets:[{key:'t',supplier:'DM SUPPLIER',before:200,deduction:100,after:100,income_before:10000,income_deduction:5000,income_after:5000,tin:'123456789',atc:'WC160',rate:2,source_count:1}],
 }}));
 let applied=false;await page.route('**/expanded-dm/apply',route=>{applied=true;return route.fulfill({json:{message:'Unexpected apply'}});});
 await page.goto('http://127.0.0.1:'+server.address().port+'/records');
 await page.locator('select').filter({has:page.locator('option[value="expanded"]')}).selectOption('expanded');
 await page.locator('input[type="file"]').last().setInputFiles({name:'fixture.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:Buffer.from('fixture')});
 await page.getByLabel('Percentage (%)',{exact:true}).fill('2');
 await page.getByRole('dialog').getByRole('button',{name:'Upload',exact:true}).click();
 await page.getByRole('heading',{name:'Review DM Adjustment'}).waitFor();
 assert.equal(await page.getByRole('dialog').locator('tbody tr').count(),1);
 await page.getByText('View 1 DM entries',{exact:true}).click();
 await page.getByText('Row 4: DM#1',{exact:false}).waitFor();
 await page.getByLabel('Show suppliers',{exact:false}).selectOption('needs_review');
 await page.getByText('No DM groups match this filter.',{exact:true}).waitFor();
 await page.getByRole('button',{name:'Cancel',exact:true}).click();
 assert.equal(applied,false);assert.deepEqual(errors,[]);
 console.log('PASS DM review table, details, filter, and cancel without apply');
    } catch (error) {
        await page.screenshot({ path: path.join(require('node:os').tmpdir(), 'expanded-table-browser-failure.png'), fullPage: true });
        console.error('Browser errors:', errors);
        console.error('Menu:', await page.getByRole('menu').evaluateAll(elements => elements.map(element => ({ rect: element.getBoundingClientRect().toJSON(), parent: element.parentElement.outerHTML.slice(0, 700) }))));
        throw error;
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
