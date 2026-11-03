// ---------------------------------------------------------------------------
// shop-doc.test.ts — getShop normalization (Proposal 108 §2): legacy
// backends omit the extension fields; the client must resolve them to the
// fail-closed defaults (native source, empty return URL, no gateways).
// ---------------------------------------------------------------------------

import { afterEach, describe, expect, it, vi } from 'vitest';
import { getShop } from '../../src/lib/commerce/client';

function stubFetch(body: unknown): void {
	vi.stubGlobal(
		'fetch',
		vi.fn(async () => ({
			ok: true,
			status: 200,
			json: async () => body,
		}) as Response)
	);
}

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('getShop normalization', () => {
	it('resolves the 108 extension fields on the full doc', async () => {
		stubFetch({
			checkoutUrl: 'https://nvoos.pro/checkout/',
			cartUrl: 'https://nvoos.pro/cart/',
			returnUrl: 'https://nvoos.pro/checkout/order-received/',
			currency: 'USD',
			currencySymbol: '$',
			updatedAt: '2026-11-03T09:00:00Z',
			productsSource: 'woo',
			paymentMethods: [{ id: 'stripe', title: 'Stripe' }],
		});

		const shop = await getShop(new URL('https://full.example/cart/'));

		expect(shop.productsSource).toBe('woo');
		expect(shop.returnUrl).toBe('https://nvoos.pro/checkout/order-received/');
		expect(shop.paymentMethods).toHaveLength(1);
	});

	it('fails closed to native mode on a legacy backend doc', async () => {
		stubFetch({
			checkoutUrl: 'https://nvoos.pro/checkout/',
			cartUrl: 'https://nvoos.pro/cart/',
			currency: 'USD',
			currencySymbol: '$',
			updatedAt: '2026-11-03T09:00:00Z',
		});

		const shop = await getShop(new URL('https://legacy.example/cart/'));

		expect(shop.productsSource).toBe('native');
		expect(shop.returnUrl).toBe('');
		expect(shop.paymentMethods).toEqual([]);
	});

	it('drops malformed payment-method entries', async () => {
		stubFetch({
			checkoutUrl: 'https://nvoos.pro/checkout/',
			cartUrl: 'https://nvoos.pro/cart/',
			currency: 'USD',
			currencySymbol: '$',
			updatedAt: '2026-11-03T09:00:00Z',
			productsSource: 'both',
			paymentMethods: [{ id: 'stripe', title: 'Stripe' }, 'garbage', null],
		});

		const shop = await getShop(new URL('https://mixed.example/cart/'));

		expect(shop.paymentMethods).toEqual([{ id: 'stripe', title: 'Stripe' }]);
	});
});
