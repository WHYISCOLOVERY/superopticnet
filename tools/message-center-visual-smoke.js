const fs = require('fs');
const http = require('http');
const path = require('path');
const { chromium } = require(path.resolve(__dirname, '..', '.tools', 'node_modules', 'playwright-core'));

const root = path.resolve(__dirname, '..');
const fixtureDir = path.join(root, 'tmp', 'message-center-visual');
const screenshotDir = path.join(fixtureDir, 'screenshots');
const executablePath = process.env.SUPEROPTIC_BROWSER
  || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
fs.mkdirSync(screenshotDir, { recursive: true });

const fixtures = new Map();
for (const view of ['overview', 'single', 'group', 'templates', 'history', 'audit', 'test', 'settings']) {
  fixtures.set(view, fs.readFileSync(path.join(fixtureDir, `${view}.html`), 'utf8'));
}

const server = http.createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1');
  const view = url.searchParams.get('view') || 'overview';
  const html = fixtures.get(view) || fixtures.get('overview');
  response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
  response.end(html);
});

const results = [];
const errors = [];

async function inspectPage(browser, baseUrl, view, viewport, expectedSelector, options = {}) {
  const page = await browser.newPage({ viewport });
  const consoleErrors = [];
  page.on('pageerror', error => consoleErrors.push(`pageerror:${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push(`console:${message.text()}`);
  });
  await page.goto(`${baseUrl}/message-center.php?view=${view}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(250);
  const metrics = await page.evaluate(({ selector, mobile }) => {
    const visible = element => Boolean(element) && getComputedStyle(element).display !== 'none';
    const targets = Array.from(document.querySelectorAll('input, select, textarea, button, a, table, .portal-table-wrap, .message-command-strip, .message-channel-chart'));
    const offenders = targets.filter(element => {
      if (!visible(element)) return false;
      const rect = element.getBoundingClientRect();
      return rect.left < -2 || rect.right > innerWidth + 2;
    });
    const labelledCells = Array.from(document.querySelectorAll('.portal-table tbody td[data-label]'));
    const visibleLabels = labelledCells.filter(cell => {
      const content = getComputedStyle(cell, '::before').content;
      return visible(cell) && content && content !== 'none' && content !== '""';
    });
    return {
      contentLength: document.body.innerText.trim().length,
      viewportWidth: innerWidth,
      scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
      offenders: offenders.map(element => ({ tag: element.tagName, className: String(element.className || ''), text: String(element.textContent || '').trim().slice(0, 60) })).slice(0, 8),
      selectorVisible: visible(document.querySelector(selector)),
      settingsFormCount: document.querySelectorAll('#sms-provider-settings').length,
      detailsCount: document.querySelectorAll('.message-details').length,
      openDetailsCount: document.querySelectorAll('.message-details[open]').length,
      labelledCells: labelledCells.length,
      visibleLabels: visibleLabels.length,
      mobile,
    };
  }, { selector: expectedSelector, mobile: viewport.width <= 560 });

  const screenshot = path.join(screenshotDir, `${view}-${viewport.width}.png`);
  await page.screenshot({ path: screenshot, fullPage: true });
  const ok = metrics.contentLength > 80
    && metrics.selectorVisible
    && metrics.scrollWidth <= metrics.viewportWidth + 2
    && metrics.offenders.length === 0
    && consoleErrors.length === 0
    && (options.settingsFormCount === undefined || metrics.settingsFormCount === options.settingsFormCount)
    && (options.detailsCount === undefined || metrics.detailsCount === options.detailsCount)
    && (options.openDetailsCount === undefined || metrics.openDetailsCount === options.openDetailsCount)
    && (!options.requireMobileLabels || !metrics.mobile || metrics.visibleLabels > 0);

  const name = `${view}-${viewport.width}`;
  results.push({ name, ok, screenshot, metrics, consoleErrors });
  if (!ok) errors.push(name);
  await page.close();
}

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  const baseUrl = `http://127.0.0.1:${address.port}`;
  const browser = await chromium.launch({ headless: true, executablePath });
  try {
    const views = [
      ['overview', '[data-message-channel-chart]', { settingsFormCount: 0, detailsCount: 0 }],
      ['single', '#singleSubscriber', {}],
      ['group', '#groupScope', {}],
      ['templates', '.message-template-body', { requireMobileLabels: true }],
      ['history', '.message-history-table', { requireMobileLabels: true }],
      ['audit', '.message-audit-table', {}],
      ['test', '#providerChannel', {}],
      ['settings', '#sms-provider-settings', { settingsFormCount: 1, detailsCount: 4, openDetailsCount: 0 }],
    ];
    for (const [view, selector, options] of views) {
      await inspectPage(browser, baseUrl, view, { width: 1440, height: 900 }, selector, options);
      await inspectPage(browser, baseUrl, view, { width: 390, height: 844 }, selector, options);
    }

    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    const ajaxErrors = [];
    page.on('pageerror', error => ajaxErrors.push(error.message));
    page.on('console', message => {
      if (message.type() === 'error') ajaxErrors.push(message.text());
    });
    await page.goto(`${baseUrl}/message-center.php?view=overview`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { window.__messageAjaxMarker = 'retained'; });
    await page.click('.message-command-actions [data-message-nav][data-module-section="settings"]');
    await page.waitForSelector('#sms-provider-settings');
    const settingsAjax = await page.evaluate(() => ({
      marker: window.__messageAjaxMarker,
      view: document.querySelector('[data-message-shell]')?.dataset.messageView,
      url: location.search,
      details: document.querySelectorAll('.message-details').length,
    }));
    await page.goto(`${baseUrl}/message-center.php?view=history`, { waitUntil: 'domcontentloaded' });
    await page.selectOption('#messageStatus', 'failed');
    await page.waitForTimeout(1200);
    const filterDebug = await page.evaluate(() => ({
      url: location.href,
      value: document.querySelector('#messageStatus')?.value,
      busy: document.querySelector('[data-message-shell]')?.getAttribute('aria-busy'),
      statusText: document.querySelector('[data-message-status]')?.textContent,
    }));
    if (new URL(filterDebug.url).searchParams.get('message_status') !== 'failed') {
      throw new Error(`automatic_filter_failed:${JSON.stringify({ filterDebug, ajaxErrors })}`);
    }
    const filterAjax = await page.evaluate(() => ({
      view: document.querySelector('[data-message-shell]')?.dataset.messageView,
      status: new URL(location.href).searchParams.get('message_status'),
    }));
    await page.goto(`${baseUrl}/message-center.php?view=single`, { waitUntil: 'domcontentloaded' });
    const firstResult = page.locator('[data-message-customer-code]').first();
    if (await firstResult.count()) await firstResult.click();
    const customerPrefill = await page.inputValue('#singleSubscriber');
    const ajaxOk = settingsAjax.marker === 'retained'
      && settingsAjax.view === 'settings'
      && settingsAjax.url.includes('view=settings')
      && settingsAjax.details === 4
      && filterAjax.view === 'history'
      && filterAjax.status === 'failed'
      && customerPrefill !== ''
      && ajaxErrors.length === 0;
    results.push({ name: 'ajax-navigation-filters-prefill', ok: ajaxOk, settingsAjax, filterAjax, customerPrefill, consoleErrors: ajaxErrors });
    if (!ajaxOk) errors.push('ajax-navigation-filters-prefill');
    await page.close();
  } finally {
    await browser.close();
    server.close();
  }

  const report = { ok: errors.length === 0, errors, results };
  const reportPath = path.join(fixtureDir, `message-center-visual-${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2), 'utf8');
  report.report_path = reportPath;
  console.log(JSON.stringify(report, null, 2));
  process.exit(errors.length === 0 ? 0 : 1);
})().catch(error => {
  server.close();
  console.error(JSON.stringify({ ok: false, error: error.message, stack: error.stack }, null, 2));
  process.exit(1);
});
