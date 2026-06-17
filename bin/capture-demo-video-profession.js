#!/usr/bin/env node
/**
 * NV oOS Demo Video — Create Profession Template
 *
 * Demonstrates:
 *   1. Navigating to Professions CPT list
 *   2. Opening the Add New Profession screen
 *   3. Filling profession metadata (name, description, icon)
 *   4. Writing a system prompt template
 *   5. Assigning tools and presets to the profession
 *   6. Publishing the profession
 *   7. Verifying it appears in the profession grid/list
 *
 * Usage:   node bin/capture-demo-video-profession.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup)
 * Output:  docs/videos/base/create-profession.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const ADMIN_URL = VIDEO_CONFIG.adminUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'create-profession';

const PROFESSION_NAME = 'Customer Support Agent';
const PROFESSION_DESC =
	'A profession template for creating customer support AI assistants. ' +
	'Handles common support queries, ticket routing, and knowledge base searches.';

const SYSTEM_PROMPT_TEMPLATE =
	'You are a {company_name} customer support agent. ' +
	'Your role is to help customers with product questions, ' +
	'troubleshooting, and account issues. ' +
	'Always be polite, patient, and solution-oriented. ' +
	'If you cannot resolve the issue, escalate to a human agent.';

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Selector Helpers ──────────────────────────────────────────

async function findElement(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) return el;
	}
	return null;
}

async function tryClick(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try { await el.click(); return true; } catch {}
		}
	}
	return false;
}

async function tryFill(page, selectors, text) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try { await el.fill(text); return true; } catch {}
		}
	}
	return false;
}

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Create Profession Template\n');

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
		recordVideo: { dir: OUT_DIR, size: VIDEO_CONFIG.size },
	});

	const page = await context.newPage();
	const admin = new WPAdmin(page);

	try {
		// ═══════════════════════════════════════════════════════
		// 1. Login
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Login');
		await admin.login();
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 2. Navigate to Professions list
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Navigate to Professions list');
		await admin.goToPostTypeList('mcp_ai_profession');
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 3. Click "Add New" or navigate directly
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Open Add New Profession');
		const clicked = await tryClick(page, [
			'a.page-title-action',
			'.wrap a[href*="post-new"]',
			'a[href*="post-new.php?post_type=mcp_ai_profession"]',
		]);

		if (!clicked) {
			await admin.goToPostTypeNew('mcp_ai_profession');
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 4. Fill profession title
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill profession title');
		await page.waitForSelector('#title', { timeout: 10000 });
		await page.fill('#title', PROFESSION_NAME);
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 5. Fill description
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill description');
		const descFilled = await tryFill(page, [
			'#content',
			'.wp-block-post-content',
			'[data-testid="profession-description"]',
			'textarea[name="post_content"]',
			'.block-editor-rich-text__editable',
		], PROFESSION_DESC);

		if (descFilled) {
			console.log('    ✅ Description filled');
		} else {
			console.log('    — Description field not found (Gutenberg active)');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 6. Fill system prompt template
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill system prompt template');
		const promptFilled = await tryFill(page, [
			'[data-testid="system-prompt-template"]',
			'textarea[name*="system_prompt"]',
			'textarea[name*="prompt_template"]',
			'#mcp_ai_system_prompt',
			'.mcp-ai-system-prompt textarea',
			'.profession-prompt textarea',
		], SYSTEM_PROMPT_TEMPLATE);

		if (promptFilled) {
			console.log('    ✅ System prompt template filled');
		} else {
			console.log('    — System prompt field not found — continuing');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 7. Assign tools to the profession
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Assign tools');

		const toolsTabClicked = await tryClick(page, [
			'[data-testid="tools-tab"]',
			'.nav-tab-wrapper a[href*="tools"]',
			'button:has-text("Tools")',
			'.mcp-ai-tools-tab',
			'a.nav-tab:has-text("Tools")',
		]);

		if (toolsTabClicked) {
			await page.waitForTimeout(PAUSE.MEDIUM);

			// Search for support-related tools
			const searchFilled = await tryFill(page, [
				'input[type="search"]',
				'input[placeholder*="search" i]',
				'input[placeholder*="Search"]',
				'.mcp-ai-tool-search input',
			], 'support');

			if (searchFilled) {
				console.log('    ✅ Searched for support tools');
			}
			await page.waitForTimeout(PAUSE.MEDIUM);

			// Enable relevant tools
			const checkboxes = await page.$$('input[type="checkbox"]:not(:checked)');
			let enabled = 0;
			for (let i = 0; i < Math.min(5, checkboxes.length); i++) {
				try {
					await checkboxes[i].check();
					enabled++;
					await page.waitForTimeout(200);
				} catch {}
			}
			console.log(`    ✅ Enabled ${enabled} tools`);
		} else {
			console.log('    — Tools tab not found');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 8. Select model (if available)
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Select default model');
		const modelSelectors = [
			'select[name*="model"]',
			'[data-testid="model-select"]',
			'#mcp_ai_model',
			'select[name="_mcp_ai_model"]',
		];

		for (const sel of modelSelectors) {
			const el = await page.$(sel);
			if (el) {
				try {
					await el.selectOption({ label: 'GPT-4o' }).catch(() => {});
					await el.selectOption({ value: 'gpt-4o' }).catch(() => {});
					const options = await el.$$eval('option', (opts) =>
						opts.map((o) => o.value).filter((v) => v)
					);
					if (options.length > 0) {
						await el.selectOption(options[0]);
					}
					console.log('    ✅ Model selected');
				} catch {
					console.log('    — Model selection failed');
				}
				break;
			}
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 9. Publish
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Publish profession');
		const published = await tryClick(page, [
			'#publish',
			'button.editor-post-publish-button__button',
			'button.editor-post-publish-button',
			'[data-testid="publish-button"]',
			'input#publish',
		]);

		if (!published) {
			await tryClick(page, [
				'#save-post',
				'button.editor-post-save-draft',
				'input#save-post',
			]);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// Wait for publish confirmation
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 10. Verify in the profession list/grid
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Verify profession in list');
		await admin.goToPostTypeList('mcp_ai_profession');
		await page.waitForTimeout(PAUSE.LONG);

		// Scroll through the list/grid to show the new profession
		await page.evaluate(async () => {
			const scrollHeight = document.body.scrollHeight;
			const step = Math.max(1, Math.floor(scrollHeight / 8));
			for (let i = 0; i <= scrollHeight; i += step) {
				window.scrollTo(0, i);
				await new Promise((r) => setTimeout(r, 100));
			}
		});
		await page.waitForTimeout(PAUSE.LONG);

		console.log(`\n✅ Video captured: ${path.join(OUT_DIR, VIDEO_FILE + '.webm')}\n`);

	} catch (error) {
		console.error(`\n❌ Error during video capture: ${error.message}`);
		console.error(error.stack);
	} finally {
		await context.close();
		await browser.close();
	}
})();
