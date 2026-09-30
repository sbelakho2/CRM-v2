const { test, expect } = require('@playwright/test');

/**
 * REAL mutation flows (round-8 audit item 27): the previous e2e suite only
 * screenshotted GET routes. These tests drive actual CRM mutations through
 * the UI — login, create, complete, archive — the interactions a broken
 * form or a mis-wired controller can only reveal under a browser.
 */

const TASK_TITLE = `E2E Mutation Task ${Date.now()}`;
const COMPANY_NAME = `E2E Mutation Co ${Date.now()}`;

test('login → create task → complete → archive', async ({ page }) => {
  // Login through the real form.
  await page.goto('/login');
  await page.fill('input[name="_username"]', 'demo.admin@starz.local');
  await page.fill('input[name="_password"]', 'DemoPass2026');
  await page.click('button[type="submit"]');
  await page.waitForURL(/dashboard|\/$/);

  // CREATE: the tasks form.
  await page.goto('/tasks/new');
  await page.fill('input[name="task[title]"]', TASK_TITLE);
  await page.fill('textarea[name="task[description]"]', 'Created by the Playwright mutation-flow lane.');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/tasks\/\d+/);
  await expect(page.getByText(TASK_TITLE).first()).toBeVisible();

  // COMPLETE: from the task detail page (controller redirects to the list).
  const taskId = page.url().match(/\/tasks\/(\d+)/)[1];
  await page.click('form[action*="/complete"] button');
  await page.waitForURL(/\/tasks(?!\/)\b|\/tasks\?/);
  await expect(page.locator('.rams-table').getByText(TASK_TITLE)).toBeVisible();

  // ARCHIVE: back on the task page — the delete form opens the shared
  // confirm modal (ramsConfirmSubmit), which the test accepts.
  await page.goto(`/tasks/${taskId}`);
  await page.click('form[action*="/delete"] button');
  await page.click('#rams-confirm-btn');
  await page.waitForURL(/\/tasks(?!\/)\b|\/tasks\?/);
  await expect(page.getByText('archived', { exact: false }).first()).toBeVisible();
});

test('login → create company → visible in list', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name="_username"]', 'demo.admin@starz.local');
  await page.fill('input[name="_password"]', 'DemoPass2026');
  await page.click('button[type="submit"]');
  await page.waitForURL(/dashboard|\/$/);

  await page.goto('/companies/new');
  await page.fill('input[name="company[name]"]', COMPANY_NAME);
  const sector = page.locator('select[name="company[sector]"]');
  if (await sector.count()) {
    await sector.selectOption({ index: 1 });
  }
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/companies\/\d+/);
  await expect(page.getByText(COMPANY_NAME).first()).toBeVisible();

  await page.goto('/companies');
  await expect(page.getByText(COMPANY_NAME).first()).toBeVisible();
});
