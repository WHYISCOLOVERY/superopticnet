const fs = require('fs');
const http = require('http');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(path.resolve(__dirname, '..', '.tools', 'node_modules', 'playwright-core'));

const root = path.resolve(__dirname, '..');
const outputDir = path.join(root, 'tmp', 'statistics-ai-visual');
const screenshotDir = path.join(outputDir, 'screenshots');
const php = 'D:\\xampp\\php\\php.exe';
const renderer = path.join(__dirname, 'render-statistics-ai-visual-fixture.php');
const executablePath = process.env.SUPEROPTIC_BROWSER
  || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
fs.mkdirSync(screenshotDir, { recursive: true });

const dynamicPages = new Set([
  'statistics-center.php', 'statistics-center-partial.php', 'ai-dashboard.php',
  'ai-copilot.php', 'ai-customer-analytics.php', 'ai-network-analytics.php',
  'ai-finance-analytics.php', 'ai-risks.php', 'ai-recommendations.php',
  'ai-automations.php', 'ai-audit.php', 'ai-model-status.php', 'ai-module-partial.php',
]);
const contentTypes = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.woff2': 'font/woff2',
};

const server = http.createServer((request, response) => {
  try {
    const url = new URL(request.url, 'http://127.0.0.1');
    const relativePath = decodeURIComponent(url.pathname).replace(/^\/+/, '');
    if (relativePath === 'favicon.ico') {
      response.writeHead(204);
      response.end();
      return;
    }
    const page = path.basename(relativePath || 'statistics-center.php');
    if (dynamicPages.has(page)) {
      const html = execFileSync(php, [renderer, `--page=${page}`, `--query=${url.searchParams.toString()}`], {
        cwd: root,
        encoding: 'utf8',
        maxBuffer: 20 * 1024 * 1024,
      });
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(html);
      return;
    }
    const resolved = path.resolve(root, relativePath);
    if (!resolved.startsWith(root) || !fs.existsSync(resolved) || !fs.statSync(resolved).isFile()) {
      response.writeHead(404);
      response.end('Not found');
      return;
    }
    response.writeHead(200, { 'Content-Type': contentTypes[path.extname(resolved).toLowerCase()] || 'application/octet-stream' });
    fs.createReadStream(resolved).pipe(response);
  } catch (error) {
    response.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end(error.stack || error.message);
  }
});

const results = [];
const errors = [];
const record = (name, ok, details = {}) => {
  results.push({ name, ok, ...details });
  if (!ok) errors.push(name);
};

async function layoutMetrics(page, selector) {
  return page.evaluate(targetSelector => {
    const target = document.querySelector(targetSelector);
    const visible = element => Boolean(element) && getComputedStyle(element).display !== 'none';
    const offenders = Array.from(document.querySelectorAll('input,select,button,a,svg,.statistics-bar-row,.ai-panel,.ai-kpi'))
      .filter(element => visible(element))
      .filter(element => {
        const rect = element.getBoundingClientRect();
        return rect.left < -2 || rect.right > innerWidth + 2;
      })
      .slice(0, 8)
      .map(element => ({ tag: element.tagName, className: String(element.className || ''), text: String(element.textContent || '').trim().slice(0, 50) }));
    return {
      targetVisible: visible(target),
      viewportWidth: innerWidth,
      scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
      offenders,
    };
  }, selector);
}

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const baseUrl = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ headless: true, executablePath });
  try {
    for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport });
      const consoleErrors = [];
      page.on('pageerror', error => consoleErrors.push(`pageerror:${error.message}`));
      page.on('console', message => { if (message.type() === 'error') consoleErrors.push(`console:${message.text()}`); });
      await page.goto(`${baseUrl}/statistics-center.php?range=month&metric=all`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForSelector('[data-statistics-results]');
      const metrics = await layoutMetrics(page, '[data-statistics-module]');
      const chartCount = await page.locator('[data-statistics-chart]').count();
      const screenshot = path.join(screenshotDir, `statistics-${viewport.width}.png`);
      await page.screenshot({ path: screenshot, fullPage: true });
      record(`statistics-layout-${viewport.width}`, metrics.targetVisible && metrics.scrollWidth <= metrics.viewportWidth + 2 && metrics.offenders.length === 0 && chartCount >= 5 && consoleErrors.length === 0, { metrics, chartCount, consoleErrors, screenshot });
      await page.close();
    }

    const statsPage = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    const statsErrors = [];
    statsPage.on('pageerror', error => statsErrors.push(error.message));
    statsPage.on('console', message => { if (message.type() === 'error') statsErrors.push(message.text()); });
    await statsPage.goto(`${baseUrl}/statistics-center.php?range=month&metric=all`, { waitUntil: 'domcontentloaded' });
    await statsPage.evaluate(() => { window.__statisticsAjaxMarker = 'retained'; });
    await statsPage.selectOption('#statistics-metric', 'expenses');
    await statsPage.waitForFunction(() => document.querySelector('[data-statistics-status]')?.textContent.includes('yenilendi'));
    const selectedState = await statsPage.evaluate(() => ({
      marker: window.__statisticsAjaxMarker,
      metric: new URL(location.href).searchParams.get('metric'),
      charts: document.querySelectorAll('[data-statistics-chart]').length,
      kpis: document.querySelectorAll('.statistics-kpi').length,
    }));
    await statsPage.fill('#statistics-start', '2026-07-01');
    await statsPage.waitForFunction(() => new URL(location.href).searchParams.get('range') === 'custom');
    const dateState = await statsPage.evaluate(() => ({
      range: document.querySelector('#statistics-range')?.value,
      start: document.querySelector('#statistics-start')?.value,
      marker: window.__statisticsAjaxMarker,
    }));
    record('statistics-ajax-filters', selectedState.marker === 'retained' && selectedState.metric === 'expenses' && selectedState.charts === 1 && selectedState.kpis === 1 && dateState.range === 'custom' && dateState.start === '2026-07-01' && dateState.marker === 'retained' && statsErrors.length === 0, { selectedState, dateState, consoleErrors: statsErrors });
    await statsPage.close();

    for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport });
      const consoleErrors = [];
      page.on('pageerror', error => consoleErrors.push(`pageerror:${error.message}`));
      page.on('console', message => { if (message.type() === 'error') consoleErrors.push(`console:${message.text()}`); });
      await page.goto(`${baseUrl}/ai-dashboard.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForSelector('[data-ai-module]');
      const metrics = await layoutMetrics(page, '[data-ai-module]');
      const adminGroup = await page.locator('[data-module-nav-group]').filter({ hasText: 'Admin texniki aletleri' }).count();
      const screenshot = path.join(screenshotDir, `ai-${viewport.width}.png`);
      await page.screenshot({ path: screenshot, fullPage: true });
      record(`ai-layout-${viewport.width}`, metrics.targetVisible && metrics.scrollWidth <= metrics.viewportWidth + 2 && metrics.offenders.length === 0 && adminGroup === 1 && consoleErrors.length === 0, { metrics, adminGroup, consoleErrors, screenshot });
      await page.close();
    }

    const aiPage = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    const aiErrors = [];
    aiPage.on('pageerror', error => aiErrors.push(error.message));
    aiPage.on('console', message => { if (message.type() === 'error') aiErrors.push(message.text()); });
    await aiPage.goto(`${baseUrl}/ai-dashboard.php`, { waitUntil: 'domcontentloaded' });
    await aiPage.evaluate(() => { window.__aiAjaxMarker = 'retained'; });
    const riskLink = aiPage.locator('[data-module-group-nav] [data-ai-nav][data-module-section="risks"]');
    await riskLink.evaluate(link => { link.closest('details').open = true; });
    await riskLink.click();
    await aiPage.waitForSelector('[data-ai-risks]');
    const aiState = await aiPage.evaluate(() => ({
      marker: window.__aiAjaxMarker,
      section: document.querySelector('[data-ai-module]')?.dataset.aiSection,
      title: document.querySelector('[data-ai-title]')?.textContent.trim(),
      path: location.pathname,
    }));
    record('ai-ajax-navigation', aiState.marker === 'retained' && aiState.section === 'risks' && aiState.title === 'Riskler' && aiState.path.endsWith('/ai-risks.php') && aiErrors.length === 0, { aiState, consoleErrors: aiErrors });
    await aiPage.close();
  } finally {
    await browser.close();
    server.close();
  }

  const report = { ok: errors.length === 0, errors, results };
  const reportPath = path.join(outputDir, `statistics-ai-visual-${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2), 'utf8');
  console.log(JSON.stringify({ ...report, report_path: reportPath }, null, 2));
  process.exit(errors.length === 0 ? 0 : 1);
})().catch(error => {
  server.close();
  console.error(JSON.stringify({ ok: false, error: error.message, stack: error.stack }, null, 2));
  process.exit(1);
});
