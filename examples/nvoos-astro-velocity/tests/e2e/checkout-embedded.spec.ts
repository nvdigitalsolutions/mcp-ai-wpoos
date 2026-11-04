// checkout-embedded.spec.ts — Proposal 110 Phase B e2e gates: the embedded
// checkout end to end on the woo fixture pair (8126/4324, serial — it
// places orders and mutates per-session fixture state).
//
// Stripe.js never loads here (the fixture shop doc has no publishable key),
// so the card UI is absent by design — the COD path exercises the full
// address → shipping → payment → thanks flow hermetically.
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// One-shot localStorage seed — addInitScript re-runs on EVERY navigation
// (the cart click navigates to /checkout, the order to /thanks), so a bare
// seed would resurrect the cleared cart on the next document. The
// sessionStorage flag scopes the seed to the first load of the tab.
const seedCart = async (page: import('@playwright/test').Page, lines: Record<string, unknown>) => {
	await page.addInitScript((cartJson) => {
		if (sessionStorage.getItem('__seeded-cart')) {
			return;
		}
		sessionStorage.setItem('__seeded-cart', '1');
		localStorage.setItem('nvoos-cart-v1', cartJson);
	}, JSON.stringify(lines));
};

const waitForCheckout = async (page: import('@playwright/test').Page, phase: string) => {
	await expect(page.locator('[data-checkout-root]')).toHaveAttribute('data-hydrated', 'true');
	await expect(page.locator('[data-checkout-root]')).toHaveAttribute('data-checkout-phase', phase);
};

const seedTwoLines = async (page: import('@playwright/test').Page) => {
	await seedCart(page, {
		1: {
			productId: 1,
			name: 'Aurora 3-Seater Sofa',
			price: 1299,
			image: '',
			qty: 1,
		},
		4: {
			productId: 4,
			name: 'Terra Side Table',
			price: 249,
			image: '',
			qty: 2,
		},
	});
};

const goToCheckoutViaCart = async (page: import('@playwright/test').Page) => {
	await page.goto('/cart/');
	await expect(page.locator('[data-cart-root]')).toHaveAttribute('data-hydrated', 'true');
	await page.locator('a[data-cart-checkout]').evaluate((el) =>
		(el as HTMLAnchorElement).click()
	);
	await expect(page).toHaveURL(/\/checkout\/$/);
};

const fillBilling = async (page: import('@playwright/test').Page) => {
	await page.getByLabel('First name').fill('Jane');
	await page.getByLabel('Last name').fill('Doe');
	await page.getByLabel('Email').fill('jane@example.com');
	// exact: 'Address' is a substring of the same-address checkbox label.
	await page.getByLabel('Address', { exact: true }).fill('1 Astro Way');
	await page.getByLabel('City').fill('Velocity');
	await page.getByLabel('Postcode').fill('90001');
};

test('the embedded checkout places a COD order end to end', async ({ page }) => {
	await seedTwoLines(page);
	await goToCheckoutViaCart(page);
	await waitForCheckout(page, 'ready');

	await fillBilling(page);
	await page.locator('[data-checkout-continue]').click();

	// The synced server cart renders in the review summary.
	await expect(page.locator('[data-checkout-summary]')).toContainText('Aurora 3-Seater Sofa');
	await expect(page.locator('[data-checkout-summary]')).toContainText('Terra Side Table × 2');
	// Subtotal (minor units): 1299 + 2×249 → 179700 + flat shipping 1000.
	await expect(page.locator('[data-checkout-total]')).toHaveText('180700');

	// Shipping step appears with both rates; express re-totals.
	await expect(page.locator('input[data-shipping-rate]')).toHaveCount(2);
	// click(), not check(): the radio is CONTROLLED (the selected state comes
	// back from the rate-selection round-trip), so under parallel load a
	// check() state assertion can race the async re-render.
	await page.locator('input[data-shipping-rate][value="express_rate"]').click();
	await expect(page.locator('[data-checkout-total]')).toHaveText('182200');

	// COD — the hermetic path (Stripe is absent by design on the fixture).
	await page.locator('input[data-payment-method][value="cod"]').click();
	await page.locator('[data-checkout-submit]').click();

	// Thanks page with a validated order id.
	await expect(page).toHaveURL(
		/\/checkout\/thanks\/\?order_id=\d+&order_key=wc_order_fixture_\d+/
	);
	await expect(page.locator('[data-thanks-order]')).toBeVisible();
	await expect(page.locator('.ref')).toContainText('#9');

	// The local cart cleared on success.
	const raw = await page.evaluate(() => localStorage.getItem('nvoos-cart-v1'));
	expect(JSON.parse(raw ?? '{}')).toEqual({});
});

test('a server-total drift is rejected and the summary refreshes', async ({ page }) => {
	await seedTwoLines(page);
	await goToCheckoutViaCart(page);
	await waitForCheckout(page, 'ready');

	await fillBilling(page);
	await page.locator('[data-checkout-continue]').click();
	await page.locator('input[data-payment-method][value="cod"]').click();

	// The backend rejects the confirmed total (409) — the storefront must
	// surface the notice and re-read the doc instead of placing the order.
	await page.route('**/api/checkout', async (route) => {
		if (route.request().method() !== 'POST') {
			await route.continue();
			return;
		}
		await route.fulfill({
			status: 409,
			contentType: 'application/json',
			body: JSON.stringify({
				code: 'astro_checkout_rejected',
				message: 'The order total changed. Please review your order.',
				data: { totals: { total_price: '181700' } },
			}),
		});
	});

	await page.locator('[data-checkout-submit]').click();

	await expect(page.locator('[data-checkout-notice]')).toContainText('total changed');
	await expect(page.locator('[data-checkout-total]')).toHaveText('180700');
});

test('an empty server cart shows the empty state with a return link', async ({ page }) => {
	// Local cart seeded but NEVER synced — the server session has no lines.
	await seedTwoLines(page);
	await page.goto('/checkout/');
	await waitForCheckout(page, 'empty');

	await expect(page.locator('[data-checkout-back]')).toHaveAttribute('href', '/cart/');
});

test('the checkout page is axe-clean', async ({ page }) => {
	await seedTwoLines(page);
	await goToCheckoutViaCart(page);
	await waitForCheckout(page, 'ready');

	await fillBilling(page);
	await page.locator('[data-checkout-continue]').click();
	await expect(page.locator('input[data-shipping-rate]')).toHaveCount(2);

	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});
