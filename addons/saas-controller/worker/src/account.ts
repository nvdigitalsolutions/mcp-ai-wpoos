/**
 * Account endpoints — balance, top-up, revoke. All require a valid Connect
 * Token (mounted under `requireToken`).
 */

import { Hono } from 'hono';
import { getAuth, requireToken, type AuthEnv } from './auth';
import { createCheckoutSession } from './stripe';
import type { TopupRequest } from './types';
import { errorResponse, microToUsd, normalizeSiteUrl } from './utils';

const accountApp = new Hono<AuthEnv>();
accountApp.use('*', requireToken);

accountApp.get('/balance', (c) => {
	const { wallet } = getAuth(c);
	return Response.json({
		balance_usd: microToUsd(wallet.balance_micro_usd),
		currency: c.env.DEFAULT_CURRENCY,
		min_topup_usd: parseFloat(c.env.MIN_TOPUP_USD),
		low_balance_threshold_usd: parseFloat(c.env.LOW_BALANCE_THRESHOLD_USD),
	});
});

accountApp.post('/topup', async (c) => {
	const { wallet, token } = getAuth(c);
	let body: TopupRequest;
	try {
		body = (await c.req.json()) as TopupRequest;
	} catch {
		return errorResponse(400, 'invalid_body', 'JSON body required.');
	}

	const minTopup = parseFloat(c.env.MIN_TOPUP_USD);
	const amount = Number(body.amount_usd);
	if (!Number.isFinite(amount) || amount < minTopup) {
		return errorResponse(
			400,
			'amount_below_minimum',
			`Top-up must be at least $${minTopup.toFixed(2)} USD.`
		);
	}

	if (!body.return_url || !body.cancel_url || !body.site_url) {
		return errorResponse(400, 'missing_url', 'return_url, cancel_url, and site_url are required.');
	}

	let siteUrl: string;
	try {
		siteUrl = normalizeSiteUrl(body.site_url);
	} catch {
		return errorResponse(400, 'invalid_site_url', 'site_url could not be parsed.');
	}
	if (siteUrl !== token.site_url) {
		return errorResponse(403, 'site_mismatch', 'site_url does not match the bound site.');
	}

	// Compute Stripe processor fee (transparent pass-through).
	const feePercent = parseFloat(c.env.STRIPE_FEE_PERCENT);
	const feeFixed = parseFloat(c.env.STRIPE_FEE_FIXED_USD);
	const processorFee = +(amount * feePercent + feeFixed).toFixed(2);

	const checkout = await createCheckoutSession(c.env, {
		walletId: wallet.id,
		stripeCustomerId: wallet.stripe_customer_id,
		creditAmountUsd: amount,
		processorFeeUsd: processorFee,
		returnUrl: body.return_url,
		cancelUrl: body.cancel_url,
		siteUrl,
	});

	// Pre-record the pending session so the webhook can mark it completed.
	await c.env.NVOOS_DB.prepare(
		`INSERT INTO topup_sessions (id, wallet_id, amount_micro_usd, processor_fee_micro_usd, status, created_at)
		 VALUES (?1, ?2, ?3, ?4, 'pending', ?5)`
	)
		.bind(
			checkout.id,
			wallet.id,
			Math.round(amount * 1_000_000),
			Math.round(processorFee * 1_000_000),
			Date.now()
		)
		.run();

	return Response.json({
		checkout_url: checkout.url,
		session_id: checkout.id,
		amount_usd: amount,
		processor_fee_usd: processorFee,
	});
});

accountApp.post('/revoke', async (c) => {
	const { token } = getAuth(c);
	await c.env.NVOOS_DB.prepare(
		'UPDATE connect_tokens SET revoked_at = ?1 WHERE id = ?2'
	)
		.bind(Date.now(), token.id)
		.run();
	return Response.json({ revoked: true });
});

accountApp.get('/ledger', async (c) => {
	const { wallet } = getAuth(c);
	const limit = Math.min(parseInt(c.req.query('limit') ?? '50', 10) || 50, 200);
	const rows = await c.env.NVOOS_DB.prepare(
		`SELECT id, request_id, model, prompt_tokens, completion_tokens,
		        wholesale_micro_usd, fee_micro_usd, total_micro_usd, status, created_at
		 FROM ledger WHERE wallet_id = ?1 ORDER BY id DESC LIMIT ?2`
	)
		.bind(wallet.id, limit)
		.all<{
			id: number;
			request_id: string;
			model: string | null;
			prompt_tokens: number;
			completion_tokens: number;
			wholesale_micro_usd: number;
			fee_micro_usd: number;
			total_micro_usd: number;
			status: string;
			created_at: number;
		}>();

	const entries = (rows.results ?? []).map((r) => ({
		id: r.id,
		request_id: r.request_id,
		model: r.model,
		prompt_tokens: r.prompt_tokens,
		completion_tokens: r.completion_tokens,
		wholesale_usd: microToUsd(r.wholesale_micro_usd),
		fee_usd: microToUsd(r.fee_micro_usd),
		total_usd: microToUsd(r.total_micro_usd),
		status: r.status,
		created_at: r.created_at,
	}));

	return Response.json({ entries, count: entries.length });
});

export default accountApp;
