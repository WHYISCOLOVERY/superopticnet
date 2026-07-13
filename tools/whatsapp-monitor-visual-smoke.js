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

  const metrics = await page.evaluate(() => {
    const visible = element => Boolean(element) && getComputedStyle(element).display !== 'none';
    return {
      viewportWidth: innerWidth,
      scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
      listVisible: visible(document.querySelector('.wa-chat-list')),
      threadVisible: visible(document.querySelector('.wa-chat-thread')),
      backVisible: visible(document.querySelector('.wa-chat-back')),
      chatItems: document.querySelectorAll('[data-wa-chat-search-item]').length,
      emptyStates: document.querySelectorAll('[data-wa-chat-list] .mgs-empty-state').length,
      totalLabel: document.querySelector('[data-wa-chat-count-total]')?.textContent.trim() || '',
      qrColumns: document.querySelector('.wa-qr-panel') ? getComputedStyle(document.querySelector('.wa-qr-panel')).gridTemplateColumns : '',
      contentLength: document.body.innerText.trim().length,
    };
  });

  const screenshot = path.join(outputDir, `${name}.png`);
  await page.screenshot({ path: screenshot, fullPage: true });
  const ok = metrics.contentLength > 50
    && metrics.scrollWidth <= metrics.viewportWidth + 2
    && (expectations.listVisible === undefined || metrics.listVisible === expectations.listVisible)
    && (expectations.threadVisible === undefined || metrics.threadVisible === expectations.threadVisible)
    && (expectations.backVisible === undefined || metrics.backVisible === expectations.backVisible)
    && (expectations.chatItems === undefined || metrics.chatItems === expectations.chatItems)
    && (expectations.emptyStates === undefined || metrics.emptyStates === expectations.emptyStates)
    && (expectations.totalLabel === undefined || metrics.totalLabel === expectations.totalLabel)
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
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify({ ok: errors.length === 0, errors, results }, null, 2));
  process.exit(errors.length === 0 ? 0 : 1);
})().catch(error => {
  console.error(JSON.stringify({ ok: false, error: error.message, stack: error.stack }, null, 2));
  process.exit(1);
});
