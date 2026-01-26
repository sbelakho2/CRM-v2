const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.join(ROOT, 'test-results', 'screenshots');

function loadRoutes() {
  const output = execSync('php bin/console debug:router --format=json', {
    cwd: ROOT,
    encoding: 'utf-8',
  });

  const routes = JSON.parse(output);
  return Object.values(routes)
    .filter((route) => Array.isArray(route.methods) && route.methods.includes('GET'))
    .map((route) => route.path)
    .filter((path) => !path.includes('{'))
    .filter((path) => !path.startsWith('/_'))
    .filter((path) => !path.startsWith('/_wdt'))
    .filter((path) => !path.startsWith('/_profiler'))
    .filter((path) => !path.startsWith('/_error'))
    .filter((path) => !path.startsWith('/_fragment'));
}

function slugifyPath(urlPath) {
  if (urlPath === '/') return 'home';
  return urlPath.replace(/\//g, '_').replace(/[^a-zA-Z0-9_-]/g, '');
}

test('screenshot sweep of all GET pages', async ({ page }) => {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

  await page.goto('/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="_username"]', 'demo.admin@starz.local');
  await page.fill('input[name="_password"]', 'DemoPass2026');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"]'),
  ]);

  const routes = loadRoutes();
  const visited = new Set();

  for (const routePath of routes) {
    if (visited.has(routePath)) continue;
    visited.add(routePath);

    await page.goto(routePath, { waitUntil: 'networkidle' });

    const missingKeys = await page.evaluate(() => {
      const text = document.body?.innerText || '';
      const matches = text.match(/\b[a-z_]+(?:\.[a-z_]+)+\b/g) || [];
      const blacklistPrefixes = ['http', 'https', 'www'];
      return matches.filter((match) => !blacklistPrefixes.some((prefix) => match.startsWith(prefix)));
    });

    expect(missingKeys, `Missing translation keys on ${routePath}: ${missingKeys.join(', ')}`).toEqual([]);

    const fileName = `${slugifyPath(routePath)}.png`;
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, fileName),
      fullPage: true,
    });
  }
});
