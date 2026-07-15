/**
 * Fake mouse cursor injection for headless Playwright recordings.
 *
 * Headless Chromium does not render a real cursor. This utility injects
 * a visible CSS cursor element and provides helpers to move it to target
 * elements, mimicking realistic mouse movement for demo videos.
 *
 * Based on the pattern by Justin Abrahms (Feb 2026):
 *   https://justin.abrah.ms/blog/2026-02-12-generating-demo-videos-with-playwright.html
 */

/**
 * Inject a visible fake cursor into the page.
 * Safe to call multiple times — subsequent calls are no-ops.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<void>}
 */
async function injectCursor(page) {
	await page.evaluate(() => {
		if (document.getElementById('__demo_cursor')) return;

		const cursor = document.createElement('div');
		cursor.id = '__demo_cursor';
		cursor.setAttribute('aria-hidden', 'true');
		cursor.style.cssText = [
			'position: fixed',
			'pointer-events: none',
			'z-index: 999999',
			'width: 22px',
			'height: 22px',
			'border-radius: 50%',
			'background: rgba(255, 60, 60, 0.45)',
			'border: 2px solid rgba(255, 60, 60, 0.85)',
			'transition: left 0.12s ease-out, top 0.12s ease-out',
			'transform: translate(-50%, -50%)',
			'box-shadow: 0 0 4px rgba(0,0,0,0.3)',
		].join(';');

		document.body.appendChild(cursor);
	});
}

/**
 * Move the fake cursor to the center of a target element.
 *
 * @param {import('playwright').Page} page
 * @param {string} selector - CSS selector for the target element.
 * @returns {Promise<boolean>} True if the element was found and cursor moved.
 */
async function moveCursorTo(page, selector) {
	try {
		const box = await page.locator(selector).first().boundingBox();
		if (!box) return false;

		await page.evaluate(({ x, y }) => {
			const cursor = document.getElementById('__demo_cursor');
			if (cursor) {
				cursor.style.left = (x + 6) + 'px';
				cursor.style.top = (y + 6) + 'px';
			}
		}, { x: box.x, y: box.y });

		return true;
	} catch {
		return false;
	}
}

/**
 * Animate the cursor gliding to a target element over a duration.
 *
 * @param {import('playwright').Page} page
 * @param {string} selector
 * @param {number} [durationMs=300] - Animation duration in ms.
 * @returns {Promise<boolean>}
 */
async function glideCursorTo(page, selector, durationMs = 300) {
	try {
		const box = await page.locator(selector).first().boundingBox();
		if (!box) return false;

		await page.evaluate(({ x, y, duration }) => {
			const cursor = document.getElementById('__demo_cursor');
			if (cursor) {
				cursor.style.transition = `left ${duration}ms ease-out, top ${duration}ms ease-out`;
				cursor.style.left = (x + 6) + 'px';
				cursor.style.top = (y + 6) + 'px';
			}
		}, { x: box.x, y: box.y, duration: durationMs });

		await page.waitForTimeout(durationMs + 50);
		return true;
	} catch {
		return false;
	}
}

/**
 * Remove the fake cursor from the page.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<void>}
 */
async function removeCursor(page) {
	await page.evaluate(() => {
		const cursor = document.getElementById('__demo_cursor');
		if (cursor) cursor.remove();
	});
}

module.exports = { injectCursor, moveCursorTo, glideCursorTo, removeCursor };
