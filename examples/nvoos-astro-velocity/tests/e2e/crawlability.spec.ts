// ---------------------------------------------------------------------------
// No-JS crawlability audit — Proposal 092 Phase 2.5.
//
// The industry-standard SEO gate for a server-rendered storefront: with
// JavaScript DISABLED, the HTML alone must deliver content, real crawlable
// links, canonical tags, and parseable JSON-LD in the initial payload.
// ---------------------------------------------------------------------------

import { expect, test } from '@playwright/test';

test.use({ javaScriptEnabled: false });

test('PLP serves content, links and canonical without JS', async ({ page }) => {
	await page.goto('/categories/1/sofas/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Sofas/);

	// Real <a href> product links — the crawlability contract (085 §2.3).
	const productLinks = page.locator('a[href^="/products/"]');
	expect(await productLinks.count()).toBeGreaterThan(0);

	await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
		'href',
		/\/categories\/1\/sofas\/$/
	);
});

test('filtered PLP is noindex and narrows server-side', async ({ page }) => {
	await page.goto('/categories/1/sofas/?color=4');

	await expect(page.locator('meta[name="robots"]')).toHaveAttribute(
		'content',
		'noindex, follow'
	);

	// Server-side filtering: the grid contains only matching products.
	await expect(page.getByText('Solace Daybed')).toBeVisible();
	await expect(page.getByText('Aurora 3-Seater Sofa')).toHaveCount(0);
});

test('PDP delivers JSON-LD and content in the initial HTML', async ({ page }) => {
	await page.goto('/products/1/aurora-3-seater-sofa/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Aurora/);

	const blocks = await page
		.locator('script[type="application/ld+json"]')
		.allTextContents();
	expect(blocks.length).toBeGreaterThanOrEqual(1);

	const types = blocks
		.map((block) => {
			const parsed = JSON.parse(block) as { '@type'?: string };
			return parsed['@type'] ?? '';
		})
		.join(',');

	expect(types).toContain('Product');
	expect(types).toContain('BreadcrumbList');

	// Description content present without JS.
	await expect(page.getByRole('heading', { name: 'Description' })).toBeVisible();
});

test('sitemap lists the prerendered catalog', async ({ page }) => {
	await page.goto('/sitemap.xml');
	const body = await page.textContent('body');
	expect(body).toContain('<urlset');
	expect(body).toContain('/products/1/');
	expect(body).toContain('<lastmod>');
});
