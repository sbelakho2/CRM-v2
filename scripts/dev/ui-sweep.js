/**
 * UI sweep: screenshots of every concrete GET route at mobile + desktop,
 * for the global design audit. Run from the repo root against the e2e
 * server (php -S 127.0.0.1:8080):
 *
 *   node scripts/dev/ui-sweep.js [outdir]
 *
 * Output: <outdir>/mobile/*.png and <outdir>/desktop/*.png plus routes.json.
 */

const { chromium } = require('playwright');
const { execSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '../..');
const OUT = process.argv[2] || path.join(ROOT, 'test-results/ui-sweep');
const BASE = process.env.SWEEP_BASE || 'http://127.0.0.1:8080';

function loadRoutes() {
  const routes = JSON.parse(
    execSync('php bin/console debug:router --format=json', { cwd: ROOT, encoding: 'utf8' })
  );
  return Array.from(
    new Set(
      Object.values(routes)
        .filter((r) => {
          // Symfony 7 emits `method` (string, comma-separated); older
          // format used `methods` (array). ANY covers GET too.
          const m = r.method ?? (r.methods || []).join(',');
          const parts = String(m).split(',');
          return parts.includes('GET') || parts.includes('ANY');
        })
        .map((r) => r.path)
        .filter((p) => !p.includes('{'))
        .filter((p) => !p.startsWith('/_'))
    )
  ).sort();
}

function slug(p) {
  if (p === '/' || p === '') return 'home';
  return p.replace(/\//g, '-').replace(/[^a-zA-Z0-9-]/g, '').replace(/^-+/, '');
}

(async () => {
  const routes = loadRoutes();
  fs.mkdirSync(path.join(OUT, 'mobile'), { recursive: true });
  fs.mkdirSync(path.join(OUT, 'desktop'), { recursive: true });
  fs.writeFileSync(path.join(OUT, 'routes.json'), JSON.stringify(routes, null, 2));
  console.log(`${routes.length} routes`);

  for (const [label, viewport] of [
    ['mobile', { width: 390, height: 844 }],
    ['desktop', { width: 1440, height: 900 }],
  ]) {
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = {};

    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="_username"]', 'demo.admin@starz.local');
    await page.fill('input[name="_password"]', 'DemoPass2026');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type="submit"]'),
    ]);

    for (const route of routes) {
      try {
        const response = await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.waitForTimeout(350);
        const status = response ? response.status() : 0;
        if (status >= 400) {
          errors[route] = `HTTP ${status}`;
          continue;
        }
        await page.screenshot({ path: path.join(OUT, label, `${slug(route)}.png`), fullPage: true });
      } catch (e) {
        errors[route] = String(e).slice(0, 120);
      }
    }
    fs.writeFileSync(path.join(OUT, label, '_errors.json'), JSON.stringify(errors, null, 2));
    await browser.close();
    console.log(`${label}: ${Object.keys(errors).length} errored routes`);
  }
})();
