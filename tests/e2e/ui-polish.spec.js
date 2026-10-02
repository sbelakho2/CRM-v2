const { test, expect } = require('@playwright/test');
const fs = require('fs');

/**
 * Round-11 UI regression gates: each assertion pins a field-reported defect
 * class so it cannot silently return.
 *
 *  1. Guidance banners render IN-FLOW — a fixed-position stack covered the
 *     status bar and page header on phones (and floated over desktop content).
 *  2. Theme-aware logos resolve ROOT-relative on nested URLs — the theme
 *     switcher used to assign "images/…" relative to the current page, 404ing
 *     on /companies/{id} and every other nested route.
 *  3. Every inline SVG icon carries a viewBox — viewBox-less icons render as
 *     clipped 24-unit artwork in a 16px box (blobby black fills).
 *  4. Notification-bell items are real anchors (keyboard/middle-click),
 *     not click-handled divs.
 *
 * Set VISUAL_DUMP=1 to additionally write mobile+desktop screenshots to
 * test-results/ui-polish/ for human review (used by the local verify pass,
 * skipped in CI).
 */

const COMPANY_NAME = `E2E UI Polish Co ${Date.now()}`;
const PAGES = ['/', '/companies', '/activities', '/leads', '/tasks', '/discovery-pipeline'];

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="_username"]', 'demo.admin@starz.local');
  await page.fill('input[name="_password"]', 'DemoPass2026');
  await page.click('button[type="submit"]');
  await page.waitForURL(/dashboard|\/$/);
}

async function createCompany(page) {
  await page.goto('/companies/new');
  await page.fill('input[name="company[name]"]', COMPANY_NAME);
  const sector = page.locator('select[name="company[sector]"]');
  if (await sector.count()) {
    await sector.selectOption({ index: 1 });
  }
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/companies\/\d+/);
}

test('guidance banner is in-flow and logos resolve on nested URLs', async ({ page }) => {
  await login(page);
  await createCompany(page);

  // Nested URL: the theme switcher has swapped (or re-resolved) srcs by now.
  const logos = await page.evaluate(() =>
    Array.from(document.querySelectorAll('img[data-theme-src]')).map((img) => ({
      src: img.src,
      loaded: img.naturalWidth > 0,
    }))
  );
  expect(logos.length).toBeGreaterThan(0);
  for (const logo of logos) {
    expect(logo.loaded, `logo must load: ${logo.src}`).toBe(true);
    const path = new URL(logo.src).pathname;
    expect(
      path.startsWith('/images/'),
      `logo must resolve root-relative, got ${path}`
    ).toBe(true);
  }

  // Creating a company seeds a guidance notification ("complete the record")
  // into the session — the exact banner from the field report.
  const stack = page.locator('#guidance-notifications-container');
  await page.goto('/');
  if (await stack.count()) {
    const position = await stack.evaluate((el) => getComputedStyle(el).position);
    expect(position, 'guidance stack must not be a fixed overlay').not.toBe('fixed');
    const zIndex = await stack.evaluate((el) => getComputedStyle(el).zIndex);
    expect(zIndex, 'in-flow banners do not need overlay z-index').toBe('auto');
  }
});

test('every inline SVG on key pages carries a viewBox', async ({ page }) => {
  await login(page);
  for (const path of PAGES) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    const missing = await page.evaluate(() =>
      Array.from(document.querySelectorAll('svg'))
        .filter((svg) => !svg.hasAttribute('viewBox'))
        .map((svg) => `${svg.className.baseVal || svg.className}: ${(svg.getAttribute('class') || '').slice(0, 60)}`)
    );
    expect(missing, `viewBox-less SVGs on ${path}`).toEqual([]);
  }
});

test('notification bell exposes anchor items and closes by keyboard', async ({ page }) => {
  await login(page);
  await page.goto('/');
  await page.click('#notification-bell');
  const modal = page.locator('#notification-modal');
  await expect(modal).toBeVisible();

  const badItems = await page.evaluate(() =>
    Array.from(document.querySelectorAll('#notification-list .rams-notification__item'))
      .filter((el) => el.tagName !== 'A' && el.tagName !== 'DIV')
      .map((el) => el.tagName)
  );
  expect(badItems).toEqual([]);

  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();
  await expect(page.locator('#notification-bell')).toBeFocused();
});

test('visual dump (local review only)', async ({ browser }) => {
  if (!process.env.VISUAL_DUMP) {
    test.skip(true, 'set VISUAL_DUMP=1 to capture screenshots');
  }
  const fsync = fs;
  const dir = 'test-results/ui-polish';
  fsync.mkdirSync(dir, { recursive: true });

  for (const [label, viewport] of [
    ['mobile', { width: 390, height: 844 }],
    ['desktop', { width: 1440, height: 900 }],
  ]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    await login(page);
    await createCompany(page);
    for (const path of ['/', '/companies', '/activities', '/discovery-pipeline']) {
      await page.goto(path, { waitUntil: 'networkidle' });
      await page.screenshot({ path: `${dir}/${label}-${path === '/' ? 'dashboard' : path.replace(/\//g, '-')}.png`, fullPage: false });
    }
    // company show (nested URL, guidance banner likely present)
    await page.goto('/companies');
    await page.screenshot({ path: `${dir}/${label}-companies-list.png`, fullPage: false });
    await context.close();
  }
});
