const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
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
