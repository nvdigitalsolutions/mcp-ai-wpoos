// ---------------------------------------------------------------------------
// content-write.spec.ts — Proposal 107 e2e gates: the content write path,
// the approval ladder (draft → publish), the Portable Text render pipeline,
// and the fail-closed preview flow.
//
// Runs SERIAL on its OWN fixture + BFF pair (8125/4323 — see
// playwright.config.ts) because publishing/deleting content would race the
// shared listing-count assertions of the parallel specs.
// ---------------------------------------------------------------------------

import { expect, test } from '@playwright/test';
import { signPreviewToken } from '../../src/lib/content/preview';

const FIXTURE = 'http://localhost:8125/wp-json/nvoos-commerce/v1';
const MANAGER_COOKIE = 'nvoos_fixture_manager=1';

async function createDraftPost(
	request: import('@playwright/test').APIRequestContext,
	title: string
): Promise<{ id: number; slug: string }> {
	const res = await request.post(`${FIXTURE}/content/posts`, {
		headers: { cookie: MANAGER_COOKIE },
		data: { title, content: '<p>hello</p>', status: 'draft' },
	});
	expect(res.status()).toBe(200);
	return (await res.json()) as { id: number; slug: string };
}

async function deletePost(
	request: import('@playwright/test').APIRequestContext,
	id: number
): Promise<void> {
	await request.delete(`${FIXTURE}/content/posts/${id}`, {
		headers: { cookie: MANAGER_COOKIE },
	});
}

test('draft → public 404 → preview render → publish → public render → delete', async ({
	page,
	context,
}) => {
	const created = await createDraftPost(page.request, 'Write-spec draft');
	const draftUrl = `/blog/${created.id}/${created.slug}/`;

	// 1. The public surface hides the draft entirely.
	const hidden = await page.goto(draftUrl);
	expect(hidden?.status()).toBe(404);
	await page.goto('/blog/');
	await expect(page.locator(`a[href*="${created.id}"]`)).toHaveCount(0);

	// 2. A manager session on the BFF origin makes the signed preview link
	//    resolve the draft: token exchange → preview cookie → canonical route
	//    with the draft banner.
	await context.addCookies([
		{ name: 'nvoos_fixture_manager', value: '1', url: 'http://localhost:4323' },
	]);
	const token = signPreviewToken('post', created.id, Date.now() + 60_000);
	await page.goto(`/preview/post/${created.id}/?token=${encodeURIComponent(token)}`);
	expect(page.url()).toContain(draftUrl);
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Write-spec draft');
	await expect(page.getByText('Draft preview')).toBeVisible();
	expect(await page.content()).not.toContain('alert(1)');

	// 3. Publish → the same URL renders publicly (no banner).
	const published = await page.request.patch(`${FIXTURE}/content/posts/${created.id}`, {
		headers: { cookie: MANAGER_COOKIE },
		data: { status: 'publish' },
	});
	expect(published.status()).toBe(200);
	await page.goto(draftUrl);
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Write-spec draft');
	await expect(page.locator('.content-body')).toContainText('hello');
	await expect(page.getByText('Draft preview')).toHaveCount(0);

	// 4. Delete → 404 again.
	await deletePost(page.request, created.id);
	const gone = await page.goto(draftUrl);
	expect(gone?.status()).toBe(404);
});

test('agent-authored blocks render through the Portable Text components', async ({ page }) => {
	const res = await page.request.post(`${FIXTURE}/content/posts`, {
		headers: { cookie: MANAGER_COOKIE },
		data: {
			title: 'Blocks post',
			status: 'publish',
			content: '<p>raw <script>alert(1)</script> body</p>',
			blocks: [
				{ _type: 'stat', value: '42', label: 'widgets' },
				{ _type: 'faq', question: 'Why?', answer: 'Because.' },
				{
					_type: 'tabs',
					tabs: [
						{
							title: 'Details',
							blocks: [
								{
									_type: 'block',
									style: 'normal',
									children: [{ _type: 'span', text: 'Tab body', marks: [] }],
									markDefs: [],
								},
							],
						},
						{
							title: 'Shipping',
							blocks: [
								{
									_type: 'block',
									style: 'normal',
									children: [{ _type: 'span', text: 'Ships free', marks: [] }],
									markDefs: [],
								},
							],
						},
					],
				},
				{
					_type: 'block',
					style: 'h2',
					children: [{ _type: 'span', text: 'Heading', marks: [] }],
					markDefs: [],
				},
				{
					_type: 'block',
					style: 'normal',
					children: [
						{ _type: 'span', text: 'A ', marks: [] },
						{ _type: 'span', text: 'link', marks: ['a1'] },
					],
					markDefs: [{ _key: 'a1', _type: 'link', href: 'https://example.com' }],
				},
			],
		},
	});
	expect(res.status()).toBe(200);
	const doc = (await res.json()) as { id: number; slug: string };

	await page.goto(`/blog/${doc.id}/${doc.slug}/`);
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Blocks post');
	// Custom components rendered.
	await expect(page.locator('.block-stat__value')).toHaveText('42');
	await expect(page.locator('.block-stat__label')).toHaveText('widgets');
	// FAQ now renders as the adopted accordion (108): a details/summary
	// pair with the shared exclusive-accordion name.
	await expect(page.locator('.block-faq summary .ui-accordion-heading')).toHaveText('Why?');
	await expect(page.locator('.block-faq p')).toHaveText('Because.');
	await expect(page.locator('.block-faq details')).toHaveAttribute('name', 'nvoos-content-faq');
	await page.locator('.block-faq summary').click();
	await expect(page.locator('.block-faq details')).toHaveAttribute('open', '');
	// Tabs block (108): first tab selected, click switches panels.
	const tabs = page.locator('.block-tabs');
	await expect(tabs.locator('[role="tab"]')).toHaveCount(2);
	await expect(tabs.locator('[role="tabpanel"]')).toHaveCount(2);
	await expect(tabs.locator('[role="tab"]').first()).toHaveAttribute('aria-selected', 'true');
	await expect(tabs.locator('[role="tabpanel"]').first()).toHaveAttribute('aria-hidden', 'false');
	await expect(tabs.locator('[role="tabpanel"]').first()).toContainText('Tab body');
	await expect(tabs.locator('[role="tabpanel"]').nth(1)).toHaveAttribute('aria-hidden', 'true');
	await tabs.locator('[role="tab"]').nth(1).click();
	await expect(tabs.locator('[role="tab"]').nth(1)).toHaveAttribute('aria-selected', 'true');
	await expect(tabs.locator('[role="tabpanel"]').nth(1)).toHaveAttribute('aria-hidden', 'false');
	await expect(tabs.locator('[role="tabpanel"]').nth(1)).toContainText('Ships free');
	// Standard block styles + marks through the library defaults.
	await expect(page.locator('.blocks h2')).toHaveText('Heading');
	await expect(page.locator('.blocks a[href="https://example.com"]')).toHaveText('link');

	// Blocks win over raw content; the fixture kses strips scripts anyway.
	const html = await page.content();
	expect(html).not.toContain('alert(1)');
	expect(html).not.toContain('raw body');

	await deletePost(page.request, doc.id);
});

test('preview links fail closed without a manager session and for bad tokens', async ({
	page,
}) => {
	const created = await createDraftPost(page.request, 'Closed draft');
	const token = signPreviewToken('post', created.id, Date.now() + 60_000);

	// No manager cookie → the exchange cannot resolve the draft → 404
	// (draft existence never leaks).
	const denied = await page.goto(
		`/preview/post/${created.id}/?token=${encodeURIComponent(token)}`
	);
	expect(denied?.status()).toBe(404);

	// Invalid token → 401.
	const bad = await page.goto('/preview/post/101/?token=garbage');
	expect(bad?.status()).toBe(401);

	await deletePost(page.request, created.id);
});
