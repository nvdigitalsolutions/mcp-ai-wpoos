// checkout-woo.spec.ts — Proposal 108 Phase 2 + 110 e2e gates: the
// server-cart path. Runs serial on its OWN fixture + BFF pair (8126/4324 —
// see playwright.config.ts) where the fixture serves woo product mode.
//
// 110: a successful sync now continues to the EMBEDDED checkout (`/checkout/`)
// instead of the hosted URL — the handoff event mode is `embedded`. The
// hosted URL form stays the fail-open fallback when the sync errors.
import { expect, test } from '@playwright/test';

// One-shot localStorage seed — addInitScript re-runs on EVERY navigation
// (the cart click navigates to /checkout), so a bare seed would resurrect
// the cleared cart on the next document. The sessionStorage flag scopes the
// seed to the first load of the tab.
const seedCart = async (page: import('@playwright/test').Page, lines: Record<string, unknown>) => {
	await page.addInitScript((cartJson) => {
		if (sessionStorage.getItem('__seeded-cart')) {
			return;
		}
		sessionStorage.setItem('__seeded-cart', '1');
		localStorage.setItem('nvoos-cart-v1', cartJson);
	}, JSON.stringify(lines));
};

const waitForCart = async (page: import('@playwright/test').Page) => {
	await expect(page.locator('[data-cart-root]')).toHaveAttribute('data-hydrated', 'true');
};

test('woo mode syncs the cart server-side (variants included) and continues to the embedded checkout', async ({
	page,
}) => {
	// Capture the handoff event (108 §2 analytics seam) before anything runs.
	// The click NAVIGATES to /checkout/ (same-origin), so the capture must
	// survive the reload — it rides sessionStorage and re-hydrates on the
	// new document (addInitScript alone would be wiped by the navigation).
	await page.addInitScript(() => {
		const key = '__nvoosCheckoutHandoff';
		(
			window as unknown as {
				__nvoosCheckoutHandoff?: { mode: string; lines: number; variants: number } | null;
			}
		).__nvoosCheckoutHandoff = JSON.parse(sessionStorage.getItem(key) ?? 'null') as {
			mode: string;
			lines: number;
			variants: number;
		} | null;
		window.addEventListener('nvoos:checkout-handoff', (event) => {
			const detail = (event as CustomEvent).detail as {
				mode: string;
				lines: number;
				variants: number;
			};
			sessionStorage.setItem(key, JSON.stringify(detail));
			(
				window as unknown as {
					__nvoosCheckoutHandoff: { mode: string; lines: number; variants: number } | null;
				}
			).__nvoosCheckoutHandoff = detail;
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

	// Capture the sync POST without intercepting it — the fixture seeds its
	// per-session cart state from this call, and the checkout page reads it.
	let syncBody: Record<string, unknown> | null = null;
	page.on('request', (request) => {
		if (request.url().includes('/api/cart/sync')) {
			syncBody = request.postDataJSON() as Record<string, unknown> | null;
		}
	});

	await page.goto('/cart/');
	await waitForCart(page);

	// The checkout link points at the EMBEDDED checkout (110).
	await expect(page.locator('a[data-cart-checkout]')).toHaveAttribute('href', '/checkout/');

	// Dispatch the DOM click directly: the React handler runs synchronously
	// (sync → clear) while the navigation resolves.
	await page.locator('a[data-cart-checkout]').evaluate((el) =>
		(el as HTMLAnchorElement).click()
	);

	// Lands on the embedded checkout page.
	await expect(page).toHaveURL(/\/checkout\/$/);
	await expect(page.locator('[data-checkout-root]')).toHaveAttribute('data-hydrated', 'true');

	// Both lines synced — the variant line carries its attributes.
	await expect(async () => {
		expect(syncBody).not.toBeNull();
	}).toPass();
	const body = syncBody as unknown as Record<string, unknown>;
	const lines = body.lines as Array<Record<string, unknown>>;
	expect(lines).toHaveLength(2);
	const variantLine = lines.find((line) => line.productId === 3);
	expect(variantLine).toBeDefined();
	expect(variantLine?.qty).toBe(2);
	expect(variantLine?.attributes).toEqual([{ name: 'Size', value: 'Large' }]);

	// The handoff event fired with the embedded mode + the variant count.
	const handoff = await page.evaluate(
		() =>
			(
				window as unknown as {
					__nvoosCheckoutHandoff?: { mode: string; lines: number; variants: number } | null;
				}
			).__nvoosCheckoutHandoff
	);
	expect(handoff).toEqual({ mode: 'embedded', lines: 2, variants: 1 });

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
