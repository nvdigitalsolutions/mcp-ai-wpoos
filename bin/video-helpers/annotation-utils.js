/**
 * Screencast and annotation helpers for demo video scripts.
 *
 * Uses Playwright v1.59+ page.screencast API when available, with
 * graceful fallbacks to DOM injection for older Playwright versions.
 *
 * Playwright v1.59 screencast API reference:
 *   https://playwright.dev/docs/api/class-page#page-screencast
 *   https://testdino.com/blog/playwright-screencast
 */

/**
 * Check whether the current Playwright version supports page.screencast (v1.59+).
 *
 * @param {import('playwright').Page} page
 * @returns {boolean}
 */
function supportsScreencast(page) {
	return typeof page.screencast !== 'undefined';
}

/**
 * Start an annotated video recording.
 * Uses screencast API if available; otherwise relies on recordVideo config.
 *
 * @param {import('playwright').Page} page
 * @param {object} options
 * @param {string} [options.path] - Output file path (screencast API only).
 * @param {{width: number, height: number}} [options.size]
 * @param {boolean} [options.annotateActions=true] - Show action annotations.
 * @returns {Promise<void>}
 */
async function startAnnotatedRecording(page, options = {}) {
	if (!supportsScreencast(page)) return;

	const screencastOpts = {};
	if (options.path) screencastOpts.path = options.path;
	if (options.size) screencastOpts.size = options.size;

	await page.screencast.start(screencastOpts);

	if (options.annotateActions !== false) {
		await page.screencast.showActions({
			position: 'top-right',
			duration: 800,
			fontSize: 16,
		});
	}
}

/**
 * Stop the screencast recording.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<void>}
 */
async function stopAnnotatedRecording(page) {
	if (!supportsScreencast(page)) return;
	await page.screencast.stop();
}

/**
 * Show a chapter marker overlay.
 * Uses screencast API if available; falls back to DOM injection.
 *
 * @param {import('playwright').Page} page
 * @param {object} options
 * @param {string} options.title - Chapter title (e.g., "Step 2: Assign Tools").
 * @param {string} [options.description] - Optional subtitle.
 * @param {number} [options.duration=2500] - Display duration in ms.
 * @returns {Promise<void>}
 */
async function showChapter(page, { title, description = '', duration = 2500 }) {
	if (supportsScreencast(page)) {
		await page.screencast.showChapter(title, { description, duration });
		return;
	}

	// Fallback: DOM injection
	await page.evaluate(({ title, description }) => {
		const overlay = document.createElement('div');
		overlay.id = '__demo_chapter';
		overlay.setAttribute('aria-hidden', 'true');
		overlay.style.cssText = [
			'position: fixed', 'top: 0', 'left: 0', 'right: 0', 'bottom: 0',
			'display: flex', 'flex-direction: column', 'align-items: center', 'justify-content: center',
			'z-index: 999998', 'background: rgba(0,0,0,0.6)', 'backdrop-filter: blur(4px)',
			'color: #fff', 'font-family: -apple-system, BlinkMacSystemFont, sans-serif',
			'transition: opacity 0.3s ease',
		].join(';');

		const h2 = document.createElement('h2');
		h2.textContent = title;
		h2.style.cssText = 'font-size:2rem;font-weight:700;margin:0 0 0.5rem;text-align:center;padding:0 2rem;';

		const p = document.createElement('p');
		p.textContent = description;
		p.style.cssText = 'font-size:1.2rem;color:rgba(255,255,255,0.75);margin:0;text-align:center;padding:0 2rem;';

		overlay.appendChild(h2);
		if (description) overlay.appendChild(p);
		document.body.appendChild(overlay);
	}, { title, description });

	await page.waitForTimeout(duration);

	await page.evaluate(() => {
		const overlay = document.getElementById('__demo_chapter');
		if (overlay) overlay.remove();
	});
}

/**
 * Show an HTML overlay (custom content).
 *
 * @param {import('playwright').Page} page
 * @param {string} html - Raw HTML content.
 * @param {number} [duration=3000] - Display duration in ms.
 * @returns {Promise<void>}
 */
async function showOverlay(page, html, duration = 3000) {
	if (supportsScreencast(page)) {
		const indicator = await page.screencast.showOverlay(html);
		await page.waitForTimeout(duration);
		await indicator.dispose();
		return;
	}

	// Fallback
	await page.evaluate((htmlContent) => {
		const wrapper = document.createElement('div');
		wrapper.id = '__demo_overlay';
		wrapper.innerHTML = htmlContent;
		wrapper.style.cssText = 'position:fixed;top:0;left:0;z-index:999998;';
		document.body.appendChild(wrapper);
	}, html);

	await page.waitForTimeout(duration);

	await page.evaluate(() => {
		const el = document.getElementById('__demo_overlay');
		if (el) el.remove();
	});
}

/**
 * Hide all screencast overlays at once.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<void>}
 */
async function hideAllOverlays(page) {
	if (supportsScreencast(page)) {
		await page.screencast.hideOverlays();
		return;
	}

	await page.evaluate(() => {
		for (const id of ['__demo_chapter', '__demo_overlay', '__demo_cursor']) {
			const el = document.getElementById(id);
			if (el) el.remove();
		}
	});
}

module.exports = {
	supportsScreencast,
	startAnnotatedRecording,
	stopAnnotatedRecording,
	showChapter,
	showOverlay,
	hideAllOverlays,
};
