import { test as setup } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

/**
 * WP admin login setup.
 *
 * Form-fills /wp-login.php and saves the resulting session to
 * tests/e2e/.auth/admin.json for reuse across all spec files.
 *
 * Credentials come from environment variables with DDEV defaults:
 *   WP_ADMIN_USER  (default: admin)
 *   WP_ADMIN_PASS  (default: admin)
 */

const AUTH_FILE = path.join(__dirname, '..', '.auth', 'admin.json');

setup('authenticate as WP admin', async ({ page }) => {
  const username = process.env.WP_ADMIN_USER ?? 'admin';
  const password = process.env.WP_ADMIN_PASS ?? 'admin';

  // Ensure the auth directory exists
  const authDir = path.dirname(AUTH_FILE);
  if (!fs.existsSync(authDir)) {
    fs.mkdirSync(authDir, { recursive: true });
  }

  await page.goto('/wp-login.php');

  await page.fill('#user_login', username);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');

  // Wait for the WP admin dashboard to confirm the login succeeded
  await page.waitForURL('**/wp-admin/**', { timeout: 15_000 });

  // Persist the authenticated session for the other specs
  await page.context().storageState({ path: AUTH_FILE });
});
