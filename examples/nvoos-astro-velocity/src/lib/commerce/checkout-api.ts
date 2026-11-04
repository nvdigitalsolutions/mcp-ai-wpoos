// ---------------------------------------------------------------------------
// checkout-api.ts — the bridge checkout proxy client (Proposal 110 Phase B).
//
// The embedded checkout: addresses, shipping rates, coupons and payment all
// ride the bridge's /checkout routes (same-origin /api, the contract
// namespace) — the storefront never calls the Store API or wc/v3 directly.
//
// Payment boundary (INV-1 refined): card collection happens inside the
// gateway's HOSTED fields (Stripe Elements), never in app-rendered inputs.
// The app relays only the gateway's payment-method token in `payment_data`
// — card data never touches the app, the BFF, or the bridge.
// ---------------------------------------------------------------------------

import { z } from 'zod';
import type {
	CheckoutAddress,
	CheckoutDoc,
	CheckoutResult,
	ShopDoc,
} from './types';

// ---------------------------------------------------------------------------
// Schemas (zod v4 — loud failure on contract drift, the client.ts rule).
// ---------------------------------------------------------------------------

export const checkoutAddressSchema = z.object({
	firstName: z.string(),
	lastName: z.string(),
	company: z.string(),
	address1: z.string(),
	address2: z.string(),
	city: z.string(),
	state: z.string(),
	postcode: z.string(),
	country: z.string(),
	phone: z.string(),
	email: z.string(),
});

export const checkoutDocSchema = z.object({
	session: z.string(),
	orderId: z.number().int().nonnegative(),
	orderStatus: z.string(),
	items: z.array(
		z.object({
			key: z.string(),
			productId: z.number().int().positive(),
			name: z.string(),
			qty: z.number().int().nonnegative(),
			price: z.number(),
		})
	),
	totals: z.object({
		totalItems: z.number().int().nonnegative(),
		totalPrice: z.string(),
		shippingTotal: z.string().optional(),
		taxTotal: z.string().optional(),
	}),
	billingAddress: checkoutAddressSchema.nullable(),
	shippingAddress: checkoutAddressSchema.nullable(),
	paymentMethod: z.string(),
	shippingRates: z.array(
		z.object({
			packageId: z.string(),
			name: z.string(),
			rates: z.array(
				z.object({
					rateId: z.string(),
					name: z.string(),
					description: z.string(),
					price: z.string(),
					selected: z.boolean(),
				})
			),
		})
	),
	needsShipping: z.boolean(),
	coupons: z.array(z.object({ code: z.string(), discount: z.string() })),
	notices: z.array(z.object({ type: z.string(), text: z.string() })),
});

export const checkoutResultSchema = z.object({
	orderId: z.number().int().positive(),
	orderKey: z.string(),
	status: z.string(),
	paymentResult: z.object({
		paymentStatus: z.string(),
		redirectUrl: z.string(),
		paymentDetails: z.array(z.unknown()),
	}),
	notices: z.array(z.object({ type: z.string(), text: z.string() })),
});

// ---------------------------------------------------------------------------
// Gateway + flow knowledge (app-side constants — the bridge stays generic).
// ---------------------------------------------------------------------------

/** Gateways whose payment collection happens inside hosted fields in the
 *  storefront (Stripe Elements) — INV-1-compliant, no raw card inputs. */
export const EMBEDDED_CARD_GATEWAY = 'stripe';

/** Gateways that complete payment on their own domain — the Store API
 *  answers with paymentResult.redirectUrl and the app navigates there. */
export const REDIRECT_GATEWAYS = new Set(['paypal', 'ppcp-gateway']);

/** Whether the embedded checkout is usable for this shop (108 §2 mode rule:
 *  woo/both + a backend checkout URL, so the hosted page stays the
 *  fail-open fallback). */
export function canEmbedCheckout(shop: ShopDoc): boolean {
	return (
		(shop.productsSource === 'woo' || shop.productsSource === 'both') &&
		typeof shop.checkoutUrl === 'string' &&
		shop.checkoutUrl.length > 0
	);
}

// ---------------------------------------------------------------------------
// Payload builders (pure — unit-tested without a backend).
// ---------------------------------------------------------------------------

/** Build the Woo Stripe gateway payment_data for the Store API checkout —
 *  the key set WooCommerce documents for its Stripe gateway (developer
 *  docs, "Payment Data"): the gateway reads `stripe_source` (a pm_/src_/
 *  card_ token from Stripe.js), `paymentMethod: stripe`, the request type,
 *  and billing keys. `billing` values come from the address the shopper
 *  confirmed (Stripe requires them for 3DS/link flows). */
export function buildStripePaymentData(
	paymentMethodId: string,
	billing: { email: string; firstName: string; lastName: string },
	requestType: 'cc' | 'payment_request_api'
): Array<{ key: string; value: string | boolean }> {
	return [
		{ key: 'stripe_source', value: paymentMethodId },
		{ key: 'paymentMethod', value: 'stripe' },
		{ key: 'paymentRequestType', value: requestType },
		{ key: 'wc-stripe-new-payment-method', value: true },
		{ key: 'billing_first_name', value: billing.firstName },
		{ key: 'billing_last_name', value: billing.lastName },
		{ key: 'billing_email', value: billing.email },
	];
}

/** Where a placed order sends the shopper: the gateway's redirect (PayPal
 *  et al) or the app's thanks route. Pure — unit-tested. The thanks route
 *  reads the Woo-convention params (`order_id` / `order_key` — the same
 *  names the 108 return-URL filter lands with). */
export function postPaymentDestination(
	result: CheckoutResult
): { type: 'thanks'; url: string } | { type: 'redirect'; url: string } {
	const redirect = result.paymentResult.redirectUrl;
	if (typeof redirect === 'string' && redirect.length > 0) {
		return { type: 'redirect', url: redirect };
	}
	const params = new URLSearchParams();
	params.set('order_id', String(result.orderId));
	if (result.orderKey) {
		params.set('order_key', result.orderKey);
	}
	return { type: 'thanks', url: `/checkout/thanks/?${params.toString()}` };
}

/** Validate the thanks-page query params (order_id digits, wc order-key
 *  shape). Invalid input renders the generic confirmation — never echoed
 *  unvalidated into the page. */
export function parseThanksParams(search: URLSearchParams): {
	orderId: number | null;
	orderKey: string | null;
} {
	const rawId = search.get('order_id') ?? '';
	const rawKey = search.get('order_key') ?? '';
	const orderId = /^\d{1,12}$/.test(rawId) ? Number(rawId) : null;
	const orderKey = /^wc_order_[A-Za-z0-9]+$/.test(rawKey) ? rawKey : null;
	return { orderId, orderKey };
}

// ---------------------------------------------------------------------------
// HTTP surface (same-origin /api — the BFF proxies to the contract).
// ---------------------------------------------------------------------------

export type CheckoutCallResult<T> = { ok: true; data: T } | { ok: false; error: string };

const SESSION_HEADERS = (session: string): Record<string, string> => ({
	accept: 'application/json',
	'content-type': 'application/json',
	'x-nvoos-cart-session': session,
});

async function postJson(path: string, session: string, body: unknown): Promise<Response> {
	return fetch(path, {
		method: 'POST',
		headers: SESSION_HEADERS(session),
		body: JSON.stringify(body),
	});
}

async function parseCall<T>(
	res: Response,
	schema: z.ZodType<T>
): Promise<CheckoutCallResult<T>> {
	const payload = (await res.json().catch(() => null)) as unknown;
	if (!res.ok) {
		const message =
			typeof payload === 'object' &&
			payload !== null &&
			typeof (payload as { message?: unknown }).message === 'string'
				? ((payload as { message: string }).message as string)
				: `Checkout failed (${res.status}).`;
		return { ok: false, error: message };
	}
	const parsed = schema.safeParse(payload);
	if (!parsed.success) {
		return { ok: false, error: 'The checkout response was malformed.' };
	}
	return { ok: true, data: parsed.data };
}

/** GET the merged checkout doc for the session cart. */
export async function getCheckout(session: string): Promise<CheckoutCallResult<CheckoutDoc>> {
	let res: Response;
	try {
		res = await fetch('/api/checkout', {
			headers: {
				accept: 'application/json',
				'x-nvoos-cart-session': session,
			},
		});
	} catch {
		return { ok: false, error: 'The checkout service is unreachable.' };
	}
	return parseCall(res, checkoutDocSchema);
}

/** Validate the addresses Woo-side; totals + shipping rates recalculate. */
export async function updateCheckoutCustomer(
	session: string,
	payload: { billingAddress: CheckoutAddress; shippingAddress: CheckoutAddress }
): Promise<CheckoutCallResult<CheckoutDoc>> {
	let res: Response;
	try {
		res = await postJson('/api/checkout/customer', session, payload);
	} catch {
		return { ok: false, error: 'The checkout service is unreachable.' };
	}
	return parseCall(res, checkoutDocSchema);
}

/** Select one shipping rate for one package. */
export async function selectShippingRate(
	session: string,
	packageId: string,
	rateId: string
): Promise<CheckoutCallResult<CheckoutDoc>> {
	let res: Response;
	try {
		res = await postJson('/api/checkout/shipping-rate', session, { packageId, rateId });
	} catch {
		return { ok: false, error: 'The checkout service is unreachable.' };
	}
	return parseCall(res, checkoutDocSchema);
}

/** Apply a coupon to the session cart. */
export async function applyCheckoutCoupon(
	session: string,
	code: string
): Promise<CheckoutCallResult<CheckoutDoc>> {
	let res: Response;
	try {
		res = await postJson('/api/checkout/coupon', session, { code });
	} catch {
		return { ok: false, error: 'The checkout service is unreachable.' };
	}
	return parseCall(res, checkoutDocSchema);
}

/** The place-order payload (POST /checkout). */
export interface PlaceOrderPayload {
	paymentMethod: string;
	billingAddress: CheckoutAddress;
	shippingAddress: CheckoutAddress;
	paymentData?: Array<{ key: string; value: string | boolean }>;
	customerNote?: string;
	createAccount?: boolean;
	customerPassword?: string;
	/** The confirmed total in minor units — the Store API rejects drift with
	 *  409 (the tamper-proofing anchor). Omitted for express flows whose
	 *  final total the wallet may still change. */
	expectedTotal?: string;
}

/** Process the order and payment. */
export async function submitCheckout(
	session: string,
	payload: PlaceOrderPayload
): Promise<CheckoutCallResult<CheckoutResult>> {
	let res: Response;
	try {
		res = await postJson('/api/checkout', session, {
			payment_method: payload.paymentMethod,
			billingAddress: payload.billingAddress,
			shippingAddress: payload.shippingAddress,
			...(payload.paymentData ? { paymentData: payload.paymentData } : {}),
			...(payload.customerNote ? { customerNote: payload.customerNote } : {}),
			...(payload.createAccount ? { createAccount: true } : {}),
			...(payload.customerPassword ? { customerPassword: payload.customerPassword } : {}),
			...(payload.expectedTotal ? { expectedTotal: payload.expectedTotal } : {}),
		});
	} catch {
		return { ok: false, error: 'The checkout service is unreachable.' };
	}
	return parseCall(res, checkoutResultSchema);
}
