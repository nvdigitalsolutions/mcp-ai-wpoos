/**
 * Stripe integration — Checkout session creation and webhook signature
 * verification.
 *
 * We avoid the full `stripe` Node SDK at request-time because it pulls in
 * Node-shaped dependencies. Instead we use the bare HTTPS API for the two
 * operations we need (Checkout create, Customer create) and verify webhooks
 * with our own HMAC-SHA-256 implementation per Stripe's spec:
 *   https://stripe.com/docs/webhooks/signatures
 */

import type { Env } from './types';
import { timingSafeEqual } from './utils';

const STRIPE_API_BASE = 'https://api.stripe.com/v1';

/** Tolerance window for the Stripe-Signature timestamp (5 minutes). */
const SIGNATURE_TOLERANCE_SECONDS = 300;

interface StripeCheckoutSession {
	id: string;
	url: string;
	customer?: string | null;
	customer_email?: string | null;
	amount_total?: number | null;
	metadata?: Record<string, string>;
	payment_status?: string;
}

interface StripeWebhookEvent {
	id: string;
	type: string;
	data: { object: StripeCheckoutSession };
}

/**
 * Create a Stripe Checkout session for a wallet top-up.
 *
 * `amountUsd` is the **gross** amount the customer pays, i.e. the credit they
 * want PLUS the Stripe processor fee (`2.9% + $0.30`). The plugin computes
 * this and passes it through verbatim — we surface it as a transparent line
 * item to the customer.
 */
export async function createCheckoutSession(
	env: Env,
	args: {
		walletId: string;
		stripeCustomerId: string;
		creditAmountUsd: number;
		processorFeeUsd: number;
		returnUrl: string;
		cancelUrl: string;
		siteUrl: string;
	}
): Promise<{ id: string; url: string }> {
	const creditCents = Math.round(args.creditAmountUsd * 100);
	const feeCents = Math.round(args.processorFeeUsd * 100);

	const body = new URLSearchParams();
	body.set('mode', 'payment');
	body.set('customer', args.stripeCustomerId);
	body.set('success_url', args.returnUrl);
	body.set('cancel_url', args.cancelUrl);
	body.set('automatic_tax[enabled]', 'true');
	body.set('metadata[wallet_id]', args.walletId);
	body.set('metadata[site_url]', args.siteUrl);
	body.set('metadata[credit_micro_usd]', String(creditCents * 10_000));

	// Line item 1 — the credit itself.
	body.set('line_items[0][quantity]', '1');
	body.set('line_items[0][price_data][currency]', env.DEFAULT_CURRENCY);
	body.set('line_items[0][price_data][unit_amount]', String(creditCents));
	body.set('line_items[0][price_data][product_data][name]', 'NV oOS Cloud — wallet credit');
	body.set(
		'line_items[0][price_data][product_data][description]',
		`Pre-paid credit for ${args.siteUrl}`
	);

	// Line item 2 — transparent processor fee pass-through.
	if (feeCents > 0) {
		body.set('line_items[1][quantity]', '1');
		body.set('line_items[1][price_data][currency]', env.DEFAULT_CURRENCY);
		body.set('line_items[1][price_data][unit_amount]', String(feeCents));
		body.set(
			'line_items[1][price_data][product_data][name]',
			'Payment processor fee (Stripe)'
		);
		body.set(
			'line_items[1][price_data][product_data][description]',
			'Pass-through — Stripe charges 2.9% + $0.30 per top-up.'
		);
	}

	const res = await stripeRequest(env, '/checkout/sessions', body);
	const session = (await res.json()) as StripeCheckoutSession;
	if (!res.ok || !session.id || !session.url) {
		throw new Error(`stripe_checkout_failed: ${res.status}`);
	}
	return { id: session.id, url: session.url };
}

/**
 * Find or create a Stripe customer scoped to a site. The site URL is stored
 * in customer metadata so the dashboard / billing portal can show it.
 */
export async function findOrCreateCustomer(
	env: Env,
	args: { email: string; siteUrl: string }
): Promise<{ id: string }> {
	// Lookup by email. Stripe permits multiple customers per email so we use
	// metadata.site_url as the secondary key.
	const search = await stripeRequest(
		env,
		`/customers/search?query=${encodeURIComponent(
			`email:'${args.email}' AND metadata['site_url']:'${args.siteUrl}'`
		)}`,
		null,
		'GET'
	);
	const searchJson = (await search.json()) as { data?: Array<{ id: string }> };
	if (search.ok && searchJson.data && searchJson.data.length > 0) {
		return { id: searchJson.data[0]!.id };
	}

	const body = new URLSearchParams();
	body.set('email', args.email);
	body.set('metadata[site_url]', args.siteUrl);
	const res = await stripeRequest(env, '/customers', body);
	const json = (await res.json()) as { id?: string };
	if (!res.ok || !json.id) {
		throw new Error(`stripe_customer_failed: ${res.status}`);
	}
	return { id: json.id };
}

/**
 * Verify a Stripe webhook signature using the t/v1 scheme. Returns the parsed
 * event on success, throws on any failure (caller should respond 400).
 */
export async function verifyWebhook(
	rawBody: string,
	signatureHeader: string | null,
	secret: string
): Promise<StripeWebhookEvent> {
	if (!signatureHeader) {
		throw new Error('missing_signature');
	}
	const parts = signatureHeader.split(',').reduce<Record<string, string[]>>((acc, part) => {
		const eq = part.indexOf('=');
		if (eq === -1) return acc;
		const key = part.slice(0, eq).trim();
		const val = part.slice(eq + 1).trim();
		if (!acc[key]) acc[key] = [];
		acc[key]!.push(val);
		return acc;
	}, {});

	const timestamp = parts['t']?.[0];
	const signatures = parts['v1'] ?? [];
	if (!timestamp || signatures.length === 0) {
		throw new Error('malformed_signature');
	}

	const ts = parseInt(timestamp, 10);
	if (!Number.isFinite(ts)) {
		throw new Error('malformed_timestamp');
	}
	const now = Math.floor(Date.now() / 1000);
	if (Math.abs(now - ts) > SIGNATURE_TOLERANCE_SECONDS) {
		throw new Error('timestamp_outside_tolerance');
	}

	const signedPayload = `${timestamp}.${rawBody}`;
	const expected = await hmacSha256Hex(secret, signedPayload);

	const matched = signatures.some((sig) => timingSafeEqual(sig, expected));
	if (!matched) {
		throw new Error('signature_mismatch');
	}

	const event = JSON.parse(rawBody) as StripeWebhookEvent;
	if (!event.id || !event.type) {
		throw new Error('malformed_event');
	}
	return event;
}

async function hmacSha256Hex(secret: string, payload: string): Promise<string> {
	const enc = new TextEncoder();
	const key = await crypto.subtle.importKey(
		'raw',
		enc.encode(secret),
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		['sign']
	);
	const buf = await crypto.subtle.sign('HMAC', key, enc.encode(payload));
	const bytes = new Uint8Array(buf);
	let hex = '';
	for (let i = 0; i < bytes.length; i += 1) {
		hex += bytes[i]!.toString(16).padStart(2, '0');
	}
	return hex;
}

async function stripeRequest(
	env: Env,
	path: string,
	body: URLSearchParams | null,
	method: string = 'POST'
): Promise<Response> {
	const headers = new Headers();
	headers.set('Authorization', `Bearer ${env.STRIPE_SECRET_KEY}`);
	headers.set('Stripe-Version', '2024-11-20.acacia');
	if (body) {
		headers.set('Content-Type', 'application/x-www-form-urlencoded');
	}
	return fetch(`${STRIPE_API_BASE}${path}`, {
		method,
		headers,
		body: body ? body.toString() : undefined,
	});
}

export type { StripeCheckoutSession, StripeWebhookEvent };
