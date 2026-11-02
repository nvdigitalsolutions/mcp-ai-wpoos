// ---------------------------------------------------------------------------
// Home e2e — the explainer homepage.
//
// JS-disabled: the hero + explainer must arrive in the initial HTML with
// canonical + Organization/WebSite JSON-LD and server-rendered live counts.
// JS-enabled: the stats island animates to the server values and the stack
// explorer tabs are keyboard-accessible with live contract/theme reads.
// ---------------------------------------------------------------------------

import { expect, test } from '@playwright/test';

test.describe('home — no JS', () => {
	test.use({ javaScriptEnabled: false });

	test('explains the app with canonical and JSON-LD in initial HTML', async ({ page }) => {
		await page.goto('/');

		// The home render makes six contract round-trips before the first byte;
		// under full-suite load the shared fixture can be slow — give the
		// first byte budget to spare.
		await expect(page.getByRole('heading', { level: 1 })).toHaveText(
			/NV Store/,
			{ timeout: 30_000 }
		);
		await expect(page.getByRole('heading', { name: 'What is this app?' })).toBeVisible();
		await expect(page.getByRole('heading', { name: 'Explore the stack' })).toBeVisible();

		// Server-rendered live counts — no JS required (fixture: 8 products).
		const stats = page.locator('.home-stats dd');
		expect(await stats.count()).toBe(4);
		await expect(stats.first()).toHaveText('8');

		await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
			'href',
			/\/$/
		);

		const blocks = await page
			.locator('script[type="application/ld+json"]')
			.allTextContents();
		const types = blocks.map(
			(block) => (JSON.parse(block) as { '@type'?: string })['@type'] ?? ''
		);
		expect(types).toContain('Organization');
		expect(types).toContain('WebSite');
	});
});

test('stats island counts up to the server values', async ({ page }) => {
	await page.goto('/');

	const island = page.locator('.home-stats');
	await island.scrollIntoViewIfNeeded();
	// client:visible hydration — the same deterministic wait the other
	// lazy-hydration specs use before interacting.
	await expect(island).toHaveAttribute('data-hydrated', 'true');

	await expect(async () => {
		const items = island.locator('.home-stats__item');
		expect(await items.count()).toBe(4);
		for (let i = 0; i < 4; i++) {
			const dd = items.nth(i).locator('dd');
			const target = Number(await dd.getAttribute('data-count'));
			await expect(dd).toHaveText(target.toLocaleString('en-US'));
		}
	}).toPass();
});

test('stack explorer tabs are keyboard-accessible with live reads', async ({ page }) => {
	await page.goto('/');

	const explorer = page.locator('.stack-explorer');
	await explorer.scrollIntoViewIfNeeded();
	await expect(explorer).toHaveAttribute('data-hydrated', 'true');

	const contractTab = page.getByRole('tab', { name: 'Commerce contract' });
	await contractTab.click();
	await expect(contractTab).toHaveAttribute('aria-selected', 'true');

	// Live contract read: the fixture serves 8 products.
	const contractPanel = page.getByRole('tabpanel', { name: 'Commerce contract' });
	await expect(contractPanel).toBeVisible();
	await expect(contractPanel.locator('.stack-live')).toContainText(
		'8 products',
		{ timeout: 30_000 }
	);

	// WAI-ARIA tabs: ArrowRight moves to the design tab; ArrowLeft returns.
	await contractTab.focus();
	await page.keyboard.press('ArrowRight');
	await expect(page.getByRole('tab', { name: 'Design system' })).toHaveAttribute(
		'aria-selected',
		'true'
	);

	const designPanel = page.getByRole('tabpanel', { name: 'Design system' });
	// Playwright's default color scheme is light → the live read says so.
	await expect(designPanel.locator('.stack-live')).toContainText('light theme');

	await page.keyboard.press('ArrowLeft');
	await expect(contractTab).toHaveAttribute('aria-selected', 'true');
});
