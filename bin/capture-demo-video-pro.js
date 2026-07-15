#!/usr/bin/env node
/**
 * NV oOS Demo Video — Pro Plugin Features (Phase 2)
 *
 * Captures 8 Pro plugin feature videos in sequence. Each task maps to
 * a specific admin page and includes interactive demonstrations.
 * Pro pages are JS-heavy (React-rendered) and receive extra wait time.
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
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');
const { SELECTORS, tryClick } = require('./utils/video-selectors');
const { injectCursor, removeCursor } = require('./video-helpers/cursor-utils');
const { showIntroCard, showOutroCard } = require('./video-helpers/card-utils');
const { showChapter } = require('./video-helpers/annotation-utils');

// ── Configuration ─────────────────────────────────────────────

const OUT_DIR = resolveOutputDir(__dirname, 'pro');
const PRO_WAIT = 3000;

// ── Icon mapping for intro cards ──
const ICONS = {
	'pro-dashboard': 'dashboard',
	'orchestration-workflow': 'orchestration',
	'security-audit': 'security',
	'site-creator': 'site',
	'federation-setup': 'federation',
	'schedule-manager': 'schedule',
	'workflow-builder': 'workflow',
	'blueprints': 'blueprint',
};

// ── Task definitions with interactions ────────────────────────

const PRO_TASKS = [
	{
		file: 'pro-dashboard',
		label: 'Pro Dashboard Overview',
		url: 'nvoos-pro-dashboard',
		subtitle: 'Analytics, token usage, and monitoring at a glance',
		extraActions: async (page) => {
			// Try switching between dashboard tabs
			const tabs = [SELECTORS.pro.dashboard.analyticsTab, SELECTORS.pro.dashboard.usageTab];
			for (const tab of tabs) {
				try {
					const el = await page.$(tab);
					if (el) { await el.click(); await page.waitForTimeout(PAUSE.MEDIUM); }
				} catch { /* skip */ }
			}
			// Hover over chart for tooltip
			try {
				await page.hover(SELECTORS.pro.dashboard.chartTokenUsage).catch(() => {});
				await page.waitForTimeout(PAUSE.MEDIUM);
			} catch { /* skip */ }
		},
	},
	{
		file: 'orchestration-workflow',
		label: 'Multi-Agent Orchestration',
		url: 'wp-mcp-ai-pro-orchestration',
		subtitle: 'Chain multiple AI agents for complex workflows',
		extraActions: async (page) => {
			await tryClick(page, [SELECTORS.pro.orchestration.createWorkflowButton]);
			await page.waitForTimeout(PAUSE.LONG);
		},
	},
	{
		file: 'security-audit',
		label: 'Run Security Audit',
		url: 'nvoos-pro-dashboard-audits',
		subtitle: 'Scan your site and get actionable security findings',
		extraActions: async (page) => {
			const clicked = await tryClick(page, [SELECTORS.pro.securityAudit.startButton]);
			if (clicked) {
				console.log('    Audit started, waiting for results...');
				try {
					await page.waitForSelector(SELECTORS.pro.securityAudit.resultsPanel, { timeout: 60000 });
				} catch { /* audit may take longer or element name differs */ }
			}
			await page.waitForTimeout(PAUSE.LONG);
		},
	},
	{
		file: 'site-creator',
		label: 'Site Creator',
		url: 'wp-mcp-ai-site-creator',
		subtitle: 'Generate a complete WordPress site from a template',
		extraActions: async (page) => {
			await page.waitForTimeout(PRO_WAIT);
			// Show template selection and deploy button if visible
			try {
				await page.waitForSelector('select, .template-card, [data-testid="template-select"]', { timeout: 5000 });
			} catch { /* page may load differently */ }
		},
	},
	{
		file: 'federation-setup',
		label: 'Federation / Mesh Setup',
		url: 'wp-mcp-ai-mesh-settings',
		subtitle: 'Connect remote sites for cross-site AI tool access',
		extraActions: async (page) => {
			await tryClick(page, [SELECTORS.pro.federation.addRemoteButton]);
			await page.waitForTimeout(PAUSE.LONG);
		},
	},
	{
		file: 'schedule-manager',
		label: 'Schedule Manager',
		url: 'wp-mcp-ai-schedule-manager',
		subtitle: 'Schedule recurring AI tasks automatically',
		extraActions: async (page) => {
			await tryClick(page, [SELECTORS.pro.scheduleManager.createScheduleButton]);
			await page.waitForTimeout(PAUSE.LONG);
		},
	},
	{
		file: 'workflow-builder',
		label: 'Workflow Builder',
		url: 'wp-mcp-ai-pro-workflow-builder',
		subtitle: 'Visually design multi-step AI pipelines',
		extraActions: async (page) => {
			await page.waitForTimeout(PRO_WAIT);
			// Scroll to show canvas area
			await page.evaluate(async () => {
				const scrollHeight = document.body.scrollHeight;
				for (let i = 0; i <= scrollHeight * 0.7; i += 200) {
					window.scrollTo(0, i);
					await new Promise((r) => setTimeout(r, 80));
				}
			});
		},
	},
	{
		file: 'blueprints',
		label: 'Blueprint System',
		url: 'wp-mcp-ai-blueprints',
		subtitle: 'Export and import complete assistant configurations',
		extraActions: async (page) => {
			await tryClick(page, [SELECTORS.pro.blueprints.exportButton, SELECTORS.pro.blueprints.importButton]);
			await page.waitForTimeout(PAUSE.LONG);
		},
	},
];

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Pro Plugin Features\n');

	const browser = await chromium.launch({ headless: true });

	// ── Login once ──
	const adminSetupContext = await browser.newContext({ viewport: VIDEO_CONFIG.viewport });
	const setupPage = await adminSetupContext.newPage();
	const admin = new WPAdmin(setupPage);

	try {
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
		});

		const page = await taskContext.newPage();
		const taskAdmin = new WPAdmin(page);

		try {
			// ── Login (fast — cookies from shared browser) ──
			await taskAdmin.login();
			await injectCursor(page);
			await page.waitForTimeout(PAUSE.SHORT);

			// ── Intro card ──
			await showIntroCard(page, {
				title: task.label,
				subtitle: task.subtitle || '',
				icon: ICONS[task.file] || 'default',
				isPro: true,
				duration: 2500,
			});

			// ── Chapter: Feature Overview ──
			await showChapter(page, {
				title: task.label,
				description: task.subtitle || '',
				duration: 2000,
			});

			// ── Navigate to page ──
			let pageUrl;
			if (task.url.startsWith('admin.php')) {
				pageUrl = `${VIDEO_CONFIG.adminUrl}/${task.url}`;
			} else {
				pageUrl = `${VIDEO_CONFIG.adminUrl}/admin.php?page=${task.url}`;
			}

			console.log(`    Navigating to ${pageUrl}`);
			await page.goto(pageUrl, { waitUntil: 'networkidle', timeout: 45000 });
			await page.waitForTimeout(PRO_WAIT);

			// ── Run interactions ──
			if (task.extraActions) {
				await task.extraActions(page);
			}

			// ── Scroll through the page ──
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
			await page.waitForTimeout(PAUSE.SHORT);

			// ── Outro card ──
			await showOutroCard(page);

			console.log(`    ✅ ${task.file}.webm`);
			succeeded++;
		} catch (error) {
			console.warn(`    ⚠️  ${task.file} failed: ${error.message.slice(0, 120)}`);
			failed++;
		} finally {
			await removeCursor(page);
			await taskContext.close(); // writes the .webm
		}
	}

	await browser.close();

	console.log(`\n✅ Pro videos complete: ${succeeded} succeeded, ${failed} failed`);
	console.log(`📁 Output: ${OUT_DIR}\n`);
})();
