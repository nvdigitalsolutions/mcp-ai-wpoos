#!/usr/bin/env node
/**
 * NV oOS Admin Screenshot Capture
 *
 * Captures full-page screenshots of every admin page in the NV oOS plugin.
 * Uses Playwright + Docker WordPress environment at localhost:8000.
 *
 * Usage:  node bin/capture-admin-screenshots.js
 * Prereq: docker compose up -d
 * Output: docs/screenshots/admin/ (core pages)
 *         docs/screenshots/dashboard/ (pro dashboards)
 *         docs/screenshots/tools/ (tools & features)
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = 'http://localhost:8000';
const ADMIN = `${BASE}/wp-admin`;
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'StrongPassword123!';
const OUT = path.resolve(__dirname, '..', 'docs', 'screenshots');

// Ensure output dirs exist
['admin', 'dashboard', 'tools', 'integrations'].forEach(d =>
  fs.mkdirSync(path.join(OUT, d), { recursive: true })
);

function out(category, filename) {
  return path.join(OUT, category, filename);
}

async function login(page) {
  await page.goto(`${ADMIN}/`, { waitUntil: 'networkidle' });
  // If already logged in, skip
  if (page.url().includes('wp-login')) {
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASS);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 15000 });
  }
  // Dismiss any notices / onboarding
  await page.waitForTimeout(500);
}

async function screenshot(page, url, category, filename, opts = {}) {
  console.log(`  [${category}] ${filename}`);
  const timeout = opts.timeout || 10000;
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
    await page.waitForTimeout(opts.wait || 500);
    await page.screenshot({
      path: out(category, filename),
      fullPage: true,
    });
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

  // ─────────────────────────────────────────────────────────
  // NV oOS → Settings pages (each tab + sub-tab)
  // ─────────────────────────────────────────────────────────
  console.log('\n=== NV oOS Settings ===');

  const slug = 'admin.php?page=wp-mcp-ai-dashboard';

  await screenshot(page, `${ADMIN}/${slug}`, 'admin', 'settings-general.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=overview`, 'admin', 'settings-overview.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=general`, 'admin', 'settings-general-tab.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=ai_providers`, 'admin', 'settings-ai-providers.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=authentication`, 'admin', 'settings-authentication.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=token_manager`, 'admin', 'settings-token-manager.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=tools_features`, 'admin', 'settings-tools-features.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=tools_manager`, 'admin', 'settings-tools-manager.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=security`, 'admin', 'settings-security.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=advanced`, 'admin', 'settings-advanced.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=orchestration`, 'admin', 'settings-orchestration.png');
  await screenshot(page, `${ADMIN}/${slug}&tab=logs`, 'admin', 'settings-logs.png');

  // ─────────────────────────────────────────────────────────
  // AI Assistants
  // ─────────────────────────────────────────────────────────
  console.log('\n=== AI Assistants ===');

  await screenshot(page, `${ADMIN}/edit.php?post_type=mcp_ai_assistant`, 'admin', 'assistants-list.png');
  await screenshot(page, `${ADMIN}/post-new.php?post_type=mcp_ai_assistant`, 'admin', 'assistants-create.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-build-assistant`, 'admin', 'assistants-build.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-assistant`, 'admin', 'assistants-test.png');

  // ─────────────────────────────────────────────────────────
  // Professions
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Professions ===');

  await screenshot(page, `${ADMIN}/edit.php?post_type=mcp_ai_profession`, 'admin', 'professions-list.png');
  // Profession editor (if any exist)
  await screenshot(page, `${ADMIN}/post-new.php?post_type=mcp_ai_profession`, 'admin', 'professions-create.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-profession`, 'admin', 'professions-test.png');

  // ─────────────────────────────────────────────────────────
  // Teams
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Teams ===');

  await screenshot(page, `${ADMIN}/edit.php?post_type=mcp_ai_team`, 'admin', 'teams-list.png');
  await screenshot(page, `${ADMIN}/post-new.php?post_type=mcp_ai_team`, 'admin', 'teams-create.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-build-team`, 'admin', 'teams-build.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-team`, 'admin', 'teams-test.png');

  // ─────────────────────────────────────────────────────────
  // Token Manager
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Token Manager ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-token-manager`, 'admin', 'token-manager.png');

  // ─────────────────────────────────────────────────────────
  // Cron Manager
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Cron Manager ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-cron-manager`, 'admin', 'cron-manager.png');

  // ─────────────────────────────────────────────────────────
  // Diagnostics
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Diagnostics ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-diagnostics`, 'admin', 'diagnostics.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-mcp-diagnostic`, 'admin', 'mcp-diagnostic.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-system-status`, 'admin', 'system-status.png');

  // ─────────────────────────────────────────────────────────
  // Remote Sites / Federation
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Remote Sites ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-remote-sites`, 'admin', 'remote-sites.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-mesh-settings`, 'admin', 'mesh-settings.png');

  // ─────────────────────────────────────────────────────────
  // Workflow Builder
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Workflow Builder ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-workflow-builder`, 'admin', 'workflow-builder.png');

  // ─────────────────────────────────────────────────────────
  // Crawl4AI Monitor
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Crawl4AI ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-crawl4ai-monitor`, 'admin', 'crawl4ai-monitor.png');

  // ─────────────────────────────────────────────────────────
  // Hugging Face Datasets
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Hugging Face Datasets ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-hf-datasets`, 'admin', 'hf-datasets.png');

  // ─────────────────────────────────────────────────────────
  // Onboarding Wizard
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Onboarding Wizard ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-onboarding`, 'admin', 'onboarding-wizard.png');

  // ─────────────────────────────────────────────────────────
  // Measurement Dashboard
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Measurement ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-measurement`, 'admin', 'measurement-dashboard.png');

  // ─────────────────────────────────────────────────────────
  // Content Assistant
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Content Assistant ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-content-assistant`, 'admin', 'content-assistant.png');

  // ─────────────────────────────────────────────────────────
  // Pro Dashboard pages (if available)
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Pro Dashboard ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard`, 'dashboard', 'pro-dashboard-overview.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard&tab=analytics`, 'dashboard', 'pro-dashboard-analytics.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard&tab=monitoring`, 'dashboard', 'pro-dashboard-monitoring.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard&tab=iso27001`, 'dashboard', 'pro-dashboard-iso27001.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard&tab=soc2`, 'dashboard', 'pro-dashboard-soc2.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard&tab=security_training`, 'dashboard', 'pro-dashboard-security-training.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-dashboard&tab=security_monitor`, 'dashboard', 'pro-dashboard-security-monitor.png');

  // ─────────────────────────────────────────────────────────
  // Pro Toolkits
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Pro Toolkits ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-eca-dashboard`, 'dashboard', 'eca-dashboard.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-schedule-manager`, 'dashboard', 'schedule-manager.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-agent-command-center`, 'dashboard', 'agent-command-center.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-toolkit-settings`, 'dashboard', 'toolkit-settings.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-toolkit-mcp-servers`, 'dashboard', 'toolkit-mcp-servers.png');

  // ─────────────────────────────────────────────────────────
  // Pro Research / Consolidate pages (sample)
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Pro Research Pages ===');

  const proResearchPages = [
    'wp-mcp-ai-company-research',
    'wp-mcp-ai-deal-research',
    'wp-mcp-ai-event-research',
    'wp-mcp-ai-comic-research',
    'wp-mcp-ai-document-template-research',
    'wp-mcp-ai-architectural-project-research',
    'wp-mcp-ai-architectural-drawing-research',
    'wp-mcp-ai-architectural-specification-research',
    'wp-mcp-ai-calendar-booking-research',
    'wp-mcp-ai-cre-debt-research',
    'wp-mcp-ai-financial-account-research',
    'wp-mcp-ai-eca-research',
  ];
  for (const p of proResearchPages) {
    await screenshot(page, `${ADMIN}/admin.php?page=${p}`, 'dashboard', `${p.replace('wp-mcp-ai-', '')}.png`);
  }

  // ─────────────────────────────────────────────────────────
  // Pro Consolidate pages
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Pro Consolidate Pages ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-event-consolidate`, 'dashboard', 'event-consolidate.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-comic-consolidate`, 'dashboard', 'comic-consolidate.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-health-records-consolidate`, 'dashboard', 'health-records-consolidate.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-cre-debt-dashboard`, 'dashboard', 'cre-debt-dashboard.png');
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-health-wellness-dashboard`, 'dashboard', 'health-wellness-dashboard.png');

  // ─────────────────────────────────────────────────────────
  // Tools Manager (detailed views)
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Tools Manager Detail ===');

  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-tools-manager`, 'tools', 'tools-manager.png', { wait: 1500 });
  await screenshot(page, `${ADMIN}/admin.php?page=wp-mcp-ai-tool-presets`, 'tools', 'tool-presets.png');

  // ─────────────────────────────────────────────────────────
  // WordPress core pages with plugin context
  // ─────────────────────────────────────────────────────────
  console.log('\n=== WordPress Core ===');

  await screenshot(page, `${ADMIN}/`, 'admin', 'wp-dashboard.png');
  await screenshot(page, `${ADMIN}/plugins.php`, 'admin', 'plugins-list.png');

  // ─────────────────────────────────────────────────────────
  // Frontend (shortcode page if exists, or homepage)
  // ─────────────────────────────────────────────────────────
  console.log('\n=== Frontend ===');

  // Try to find a page with the chat shortcode
  try {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle', timeout: 10000 });
    await page.screenshot({ path: out('admin', 'frontend-homepage.png'), fullPage: true });
  } catch (e) {
    console.log('  SKIP frontend: ' + e.message.slice(0, 60));
  }

  console.log('\nDone! Screenshots saved to docs/screenshots/');
  await browser.close();
})();
