#!/usr/bin/env node
/**
 * NV oOS Demo Video — Configure AI Provider
 *
 * Demonstrates:
 *   1. Navigating to the Settings → AI Providers tab
 *   2. Selecting an AI provider (OpenAI, Gemini, or Ollama)
 *   3. Entering an API key (masked for security in video)
 *   4. Choosing a default model from the dropdown
 *   5. Testing the connection
 *   6. Saving configuration
 *
 * Usage:   node bin/capture-demo-video-provider.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup)
 * Output:  docs/videos/base/configure-ai-provider.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const ADMIN_URL = VIDEO_CONFIG.adminUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'configure-ai-provider';

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
	console.log('🎬 Starting video capture: Configure AI Provider\n');

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
		// 2. Navigate to Settings → AI Providers tab
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Navigate to Settings → AI Providers');
		await admin.goToAdminPage('wp-mcp-ai-dashboard', { tab: 'ai_providers' });
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 3. Show provider selection
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Provider selection');

		// Look for provider selector (radio buttons, dropdown, or card-based)
		const providerSelectors = [
			'select[name*="provider"]',
			'[data-testid="provider-select"]',
			'#wp_mcp_ai_default_provider',
			'.provider-selector select',
			'input[name*="default_provider"]',
			'.mcp-ai-provider-card',
		];

		const providerEl = await findElement(page, providerSelectors);
		if (providerEl) {
			// Try selecting OpenAI
			try {
				await providerEl.selectOption({ label: 'OpenAI' }).catch(() => {});
				await providerEl.selectOption({ value: 'openai' }).catch(() => {});
			} catch {}
			console.log('    ✅ Provider selector found');
		} else {
			// Maybe card-based — click the OpenAI card
			const clicked = await tryClick(page, [
				'.provider-card[data-provider="openai"]',
				'.mcp-ai-provider-card.openai',
				'[data-testid="provider-openai"]',
				'label:has-text("OpenAI")',
			]);
			console.log(`    ${clicked ? '✅' : '—'} Provider selected`);
		}
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 4. API Key field
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ API Key field');

		const apiKeySelectors = [
			'input[name*="openai_api_key" i]',
			'[data-testid="api-key-input"]',
			'#wp_mcp_ai_openai_api_key',
			'input[type="password"][name*="api"]',
			'input[name*="api_key"]',
		];

		const apiKeyField = await findElement(page, apiKeySelectors);
		if (apiKeyField) {
			// Use a placeholder value so real keys are never recorded
			await apiKeyField.click();
			await page.waitForTimeout(300);
			await apiKeyField.fill('sk-proj-●●●●●●●●●●●●●●●●●●●●●●●●●●');
			await page.waitForTimeout(PAUSE.SHORT);
			console.log('    ✅ API key field filled (placeholder)');
		} else {
			console.log('    — API key field not found (may be on a sub-tab)');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 5. Model selection
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Model selection');

		const modelSelectors = [
			'select[name*="model"]',
			'[data-testid="model-select"]',
			'#wp_mcp_ai_default_model',
			'select[name*="default_model"]',
		];

		const modelEl = await findElement(page, modelSelectors);
		if (modelEl) {
			try {
				await modelEl.selectOption({ label: 'GPT-4o' }).catch(() => {});
				await modelEl.selectOption({ label: 'gpt-4o' }).catch(() => {});
				await modelEl.selectOption({ value: 'gpt-4o' }).catch(() => {});
				// Fallback: pick first non-placeholder option
				const options = await modelEl.$$eval('option', (opts) =>
					opts.map((o) => o.value).filter((v) => v)
				);
				if (options.length > 0) {
					await modelEl.selectOption(options[0]);
				}
			} catch {}
			console.log('    ✅ Model selected');
		} else {
			console.log('    — Model selector not found');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 6. Test connection button
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Test connection');

		const testClicked = await tryClick(page, [
			'[data-testid="test-connection"]',
			'button:has-text("Test Connection")',
			'#test-connection',
			'.test-connection-button',
			'button.test-connection',
		]);

		if (testClicked) {
			console.log('    ✅ Test connection clicked');
			// Wait briefly to show the loading/spinner state
			await page.waitForTimeout(PAUSE.LONG);
		} else {
			console.log('    — Test connection button not found');
		}
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 7. Save settings
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Save settings');

		const saved = await tryClick(page, [
			'[data-testid="save-settings"]',
			'input[type="submit"]',
			'button[type="submit"]',
			'#submit',
			'button:has-text("Save")',
			'.button-primary',
		]);

		if (saved) {
			console.log('    ✅ Settings saved');
		} else {
			// Try clicking the first submit button on the page
			await tryClick(page, ['form input[type="submit"]', 'form button[type="submit"]']);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// Show success notice if visible
		const notice = await findElement(page, [
			'.notice-success',
			'.updated',
			'[data-testid="save-success"]',
			'.settings-saved',
		]);
		if (notice) {
			console.log('    ✅ Success notice visible');
		}
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
