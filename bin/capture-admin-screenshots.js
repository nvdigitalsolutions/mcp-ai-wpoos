#!/usr/bin/env node
/**
 * NV oOS Admin Screenshot Capture (Base + Pro)
 *
 * Captures every admin page in the full plugin.
 * Pro pages need networkidle + 3s wait for JS rendering.
 *
 * Usage:  node bin/capture-admin-screenshots.js
 * Prereq: docker compose up -d
 * Output: docs/screenshots/admin/ + docs/screenshots/dashboard/
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = 'http://localhost:8000';
const ADMIN = `${BASE}/wp-admin`;
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'password';
const OUT = path.resolve(__dirname, '..', 'docs', 'screenshots');

['admin', 'dashboard'].forEach(d =>
  fs.mkdirSync(path.join(OUT, d), { recursive: true })
);

function out(cat, f) { return path.join(OUT, cat, f); }

async function login(page) {
  await page.goto(`${ADMIN}/`, { waitUntil: 'networkidle', timeout: 60000 });
  if (page.url().includes('wp-login')) {
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASS);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 60000 });
  }
  await page.waitForTimeout(500);
}

async function ss(page, url, cat, file, pro = false) {
  console.log(`  [${cat}] ${file}`);
  try {
    if (pro) {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
      await page.waitForTimeout(3000);
    } else {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
      await page.waitForTimeout(800);
    }
    await page.screenshot({ path: out(cat, file), fullPage: true });
  } catch (e) { console.log(`    SKIP: ${e.message.slice(0, 80)}`); }
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 }, ignoreHTTPSErrors: true });

  console.log('Logging in...');
  await login(page);

  const slug = 'admin.php?page=wp-mcp-ai-dashboard';

  // ── Base Settings ──
  console.log('\n=== Settings ===');
  await ss(page, `${ADMIN}/${slug}`, 'admin', 'settings-general.png');
  await ss(page, `${ADMIN}/${slug}&tab=general`, 'admin', 'settings-general-tab.png');
  await ss(page, `${ADMIN}/${slug}&tab=ai_providers`, 'admin', 'settings-ai-providers.png');
  await ss(page, `${ADMIN}/${slug}&tab=authentication`, 'admin', 'settings-authentication.png');
  await ss(page, `${ADMIN}/${slug}&tab=tools_features`, 'admin', 'settings-tools-features.png');
  await ss(page, `${ADMIN}/${slug}&tab=tools_manager`, 'admin', 'settings-tools-manager.png');
  await ss(page, `${ADMIN}/${slug}&tab=security`, 'admin', 'settings-security.png');
  await ss(page, `${ADMIN}/${slug}&tab=advanced`, 'admin', 'settings-advanced.png');
  await ss(page, `${ADMIN}/${slug}&tab=orchestration`, 'admin', 'settings-orchestration.png');

  // ── Assistants, Professions, Teams ──
  console.log('\n=== Assistants ===');
  await ss(page, `${ADMIN}/edit.php?post_type=mcp_ai_assistant`, 'admin', 'assistants-list.png');
  await ss(page, `${ADMIN}/post-new.php?post_type=mcp_ai_assistant`, 'admin', 'assistants-create.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-build-assistant`, 'admin', 'assistants-build.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-assistant`, 'admin', 'assistants-test.png');

  console.log('\n=== Professions ===');
  await ss(page, `${ADMIN}/edit.php?post_type=mcp_ai_profession`, 'admin', 'professions-list.png');
  await ss(page, `${ADMIN}/post-new.php?post_type=mcp_ai_profession`, 'admin', 'professions-create.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-test-profession`, 'admin', 'professions-test.png');

  console.log('\n=== Teams ===');
  await ss(page, `${ADMIN}/edit.php?post_type=mcp_ai_team`, 'admin', 'teams-list.png');
  await ss(page, `${ADMIN}/post-new.php?post_type=mcp_ai_team`, 'admin', 'teams-create.png');

  // ── Management ──
  console.log('\n=== Management ===');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-token-manager`, 'admin', 'token-manager.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-cron-manager`, 'admin', 'cron-manager.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-remote-sites`, 'admin', 'remote-sites.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-crawl4ai-monitor`, 'admin', 'crawl4ai-monitor.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-mcp-diagnostic`, 'admin', 'mcp-diagnostic.png');

  // ── More Base Pages ──
  console.log('\n=== More Base ===');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-dlq-manager`, 'admin', 'dlq-manager.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-markup-telemetry`, 'admin', 'markup-telemetry.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-ext-cognition`, 'admin', 'ext-cognition.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-skill-manager`, 'admin', 'skill-manager.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-skill-settings`, 'admin', 'skill-settings.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-getting-started`, 'admin', 'getting-started.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-settings`, 'admin', 'settings-legacy.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-simple-settings`, 'admin', 'simple-settings.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-plugins`, 'admin', 'plugins-integration.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-workflow-editor`, 'admin', 'workflow-editor.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-mesh-settings`, 'admin', 'mesh-settings.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-content-assistant`, 'admin', 'content-assistant.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-diagnostics`, 'admin', 'diagnostics.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-hf-datasets`, 'admin', 'hf-datasets.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-onboarding`, 'admin', 'onboarding-wizard.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-system-status`, 'admin', 'system-status.png');

  // ── Pro Dashboard (JS-heavy, needs extra wait) ──
  console.log('\n=== Pro Dashboard ===');
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard`, 'dashboard', 'pro-dashboard-overview.png', true);

  // Pro Dashboard sub-tabs
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard&tab=overview`, 'dashboard', 'pro-dashboard-overview-tab.png', true);
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard&tab=analytics`, 'dashboard', 'pro-dashboard-analytics.png', true);
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard&tab=monitoring`, 'dashboard', 'pro-dashboard-monitoring.png', true);

  // ── Pro Settings ──
  console.log('\n=== Pro Settings ===');
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-settings`, 'dashboard', 'pro-settings.png', true);

  // ── Pro Diagnostics ──
  console.log('\n=== Pro Diagnostics ===');
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard-diagnostic`, 'dashboard', 'pro-dashboard-diagnostic.png', true);

  // ── Pro Security Audits ──
  console.log('\n=== Pro Security ===');
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard-audits`, 'dashboard', 'security-audits.png', true);
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard-training`, 'dashboard', 'security-training.png', true);
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard-suppliers`, 'dashboard', 'security-suppliers.png', true);
  await ss(page, `${ADMIN}/admin.php?page=nvoos-pro-dashboard-assets`, 'dashboard', 'asset-inventory.png', true);

  // ── Pro Toolkits ──
  console.log('\n=== Pro Toolkits ===');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-toolkit-mcp-servers`, 'dashboard', 'toolkit-mcp-servers.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-toolkit-settings`, 'dashboard', 'toolkit-settings.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-eca-dashboard`, 'dashboard', 'eca-dashboard.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-schedule-manager`, 'dashboard', 'schedule-manager.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-agent-command-center`, 'dashboard', 'agent-command-center.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-orchestration`, 'dashboard', 'orchestration-monitor.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-webhook-status`, 'dashboard', 'webhook-status.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-blueprints`, 'dashboard', 'blueprints.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-pro-workflow-builder`, 'dashboard', 'workflow-builder.png', true);

  // ── Pro Research Pages ──
  console.log('\n=== Pro Research ===');
  const researchPages = [
    'wp-mcp-ai-company-research', 'wp-mcp-ai-deal-research', 'wp-mcp-ai-event-research',
    'wp-mcp-ai-comic-research', 'wp-mcp-ai-document-template-research',
    'wp-mcp-ai-architectural-project-research', 'wp-mcp-ai-architectural-drawing-research',
    'wp-mcp-ai-architectural-specification-research', 'wp-mcp-ai-calendar-booking-research',
    'wp-mcp-ai-cre-debt-research', 'wp-mcp-ai-financial-account-research', 'wp-mcp-ai-eca-research',
    'wp-mcp-ai-schedule-research',
  ];
  for (const p of researchPages) {
    await ss(page, `${ADMIN}/admin.php?page=${p}`, 'dashboard', p.replace('wp-mcp-ai-', '') + '.png', true);
  }

  // ── Pro Consolidate Pages ──
  console.log('\n=== Pro Consolidate ===');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-event-consolidate`, 'dashboard', 'event-consolidate.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-comic-consolidate`, 'dashboard', 'comic-consolidate.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-health-records-consolidate`, 'dashboard', 'health-records-consolidate.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-cre-debt-dashboard`, 'dashboard', 'cre-debt-dashboard.png', true);
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-health-wellness-dashboard`, 'dashboard', 'health-wellness-dashboard.png', true);

  // ── Other Pro pages ──
  console.log('\n=== Pro Misc ===');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-content-assistant`, 'admin', 'content-assistant.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-diagnostics`, 'admin', 'diagnostics.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-system-status`, 'admin', 'system-status.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-tools-manager`, 'admin', 'tools-manager.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-tool-presets`, 'admin', 'tool-presets.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-hf-datasets`, 'admin', 'hf-datasets.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-onboarding`, 'admin', 'onboarding-wizard.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-measurement`, 'admin', 'measurement-dashboard.png');
  await ss(page, `${ADMIN}/admin.php?page=wp-mcp-ai-mesh-settings`, 'admin', 'mesh-settings.png');

  // ── Core WordPress ──
  console.log('\n=== Core ===');
  await ss(page, `${ADMIN}/`, 'admin', 'wp-dashboard.png');
  await ss(page, `${ADMIN}/plugins.php`, 'admin', 'plugins-list.png');

  // ── Frontend ──
  console.log('\n=== Frontend ===');
  try {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle', timeout: 10000 });
    await page.screenshot({ path: out('admin', 'frontend-homepage.png'), fullPage: true });
  } catch (e) { console.log('  SKIP frontend'); }

  console.log('\nDone!');
  await browser.close();
})();
