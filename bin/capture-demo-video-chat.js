#!/usr/bin/env node
/**
 * NV oOS Demo Video — Chat Conversation with Streaming Response
 *
 * Demonstrates:
 *   1. Navigating to the chat frontend page
 *   2. Sending a greeting message and seeing the streaming response
 *   3. Asking a follow-up that triggers tool execution (wp_post_search)
 *   4. Sending a capabilities question and reading the reply
 *   5. Scrolling through the full conversation
 *
 * Usage:   node bin/capture-demo-video-chat.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup)
 *          Or:   PAGE_ID=123 node bin/capture-demo-video-chat.js
 * Output:  docs/videos/base/chat-conversation.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const BASE_URL = VIDEO_CONFIG.baseUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'chat-conversation';
const CHAT_PAGE_SLUG = 'ai-chat-demo';

// Messages to send (keep them short so responses are fast)
const MESSAGES = [
	'Hello! What can you help me with today?',
	'Can you search my website for recent blog posts?',
	'What tools do you have access to?',
];

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Selector Helpers ──────────────────────────────────────────

/**
 * Try multiple selectors in order, returning the first match.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors
 * @returns {Promise<import('playwright').ElementHandle|null>}
 */
async function findElement(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) return el;
	}
	return null;
}

/**
 * Try multiple CSS selectors in order, clicking the first match.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors
 * @returns {Promise<boolean>}
 */
async function tryClick(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try {
				await el.click();
				return true;
			} catch {
				// not clickable — try next
			}
		}
	}
	return false;
}

// ── Chat-Specific Selectors ───────────────────────────────────

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

const TOOL_INDICATOR_SELECTORS = [
	'[data-testid="tool-execution"]',
	'.mcp-ai-tool-call',
	'.tool-execution-indicator',
	'[data-role="tool-call"]',
	'.tool-call',
	'.mcp-ai-tool-status',
];

// ── Setup: Ensure a chat page exists ──────────────────────────

/**
 * Returns a chat page ID. Uses PAGE_ID env var if set, otherwise
 * searches for an existing page or creates one via the REST API.
 *
 * @param {WPAdmin} admin
 * @param {import('playwright').Page} page
 * @returns {Promise<number>}
 */
async function ensureChatPage(admin, page) {
	// Shortcut: orchestrator already set PAGE_ID
	if (process.env.PAGE_ID) {
		const id = parseInt(process.env.PAGE_ID, 10);
		if (!isNaN(id) && id > 0) return id;
	}

	// Search existing pages
	const nonce = await admin.getRestNonce();
	const searchResp = await page.request.get(
		`/wp-json/wp/v2/pages?slug=${CHAT_PAGE_SLUG}&per_page=1`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);

	if (searchResp.status() === 200) {
		const pages = await searchResp.json();
		if (pages.length > 0) return pages[0].id;
	}

	// Create a new page
	console.log('    Creating chat demo page via REST API...');
	const createResp = await page.request.post('/wp-json/wp/v2/pages', {
		headers: {
			'X-WP-Nonce': nonce,
			'Content-Type': 'application/json',
		},
		data: {
			title: 'AI Chat Demo',
			slug: CHAT_PAGE_SLUG,
			content: '[mcp_ai_chat allow_guests="true"]',
			status: 'publish',
		},
	});

	if (createResp.status() !== 201) {
		const body = await createResp.text();
		throw new Error(`Failed to create chat page: ${createResp.status()} — ${body}`);
	}

	const pageData = await createResp.json();
	return pageData.id;
}

// ── Chat Interaction ──────────────────────────────────────────

/**
 * Type a message into the chat input and click send.
 *
 * @param {import('playwright').Page} page
 * @param {string} message
 * @returns {Promise<boolean>} True if the message was sent.
 */
async function sendChatMessage(page, message) {
	const input = await findElement(page, CHAT_INPUT_SELECTORS);
	if (!input) {
		console.warn('    ⚠️  Chat input not found — selector mismatch');
		return false;
	}

	// Click to focus, clear any existing text, then type
	await input.click();
	await page.waitForTimeout(200);
	await input.fill('');
	await input.fill(message);
	await page.waitForTimeout(400);

	// Try clicking send button first
	const sent = await tryClick(page, SEND_BUTTON_SELECTORS);
	if (!sent) {
		// Fallback: press Enter
		await input.press('Enter');
	}

	return true;
}

/**
 * Wait for an AI response to appear and finish streaming.
 * Detects stability by watching for text content to stop changing.
 *
 * @param {import('playwright').Page} page
 * @param {number} timeoutMs - Max time to wait for the FIRST response token.
 * @returns {Promise<boolean>} True if a response was detected.
 */
async function waitForStreamingResponse(page, timeoutMs = 20000) {
	const startTime = Date.now();

	// Phase 1: Wait for any response element to appear
	let responseEl = null;
	while (Date.now() - startTime < timeoutMs) {
		responseEl = await findElement(page, RESPONSE_SELECTORS);
		if (responseEl) break;
		await page.waitForTimeout(500);
	}

	if (!responseEl) {
		console.warn('    ⚠️  No response element appeared within timeout');
		return false;
	}

	// Phase 2: Wait for streaming to finish (text stabilizes)
	let prevText = '';
	let stableCount = 0;
	const maxStableWait = timeoutMs + 30000; // extra time for long responses

	while (stableCount < 3 && Date.now() - startTime < maxStableWait) {
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
			// Element may have been removed/replaced
			responseEl = await findElement(page, RESPONSE_SELECTORS);
			if (!responseEl) break;
		}
	}

	return true;
}

/**
 * Check if a tool execution indicator is visible.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<boolean>}
 */
async function toolExecutionVisible(page) {
	const el = await findElement(page, TOOL_INDICATOR_SELECTORS);
	return el !== null;
}

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Chat Conversation\n');

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
		// 1. Setup: login + ensure chat page exists
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Setup: Login and prepare chat page');
		await admin.login();
		await page.waitForTimeout(PAUSE.SHORT);

		const chatPageId = await ensureChatPage(admin, page);
		console.log(`    ✅ Chat page ready (ID: ${chatPageId})`);

		// ═══════════════════════════════════════════════════════
		// 2. Navigate to the chat page
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Navigate to chat page');
		const chatUrl = `${BASE_URL}/?page_id=${chatPageId}`;
		await page.goto(chatUrl, { waitUntil: 'networkidle', timeout: 30000 });
		await page.waitForTimeout(PAUSE.LONG);

		// Wait for chat UI to render (React hydration)
		console.log('    Waiting for chat UI to render...');
		const inputReady = await findElement(page, CHAT_INPUT_SELECTORS);
		if (inputReady) {
			console.log('    ✅ Chat UI rendered');
		} else {
			// Wait a bit more for React to mount
			await page.waitForTimeout(PAUSE.LONG);
		}
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 3. Message 1: Greeting → streaming response
		// ═══════════════════════════════════════════════════════
		console.log(`  ▶ Message 1: "${MESSAGES[0]}"`);
		const sent1 = await sendChatMessage(page, MESSAGES[0]);

		if (sent1) {
			// Brief pause to capture the "sending" state in video
			await page.waitForTimeout(800);

			const gotResponse = await waitForStreamingResponse(page, 20000);
			if (gotResponse) {
				console.log('    ✅ Streaming response captured');
			} else {
				console.log('    ⚠️  No response — AI provider may not be configured');
			}
		}
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ═══════════════════════════════════════════════════════
		// 4. Message 2: Search request → tool execution
		// ═══════════════════════════════════════════════════════
		console.log(`  ▶ Message 2: "${MESSAGES[1]}"`);
		const sent2 = await sendChatMessage(page, MESSAGES[1]);

		if (sent2) {
			await page.waitForTimeout(1500);

			// Check for tool execution indicators
			const toolVisible = await toolExecutionVisible(page);
			if (toolVisible) {
				console.log('    ✅ Tool execution indicator visible');
			}

			// Wait for the response (may take longer with tool calls)
			await waitForStreamingResponse(page, 30000);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 5. Message 3: Capabilities question
		// ═══════════════════════════════════════════════════════
		console.log(`  ▶ Message 3: "${MESSAGES[2]}"`);
		const sent3 = await sendChatMessage(page, MESSAGES[2]);

		if (sent3) {
			await page.waitForTimeout(800);
			await waitForStreamingResponse(page, 20000);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 6. Show the full conversation
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Scrolling through conversation');

		// Scroll to top to show beginning
		await page.evaluate(() => window.scrollTo(0, 0));
		await page.waitForTimeout(PAUSE.MEDIUM);

		// Smooth scroll down through the conversation
		await page.evaluate(async () => {
			const scrollHeight = document.body.scrollHeight;
			const step = Math.max(1, Math.floor(scrollHeight / 15));
			for (let i = 0; i <= scrollHeight; i += step) {
				window.scrollTo(0, i);
				await new Promise((r) => setTimeout(r, 80));
			}
		});
		await page.waitForTimeout(PAUSE.MEDIUM);

		// End at the bottom so the last message is visible
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
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
