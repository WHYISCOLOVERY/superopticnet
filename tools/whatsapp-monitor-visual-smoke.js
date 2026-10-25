const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
const { chromium } = require(path.resolve(__dirname, '..', '.tools', 'node_modules', 'playwright-core'));

const root = path.resolve(__dirname, '..');
const fixtureDir = path.join(root, 'tmp', 'whatsapp-monitor-visual');
const outputDir = path.join(root, 'tmp', 'whatsapp-monitor-visual', 'screenshots');
const executablePath = process.env.SUPEROPTIC_BROWSER
  || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';

fs.mkdirSync(outputDir, { recursive: true });

const errors = [];
const results = [];

async function inspectPage(browser, name, file, viewport, expectations = {}) {
  const page = await browser.newPage({ viewport });
  const consoleErrors = [];
  page.on('pageerror', error => consoleErrors.push(`pageerror:${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push(`console:${message.text()}`);
  });
  await page.route('**/api/whatsapp-monitor/chat-list.php*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      rows: expectations.injectChat ? [{
        number_id: 201,
        chat_id: '994503333333@c.us',
        contact_name: 'Yeni test elaqe',
        company_number: '051 200 00 03',
        employee_name: 'Test operator 3',
        last_message: 'Ilk yeni mesaj',
        last_message_at: '2026-07-13 10:30:00',
        last_message_id: 700,
        message_count: 1,
      }] : [],
    }),
  }));
  await page.route('**/api/whatsapp-monitor/chat-messages.php*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, rows: [] }),
  }));
  await page.route('**/api/whatsapp-monitor/pairing-status.php*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, status: 'requested', status_label: 'QR gözləyir', worker_id: 'visual-worker' }),
  }));

  await page.goto(pathToFileURL(path.join(fixtureDir, `${file}.html`)).href, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (expectations.injectChat) await page.waitForTimeout(5400);
  else await page.waitForTimeout(300);

  const metrics = await page.evaluate((expectedSelector) => {
    const visible = element => Boolean(element) && getComputedStyle(element).display !== 'none';
    const overflowTargets = Array.from(document.querySelectorAll('input, select, textarea, button, a, table, .portal-table-wrap, .wa-screen-grid, .wa-form-grid'));
    const overflowingElements = overflowTargets.filter(element => {
      if (!visible(element)) return false;
      const rect = element.getBoundingClientRect();
      return rect.left < -2 || rect.right > innerWidth + 2;
    }).length;
    const labelledCells = Array.from(document.querySelectorAll('.portal-table td[data-label]'));
    const visibleDataLabels = labelledCells.filter(cell => {
      const content = getComputedStyle(cell, '::before').content;
      return content && content !== 'none' && content !== '""';
    }).length;
    return {
      viewportWidth: innerWidth,
      scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
      overflowingElements,
      selectorVisible: expectedSelector === '' || visible(document.querySelector(expectedSelector)),
      listVisible: visible(document.querySelector('.wa-chat-list')),
      threadVisible: visible(document.querySelector('.wa-chat-thread')),
      backVisible: visible(document.querySelector('.wa-chat-back')),
      chatItems: document.querySelectorAll('[data-wa-chat-search-item]').length,
      emptyStates: document.querySelectorAll('[data-wa-chat-list] .mgs-empty-state').length,
      totalLabel: document.querySelector('[data-wa-chat-count-total]')?.textContent.trim() || '',
      qrColumns: document.querySelector('.wa-qr-panel') ? getComputedStyle(document.querySelector('.wa-qr-panel')).gridTemplateColumns : '',
      detailsCount: document.querySelectorAll('details').length,
      openDetailsCount: document.querySelectorAll('details[open]').length,
      labelledCells: labelledCells.length,
      visibleDataLabels,
      contentLength: document.body.innerText.trim().length,
    };
  }, expectations.selector || '');

  const screenshot = path.join(outputDir, `${name}.png`);
  await page.screenshot({ path: screenshot, fullPage: true });
  const ok = metrics.contentLength > 50
    && metrics.scrollWidth <= metrics.viewportWidth + 2
    && metrics.overflowingElements === 0
    && metrics.selectorVisible
    && (expectations.listVisible === undefined || metrics.listVisible === expectations.listVisible)
    && (expectations.threadVisible === undefined || metrics.threadVisible === expectations.threadVisible)
    && (expectations.backVisible === undefined || metrics.backVisible === expectations.backVisible)
    && (expectations.chatItems === undefined || metrics.chatItems === expectations.chatItems)
    && (expectations.emptyStates === undefined || metrics.emptyStates === expectations.emptyStates)
    && (expectations.totalLabel === undefined || metrics.totalLabel === expectations.totalLabel)
    && (expectations.detailsCount === undefined || metrics.detailsCount === expectations.detailsCount)
    && (expectations.openDetailsCount === undefined || metrics.openDetailsCount === expectations.openDetailsCount)
    && (expectations.minLabelledCells === undefined || metrics.labelledCells >= expectations.minLabelledCells)
    && (expectations.minVisibleDataLabels === undefined || metrics.visibleDataLabels >= expectations.minVisibleDataLabels)
    && consoleErrors.length === 0;

  results.push({ name, ok, screenshot, metrics, consoleErrors });
  if (!ok) errors.push(name);
  await page.close();
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath });
  try {
    await inspectPage(browser, 'chats-desktop', 'chats-default', { width: 1440, height: 900 }, { listVisible: true, threadVisible: true });
    await inspectPage(browser, 'chats-mobile-list', 'chats-default', { width: 390, height: 844 }, { listVisible: true, threadVisible: false });
    await inspectPage(browser, 'chats-mobile-selected', 'chats-selected', { width: 390, height: 844 }, { listVisible: false, threadVisible: true, backVisible: true });
    await inspectPage(browser, 'chats-empty-live-insert', 'chats-empty', { width: 390, height: 844 }, { injectChat: true, chatItems: 1, emptyStates: 0, totalLabel: '1 aktiv çat' });
    await inspectPage(browser, 'pairing-desktop', 'pairing', { width: 1440, height: 900 });
    await inspectPage(browser, 'pairing-mobile', 'pairing', { width: 390, height: 844 });
    const remainingViews = [
      ['overview', '.wa-kpi-groups', 4, 0],
      ['screen-wall', '.wa-screen-grid', 0, 2],
      ['numbers', 'form input[name="phone_number"]', 8, 1],
      ['devices', 'form input[name="device_name"]', 7, 1],
      ['cases', 'form select[name="case_type"]', 6, 0],
      ['performance', '.portal-table', 4, 0],
      ['settings', 'form input[name="default_grid_columns"]', 0, 0],
      ['audit', '.wa-audit-pre', 5, 0],
    ];
    for (const [view, selector, labelledCells, detailsCount] of remainingViews) {
      await inspectPage(browser, `${view}-desktop`, view, { width: 1440, height: 900 }, {
        selector,
        minLabelledCells: labelledCells,
        detailsCount,
        openDetailsCount: 0,
      });
      await inspectPage(browser, `${view}-mobile`, view, { width: 390, height: 844 }, {
        selector,
        minLabelledCells: labelledCells,
        minVisibleDataLabels: labelledCells,
        detailsCount,
        openDetailsCount: 0,
      });
    }
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify({ ok: errors.length === 0, errors, results }, null, 2));
  process.exit(errors.length === 0 ? 0 : 1);
})().catch(error => {
  console.error(JSON.stringify({ ok: false, error: error.message, stack: error.stack }, null, 2));
  process.exit(1);
});
