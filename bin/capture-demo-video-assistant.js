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
 * Prereq:  docker compose up -d
 * Output:  docs/videos/base/add-assistant-tools.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');
const { SELECTORS, tryClick, tryFill } = require('./utils/video-selectors');
const { injectCursor, glideCursorTo, removeCursor } = require('./video-helpers/cursor-utils');
const { showIntroCard, showOutroCard } = require('./video-helpers/card-utils');
const { showChapter } = require('./video-helpers/annotation-utils');

// ── Configuration ─────────────────────────────────────────────

const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'add-assistant-tools';

const ASSISTANT_NAME = 'Demo Support Assistant';
const SYSTEM_PROMPT =
	'You are a friendly customer support assistant. ' +
	'Answer questions clearly and concisely. ' +
	'If you do not know the answer, say so honestly.';

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Add Assistant & Tools\n');

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
		recordVideo: { dir: OUT_DIR, size: VIDEO_CONFIG.size },
	});

	const page = await context.newPage();
	const admin = new WPAdmin(page);

	try {
		// ═══════════════════════════════════════════════════════
		// 0. Intro Card
		// ═══════════════════════════════════════════════════════
		await showIntroCard(page, {
			title: 'Add Assistant & Assign Tools',
			subtitle: 'Create an AI assistant and equip it with tools',
			icon: 'assistant',
		});

		// ═══════════════════════════════════════════════════════
		// 1. Login
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Login');
		await admin.login();
		await injectCursor(page);
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
		const clicked = await tryClick(page, SELECTORS.admin.addNewButton);

		if (!clicked) {
			await admin.goToPostTypeNew('mcp_ai_assistant');
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 4. Chapter: Fill Assistant Details
		// ═══════════════════════════════════════════════════════
		await showChapter(page, {
			title: 'Configure Your Assistant',
			description: 'Set the name, system prompt, and AI model',
			duration: 2500,
		});

		// ═══════════════════════════════════════════════════════
		// 5. Fill the title
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill assistant title');
		const titleSelector = SELECTORS.assistant.titleInput[0];
		await page.waitForSelector(titleSelector, { timeout: 10000 });
		await glideCursorTo(page, titleSelector);
		await page.fill(titleSelector, ASSISTANT_NAME);
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 6. Fill system prompt
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Fill system prompt');
		const filledPrompt = await tryFill(page, SELECTORS.assistant.systemPrompt, SYSTEM_PROMPT);

		if (filledPrompt) {
			console.log('    ✅ System prompt filled');
		} else {
			console.log('    ⚠️  System prompt field not found — continuing');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 7. Select model
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Select model');
		let modelSelected = false;
		for (const sel of SELECTORS.assistant.modelSelect) {
			const el = await page.$(sel);
			if (el) {
				try {
					await el.selectOption({ label: 'GPT-4o' }).catch(() => {});
					await el.selectOption({ label: 'gpt-4o' }).catch(() => {});
					await el.selectOption({ value: 'gpt-4o' }).catch(() => {});
					const options = await el.$$eval('option', (opts) =>
						opts.map((o) => o.value).filter((v) => v)
					);
					if (options.length > 0 && !modelSelected) {
						await el.selectOption(options[0]);
						modelSelected = true;
					}
					modelSelected = true;
					break;
				} catch { /* next */ }
			}
		}
		console.log(`    ${modelSelected ? '✅' : '⚠️'} Model ${modelSelected ? 'selected' : 'not found'}`);
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 8. Chapter: Assign Tools
		// ═══════════════════════════════════════════════════════
		await showChapter(page, {
			title: 'Assign Tools',
			description: 'Search and enable the tools your assistant will use',
			duration: 2500,
		});

		// ═══════════════════════════════════════════════════════
		// 9. Open Tools tab and search
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Assign tools');
		const toolsTabClicked = await tryClick(page, SELECTORS.assistant.toolsTab);

		if (toolsTabClicked) {
			await page.waitForTimeout(PAUSE.MEDIUM);

			const searchFilled = await tryFill(page, SELECTORS.assistant.toolSearchInput, 'wp_post');

			if (searchFilled) {
				console.log('    ✅ Searched for wp_post tools');
			}
			await page.waitForTimeout(PAUSE.MEDIUM);

			// Enable some tools
			const checkboxes = await page.$$(SELECTORS.assistant.toolCheckboxes.join(', '));
			let enabled = 0;
			for (let i = 0; i < Math.min(5, checkboxes.length); i++) {
				try {
					await checkboxes[i].check();
					enabled++;
					await page.waitForTimeout(200);
				} catch { /* skip */ }
			}
			console.log(`    ✅ Enabled ${enabled} tools`);
		} else {
			console.log('    ⚠️  Tools tab not found — continuing without tools');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 10. Publish
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Publish assistant');
		const published = await tryClick(page, SELECTORS.admin.publishButton);

		if (!published) {
			console.log('    ⚠️  Publish button not found — trying save draft');
			await tryClick(page, SELECTORS.admin.saveDraftButton);
		}
		await page.waitForTimeout(PAUSE.LONG);
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 11. Verify — return to list
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Verify assistant in list');
		await admin.goToPostTypeList('mcp_ai_assistant');
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 12. Outro Card
		// ═══════════════════════════════════════════════════════
		await showOutroCard(page);

		console.log(`\n✅ Video captured: ${path.join(OUT_DIR, VIDEO_FILE + '.webm')}\n`);

	} catch (error) {
		console.error(`\n❌ Error during video capture: ${error.message}`);
		console.error(error.stack);
	} finally {
		await removeCursor(page);
		await context.close(); // ← writes the .webm file
		await browser.close();
	}
})();
