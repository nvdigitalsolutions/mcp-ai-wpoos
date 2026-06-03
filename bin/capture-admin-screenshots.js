#!/usr/bin/env node
/**
 * NV oOS Base Plugin Admin Screenshot Capture
 *
 * Captures full-page screenshots of every admin page in the
 * base NV oOS plugin (no Pro addon required).
 *
 * Usage:  node bin/capture-admin-screenshots.js
 * Prereq: docker compose up -d
 * Output: docs/screenshots/admin/
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = 'http://localhost:8000';
const ADMIN = `${BASE}/wp-admin`;
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'password';
const OUT = path.resolve(__dirname, '..', 'docs', 'screenshots');

fs.mkdirSync(path.join(OUT, 'admin'), { recursive: true });

function out(filename) {
  return path.join(OUT, 'admin', filename);
}

async function login(page) {
  await page.goto(`${ADMIN}/`, { waitUntil: 'networkidle' });
  if (page.url().includes('wp-login')) {
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASS);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 15000 });
  }
  await page.waitForTimeout(500);
}

async function screenshot(page, url, filename) {
  console.log(`  ${filename}`);
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 10000 });
    await page.waitForTimeout(500);
    await page.screenshot({ path: out(filename), fullPage: true });
  } catch (e) {
    console.log(`    SKIP: ${e.message.slice(0, 80)}`);
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    ignoreHTTPSErrors: true,
  });
  const page = await context.newPage();

  console.log('Logging in...');
  await login(page);

  const slug = 'admin.php?page=wp-mcp-ai-dashboard';

  // ── NV oOS Settings tabs ──
  console.log('\n=== Settings ===');
  await screenshot(page, `${ADMIN}/${slug}`, 'settings-general.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=overview`, 'settings-overview.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=general`, 'settings-general-tab.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=ai_providers`, 'settings-ai-providers.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=authentication`, 'settings-authentication.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=tools_features`, 'settings-tools-features.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=tools_manager`, 'settings-tools-manager.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=security`, 'settings-security.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=advanced`, 'settings-advanced.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=orchestration`, 'settings-orchestration.png');

  // ── Assistants, Professions, Teams ──
  console.log('\n=== Assistants ===');
  await screenshot(page, `${ADMIN}/edit.php?post_type=mcp_ai_assistant`, 'assistants-list.png');
  await screenshot(page, `${ADMIN}/post-new.php?post_type=mcp_ai_assistant`, 'assistants-create.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-build-assistant`, 'assistants-build.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-assistant`, 'assistants-test.png');

  console.log('\n=== Professions ===');
  await screenshot(page, `${ADMIN}/edit.php?post_type=mcp_ai_profession`, 'professions-list.png');
  await screenshot(page, `${ADMIN}/post-new.php?post_type=mcp_ai_profession`, 'professions-create.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-profession`, 'professions-test.png');

  console.log('\n=== Teams ===');
  await screenshot(page, `${ADMIN}/edit.php?post_type=mcp_ai_team`, 'teams-list.png');
  await screenshot(page, `${ADMIN}/post-new.php?post_type=mcp_ai_team`, 'teams-create.png');

  // ── Management pages ──
  console.log('\n=== Management ===');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-token-manager`, 'token-manager.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-cron-manager`, 'cron-manager.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-remote-sites`, 'remote-sites.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-crawl4ai-monitor`, 'crawl4ai-monitor.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-mcp-diagnostic`, 'mcp-diagnostic.png');

  // ── WordPress core ──
  console.log('\n=== Core ===');
  await screenshot(page, `${ADMIN}/`, 'wp-dashboard.png');
  await screenshot(page, `${ADMIN}/plugins.php`, 'plugins-list.png');

  // ── Frontend ──
  console.log('\n=== Frontend ===');
  try {
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 10000 });
    await page.screenshot({ path: out('frontend-homepage.png'), fullPage: true });
  } catch (e) {
    console.log('  SKIP: ' + e.message.slice(0, 60));
  }

  console.log('\nDone!');
  await browser.close();
})();
