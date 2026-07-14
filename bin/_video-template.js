#!/usr/bin/env node
/**
 * NV oOS Demo Video — Canonical Script Template
 *
 * COPY THIS FILE as a starting point for new video capture scripts.
 *
 * Usage:   cp bin/_video-template.js bin/capture-demo-video-my-feature.js
 *          # then edit VIDEO_NAME, OUT_DIR, and the interaction steps
 *
 * Prereq:  docker compose up -d
 * Output:  docs/videos/{base|pro}/{video-name}.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE, resolveOutputDir } = require('./video-helpers/video-utils');
// Template: uncomment these imports when customizing this script
// const { SELECTORS, tryClick, tryFill, findElement } = require('./utils/video-selectors');
const { injectCursor, removeCursor } = require('./video-helpers/cursor-utils');
// const { glideCursorTo } = require('./video-helpers/cursor-utils');
const { showIntroCard, showOutroCard } = require('./video-helpers/card-utils');
const { showChapter } = require('./video-helpers/annotation-utils');

// ── Configuration ─────────────────────────────────────────────
// TODO: Set these for your feature

const OUT_SUBDIR = 'base'; // 'base' or 'pro'
const VIDEO_NAME = 'my-feature'; // output filename (without extension)
const INTRO_TITLE = 'My Feature'; // shown on intro card
const INTRO_SUBTITLE = 'Brief user story or description.'; // shown on intro card
const INTRO_ICON = 'default'; // key from card-utils ICONS map
const IS_PRO = false; // true for pro features

// ── Output Setup ──────────────────────────────────────────────

const OUT_DIR = resolveOutputDir(__dirname, OUT_SUBDIR);
fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Main ──────────────────────────────────────────────────────

(async () => {
	console.log(`🎬 Starting video capture: ${INTRO_TITLE}\n`);

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
			title: INTRO_TITLE,
			subtitle: INTRO_SUBTITLE,
			icon: INTRO_ICON,
			isPro: IS_PRO,
			duration: 3000,
		});

		// ═══════════════════════════════════════════════════════
		// 1. Login
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Login');
		await admin.login();
		await injectCursor(page);
		await page.waitForTimeout(PAUSE.SHORT);

		// ═══════════════════════════════════════════════════════
		// 2. Navigate to feature page
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Navigate to feature');
		// TODO: Replace with actual navigation
		// await admin.goToAdminPage('your-page-slug');
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 3. Chapter: Main Interaction
		// ═══════════════════════════════════════════════════════
		await showChapter(page, {
			title: 'Using the Feature',
			duration: 2000,
		});

		// TODO: Add your interaction steps here
		// Example:
		// await glideCursorTo(page, SELECTORS.assistant.titleInput[0]);
		// await page.fill(SELECTORS.assistant.titleInput[0], 'Demo Value');
		// await page.waitForTimeout(PAUSE.MEDIUM);

		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 4. Verify result
		// ═══════════════════════════════════════════════════════
		console.log('  ▶ Verify');
		// TODO: Add verification step
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 5. Scroll through results
		// ═══════════════════════════════════════════════════════
		await page.evaluate(async () => {
			const scrollHeight = document.body.scrollHeight;
			const step = Math.max(1, Math.floor(scrollHeight / 12));
			for (let i = 0; i <= scrollHeight; i += step) {
				window.scrollTo(0, i);
				await new Promise((r) => setTimeout(r, 80));
			}
		});
		await page.waitForTimeout(PAUSE.LONG);

		// ═══════════════════════════════════════════════════════
		// 6. Outro Card
		// ═══════════════════════════════════════════════════════
		await showOutroCard(page);

		console.log(`\n✅ Video captured: ${path.join(OUT_DIR, VIDEO_NAME + '.webm')}\n`);

	} catch (error) {
		console.error(`\n❌ Error during video capture: ${error.message}`);
		console.error(error.stack);
	} finally {
		await removeCursor(page);
		await context.close(); // ← writes the .webm file
		await browser.close();
	}
})();
