#!/usr/bin/env node
/**
 * NV oOS Demo Video — Manage Tools & Presets
 *
 * Demonstrates:
 *   1. Navigating to the Tools Manager page
 *   2. Browsing tool categories (Posts, Users, WooCommerce, etc.)
 *   3. Searching for a specific tool by name
 *   4. Toggling tools on/off via checkboxes or switches
 *   5. Creating a tool preset and assigning tools to it
 *   6. Verifying the preset exists
 *
 * Usage:   node bin/capture-demo-video-tools-manager.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup)
 * Output:  docs/videos/base/manage-tools-presets.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const ADMIN_URL = VIDEO_CONFIG.adminUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'manage-tools-presets';

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

// ── Selectors ─────────────────────────────────────────────────

const CATEGORY_TAB_SELECTORS = [
	'.nav-tab-wrapper a',
	'.nav-tab',
	'[data-testid="category-tab"]',
	'.mcp-ai-tool-category',
	'.tool-category-filter button',
	'.components-tab-panel__tabs button',
	'.tool-categories a',
];

const TOOL_ROW_SELECTORS = [
	'.mcp-ai-tool-row',
	'[data-testid="tool-row"]',
	'tr[data-tool-slug]',
	'.tool-item',
	'.tool-list-item',
];

const TOGGLE_SELECTORS = [
	'input[type="checkbox"]',
	'.mcp-ai-toggle input',
	'[data-testid="tool-toggle"]',
	'.toggle-switch input',
	'.tool-enable-checkbox',
];

const SEARCH_SELECTORS = [
	'input[type="search"]',
	'input[placeholder*="search" i]',
	'input[placeholder*="Search"]',
	'#tool-search',
	'.mcp-ai-tool-search input',
	'.tool-filter-search input',
];

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Manage Tools & Presets\n');

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
		// 2. Navigate to Tools Manager
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Navigate to Tools Manager');
		await admin.goToAdminPage('wp-mcp-ai-tools-manager');
		await page.waitForTimeout(PAUSE.LONG); // JS-heavy page

		// Try alternative URL if the main one doesn't render
		const toolsVisible = await findElement(page, TOOL_ROW_SELECTORS);
		if (!toolsVisible) {
			console.log('    Trying alternative Tools Manager URL...');
			// Try the settings tab version
			await admin.goToAdminPage('wp-mcp-ai-dashboard', { tab: 'tools_manager' });
			await page.waitForTimeout(PAUSE.LONG);
		}

		const toolsLoaded = await findElement(page, TOOL_ROW_SELECTORS);
		console.log(`    ${toolsLoaded ? '✅' : '—'} Tools loaded`);

		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 3. Browse tool categories
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Browse tool categories');

		const categoryTabs = await page.$$(CATEGORY_TAB_SELECTORS.join(', '));
		if (categoryTabs.length > 0) {
			// Click the second category tab (first is often "All")
			const tabIndex = Math.min(1, categoryTabs.length - 1);
			try {
				await categoryTabs[tabIndex].click();
				await page.waitForTimeout(PAUSE.MEDIUM);
				console.log(`    ✅ Switched to category tab ${tabIndex + 1} of ${categoryTabs.length}`);
			} catch {
				console.log('    — Could not switch category');
			}

			// Click a third tab if available
			if (categoryTabs.length > 2) {
				try {
					await categoryTabs[2].click();
					await page.waitForTimeout(PAUSE.MEDIUM);
					console.log('    ✅ Switched to another category');
				} catch {}
			}
		} else {
			console.log('    — No category tabs found (scrolling page instead)');

			// If no explicit tabs, scroll to show different sections
			await page.evaluate(async () => {
				const scrollHeight = document.body.scrollHeight;
				for (let i = 0; i <= scrollHeight * 0.5; i += 200) {
					window.scrollTo(0, i);
					await new Promise((r) => setTimeout(r, 100));
				}
			});
			await page.waitForTimeout(PAUSE.MEDIUM);
		}

		// ═══════════════════════════════════════════════════════
		// 4. Search for a specific tool
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Search for tools');

		// Scroll back to top where search usually is
		await page.evaluate(() => window.scrollTo(0, 0));
		await page.waitForTimeout(PAUSE.SHORT);

		const searchFilled = await tryFill(page, SEARCH_SELECTORS, 'wp_post_search');
		if (searchFilled) {
			// Trigger the search (some UIs search on type, others need Enter)
			await page.keyboard.press('Enter');
			await page.waitForTimeout(PAUSE.LONG);
			console.log('    ✅ Searched for "wp_post_search"');
		} else {
			console.log('    — Search field not found');
		}

		// ═══════════════════════════════════════════════════════
		// 5. Toggle some tools on/off
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Toggle tools');

		// Clear search to see all tools again
		const searchClear = await findElement(page, [
			'.search-clear',
			'.mcp-ai-search-clear',
			'button[aria-label*="clear" i]',
		]);
		if (searchClear) {
			await searchClear.click();
		} else {
			// Clear the search field manually
			const searchField = await findElement(page, SEARCH_SELECTORS);
			if (searchField) {
				await searchField.fill('');
				await page.keyboard.press('Enter');
			}
		}
		await page.waitForTimeout(PAUSE.MEDIUM);

		// Find and toggle some checkboxes
		const toggles = await page.$$(TOGGLE_SELECTORS.join(', '));
		let toggled = 0;
		for (let i = 0; i < Math.min(5, toggles.length); i++) {
			try {
				const isChecked = await toggles[i].isChecked();
				if (!isChecked) {
					await toggles[i].check();
				} else {
					await toggles[i].uncheck();
				}
				toggled++;
				await page.waitForTimeout(200);
			} catch {
				// not interactable — skip
			}
		}
		console.log(`    ✅ Toggled ${toggled} tools`);

		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 6. Navigate to Tool Presets (if separate page exists)
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Tool Presets');

		// Try navigating to the presets page
		await admin.goToAdminPage('wp-mcp-ai-tool-presets');
		await page.waitForTimeout(PAUSE.LONG);

		// Check if we landed on a real presets page
		const presetElements = await findElement(page, [
			'[data-testid="preset-list"]',
			'.mcp-ai-preset',
			'.tool-preset-card',
			'form[action*="preset"]',
			'h1:has-text("Preset")',
			'h2:has-text("Preset")',
		]);

		if (presetElements) {
			console.log('    ✅ Presets page loaded');

			// Try to create a new preset if there's a form
			const presetNameFilled = await tryFill(page, [
				'input[name*="preset_name"]',
				'[data-testid="preset-name"]',
				'#preset-name',
			], 'Support Team Preset');

			if (presetNameFilled) {
				console.log('    ✅ Preset name filled');

				// Save the preset
				await tryClick(page, [
					'[data-testid="save-preset"]',
					'button:has-text("Save")',
					'input[type="submit"]',
					'.button-primary',
				]);
				await page.waitForTimeout(PAUSE.LONG);
			}
		} else {
			console.log('    — Presets page not found or empty');
		}

		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 7. Return to tools manager for closing shot
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Return to Tools Manager');
		await admin.goToAdminPage('wp-mcp-ai-tools-manager');
		await page.waitForTimeout(PAUSE.LONG);

		// Scroll through to show the full list
		await page.evaluate(() => window.scrollTo(0, 0));
		await page.waitForTimeout(PAUSE.SHORT);

		await page.evaluate(async () => {
			const scrollHeight = document.body.scrollHeight;
			const step = Math.max(1, Math.floor(scrollHeight / 12));
			for (let i = 0; i <= scrollHeight; i += step) {
				window.scrollTo(0, i);
				await new Promise((r) => setTimeout(r, 80));
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
