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

test('sitemap lists the catalog', async ({ page }) => {
	await page.goto('/sitemap.xml');
	const body = await page.textContent('body');
	expect(body).toContain('<urlset');
	expect(body).toContain('/products/1/');
	expect(body).toContain('/products/');
	expect(body).toContain('<lastmod>');
});

test('unknown product id 404s (Proposal 104 SSR guard)', async ({ page }) => {
	const response = await page.goto('/products/999999/whatever/');
	expect(response?.status()).toBe(404);
});

test('draft product 404s and never enters the sitemap (Proposal 104 publication guard)', async ({ page }) => {
	// The fixture boots with a synthetic draft (id 9901) appended at runtime —
	// the golden fixtures stay byte-identical to the bridge contract.
	const response = await page.goto('/products/9901/unreleased-prototype-sofa/');
	expect(response?.status()).toBe(404);

	await page.goto('/sitemap.xml');
	const body = await page.textContent('body');
	expect(body).not.toContain('/products/9901/');
});

test('og card endpoint renders a PNG and the PDP references it (Proposal 104 Q5)', async ({ page }) => {
	const card = await page.request.get('/og/product/1.png');
	expect(card.status()).toBe(200);
	expect(card.headers()['content-type'] ?? '').toContain('image/png');
	const bytes = await card.body();
	expect(bytes.length).toBeGreaterThan(1024);

	await page.goto('/products/1/aurora-3-seater-sofa/');
	await expect(page.locator('meta[property="og:image"]')).toHaveAttribute(
		'content',
		/\/og\/product\/1\.png$/
	);
});

test('wrong slug 301s to the canonical product URL (Proposal 104)', async ({ page }) => {
	// maxRedirects: 0 — assert the 301 itself, not the followed target.
	const response = await page.request.get('/products/1/wrong-slug/', { maxRedirects: 0 });
	expect(response.status()).toBe(301);
	expect(response.headers()['location'] ?? '').toContain('/products/1/aurora-3-seater-sofa/');
});

test('all-products PLP renders with filters and a11y labels', async ({ page }) => {
	await page.goto('/products/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText(/All products/);
	expect(await page.locator('a[href^="/products/"]').count()).toBeGreaterThan(0);

	// A11y (104 P3): every filter control has an accessible name.
	await expect(page.getByLabel('Category')).toBeVisible();
	await expect(page.getByLabel('Brand')).toBeVisible();
	await expect(page.getByLabel('Sort')).toBeVisible();
	await expect(page.getByRole('group', { name: 'Filter by colour' })).toBeVisible();

	// Canonical discipline.
	await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/products\/$/);
});
