// checkout.test.ts — Proposal 106 P3: buildCheckoutUrl is a pure function
// (unit-tested without WooCommerce — the comma-form claim is pinned here).
import { describe, expect, it } from 'vitest';
import { buildCheckoutUrl } from '../../src/lib/commerce/checkout';
import type { CartLine } from '../../src/lib/store/cart';

const SHOP = { checkoutUrl: 'https://nvoos.pro/checkout/' };

const simple = (productId: number): CartLine => ({
	productId,
	name: `Product ${productId}`,
	price: 10,
	image: '',
	qty: 1,
});

const variant = (productId: number, variantId: number): CartLine => ({
	...simple(productId),
	variantId,
	attributes: [{ name: 'Size', value: 'Standard' }],
});

describe('buildCheckoutUrl', () => {
	it('joins two simple products with the comma form', () => {
		const plan = buildCheckoutUrl(SHOP, [simple(1), simple(4)]);
		expect(plan.url).toBe('https://nvoos.pro/checkout/?add-to-cart=1,4');
		expect(plan.simpleIds).toEqual([1, 4]);
		expect(plan.variantCount).toBe(0);
	});

	it('excludes variant lines from the ids and counts them', () => {
		const plan = buildCheckoutUrl(SHOP, [simple(1), variant(3, 301), simple(4)]);
		expect(plan.url).toBe('https://nvoos.pro/checkout/?add-to-cart=1,4');
		expect(plan.variantCount).toBe(1);
	});

	it('returns null when only variant lines exist', () => {
		const plan = buildCheckoutUrl(SHOP, [variant(3, 301)]);
		expect(plan.url).toBeNull();
		expect(plan.simpleIds).toEqual([]);
		expect(plan.variantCount).toBe(1);
	});

	it('returns null for an empty cart', () => {
		const plan = buildCheckoutUrl(SHOP, []);
		expect(plan.url).toBeNull();
	});

	it('fails closed on an empty checkoutUrl', () => {
		const plan = buildCheckoutUrl({ checkoutUrl: '' }, [simple(1)]);
		expect(plan.url).toBeNull();
	});

	it('uses & when the checkoutUrl already carries a query', () => {
		const plan = buildCheckoutUrl(
			{ checkoutUrl: 'https://nvoos.pro/checkout/?utm_source=store' },
			[simple(1)]
		);
		expect(plan.url).toBe('https://nvoos.pro/checkout/?utm_source=store&add-to-cart=1');
	});

	it('preserves the backend URL shape (trailing slash) and line order', () => {
		const plan = buildCheckoutUrl(SHOP, [simple(5), simple(2), simple(7)]);
		expect(plan.url).toBe('https://nvoos.pro/checkout/?add-to-cart=5,2,7');
	});
});
