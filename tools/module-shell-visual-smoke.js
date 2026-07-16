const fs = require('fs');
const http = require('http');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(path.resolve(__dirname, '..', '.tools', 'node_modules', 'playwright-core'));

const root = path.resolve(__dirname, '..');
const outputDir = path.join(root, 'tmp', 'module-shell-visual');
const screenshotDir = path.join(outputDir, 'screenshots');
const php = 'D:\\xampp\\php\\php.exe';
const renderer = path.join(__dirname, 'billing-ui-smoke.php');
const executablePath = process.env.SUPEROPTIC_BROWSER
  || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
fs.mkdirSync(screenshotDir, { recursive: true });

const renderedPages = new Set([
  'billing-dashboard.php',
  'operations-center.php',
]);
const partialPages = {
  'finance-module-partial.php': 'finance',
  'hr-module-partial.php': 'hr',
  'inventory-module-partial.php': 'inventory',
};
const contentTypes = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.woff2': 'font/woff2',
};

function renderPage(page, searchParams) {
  const query = new URLSearchParams(searchParams);
  const module = partialPages[page];
  if (module) {
    query.set('section', module);
    page = 'operations-center.php';
  }
  return execFileSync(php, [renderer, `--render=${page}`, `--query=${query.toString()}`], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 24 * 1024 * 1024,
  });
}

const server = http.createServer((request, response) => {
  try {
    const url = new URL(request.url, 'http://127.0.0.1');
    const relativePath = decodeURIComponent(url.pathname).replace(/^\/+/, '');
    const page = path.basename(relativePath || 'billing-dashboard.php');
    if (renderedPages.has(page) || partialPages[page]) {
      response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
      response.end(renderPage(page, url.searchParams));
      return;
    }
    if (relativePath === 'favicon.ico') {
      response.writeHead(204);
      response.end();
      return;
    }
    const resolved = path.resolve(root, relativePath);
    if (!resolved.startsWith(root) || !fs.existsSync(resolved) || !fs.statSync(resolved).isFile()) {
      response.writeHead(404);
      response.end('Not found');
      return;
    }
    response.writeHead(200, {'Content-Type': contentTypes[path.extname(resolved).toLowerCase()] || 'application/octet-stream'});
    fs.createReadStream(resolved).pipe(response);
  } catch (error) {
    response.writeHead(500, {'Content-Type': 'text/plain; charset=utf-8'});
    response.end(error.stack || error.message);
  }
});

const pageSpecs = [
  {name: 'billing', route: '/billing-dashboard.php', module: 'billing'},
  {name: 'finance', route: '/operations-center.php?section=finance&finance_tab=overview', module: 'finance'},
  {name: 'hr', route: '/operations-center.php?section=hr&hr_tab=overview', module: 'hr'},
  {name: 'inventory', route: '/operations-center.php?section=inventory&inventory_tab=overview', module: 'inventory'},
];
const forbiddenVisibleText = [
  'Data Analytics Center', 'Backup worker', 'Audit log', 'Setting key',
  'Problem key', 'Inspection results', 'Record ID',
];
const mojibakeMarkers = ['Ã', 'Â', 'Æ', 'â€'];

async function collectSar5Metrics(page, module) {
  return page.evaluate(({module, forbiddenVisibleText, mojibakeMarkers}) => {
    const visible = element => {
      if (!element) return false;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const root = document.querySelector(`[data-${module}-module]`);
    const bodyText = document.body.innerText.replace(/\s+/g, ' ').trim();
    const doc = document.documentElement;
    const body = document.body;
    const nav = document.querySelector('[data-module-group-nav]');
    const nestedNavScroll = nav ? Array.from(nav.querySelectorAll('*')).filter(element => {
      if (!visible(element)) return false;
      const style = getComputedStyle(element);
      return ['auto', 'scroll'].includes(style.overflowX) && element.scrollWidth > element.clientWidth + 2;
    }).map(element => String(element.className || element.tagName).slice(0, 100)) : [];

    const kpi = root?.querySelector(`.${module}-kpi strong`);
    let kpiFits = true;
    if (kpi) {
      kpi.textContent = '999 999 999 999.99 AZN';
      const valueRect = kpi.getBoundingClientRect();
      const cardRect = kpi.closest(`.${module}-kpi`)?.getBoundingClientRect();
      kpiFits = Boolean(cardRect) && valueRect.left >= cardRect.left - 1 && valueRect.right <= cardRect.right + 1;
    }

    const actions = Array.from(root?.querySelectorAll('.portal-primary-btn,.portal-ghost-btn,.portal-inline-link') || [])
      .filter(visible)
      .map(element => ({element, rect: element.getBoundingClientRect(), text: element.textContent.trim().slice(0, 60)}));
    const actionOverlaps = [];
    for (let left = 0; left < actions.length; left += 1) {
      for (let right = left + 1; right < actions.length; right += 1) {
        const a = actions[left];
        const b = actions[right];
        if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
        const overlapWidth = Math.min(a.rect.right, b.rect.right) - Math.max(a.rect.left, b.rect.left);
        const overlapHeight = Math.min(a.rect.bottom, b.rect.bottom) - Math.max(a.rect.top, b.rect.top);
        if (overlapWidth > 3 && overlapHeight > 3) actionOverlaps.push([a.text, b.text]);
      }
    }

    const maxScrollWidth = Math.max(doc.scrollWidth, body.scrollWidth);
    const copilotToggle = document.querySelector('.billing-copilot-toggle');
    let copilotOverlap = false;
    if (innerWidth <= 680 && visible(copilotToggle) && root) {
      const toggleRect = copilotToggle.getBoundingClientRect();
      copilotOverlap = Array.from(root.querySelectorAll('article,.portal-muted-card,.billing-kpi,.finance-kpi,.hr-kpi,.inventory-kpi'))
        .filter(visible)
        .some(element => {
          const rect = element.getBoundingClientRect();
          return Math.min(toggleRect.right, rect.right) - Math.max(toggleRect.left, rect.left) > 3
            && Math.min(toggleRect.bottom, rect.bottom) - Math.max(toggleRect.top, rect.top) > 3;
        });
    }
    return {
      headerCount: document.querySelectorAll('body > header, body > .portal-topbar, header.portal-topbar').length,
      mainCount: document.querySelectorAll('main').length,
      moduleCount: document.querySelectorAll(`[data-${module}-module]`).length,
      navCount: document.querySelectorAll('[data-module-group-nav]').length,
      rootVisible: visible(root),
      overflowX: maxScrollWidth > innerWidth + 2,
      maxScrollWidth,
      viewportWidth: innerWidth,
      nestedNavScroll,
      kpiFits,
      actionOverlaps,
      copilotOverlap,
      forbiddenText: forbiddenVisibleText.filter(text => bodyText.includes(text)),
      mojibake: mojibakeMarkers.filter(text => bodyText.includes(text)),
    };
  }, {module, forbiddenVisibleText, mojibakeMarkers});
}

const results = [];
const failures = [];
const record = (name, ok, details) => {
  results.push({name, ok, ...details});
  if (!ok) failures.push(name);
};

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const baseUrl = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({headless: true, executablePath});
  try {
    for (const viewport of [{width: 1280, height: 800}, {width: 390, height: 844}]) {
      for (const spec of pageSpecs) {
        const page = await browser.newPage({viewport});
        const consoleErrors = [];
        page.on('pageerror', error => consoleErrors.push(`pageerror:${error.message}`));
        page.on('console', message => { if (message.type() === 'error') consoleErrors.push(`console:${message.text()}`); });
        await page.goto(baseUrl + spec.route, {waitUntil: 'domcontentloaded', timeout: 60000});
        await page.waitForSelector(`[data-${spec.module}-module]`);
        const metrics = await collectSar5Metrics(page, spec.module);
        const screenshot = path.join(screenshotDir, `${spec.name}-${viewport.width}.png`);
        await page.screenshot({path: screenshot, fullPage: true});
        const ok = metrics.headerCount === 1 && metrics.mainCount === 1
          && metrics.moduleCount === 1 && metrics.navCount === 1 && metrics.rootVisible
          && !metrics.overflowX && metrics.nestedNavScroll.length === 0 && metrics.kpiFits
          && metrics.actionOverlaps.length === 0 && metrics.forbiddenText.length === 0
          && !metrics.copilotOverlap
          && metrics.mojibake.length === 0 && consoleErrors.length === 0;
        record(`${spec.name}-layout-${viewport.width}`, ok, {metrics, consoleErrors, screenshot});
        await page.close();
      }
    }

    for (const spec of pageSpecs.filter(item => item.module !== 'billing')) {
      const page = await browser.newPage({viewport: {width: 1280, height: 800}});
      const documentRequests = [];
      page.on('request', request => {
        if (request.isNavigationRequest() && request.resourceType() === 'document') documentRequests.push(request.url());
      });
      await page.goto(baseUrl + spec.route, {waitUntil: 'domcontentloaded', timeout: 60000});
      documentRequests.length = 0;
      await page.evaluate(() => { window.__sar5Guard = 'retained'; });
      const statisticsLink = page.locator(`[data-${spec.module}-nav][data-module-section="statistics"]`);
      await statisticsLink.evaluate(link => { link.closest('details').open = true; });
      await statisticsLink.click();
      await page.waitForFunction(module => document.querySelector(`[data-${module}-module]`)?.dataset[`${module}Tab`] === 'statistics', spec.module);
      const state = await page.evaluate(module => ({
        guard: window.__sar5Guard,
        tab: document.querySelector(`[data-${module}-module]`)?.dataset[`${module}Tab`],
        status: document.querySelector(`[data-${module}-statistics-status]`)?.textContent.trim() || '',
      }), spec.module);
      record(`${spec.name}-ajax-navigation`, state.guard === 'retained' && state.tab === 'statistics' && documentRequests.length === 0, {state, documentRequests});
      await page.close();
    }
  } finally {
    await browser.close();
    server.close();
  }

  const report = {ok: failures.length === 0, failures, results};
  const reportPath = path.join(outputDir, `module-shell-visual-${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2), 'utf8');
  console.log(JSON.stringify({...report, report_path: reportPath}, null, 2));
  process.exit(failures.length === 0 ? 0 : 1);
})().catch(error => {
  server.close();
  console.error(JSON.stringify({ok: false, error: error.message, stack: error.stack}, null, 2));
  process.exit(1);
});
