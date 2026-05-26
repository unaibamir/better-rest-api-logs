import { defineConfig, devices } from '@playwright/test';
import * as path from 'path';

/**
 * Standalone Playwright config for the Better REST API Logs browser smoke.
 *
 * Runs against the local DDEV site or the CI WordPress stack.
 * No @wordpress/scripts dependency — standalone @playwright/test only.
 *
 * Base URL resolution order:
 *  1. PLAYWRIGHT_BASE_URL env var (set in CI by the browser-smoke job)
 *  2. The DDEV site URL (default for local runs)
 */
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'https://better-rest-api-logs-gsd.ddev.site:33001';

/** Path to the saved admin session — created by the setup project on first run */
const AUTH_FILE = path.join(__dirname, '.auth', 'admin.json');

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',

  /* Reasonable timeout for a WP admin page — auth + page load */
  timeout: 30_000,
  expect: { timeout: 10_000 },

  /* Run tests in sequence so the golden-path steps build on each other */
  workers: 1,
  fullyParallel: false,

  /* Always show the test report on failure */
  reporter: 'list',

  use: {
    baseURL,
    /* Capture a screenshot on first failure for CI debugging */
    screenshot: 'only-on-failure',
    /* Ignore HTTPS certificate errors (DDEV uses a self-signed cert) */
    ignoreHTTPSErrors: true,
    /* Viewport matching WP admin standard desktop width */
    viewport: { width: 1280, height: 900 },
  },

  projects: [
    {
      /* Setup project: logs in once and saves storageState */
      name: 'setup',
      testMatch: '**/helpers/wp-login.setup.ts',
      use: {
        baseURL,
        ignoreHTTPSErrors: true,
        /* No pre-existing auth during setup — do NOT inherit global storageState */
        storageState: undefined,
      },
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        /* Reuse the authenticated session saved by the setup project */
        storageState: AUTH_FILE,
      },
      dependencies: ['setup'],
    },
  ],

  /* Output for screenshots/traces */
  outputDir: 'tests/e2e/test-results',
});
