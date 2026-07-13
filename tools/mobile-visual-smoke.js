const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const root = path.resolve(__dirname, '..');
const outDir = path.join(root, 'tmp', 'visual-smoke');
fs.mkdirSync(outDir, { recursive: true });

const baseUrl = process.env.SUPEROPTIC_BASE_URL || 'http://localhost/superopticnet';
const login = process.env.SUPEROPTIC_VISUAL_LOGIN;
const password = process.env.SUPEROPTIC_VISUAL_PASSWORD;
const executablePath = process.env.SUPEROPTIC_BROWSER
  || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';

if (!login || !password) {
  console.error(JSON.stringify({
    status: 'failed',
    reason: 'SUPEROPTIC_VISUAL_LOGIN and SUPEROPTIC_VISUAL_PASSWORD are required',
  }, null, 2));
  process.exit(2);
}

const pages = [
  ['customers', '/billing-customers.php?cb=visual'],
  ['customer_create', '/billing-customers.php?create=1&cb=visual'],
  ['customer_profile', '/billing-customer-profile.php'],
  ['admin_users', '/admin-users.php?view=table'],
  ['noc_monitor', '/noc-devices.php?view=monitor'],
  ['operations_hr', '/operations-center.php?section=hr'],
  ['operations_inventory', '/operations-center.php?section=inventory'],
  ['noc_devices', '/noc-devices.php?view=devices'],
  ['crm', '/crm.php?view=table'],
  ['radius_active', '/radius-active.php'],
  ['message_center', '/message-center.php?view=single'],
];

function urlFor(route) {
  return `${baseUrl}${route}`;
}

(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath,
  });
  const page = await browser.newPage({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
  });

  const consoleErrors = [];
  page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) {
      consoleErrors.push(`${message.type()}: ${message.text()}`);
    }
  });
  page.on('pageerror', (error) => consoleErrors.push(`pageerror: ${error.message}`));

  await page.goto(urlFor('/staff-login.php'), { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.fill('input[name="login"]', login);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null),
    page.click('button[type="submit"], .portal-submit-btn'),
  ]);

  const loggedIn = !(page.url().includes('staff-login.php') || page.url().includes('portal-login.php'));
  const results = [];

  for (const [name, route] of pages) {
    const pageErrorsBefore = consoleErrors.length;
    const targetUrl = urlFor(route);
    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(650);
    const screenshotPath = path.join(outDir, `${name}-390.png`);
    await page.screenshot({ path: screenshotPath, fullPage: false });
    const metrics = await page.evaluate(() => {
      const viewportWidth = window.innerWidth;
      const doc = document.documentElement;
      const body = document.body;
      const maxScrollWidth = Math.max(doc.scrollWidth, body.scrollWidth);
      const offenders = Array.from(document.body.querySelectorAll('*'))
        .map((element) => {
          const rect = element.getBoundingClientRect();
          const style = window.getComputedStyle(element);
          return {
            tag: element.tagName.toLowerCase(),
            className: String(element.className || '').slice(0, 120),
            text: String(element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 80),
            left: Math.round(rect.left),
            right: Math.round(rect.right),
            width: Math.round(rect.width),
            display: style.display,
            position: style.position,
          };
        })
        .filter((item) => item.display !== 'none' && item.width > 0 && (item.left < -2 || item.right > viewportWidth + 2))
        .slice(0, 8);

      return {
        viewportWidth,
        bodyScrollWidth: body.scrollWidth,
        docScrollWidth: doc.scrollWidth,
        maxScrollWidth,
        overflowX: maxScrollWidth > viewportWidth + 2,
        contentLength: body.innerText.trim().length,
        tableCards: document.querySelectorAll('.portal-table tbody tr').length,
        tableCellLabels: Array.from(document.querySelectorAll('.portal-table tbody td')).filter((td) => td.dataset.label).length,
        openModal: Boolean(document.querySelector('.billing-create-panel[open], .admin-users-modal[open]')),
        superpilotVisible: Boolean(document.querySelector('.billing-copilot-toggle')),
        offenders,
      };
    });

    results.push({
      name,
      url: targetUrl,
      ok: loggedIn && metrics.contentLength > 50 && !metrics.overflowX,
      screenshot: screenshotPath,
      consoleMessages: consoleErrors.slice(pageErrorsBefore),
      metrics,
    });
  }

  await browser.close();

  const failed = results.filter((result) => !result.ok || result.consoleMessages.some((line) => line.startsWith('error') || line.startsWith('pageerror')));
  const report = {
    status: failed.length === 0 ? 'ok' : 'failed',
    loggedIn,
    viewport: '390x844',
    failed: failed.map((item) => item.name),
    screenshots_dir: outDir,
    results,
  };

  const reportPath = path.join(outDir, `mobile-visual-smoke-${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2), 'utf8');
  report.report_path = reportPath;
  console.log(JSON.stringify(report, null, 2));
  process.exit(failed.length === 0 ? 0 : 2);
})().catch((error) => {
  console.error(JSON.stringify({ status: 'failed', error: error.message, stack: error.stack }, null, 2));
  process.exit(2);
});
