#!/usr/bin/env node
/**
 * NV oOS Demo Video — Add Assistant & Assign Tools
 *
 * Demonstrates:
 *   1. Navigating to AI Assistants CPT list
 *   2. Creating a new assistant (title, description, system prompt, model)
 *   3. Assigning tools from the Tools panel / metabox
 *   4. Publishing the assistant
 *   5. Verifying the assistant appears in the list
 *
 * Usage:   node bin/capture-demo-video-assistant.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup only)
 * Output:  docs/videos/base/add-assistant-tools.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const ADMIN_URL = VIDEO_CONFIG.adminUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'add-assistant-tools';

const ASSISTANT_NAME = 'Demo Support Assistant';
const SYSTEM_PROMPT =
	'You are a friendly customer support assistant. ' +
	'Answer questions clearly and concisely. ' +
	'If you do not know the answer, say so honestly.';

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Helpers ───────────────────────────────────────────────────

/**
 * Find and click an element using multiple fallback selectors.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors - Ordered by preference.
 * @returns {Promise<boolean>} True if an element was clicked.
 */
async function tryClick(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try {
				await el.click();
				return true;
			} catch {
				// selector matched but element not clickable — try next
			}
		}
	}
	return false;
}

/**
 * Find and fill a text field using multiple fallback selectors.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors
 * @param {string} text
 * @returns {Promise<boolean>}
 */
async function tryFill(page, selectors, text) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try {
				await el.fill(text);
				return true;
			} catch {
				// not fillable — try next
			}
		}
	}
	return false;
}

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Add Assistant & Tools\n');

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
		recordVideo: {
			dir: OUT_DIR,
			size: VIDEO_CONFIG.size,
		},
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
		// 2. Navigate to Assistants list
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Navigate to Assistants list');
		await admin.goToPostTypeList('mcp_ai_assistant');
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 3. Click "Add New"
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Click Add New');
		const clicked = await tryClick(page, [
			'a.page-title-action',
			'.wrap a[href*="post-new"]',
			'a[href*="post-new.php?post_type=mcp_ai_assistant"]',
		]);

		if (!clicked) {
			// Fallback: navigate directly
			await admin.goToPostTypeNew('mcp_ai_assistant');
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 4. Fill the title
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill assistant title');
		await page.waitForSelector('#title', { timeout: 10000 });
		await page.fill('#title', ASSISTANT_NAME);
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 5. Fill description / content
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill description');
		const filledContent = await tryFill(page, [
			'#content',
			'.wp-block-post-content',
			'[data-testid="assistant-description"]',
			'textarea[name="post_content"]',
		], 'A helpful AI assistant for customer support demonstrations.');

		if (!filledContent) {
			console.log('    ⚠️  No content area found (Gutenberg may be active)');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 6. Fill system prompt (meta box or Gutenberg panel)
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill system prompt');
		const filledPrompt = await tryFill(page, [
			'[data-testid="system-prompt"]',
			'#mcp_ai_system_prompt',
			'textarea[name*="system_prompt"]',
			'textarea[name="_mcp_ai_system_prompt"]',
			'.mcp-ai-system-prompt textarea',
		], SYSTEM_PROMPT);

		if (filledPrompt) {
			console.log('    ✅ System prompt filled');
		} else {
			console.log('    ⚠️  System prompt field not found — continuing');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 7. Select model (if dropdown exists)
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Select model');
		const modelSelected = await (async () => {
			const selectors = [
				'select[name*="model"]',
				'[data-testid="model-select"]',
				'#mcp_ai_model',
				'select[name="_mcp_ai_model"]',
			];
			for (const sel of selectors) {
				const el = await page.$(sel);
				if (el) {
					try {
						// Try common model option values
						await el.selectOption({ label: 'GPT-4o' }).catch(() => {});
						await el.selectOption({ label: 'gpt-4o' }).catch(() => {});
						await el.selectOption({ value: 'gpt-4o' }).catch(() => {});
						// If none matched, select the second option (first is often placeholder)
						const options = await el.$$eval('option', (opts) =>
							opts.map((o) => o.value).filter((v) => v)
						);
						if (options.length > 0) {
							await el.selectOption(options[0]);
						}
						return true;
					} catch {
						// continue
					}
				}
			}
			return false;
		})();

		if (modelSelected) {
			console.log('    ✅ Model selected');
		} else {
			console.log('    ⚠️  Model select not found — continuing');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 8. Assign Tools
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Assign tools');

		// Try to find and click the Tools tab/panel
		const toolsTabClicked = await tryClick(page, [
			'[data-testid="tools-tab"]',
			'.nav-tab-wrapper a[href*="tools"]',
			'button:has-text("Tools")',
			'.mcp-ai-tools-tab',
			'a.nav-tab:has-text("Tools")',
			'.components-tab-panel__tabs button:has-text("Tools")',
		]);

		if (toolsTabClicked) {
			await page.waitForTimeout(PAUSE.MEDIUM);

			// Search for tools
			const searchFilled = await tryFill(page, [
				'input[type="search"]',
				'input[placeholder*="search" i]',
				'input[placeholder*="Search" i]',
				'.mcp-ai-tool-search input',
			], 'wp_post');

			if (searchFilled) {
				console.log('    ✅ Searched for wp_post tools');
			}
			await page.waitForTimeout(PAUSE.MEDIUM);

			// Enable some tools
			const checkboxes = await page.$$('input[type="checkbox"]:not(:checked)');
			let enabled = 0;
			for (let i = 0; i < Math.min(5, checkboxes.length); i++) {
				try {
					await checkboxes[i].check();
					enabled++;
					await page.waitForTimeout(200);
				} catch {
					// skip non-checkable checkboxes
				}
			}
			console.log(`    ✅ Enabled ${enabled} tools`);
			await page.waitForTimeout(PAUSE.SHORT);
		} else {
			console.log('    ⚠️  Tools tab not found — continuing without tools');
		}

		// ═══════════════════════════════════════════════════════
		// 9. Publish
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Publish assistant');
		const published = await tryClick(page, [
			'#publish',
			'button.editor-post-publish-button__button',
			'button.editor-post-publish-button',
			'[data-testid="publish-button"]',
			'input#publish',
		]);

		if (!published) {
			console.log('    ⚠️  Publish button not found — trying to save as draft');
			await tryClick(page, [
				'#save-post',
				'button.editor-post-save-draft',
				'input#save-post',
			]);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// Wait for any post-publish confirmation
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 10. Verify — return to list
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Verify assistant in list');
		await admin.goToPostTypeList('mcp_ai_assistant');
		await page.waitForTimeout(PAUSE.LONG);

		console.log(`\n✅ Video captured: ${path.join(OUT_DIR, VIDEO_FILE + '.webm')}\n`);

	} catch (error) {
		console.error(`\n❌ Error during video capture: ${error.message}`);
		console.error(error.stack);
	} finally {
		await context.close(); // ← writes the .webm file
		await browser.close();
	}
})();
