// checkout-woo.spec.ts — Proposal 108 Phase 2 e2e gates: the server-cart
// path. Runs serial on its OWN fixture + BFF pair (8126/4324 — see
// playwright.config.ts) where the fixture serves woo product mode.
//
// The sync request is captured through Playwright's route API; the
// outbound checkout navigation is route-aborted so the test stays hermetic.
import { expect, test } from '@playwright/test';

const seedCart = async (page: import('@playwright/test').Page, lines: Record<string, unknown>) => {
	await page.addInitScript((cartJson) => {
		localStorage.setItem('nvoos-cart-v1', cartJson);
	}, JSON.stringify(lines));
};

const waitForCart = async (page: import('@playwright/test').Page) => {
	await expect(page.locator('[data-cart-root]')).toHaveAttribute('data-hydrated', 'true');
};

test('woo mode syncs the cart server-side (variants included) and hands off clean', async ({
	page,
}) => {
	// Capture the handoff event (108 §2 analytics seam) before anything runs.
	await page.addInitScript(() => {
		(
			window as unknown as {
				__nvoosCheckoutHandoff?: { mode: string; lines: number; variants: number } | null;
			}
		).__nvoosCheckoutHandoff = null;
		window.addEventListener('nvoos:checkout-handoff', (event) => {
			(
				window as unknown as {
					__nvoosCheckoutHandoff: { mode: string; lines: number; variants: number } | null;
				}
			).__nvoosCheckoutHandoff = (event as CustomEvent).detail;
		});
	});

	await seedCart(page, {
		1: {
			productId: 1,
			name: 'Aurora 3-Seater Sofa',
			price: 1299,
			image: '',
			qty: 1,
		},
		3: {
			productId: 3,
			name: 'Halo Lounge Chair',
			price: 849,
			image: '',
			qty: 2,
			variantId: 303,
			attributes: [{ name: 'Size', value: 'Large' }],
		},
	});

	// Capture the sync POST body; fulfil the outbound checkout navigation
	// with a 204 so the page never leaves the hermetic origin.
	let syncBody: Record<string, unknown> | null = null;
	await page.route('**/api/cart/sync', async (route) => {
		syncBody = JSON.parse(route.request().postData() ?? '{}') as Record<string, unknown>;
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				session: 'fixture-session',
				items: [],
				totals: { totalItems: 3, totalPrice: '0' },
				notices: [],
			}),
		});
	});
	await page.route('https://nvoos.pro/**', (route) =>
		route.fulfill({ status: 204, body: '' })
	);

	await page.goto('/cart/');
	await waitForCart(page);

	// The checkout link points at the CLEAN checkout URL (no add-to-cart).
	await expect(page.locator('a[data-cart-checkout]')).toHaveAttribute(
		'href',
		'https://nvoos.pro/checkout/'
	);

	// Dispatch the DOM click directly: the React handler runs synchronously
	// (sync → clear) while the 204 navigation resolves in the background.
	await page.locator('a[data-cart-checkout]').evaluate((el) =>
		(el as HTMLAnchorElement).click()
	);

	await expect(async () => {
		expect(syncBody).not.toBeNull();
	}).toPass();

	// Both lines synced — the variant line carries its attributes.
	// (TS narrows the closure-written variable to null, so read it through
	// explicit casts.)
	const body = syncBody as unknown as Record<string, unknown>;
	const lines = body.lines as Array<Record<string, unknown>>;
	expect(lines).toHaveLength(2);
	const variantLine = lines.find((line) => line.productId === 3);
	expect(variantLine).toBeDefined();
	expect(variantLine?.qty).toBe(2);
	expect(variantLine?.attributes).toEqual([{ name: 'Size', value: 'Large' }]);

	// The handoff event fired with the server-cart mode + the variant count.
	const handoff = await page.evaluate(
		() =>
			(
				window as unknown as {
					__nvoosCheckoutHandoff?: { mode: string; lines: number; variants: number } | null;
				}
			).__nvoosCheckoutHandoff
	);
	expect(handoff).toEqual({ mode: 'server-cart', lines: 2, variants: 1 });

	// The local cart cleared (one-shot handoff).
	await expect(async () => {
		const raw = await page.evaluate(() => localStorage.getItem('nvoos-cart-v1'));
		expect(JSON.parse(raw ?? '{}')).toEqual({});
	}).toPass();
});

test('a failed sync fails open to the legacy URL form with the variant note', async ({
	page,
}) => {
	await seedCart(page, {
		1: {
			productId: 1,
			name: 'Aurora 3-Seater Sofa',
			price: 1299,
			image: '',
			qty: 1,
		},
		3: {
			productId: 3,
			name: 'Halo Lounge Chair',
			price: 849,
			image: '',
			qty: 1,
			variantId: 303,
			attributes: [{ name: 'Size', value: 'Large' }],
		},
	});

	// The proxy errors — the island must fall open to the URL form.
	await page.route('**/api/cart/sync', (route) =>
		route.fulfill({
			status: 502,
			contentType: 'application/json',
			body: JSON.stringify({ code: 'astro_cart_store_api_error', message: 'Store API down.' }),
		})
	);

	await page.goto('/cart/');
	await waitForCart(page);

	await page.locator('a[data-cart-checkout]').evaluate((el) =>
		(el as HTMLAnchorElement).click()
	);

	await expect(page.locator('[data-cart-sync-error]')).toBeVisible();
	await expect(page.locator('a[data-cart-checkout]')).toHaveAttribute(
		'href',
		/add-to-cart=1$/
	);
	await expect(page.locator('[data-cart-variant-note]')).toBeVisible();
});
