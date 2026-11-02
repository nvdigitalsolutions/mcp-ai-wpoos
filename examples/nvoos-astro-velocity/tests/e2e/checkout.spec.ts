// checkout.spec.ts — Proposal 106 P3 e2e gates (WooCommerce handoff).
//
// The cart page reads the shop globals doc (fixture checkoutUrl =
// https://nvoos.pro/checkout/) and hands off simple-product lines via the
// comma form; variant lines are excluded with a note; the link clears the
// cart on click. Hermetic: the outbound navigation is route-aborted.
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const seedCart = async (page: import('@playwright/test').Page, lines: Record<string, unknown>) => {
	await page.addInitScript((cartJson) => {
		localStorage.setItem('nvoos-cart-v1', cartJson);
	}, JSON.stringify(lines));
};

/** Wait for the CartView island to hydrate + load the seeded storage. */
const waitForCart = (page: import('@playwright/test').Page) =>
	expect(page.locator('[data-cart-root][data-hydrated="true"]')).toBeVisible({
		timeout: 30_000,
	});

const simpleLine = (productId: number, name: string, price: number) => ({
	productId,
	name,
	price,
	image: '',
	qty: 1,
});

test('two simple products build a comma-joined handoff URL', async ({ page }) => {
	await seedCart(page, {
		1: simpleLine(1, 'Aurora 3-Seater Sofa', 1299),
		4: simpleLine(4, 'Terra Side Table', 249),
	});

	await page.goto('/cart/');
	await waitForCart(page);
	const link = page.locator('a[data-cart-checkout]');
	await expect(link).toBeVisible();
	await expect(link).toHaveAttribute('href', /add-to-cart=1,4$/);
	await expect(link).toHaveText('Checkout on WooCommerce');
});

test('variant lines are excluded from the URL and render the note', async ({ page }) => {
	await seedCart(page, {
		1: simpleLine(1, 'Aurora 3-Seater Sofa', 1299),
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

	await page.goto('/cart/');
	await waitForCart(page);
	await expect(page.locator('a[data-cart-checkout]')).toHaveAttribute(
		'href',
		/add-to-cart=1$/
	);
	await expect(page.locator('[data-cart-variant-note]')).toBeVisible();
	await expect(page.locator('[data-cart-variant-note]')).toContainText('excluded');
});

test('a variant-only cart disables the checkout button', async ({ page }) => {
	await seedCart(page, {
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

	await page.goto('/cart/');
	await waitForCart(page);
	await expect(page.locator('button[data-cart-checkout]')).toBeDisabled();
	await expect(page.locator('[data-cart-variant-note]')).toBeVisible();
});

test('an empty cart disables the checkout button', async ({ page }) => {
	await page.goto('/cart/');
	await waitForCart(page);
	await expect(page.getByText('Your cart is empty.')).toBeVisible();
	await expect(page.locator('button[data-cart-checkout]')).toBeDisabled();
});

test('clicking the checkout link clears the local cart (one-shot handoff)', async ({ page }) => {
	// Fulfil the outbound navigation with a 204 — a main-frame 204 keeps the
	// current document, so the click handler runs (cart clears) and the page
	// never leaves the hermetic origin.
	await page.route('https://nvoos.pro/**', (route) =>
		route.fulfill({ status: 204, body: '' })
	);
	await seedCart(page, {
		1: simpleLine(1, 'Aurora 3-Seater Sofa', 1299),
	});

	await page.goto('/cart/');
	await waitForCart(page);

	// Dispatch the DOM click directly: the React handler runs synchronously
	// (cart clears) while the 204 navigation resolves in the background —
	// awaiting Playwright's click() would hang on the never-completing load.
	await page.locator('a[data-cart-checkout]').evaluate((el) =>
		(el as HTMLAnchorElement).click()
	);

	await expect(async () => {
		const raw = await page.evaluate(() => localStorage.getItem('nvoos-cart-v1'));
		expect(JSON.parse(raw ?? '{}')).toEqual({});
	}).toPass();
});

test('axe: the cart is WCAG-clean', async ({ page }) => {
	await seedCart(page, {
		1: simpleLine(1, 'Aurora 3-Seater Sofa', 1299),
		4: simpleLine(4, 'Terra Side Table', 249),
	});
	await page.goto('/cart/');
	await waitForCart(page);
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});
