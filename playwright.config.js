const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  // The app runs under a single-threaded `php -S` dev server in the e2e
  // lane: parallel workers contend on that one PHP process and time out
  // (pipeline 51). One worker; tests share the seeded login state anyway.
  workers: 1,
  retries: 1,
  timeout: 120000,
  expect: {
    timeout: 10000,
  },
  use: {
    baseURL: 'http://localhost:8080',
    viewport: { width: 1440, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
});
