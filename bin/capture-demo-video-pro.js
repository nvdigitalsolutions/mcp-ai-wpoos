#!/usr/bin/env node
/**
 * NV oOS Demo Video — Pro Plugin Features
 *
 * Captures 8 Pro plugin feature videos in sequence. Each task maps to
 * a specific admin page. Pro pages are JS-heavy (React-rendered) and
 * receive extra wait time (networkidle + 3s).
 *
 * Tasks:
 *   1. Pro Dashboard Overview      (nvoos-pro-dashboard)
 *   2. Multi-Agent Orchestration   (wp-mcp-ai-pro-orchestration)
 *   3. Run Security Audit          (nvoos-pro-dashboard-audits)
 *   4. Site Creator                (admin.php?page=wp-mcp-ai-site-creator)
 *   5. Federation / Mesh Setup     (wp-mcp-ai-mesh-settings)
 *   6. Schedule Manager            (wp-mcp-ai-schedule-manager)
 *   7. Workflow Builder            (wp-mcp-ai-pro-workflow-builder)
 *   8. Blueprint System            (wp-mcp-ai-blueprints)
 *
 * Usage:   node bin/capture-demo-video-pro.js
 * Prereq:  docker compose up -d && addons/pro/ exists
 * Output:  docs/videos/pro/*.webm (one per task)
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const ADMIN_URL = VIDEO_CONFIG.adminUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'pro');

// Pro pages are JS-heavy — use longer waits
const PRO_WAIT = 3000; // extra wait after networkidle for React rendering

// ── Task definitions ──────────────────────────────────────────

const PRO_TASKS = [
	{
		file: 'pro-dashboard',
		label: 'Pro Dashboard Overview',
		url: 'nvoos-pro-dashboard',
		extraActions: null,
	},
	{
		file: 'orchestration-workflow',
		label: 'Multi-Agent Orchestration',
		url: 'wp-mcp-ai-pro-orchestration',
		extraActions: null,
	},
	{
		file: 'security-audit',
		label: 'Run Security Audit',
		url: 'nvoos-pro-dashboard-audits',
		extraActions: null,
	},
	{
		file: 'site-creator',
		label: 'Site Creator',
		url: 'wp-mcp-ai-site-creator',
		extraActions: null,
	},
	{
		file: 'federation-setup',
		label: 'Federation / Mesh Setup',
		url: 'wp-mcp-ai-mesh-settings',
		extraActions: null,
	},
	{
		file: 'schedule-manager',
		label: 'Schedule Manager',
		url: 'wp-mcp-ai-schedule-manager',
		extraActions: null,
	},
	{
		file: 'workflow-builder',
		label: 'Workflow Builder',
		url: 'wp-mcp-ai-pro-workflow-builder',
		extraActions: null,
	},
	{
		file: 'blueprints',
		label: 'Blueprint System',
		url: 'wp-mcp-ai-blueprints',
		extraActions: null,
	},
];

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Helpers ───────────────────────────────────────────────────

async function tryClick(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try { await el.click(); return true; } catch {}
		}
	}
	return false;
}

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Pro Plugin Features\n');

	const browser = await chromium.launch({ headless: true });

	// Use a single browser instance, create a fresh context per task
	// This way each task gets its own .webm file

	const adminSetupContext = await browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
	});
	const setupPage = await adminSetupContext.newPage();
	const admin = new WPAdmin(setupPage);

	try {
		// ── Login once ──
		console.log('  ▶ Login');
		await admin.login();
		await setupPage.waitForTimeout(PAUSE.SHORT);
	} finally {
		await adminSetupContext.close();
	}

	// ── Capture each Pro task in its own video context ──
	let succeeded = 0;
	let failed = 0;

	for (const task of PRO_TASKS) {
		console.log(`\n  ▶ ${task.label} (${task.file})`);

		const taskContext = await browser.newContext({
			viewport: VIDEO_CONFIG.viewport,
			recordVideo: { dir: OUT_DIR, size: VIDEO_CONFIG.size },
			// Reuse auth state by copying cookies from the setup context
			storageState: undefined,
		});

		const page = await taskContext.newPage();
		const taskAdmin = new WPAdmin(page);

		try {
			// Re-login in this context (fast — cookies from shared storage)
			await taskAdmin.login();
			await page.waitForTimeout(PAUSE.SHORT);

			// Handle both full admin.php paths and page-slug-only paths
			let pageUrl;
			if (task.url.startsWith('admin.php')) {
				pageUrl = `${ADMIN_URL}/${task.url}`;
			} else {
				pageUrl = `${ADMIN_URL}/admin.php?page=${task.url}`;
			}

			console.log(`    Navigating to ${pageUrl}`);
			await page.goto(pageUrl, { waitUntil: 'networkidle', timeout: 45000 });
			await page.waitForTimeout(PRO_WAIT); // extra time for React rendering

			// Run extra actions if defined
			if (task.extraActions) {
				await task.extraActions(page);
			}

			// Scroll through the page to show all content
			await page.evaluate(async () => {
				const scrollHeight = document.body.scrollHeight;
				const step = Math.max(1, Math.floor(scrollHeight / 10));
				for (let i = 0; i <= scrollHeight; i += step) {
					window.scrollTo(0, i);
					await new Promise((r) => setTimeout(r, 80));
				}
			});
			await page.waitForTimeout(PAUSE.MEDIUM);

			// Scroll back to top for clean ending
			await page.evaluate(() => window.scrollTo(0, 0));
			await page.waitForTimeout(PAUSE.LONG);

			console.log(`    ✅ ${task.file}.webm`);
			succeeded++;
		} catch (error) {
			console.warn(`    ⚠️  ${task.file} failed: ${error.message.slice(0, 100)}`);
			failed++;
		} finally {
			await taskContext.close(); // writes the .webm
		}
	}

	await browser.close();

	console.log(`\n✅ Pro videos complete: ${succeeded} succeeded, ${failed} failed`);
	console.log(`📁 Output: ${OUT_DIR}\n`);

})();
