const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BASE_URL = process.env.PRESENTATION_BASE_URL || 'http://127.0.0.1:8000';
const LOGIN_EMAIL = process.env.PRESENTATION_LOGIN_EMAIL || 'presentation.admin@example.com';
const LOGIN_PASSWORD = process.env.PRESENTATION_LOGIN_PASSWORD || 'DemoPass2026!';
const SCREENSHOT_DIR = path.join(ROOT, 'presentation_screenshots');
const METADATA_PATH = path.join(SCREENSHOT_DIR, 'metadata.json');

// ──────────────────────────────────────────────────
// Routes to SKIP — JSON-only endpoints, test routes,
// auth pages that redirect when logged in
// ──────────────────────────────────────────────────
const SKIP_ROUTES = new Set([
  '/logout',
  '/login',
  '/register',
  '/forgot-password',
  '/test/clear-guidance',
  // JSON API endpoints (no HTML page)
  '/calendar/events',
  '/command-center/actions',
  '/command-center/alert-count',
  '/command-center/alerts',
  '/command-center/data',
  '/command-center/interactive-quotes',
  '/command-center/leads',
  '/command-center/leads/analyze',
  '/command-center/metrics',
  '/command-center/quotes',
  '/currency-converter/rate-status',
  '/discovery-pipeline/progress',
  '/quote/interactive/stats',
]);

function loadRoutes() {
  const output = execSync('php bin/console debug:router --format=json', {
    cwd: ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  const routes = JSON.parse(output);
  const skipPrefixes = ['/api', '/_', '/webhook'];
  const skipContains = ['/api/', '/track/', '/export', '/download', '/pdf'];

  const pages = [];

  for (const [name, route] of Object.entries(routes)) {
    const method = route.method || 'ANY';
    const routePath = route.path || '';

    const allowsGet = method.includes('GET') || method === 'ANY';
    const hasParams = routePath.includes('{');
    const blockedPrefix = skipPrefixes.some(p => routePath.startsWith(p));
    const blockedContains = skipContains.some(t => routePath.includes(t));
    const blockedName = name.startsWith('_') || name.includes('webhook') || name.includes('api_');

    if (!allowsGet || hasParams || blockedPrefix || blockedContains || blockedName) continue;
    if (SKIP_ROUTES.has(routePath)) continue;

    pages.push({ name, method, path: routePath });
  }

  const dedup = new Map();
  for (const item of pages) {
    if (!dedup.has(item.path)) dedup.set(item.path, item);
  }

  return Array.from(dedup.values()).sort((a, b) => a.path.localeCompare(b.path));
}

function slugifyPath(urlPath) {
  if (urlPath === '/') return 'home';
  return urlPath.replace(/^\//, '').replace(/\//g, '__').replace(/[^a-zA-Z0-9_-]/g, '_');
}

async function login(page) {
  await page.goto(BASE_URL + '/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('input[name="_username"]', LOGIN_EMAIL);
  await page.fill('input[name="_password"]', LOGIN_PASSWORD);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1500);
  if (page.url().includes('/login')) {
    throw new Error('Login failed for ' + LOGIN_EMAIL);
  }
}

async function main() {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  const routes = loadRoutes();
  console.log('Routes to capture:', routes.length);

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
  const page = await context.newPage();
  await login(page);

  const metadata = [];
  let seq = 0;

  for (let i = 0; i < routes.length; i++) {
    const route = routes[i];
    const url = BASE_URL + route.path;
    seq++;
    const slug = slugifyPath(route.path);
    const fileName = String(seq).padStart(3, '0') + '_' + slug + '.png';
    const filePath = path.join(SCREENSHOT_DIR, fileName);

    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(1500);

      const title = await page.title();
      const heading = await page.locator('h1').first().textContent().catch(() => null);
      const subtitle = await page.locator('.rams-page-subtitle, .rams-page__subtitle').first().textContent().catch(() => null);
      const finalUrl = page.url();

      // Detect if it redirected to a different page
      const blocked = finalUrl.includes('/login') || finalUrl.includes('/denied');

      // Detect if this rendered as a JSON blob (no HTML structure)
      const isJson = await page.evaluate(() => {
        const body = document.body;
        if (!body) return false;
        const text = body.innerText.trim();
        return (text.startsWith('{') || text.startsWith('[')) && body.querySelectorAll('h1, h2, nav, .rams-module').length === 0;
      });

      if (isJson) {
        console.log('  Skipped JSON endpoint: ' + route.path);
        seq--;
        continue;
      }

      await page.screenshot({ path: filePath, fullPage: true });

      metadata.push({
        index: seq,
        routeName: route.name,
        routePath: route.path,
        method: route.method,
        url,
        finalUrl,
        fileName,
        title: (title || '').trim(),
        heading: (heading || '').trim(),
        subtitle: (subtitle || '').trim(),
        blocked,
      });

      console.log('Captured ' + route.path + ' -> ' + fileName);
    } catch (error) {
      metadata.push({
        index: seq,
        routeName: route.name,
        routePath: route.path,
        method: route.method,
        url,
        finalUrl: page.url(),
        fileName,
        title: '',
        heading: '',
        subtitle: '',
        blocked: true,
        error: String(error.message || error),
      });
      console.warn('ERROR ' + route.path + ': ' + (error.message || error));
    }
  }

  fs.writeFileSync(METADATA_PATH, JSON.stringify({
    generatedAt: new Date().toISOString(),
    baseUrl: BASE_URL,
    screenshotDir: path.relative(ROOT, SCREENSHOT_DIR),
    routeCount: routes.length,
    capturedCount: metadata.filter(e => !e.error).length,
    entries: metadata,
  }, null, 2));

  await browser.close();
  console.log('Done. Captured: ' + metadata.filter(e => !e.error).length + '/' + routes.length);
}

main().catch(err => { console.error(err); process.exit(1); });
