/**
 * Public connect flow — no Connect Token required (this is how a site GETS
 * one).
 *
 * Two endpoints:
 *
 *   POST /connect/start    body: { email, site_url, amount_usd, return_url, cancel_url }
 *                          → { checkout_url, session_id }
 *                          Creates (or reuses) a wallet + Stripe customer and
 *                          returns a Stripe Checkout URL for the first top-up.
 *
 *   POST /connect/finish   body: { stripe_session_id, site_url, label? }
 *                          → { connect_token, balance_usd }
 *                          Called by the plugin's success-redirect handler.
 *                          Verifies the Checkout session was paid, then
 *                          issues a Connect Token bound to the site URL.
 */

import { Hono } from 'hono';
import type { Env, Wallet } from './types';
import { createCheckoutSession, findOrCreateCustomer } from './stripe';
import {
	errorResponse,
	generateConnectToken,
	microToUsd,
	normalizeSiteUrl,
	sha256Hex,
} from './utils';

const connectApp = new Hono<{ Bindings: Env }>();

interface ConnectStartBody {
	email: string;
	site_url: string;
	amount_usd: number;
	return_url: string;
	cancel_url: string;
}

interface ConnectFinishBody {
	stripe_session_id: string;
	site_url: string;
	label?: string;
}

connectApp.post('/start', async (c) => {
	let body: ConnectStartBody;
	try {
		body = (await c.req.json()) as ConnectStartBody;
	} catch {
		return errorResponse(400, 'invalid_body', 'JSON body required.');
	}

	const minTopup = parseFloat(c.env.MIN_TOPUP_USD);
	if (!Number.isFinite(body.amount_usd) || body.amount_usd < minTopup) {
		return errorResponse(
			400,
			'amount_below_minimum',
			`First top-up must be at least $${minTopup.toFixed(2)} USD.`
		);
	}
	if (!body.email || !body.email.includes('@')) {
		return errorResponse(400, 'invalid_email', 'A valid email is required.');
	}

	let siteUrl: string;
	try {
		siteUrl = normalizeSiteUrl(body.site_url);
	} catch {
		return errorResponse(400, 'invalid_site_url', 'site_url could not be parsed.');
	}

	// Find or create the Stripe customer + the wallet.
	const customer = await findOrCreateCustomer(c.env, { email: body.email, siteUrl });
	const existing = await c.env.NVOOS_DB.prepare(
		'SELECT id, stripe_customer_id, email, balance_micro_usd, created_at, updated_at ' +
			'FROM wallets WHERE stripe_customer_id = ?1 LIMIT 1'
	)
		.bind(customer.id)
		.first<Wallet>();

	let walletId: string;
	if (existing) {
		walletId = existing.id;
	} else {
		walletId = crypto.randomUUID();
		await c.env.NVOOS_DB.prepare(
			'INSERT INTO wallets (id, stripe_customer_id, email, balance_micro_usd, created_at, updated_at) ' +
				'VALUES (?1, ?2, ?3, 0, ?4, ?4)'
		)
			.bind(walletId, customer.id, body.email, Date.now())
			.run();
	}

	const feePercent = parseFloat(c.env.STRIPE_FEE_PERCENT);
	const feeFixed = parseFloat(c.env.STRIPE_FEE_FIXED_USD);
	const processorFee = +(body.amount_usd * feePercent + feeFixed).toFixed(2);

	const checkout = await createCheckoutSession(c.env, {
		walletId,
		stripeCustomerId: customer.id,
		creditAmountUsd: body.amount_usd,
		processorFeeUsd: processorFee,
		returnUrl: body.return_url,
		cancelUrl: body.cancel_url,
		siteUrl,
	});

	await c.env.NVOOS_DB.prepare(
		`INSERT INTO topup_sessions (id, wallet_id, amount_micro_usd, processor_fee_micro_usd, status, created_at)
		 VALUES (?1, ?2, ?3, ?4, 'pending', ?5)`
	)
		.bind(
			checkout.id,
			walletId,
			Math.round(body.amount_usd * 1_000_000),
			Math.round(processorFee * 1_000_000),
			Date.now()
		)
		.run();

	return Response.json({
		checkout_url: checkout.url,
		session_id: checkout.id,
		amount_usd: body.amount_usd,
		processor_fee_usd: processorFee,
	});
});

connectApp.post('/finish', async (c) => {
	let body: ConnectFinishBody;
	try {
		body = (await c.req.json()) as ConnectFinishBody;
	} catch {
		return errorResponse(400, 'invalid_body', 'JSON body required.');
	}

	if (!body.stripe_session_id || !body.site_url) {
		return errorResponse(400, 'missing_fields', 'stripe_session_id and site_url required.');
	}

	let siteUrl: string;
	try {
		siteUrl = normalizeSiteUrl(body.site_url);
	} catch {
		return errorResponse(400, 'invalid_site_url', 'site_url could not be parsed.');
	}

	// The session must be marked completed by the webhook before we issue a token.
	const session = await c.env.NVOOS_DB.prepare(
		'SELECT id, wallet_id, status FROM topup_sessions WHERE id = ?1 LIMIT 1'
	)
		.bind(body.stripe_session_id)
		.first<{ id: string; wallet_id: string; status: string }>();

	if (!session) {
		return errorResponse(404, 'session_not_found', 'Stripe session not recognized.');
	}
	if (session.status !== 'completed') {
		return errorResponse(409, 'session_not_paid', 'Top-up has not yet been confirmed by Stripe.');
	}

	const wallet = await c.env.NVOOS_DB.prepare(
		'SELECT id, stripe_customer_id, email, balance_micro_usd, created_at, updated_at ' +
			'FROM wallets WHERE id = ?1 LIMIT 1'
	)
		.bind(session.wallet_id)
		.first<Wallet>();

	if (!wallet) {
		return errorResponse(404, 'wallet_missing', 'Wallet for session was not found.');
	}

	// Issue the Connect Token (plaintext shown ONCE).
	const plaintext = generateConnectToken();
	const tokenHash = await sha256Hex(plaintext);
	const tokenId = crypto.randomUUID();

	await c.env.NVOOS_DB.prepare(
		`INSERT INTO connect_tokens (id, wallet_id, token_hash, site_url, label, created_at)
		 VALUES (?1, ?2, ?3, ?4, ?5, ?6)`
	)
		.bind(tokenId, wallet.id, tokenHash, siteUrl, body.label ?? 'production', Date.now())
		.run();

	return Response.json({
		connect_token: plaintext,
		balance_usd: microToUsd(wallet.balance_micro_usd),
		token_id: tokenId,
	});
});

export default connectApp;
