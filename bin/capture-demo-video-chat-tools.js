#!/usr/bin/env node
/**
 * NV oOS Demo Video — Chat with Tool Execution
 *
 * Demonstrates:
 *   1. Navigating to the chat frontend
 *   2. Sending a message that triggers wp_post_search tool
 *   3. Showing tool execution indicator during the call
 *   4. Displaying structured tool results in the conversation
 *   5. Sending a second tool-triggering message (different tool)
 *
 * Usage:   node bin/capture-demo-video-chat-tools.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup)
 *          Or:   PAGE_ID=123 node bin/capture-demo-video-chat-tools.js
 * Output:  docs/videos/base/chat-tool-execution.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');

// ── Configuration ─────────────────────────────────────────────

const BASE_URL = VIDEO_CONFIG.baseUrl;
const OUT_DIR = resolveOutputDir(__dirname, 'base');
const VIDEO_FILE = 'chat-tool-execution';
const CHAT_PAGE_SLUG = 'ai-chat-demo';

// Messages designed to trigger specific tools
const TOOL_MESSAGES = [
	{
		text: 'Search my website for recent blog posts about getting started.',
		tool: 'wp_post_search',
		wait: 30000,
	},
	{
		text: 'List all the pages on my website.',
		tool: 'wp_list_pages / wp_list_posts',
		wait: 25000,
	},
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

// ── Chat Selectors ────────────────────────────────────────────

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
	'[data-testid="tool-status"]',
	'.tool-result',
	'[data-testid="tool-result"]',
];

const THINKING_INDICATOR_SELECTORS = [
	'[data-testid="thinking-indicator"]',
	'.mcp-ai-thinking',
	'.thinking-indicator',
	'.typing-indicator',
	'[data-role="thinking"]',
	'.streaming-cursor',
	'.blinking-cursor',
];

// ── Setup ─────────────────────────────────────────────────────

async function ensureChatPage(admin, page) {
	if (process.env.PAGE_ID) {
		const id = parseInt(process.env.PAGE_ID, 10);
		if (!isNaN(id) && id > 0) return id;
	}

	const nonce = await admin.getRestNonce();
	const searchResp = await page.request.get(
		`${BASE_URL}/wp-json/wp/v2/pages?slug=${CHAT_PAGE_SLUG}&per_page=1`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);

	if (searchResp.status() === 200) {
		const pages = await searchResp.json();
		if (pages.length > 0) return pages[0].id;
	}

	console.log('    Creating chat demo page via REST API...');
	const createResp = await page.request.post(`${BASE_URL}/wp-json/wp/v2/pages`, {
		headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
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

/**
 * Wait for the full tool-execution lifecycle:
 *   thinking → tool-call indicator → tool result → final response
 *
 * @param {import('playwright').Page} page
 * @param {number} timeoutMs
 * @returns {Promise<{thinking: boolean, toolCall: boolean, toolResult: boolean, response: boolean}>}
 */
async function waitForToolExecution(page, timeoutMs = 35000) {
	const result = { thinking: false, toolCall: false, toolResult: false, response: false };
	const startTime = Date.now();

	// Phase 1: Look for thinking indicator (AI is processing)
	const thinkingEl = await findElement(page, THINKING_INDICATOR_SELECTORS);
	if (thinkingEl) {
		result.thinking = true;
		await page.waitForTimeout(1000); // capture the thinking state
	}

	// Phase 2: Wait for tool execution indicator
	let deadline = startTime + timeoutMs;
	while (Date.now() < deadline && !result.toolCall) {
		const toolEl = await findElement(page, TOOL_INDICATOR_SELECTORS);
		if (toolEl) {
			result.toolCall = true;
			await page.waitForTimeout(1500); // capture the tool call state
			break;
		}
		await page.waitForTimeout(500);
	}

	// Phase 3: Wait for tool result to appear (may replace or augment the indicator)
	deadline = startTime + timeoutMs + 10000;
	while (Date.now() < deadline && !result.toolResult) {
		const resultEl = await findElement(page, [
			'.tool-result',
			'[data-testid="tool-result"]',
			'.mcp-ai-tool-result',
			'[data-role="tool-result"]',
		]);
		if (resultEl) {
			result.toolResult = true;
			await page.waitForTimeout(PAUSE.MEDIUM);
			break;
		}
		await page.waitForTimeout(500);
	}

	// Phase 4: Wait for the final AI response to stabilize
	let responseEl = null;
	deadline = startTime + timeoutMs + 30000;
	while (Date.now() < deadline && !result.response) {
		responseEl = await findElement(page, RESPONSE_SELECTORS);
		if (responseEl) {
			// Wait for text to stabilize
			let prevText = '';
			let stableCount = 0;
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
			result.response = true;
			break;
		}
		await page.waitForTimeout(500);
	}

	return result;
}

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log('🎬 Starting video capture: Chat with Tool Execution\n');

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
		recordVideo: { dir: OUT_DIR, size: VIDEO_CONFIG.size },
	});

	const page = await context.newPage();
	const admin = new WPAdmin(page);

	try {
		// ── Setup ──
		console.log('  ▶ Setup: Login and prepare chat page');
		await admin.login();
		await page.waitForTimeout(PAUSE.SHORT);

		const chatPageId = await ensureChatPage(admin, page);
		console.log(`    ✅ Chat page ready (ID: ${chatPageId})`);

		// ── Navigate to chat ──
		console.log('  ▶ Navigate to chat page');
		const chatUrl = `${BASE_URL}/?page_id=${chatPageId}`;
		await page.goto(chatUrl, { waitUntil: 'networkidle', timeout: 30000 });
		await page.waitForTimeout(PAUSE.LONG);

		const inputReady = await findElement(page, CHAT_INPUT_SELECTORS);
		if (!inputReady) {
			await page.waitForTimeout(PAUSE.LONG);
		}
		console.log('    ✅ Chat UI ready');
		await page.waitForTimeout(PAUSE.MEDIUM);

		// ─────────────────────────────────────────────────────
		// Tool Message 1: Search posts
		// ─────────────────────────────────────────────────────
		const msg1 = TOOL_MESSAGES[0];
		console.log(`  ▶ Tool Request 1: "${msg1.text}"`);
		console.log(`    Expected tool: ${msg1.tool}`);

		const sent1 = await sendChatMessage(page, msg1.text);
		if (sent1) {
			await page.waitForTimeout(800);

			const result1 = await waitForToolExecution(page, msg1.wait);
			console.log(`    Thinking:  ${result1.thinking ? '✅' : '—'}`);
			console.log(`    Tool call: ${result1.toolCall ? '✅' : '—'}`);
			console.log(`    Tool res:  ${result1.toolResult ? '✅' : '—'}`);
			console.log(`    Response:  ${result1.response ? '✅' : '—'}`);

			if (!result1.response && !result1.toolCall) {
				console.log('    ⚠️  No tool execution detected — AI provider may not be configured');
			}
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ─────────────────────────────────────────────────────
		// Tool Message 2: List pages
		// ─────────────────────────────────────────────────────
		const msg2 = TOOL_MESSAGES[1];
		console.log(`  ▶ Tool Request 2: "${msg2.text}"`);
		console.log(`    Expected tool: ${msg2.tool}`);

		const sent2 = await sendChatMessage(page, msg2.text);
		if (sent2) {
			await page.waitForTimeout(800);

			const result2 = await waitForToolExecution(page, msg2.wait);
			console.log(`    Thinking:  ${result2.thinking ? '✅' : '—'}`);
			console.log(`    Tool call: ${result2.toolCall ? '✅' : '—'}`);
			console.log(`    Tool res:  ${result2.toolResult ? '✅' : '—'}`);
			console.log(`    Response:  ${result2.response ? '✅' : '—'}`);
		}
		await page.waitForTimeout(PAUSE.LONG);

		// ── Show the conversation ──
		console.log('  ▶ Scrolling through tool results');
		await page.evaluate(() => window.scrollTo(0, 0));
		await page.waitForTimeout(PAUSE.MEDIUM);

		await page.evaluate(async () => {
			const scrollHeight = document.body.scrollHeight;
			const step = Math.max(1, Math.floor(scrollHeight / 15));
			for (let i = 0; i <= scrollHeight; i += step) {
				window.scrollTo(0, i);
				await new Promise((r) => setTimeout(r, 80));
			}
		});
		await page.waitForTimeout(PAUSE.MEDIUM);

		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
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
