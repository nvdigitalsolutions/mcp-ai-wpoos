#!/usr/bin/env node
/**
 * NV oOS Demo Video — Guest Mode Chat
 *
 * Demonstrates:
 *   1. Opening a guest-enabled chat page in a fresh (incognito) context
 *   2. Automatic guest token generation on first visit
 *   3. Sending messages as an anonymous guest
 *   4. Conversation history surviving a page reload (localStorage)
 *   5. Contrast with authenticated chat (optional side-by-side)
 *
 * Usage:   node bin/capture-demo-video-guest.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup)
 *          Or:   GUEST_PAGE_ID=123 node bin/capture-demo-video-guest.js
 * Output:  docs/videos/base/guest-mode-chat.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const BASE_URL = VIDEO_CONFIG.baseUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'guest-mode-chat';
const GUEST_PAGE_SLUG = 'ai-chat-demo-guest';

// Messages a guest might send
const GUEST_MESSAGES = [
	'Hi! I am a visitor. What can this AI assistant do?',
	'Do I need to create an account to use this?',
];

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

// ── Selectors ─────────────────────────────────────────────────

const CHAT_INPUT_SELECTORS = [
	'[data-testid="chat-input"]',
	'textarea[placeholder*="message" i]',
	'textarea[placeholder*="Message"]',
	'#mcp-ai-chat-input',
	'.mcp-ai-chat-input textarea',
	'.chat-input textarea',
	'.chat-input-container textarea',
	'[role="textbox"]',
];

const SEND_BUTTON_SELECTORS = [
	'[data-testid="send-button"]',
	'button[type="submit"]',
	'button[aria-label*="send" i]',
	'button[aria-label*="Send"]',
	'.mcp-ai-send-button',
	'.chat-submit button',
	'.send-message-button',
];

const RESPONSE_SELECTORS = [
	'[data-testid="assistant-message"]',
	'.mcp-ai-message-assistant',
	'.chat-message.assistant',
	'.assistant-message',
	'[data-role="assistant"]',
	'.message.assistant',
	'.mcp-ai-response',
];

const GUEST_INDICATOR_SELECTORS = [
	'[data-testid="guest-badge"]',
	'.guest-badge',
	'.guest-mode-indicator',
	'[data-role="guest"]',
	'.mcp-ai-guest-notice',
	'text="Guest"',
];

const LOGIN_PROMPT_SELECTORS = [
	'[data-testid="login-prompt"]',
	'.mcp-ai-login-prompt',
	'.guest-login-prompt',
	'a[href*="wp-login"]',
];

// ── Setup: Ensure a guest-enabled chat page exists ─────────────

async function ensureGuestPage(admin, page) {
	if (process.env.GUEST_PAGE_ID) {
		const id = parseInt(process.env.GUEST_PAGE_ID, 10);
		if (!isNaN(id) && id > 0) return id;
	}

	// Also check PAGE_ID (orchestrator uses same page for both)
	if (process.env.PAGE_ID) {
		const id = parseInt(process.env.PAGE_ID, 10);
		if (!isNaN(id) && id > 0) return id;
	}

	const nonce = await admin.getRestNonce();

	// Try the guest-specific slug first, then fall back to generic
	for (const slug of [GUEST_PAGE_SLUG, 'ai-chat-demo']) {
		const searchResp = await page.request.get(
			`${BASE_URL}/wp-json/wp/v2/pages?slug=${slug}&per_page=1`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);
		if (searchResp.status() === 200) {
			const pages = await searchResp.json();
			if (pages.length > 0) return pages[0].id;
		}
	}

	// Create a new guest-enabled page
	console.log('    Creating guest chat page via REST API...');
	const createResp = await page.request.post(`${BASE_URL}/wp-json/wp/v2/pages`, {
		headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		data: {
			title: 'AI Chat Demo (Guest)',
			slug: GUEST_PAGE_SLUG,
			content: '[mcp_ai_chat allow_guests="true"]',
			status: 'publish',
		},
	});

	if (createResp.status() !== 201) {
		const body = await createResp.text();
		throw new Error(`Failed to create guest page: ${createResp.status()} — ${body}`);
	}

	const pageData = await createResp.json();
	return pageData.id;
}

// ── Chat Interaction ──────────────────────────────────────────

async function sendChatMessage(page, message) {
	const input = await findElement(page, CHAT_INPUT_SELECTORS);
	if (!input) {
		console.warn('    ⚠️  Chat input not found');
		return false;
	}

	await input.click();
	await page.waitForTimeout(200);
	await input.fill('');
	await input.fill(message);
	await page.waitForTimeout(400);

	const sent = await tryClick(page, SEND_BUTTON_SELECTORS);
	if (!sent) {
		await input.press('Enter');
	}

	return true;
}

async function waitForResponse(page, timeoutMs = 25000) {
	const startTime = Date.now();
	let responseEl = null;

	while (Date.now() - startTime < timeoutMs) {
		responseEl = await findElement(page, RESPONSE_SELECTORS);
		if (responseEl) break;
		await page.waitForTimeout(500);
	}

	if (!responseEl) return false;

	// Wait for streaming to finish
	let prevText = '';
	let stableCount = 0;
	const deadline = startTime + timeoutMs + 15000;

	while (stableCount < 3 && Date.now() - startTime < deadline) {
		await page.waitForTimeout(1500);
		try {
			const currentText = await responseEl.textContent();
			if (currentText === prevText) {
				stableCount++;
			} else {
				stableCount = 0;
				prevText = currentText;
			}
		} catch {
			responseEl = await findElement(page, RESPONSE_SELECTORS);
			if (!responseEl) break;
		}
	}

	return true;
}

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Guest Mode Chat\n');

	// ═══════════════════════════════════════════════════════
	// Step 1: Admin login to set up the guest page
	// ═══════════════════════════════════════════════════════
	const setupBrowser = await chromium.launch({ headless: true });
	const setupContext = await setupBrowser.newContext({
		viewport: VIDEO_CONFIG.viewport,
	});
	const setupPage = await setupContext.newPage();
	const admin = new WPAdmin(setupPage);

	let guestPageId;
	try {
		console.log('  ▶ Setup: Login and prepare guest page');
		await admin.login();
		await setupPage.waitForTimeout(PAUSE.SHORT);
		guestPageId = await ensureGuestPage(admin, setupPage);
		console.log(`    ✅ Guest page ready (ID: ${guestPageId})`);
	} finally {
		await setupContext.close();
		await setupBrowser.close();
	}

	// ═══════════════════════════════════════════════════════
	// Step 2: Fresh incognito browser — simulate a real guest
	// ═══════════════════════════════════════════════════════
	console.log('  ▶ Launching guest browser (incognito)');

	const browser = await chromium.launch({ headless: true });
	const guestContext = await browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
		recordVideo: { dir: OUT_DIR, size: VIDEO_CONFIG.size },
		// No storage state — completely fresh, like a new visitor
	});

	const page = await guestContext.newPage();

	try {
		// ── Navigate to guest chat page ──
		console.log('  ▶ Navigate to guest chat page');
		const guestUrl = `${BASE_URL}/?page_id=${guestPageId}`;
		await page.goto(guestUrl, { waitUntil: 'networkidle', timeout: 30000 });
		await page.waitForTimeout(PAUSE.LONG);

		// Wait for chat UI to render
		const inputReady = await findElement(page, CHAT_INPUT_SELECTORS);
		if (!inputReady) {
			await page.waitForTimeout(PAUSE.LONG);
		}
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ── Check for guest indicators ──
		const guestIndicator = await findElement(page, GUEST_INDICATOR_SELECTORS);
		if (guestIndicator) {
			console.log('    ✅ Guest mode indicator visible');
		} else {
			console.log('    ℹ️  No explicit guest badge — chat may still work for guests');
		}

		// Check for login prompt (should NOT be present on a guest page)
		const loginPrompt = await findElement(page, LOGIN_PROMPT_SELECTORS);
		if (loginPrompt) {
			console.log('    ⚠️  Login prompt visible — guest mode may be disabled');
		}

		// ── Capture localStorage before chatting (should show guest token) ──
		const initialStorage = await page.evaluate(() => {
			const items = {};
			for (let i = 0; i < localStorage.length; i++) {
				const key = localStorage.key(i);
				items[key] = localStorage.getItem(key);
			}
			return items;
		});
		const hasInitialToken = Object.keys(initialStorage).some(
			(k) => k.includes('token') || k.includes('guest') || k.includes('mcp')
		);
		console.log(`    ${hasInitialToken ? '✅' : '—'} Guest token in localStorage`);

		// ─────────────────────────────────────────────────────
		// Guest Message 1
		// ─────────────────────────────────────────────────────
		console.log(`  ▶ Guest message 1: "${GUEST_MESSAGES[0]}"`);
		const sent1 = await sendChatMessage(page, GUEST_MESSAGES[0]);

		if (sent1) {
			await page.waitForTimeout(800);
			const gotResponse = await waitForResponse(page, 25000);
			console.log(`    ${gotResponse ? '✅' : '⚠️'} Response ${gotResponse ? 'received' : 'not detected'}`);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ─────────────────────────────────────────────────────
		// Guest Message 2
		// ─────────────────────────────────────────────────────
		console.log(`  ▶ Guest message 2: "${GUEST_MESSAGES[1]}"`);
		const sent2 = await sendChatMessage(page, GUEST_MESSAGES[1]);

		if (sent2) {
			await page.waitForTimeout(800);
			await waitForResponse(page, 25000);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ─────────────────────────────────────────────────────
		// Reload to show conversation persistence
		// ─────────────────────────────────────────────────────
		console.log('  ▶ Reloading page — testing localStorage persistence');
		await page.reload({ waitUntil: 'networkidle', timeout: 30000 });
		await page.waitForTimeout(PAUSE.LONG);

		// Check if conversation was restored
		const afterReload = await findElement(page, RESPONSE_SELECTORS);
		if (afterReload) {
			console.log('    ✅ Chat history restored from localStorage');
		} else {
			console.log('    — Chat history not restored (may need more messages)');
		}

		// Show localStorage in the video (scroll to show conversation if restored)
		await page.evaluate(() => window.scrollTo(0, 0));
		await page.waitForTimeout(PAUSE.MEDIUM);

		await page.evaluate(async () => {
			const scrollHeight = document.body.scrollHeight;
			const step = Math.max(1, Math.floor(scrollHeight / 10));
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
		await guestContext.close(); // ← writes the .webm file
		await browser.close();
	}
})();
