// checkout-api.test.ts — Proposal 110: the embedded checkout client's pure
// pieces (schemas, Stripe payment_data builder, post-payment routing, thanks
// param validation, the embed-eligibility rule) — all hermetic.
import { describe, expect, it } from 'vitest';
import {
	buildStripePaymentData,
	canEmbedCheckout,
	checkoutDocSchema,
	checkoutResultSchema,
	parseThanksParams,
	postPaymentDestination,
} from '../../src/lib/commerce/checkout-api';
import type { CheckoutDoc, CheckoutResult, ShopDoc } from '../../src/lib/commerce/types';

const ADDRESS = {
	firstName: 'Jane',
	lastName: 'Doe',
	company: '',
	address1: '1 Astro Way',
	address2: '',
	city: 'Velocity',
	state: 'CA',
	postcode: '90001',
	country: 'US',
	phone: '',
	email: 'jane@example.com',
};

const DOC: CheckoutDoc = {
	session: 's-12345678',
	orderId: 146,
	orderStatus: 'checkout-draft',
	items: [
		{ key: 'abc', productId: 1, name: 'Aurora', qty: 1, price: 1299 },
	],
	totals: { totalItems: 1, totalPrice: '129900' },
	billingAddress: ADDRESS,
	shippingAddress: ADDRESS,
	paymentMethod: '',
	shippingRates: [
		{
			packageId: 'pkg-0',
			name: 'Shipment',
			rates: [
				{ rateId: 'flat_rate', name: 'Standard', description: '', price: '1000', selected: true },
			],
		},
	],
	needsShipping: true,
	coupons: [],
	notices: [],
};

const RESULT: CheckoutResult = {
	orderId: 200,
	orderKey: 'wc_order_abc123',
	status: 'processing',
	paymentResult: { paymentStatus: 'success', redirectUrl: '', paymentDetails: [] },
	notices: [],
};

const SHOP: ShopDoc = {
	checkoutUrl: 'https://nvoos.pro/checkout/',
	cartUrl: 'https://nvoos.pro/cart/',
	currency: 'USD',
	currencySymbol: '$',
	updatedAt: '',
};

describe('checkoutDocSchema', () => {
	it('accepts a well-formed doc', () => {
		expect(checkoutDocSchema.safeParse(DOC).success).toBe(true);
	});

	it('rejects a doc missing totals', () => {
		const { totals: _totals, ...rest } = DOC;
		expect(checkoutDocSchema.safeParse(rest).success).toBe(false);
	});

	it('rejects a doc with a string totalItems', () => {
		expect(
			checkoutDocSchema.safeParse({
				...DOC,
				totals: { ...DOC.totals, totalItems: '1' },
			}).success
		).toBe(false);
	});
});

describe('checkoutResultSchema', () => {
	it('accepts a well-formed result', () => {
		expect(checkoutResultSchema.safeParse(RESULT).success).toBe(true);
	});

	it('rejects a result without the paymentResult shape', () => {
		expect(
			checkoutResultSchema.safeParse({ ...RESULT, paymentResult: { nope: true } }).success
		).toBe(false);
	});
});

describe('buildStripePaymentData', () => {
	it('emits the Woo-documented Stripe gateway keys for the card flow', () => {
		const data = buildStripePaymentData(
			'pm_123abc',
			{ email: 'jane@example.com', firstName: 'Jane', lastName: 'Doe' },
			'cc'
		);
		const byKey = Object.fromEntries(data.map((entry) => [entry.key, entry.value]));
		expect(byKey.stripe_source).toBe('pm_123abc');
		expect(byKey.paymentMethod).toBe('stripe');
		expect(byKey.paymentRequestType).toBe('cc');
		expect(byKey['wc-stripe-new-payment-method']).toBe(true);
		expect(byKey.billing_first_name).toBe('Jane');
		expect(byKey.billing_last_name).toBe('Doe');
		expect(byKey.billing_email).toBe('jane@example.com');
	});

	it('marks the express (wallet) request type', () => {
		const data = buildStripePaymentData(
			'pm_wallet1',
			{ email: '', firstName: '', lastName: '' },
			'payment_request_api'
		);
		const byKey = Object.fromEntries(data.map((entry) => [entry.key, entry.value]));
		expect(byKey.paymentRequestType).toBe('payment_request_api');
	});
});

describe('postPaymentDestination', () => {
	it('routes a successful direct payment to the thanks page with order id and key', () => {
		const destination = postPaymentDestination(RESULT);
		expect(destination).toEqual({
			type: 'thanks',
			url: '/checkout/thanks/?order_id=200&order_key=wc_order_abc123',
		});
	});

	it('routes a redirect-gateway payment to the gateway URL', () => {
		const destination = postPaymentDestination({
			...RESULT,
			paymentResult: { ...RESULT.paymentResult, redirectUrl: 'https://paypal.com/x' },
		});
		expect(destination).toEqual({ type: 'redirect', url: 'https://paypal.com/x' });
	});

	it('omits the key param when the backend returns none', () => {
		const destination = postPaymentDestination({ ...RESULT, orderKey: '' });
		expect(destination).toEqual({ type: 'thanks', url: '/checkout/thanks/?order_id=200' });
	});
});

describe('parseThanksParams', () => {
	it('parses valid params', () => {
		const params = new URLSearchParams('order_id=200&order_key=wc_order_abc123');
		expect(parseThanksParams(params)).toEqual({ orderId: 200, orderKey: 'wc_order_abc123' });
	});

	it('nulls invalid params (never echoed unvalidated)', () => {
		expect(
			parseThanksParams(new URLSearchParams('order_id=<script>&order_key=evil'))
		).toEqual({ orderId: null, orderKey: null });
		expect(parseThanksParams(new URLSearchParams('order_id=12.5'))).toEqual({
			orderId: null,
			orderKey: null,
		});
	});

	it('handles missing params', () => {
		expect(parseThanksParams(new URLSearchParams())).toEqual({ orderId: null, orderKey: null });
	});
});

describe('canEmbedCheckout', () => {
	it('enables the embedded checkout in woo/both modes with a backend checkout URL', () => {
		expect(canEmbedCheckout({ ...SHOP, productsSource: 'woo' })).toBe(true);
		expect(canEmbedCheckout({ ...SHOP, productsSource: 'both' })).toBe(true);
	});

	it('fails closed in native mode (URL handoff owns it)', () => {
		expect(canEmbedCheckout({ ...SHOP, productsSource: 'native' })).toBe(false);
	});

	it('fails closed without a checkoutUrl (no hosted fallback)', () => {
		expect(canEmbedCheckout({ ...SHOP, productsSource: 'woo', checkoutUrl: '' })).toBe(false);
	});
});
