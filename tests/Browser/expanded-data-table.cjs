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
function fixture(url) {
    const query = Object.fromEntries(url.searchParams);
    let rows = source.filter(row => !query.search || row.payee_name.includes(query.search.toUpperCase()));
    if (query.sort) rows.sort((a,b) => (typeof a[query.sort] === 'number' ? a[query.sort] - b[query.sort] : String(a[query.sort]).localeCompare(String(b[query.sort]))) * (query.direction === 'desc' ? -1 : 1));
    const size = Number(query.per_page || 15);
    const page = Math.min(Number(query.page || 1), Math.max(1, Math.ceil(rows.length / size)));
    return { component: 'Records/ExpandedWtaxRecords', url: url.pathname + url.search, version: null,
        props: { auth: { user: { name: 'Browser Tester', email: 'test@example.test' } }, flash: {},
            filters: { ...query, per_page: size }, months: [{ value: '2026-09', label: 'September 2026', records_count: 26 }],
            withholdingTaxRateSummary: { total_tax_withheld: '325.00', rates: [] },
            expandedWtaxEntries: { data: rows.slice((page - 1) * size, page * size), total: rows.length, current_page: page, per_page: size, last_page: Math.max(1, Math.ceil(rows.length / size)) },
        },
    };
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
        await page.goto(`http://127.0.0.1:${server.address().port}/records/expanded-wtax?withholding_agent_tin=008791976&withholding_agent_branch_code=0000&report_type=quarterly&tax_rate=2`);
        await page.getByRole('button', { name: 'Columns', exact: true }).waitFor();
        assert.equal(await page.locator('tbody tr').count(), 15);
        await page.getByRole('button', { name: 'Columns', exact: true }).click();
        await page.getByRole('menuitemcheckbox', { name: 'Agent TIN', exact: true }).click();
        await page.keyboard.press('Escape');
        assert.equal(await page.getByRole('button', { name: 'Sort by Agent TIN', exact: true }).count(), 0);
        await page.getByRole('button', { name: 'Sort by Tax Withheld', exact: true }).click();
        await page.waitForURL(/sort=tax_withheld/);
        assert.equal(new URL(page.url()).searchParams.get('tax_rate'), '2');
        await page.getByRole('button', { name: 'Next', exact: true }).click();
        await page.waitForURL(/page=2/);
        assert.equal(await page.locator('tbody tr').count(), 11);
        await page.getByLabel('Rows per page').selectOption('10');
        await page.waitForURL(url => url.searchParams.get('per_page') === '10' && url.searchParams.get('page') === '1');
        assert.equal(await page.locator('tbody tr').count(), 10);
        const search = page.getByRole('textbox', { name: 'Search payee, TIN, or ATC...' });
        await search.fill('PAYEE 26');
        await page.waitForURL(/search=PAYEE/);
        assert.equal(await page.locator('tbody tr').count(), 1);
        await page.getByRole('button', { name: 'View Info', exact: true }).click();
        await page.getByRole('heading', { name: 'Fixture record information' }).waitFor();
        assert.ok(requests.includes('/view-info/expanded-wtax/26'));
        await page.keyboard.press('Escape');
        await search.fill('NO_MATCH');
        await page.waitForURL(/search=NO_MATCH/);
        await page.getByText('No expanded withholding tax records found.', { exact: true }).waitFor();
        assert.ok(await page.getByRole('button', { name: 'Next', exact: true }).isDisabled());
        await search.fill('');
        await page.waitForURL(url => !url.searchParams.has('search'));
        await page.getByLabel('Reporting month', { exact: true }).selectOption('2026-09');
        await page.waitForURL(/period=2026-09/);
        await page.goBack();
        await page.waitForFunction(() => document.querySelector('[aria-label="Reporting month"]').value === '');
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        assert.ok(await page.locator('[data-slot="table-container"]').evaluate(element => element.scrollWidth > element.clientWidth));
        assert.deepEqual(errors, []);
        console.log('PASS: production Expanded UI: visibility, sorting, scoped requests, paging, page size, search, empty state, source View Info, history and mobile scrolling. Fixture server; no application DB writes.');
    } catch (error) {
        await page.screenshot({ path: path.join(require('node:os').tmpdir(), 'expanded-table-browser-failure.png'), fullPage: true });
        console.error('Browser errors:', errors);
        console.error('Menu:', await page.getByRole('menu').evaluateAll(elements => elements.map(element => ({ rect: element.getBoundingClientRect().toJSON(), parent: element.parentElement.outerHTML.slice(0, 700) }))));
        throw error;
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
