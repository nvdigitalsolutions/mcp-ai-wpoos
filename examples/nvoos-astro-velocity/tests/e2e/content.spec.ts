// content.spec.ts — Proposal 106 P2 e2e gates (CMS content + hybrid local).
//
// Read-only against the default fixture/BFF pair: envelope pages render
// contentRendered with WebPage JSON-LD + 301 canonical convergence; the blog
// paginates; local MDX posts/pages render with no envelope doc; the draft
// post 9902 never renders; axe clean.
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

async function jsonLdTypes(page: import('@playwright/test').Page): Promise<string[]> {
	const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
	return blocks.map((block) => {
		try {
			return (JSON.parse(block) as { '@type'?: string })['@type'] ?? '';
		} catch {
			return '';
		}
	});
}

test('envelope page renders contentRendered with WebPage JSON-LD', async ({ page }) => {
	await page.goto('/pages/21/about/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText('About');
	await expect(page.locator('.content-body')).toContainText('headless storefront');

	// SEO fields come from the envelope (seoTitle).
	await expect(page).toHaveTitle(/About \| NV Store/);
	await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
		'href',
		/\/pages\/21\/about\/$/
	);
	expect(await jsonLdTypes(page)).toContain('WebPage');
});

test('page slug drift 301s to the canonical URL (104 pattern)', async ({ page }) => {
	const response = await page.request.get('/pages/21/different-slug/', { maxRedirects: 0 });
	expect(response.status()).toBe(301);
	expect(response.headers()['location'] ?? '').toContain('/pages/21/about/');
});

test('local page fallback renders when the envelope has no matching doc (O-4)', async ({ page }) => {
	await page.goto('/pages/55/terms/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Terms');
	await expect(page.locator('.content-body')).toContainText('demo terms');
	await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
		'href',
		/\/pages\/0\/terms\/$/
	);
});

test('blog listing paginates and merges the local post on page 1', async ({ page }) => {
	await page.goto('/blog/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Blog');
	await expect(page.getByText('Page 1 of 2')).toBeVisible();
	// The local post (id 0) merges into page 1.
	await expect(page.locator('a[href="/blog/0/hello-from-the-hybrid-storefront/"]')).toBeVisible();

	await page.getByRole('link', { name: 'Next' }).click();
	await expect(page).toHaveURL(/page=2/);
	await expect(page.getByText('Page 2 of 2')).toBeVisible();
});

test('envelope post renders BlogPosting JSON-LD and 301s on slug drift', async ({ page }) => {
	const drift = await page.request.get('/blog/101/wrong-slug/', { maxRedirects: 0 });
	expect(drift.status()).toBe(301);
	expect(drift.headers()['location'] ?? '').toContain('/blog/101/autumn-lookbook/');

	await page.goto('/blog/101/autumn-lookbook/');
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Autumn Lookbook 2026');
	await expect(page.locator('.content-body')).toContainText('terracotta');
	const types = await jsonLdTypes(page);
	expect(types).toContain('BlogPosting');
	expect(types).toContain('BreadcrumbList');
});

test('local post renders with no envelope doc', async ({ page }) => {
	await page.goto('/blog/0/hello-from-the-hybrid-storefront/');

	await expect(page.getByRole('heading', { level: 1 })).toHaveText(
		'Hello from the hybrid storefront'
	);
	await expect(page.locator('.content-body')).toContainText('hybrid-local layer');
	expect(await jsonLdTypes(page)).toContain('BlogPosting');
});

test('draft post 9902 never renders publicly (publication discipline)', async ({ page }) => {
	const response = await page.goto('/blog/9902/unreleased-draft-post/');
	expect(response?.status()).toBe(404);
});

test('draft post is excluded from listings', async ({ page }) => {
	await page.goto('/blog/');
	await expect(page.locator('a[href*="9902"]')).toHaveCount(0);
});

test('sitemap lists pages and posts from both sources', async ({ page }) => {
	await page.goto('/sitemap.xml');
	const body = await page.textContent('body');
	expect(body).toContain('/pages/21/about/');
	expect(body).toContain('/blog/101/autumn-lookbook/');
	expect(body).toContain('/blog/0/hello-from-the-hybrid-storefront/');
	expect(body).toContain('/pages/0/terms/');
	expect(body).not.toContain('/blog/9902/');
});

test('axe: envelope page and blog are WCAG-clean', async ({ page }) => {
	for (const route of ['/pages/21/about/', '/blog/']) {
		await page.goto(route);
		const results = await new AxeBuilder({ page }).analyze();
		expect(
			results.violations,
			`axe violations on ${route}: ${JSON.stringify(results.violations, null, 2)}`
		).toEqual([]);
	}
});
