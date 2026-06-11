/**
 * NV oOS Cloud — Cloudflare Worker entry point.
 *
 * Mounts:
 *   GET  /              → health/version
 *   GET  /v1/health     → health
 *   /v1/chat/completions, /v1/embeddings, /v1/models  → inference (auth)
 *   /v1/account/*       → balance, topup, revoke, ledger (auth)
 *   /connect/*          → public connect flow (no auth)
 *   /stripe/webhook     → Stripe webhook (signature verified)
 */

import { Hono } from 'hono';
import accountApp from './account';
import connectApp from './connect';
import inferenceApp from './inference';
import subscriptionsApp from './subscriptions';
import { creditWallet } from './billing';
import { verifyWebhook, type StripeWebhookEvent } from './stripe';
import type { Env } from './types';
import { errorResponse } from './utils';

const app = new Hono<{ Bindings: Env }>();

app.get('/', (c) => Response.json({ service: 'nvoos-cloud', version: c.env.WORKER_VERSION }));
app.get('/v1/health', (c) =>
	Response.json({ ok: true, version: c.env.WORKER_VERSION, time: Date.now() })
);

// Public connect flow.
app.route('/connect', connectApp);

// Authenticated APIs.
app.route('/', inferenceApp);
app.route('/v1/account', accountApp);

// SaaS subscription & tenant management.
app.route('/v1', subscriptionsApp);

// Stripe webhook — signature verified, idempotent.
app.post('/stripe/webhook', async (c) => {
	const rawBody = await c.req.text();
	const sig = c.req.header('Stripe-Signature') ?? null;

	let event: StripeWebhookEvent;
	try {
		event = await verifyWebhook(rawBody, sig, c.env.STRIPE_WEBHOOK_SECRET);
	} catch (err) {
		return errorResponse(400, 'webhook_verification_failed', (err as Error).message);
	}

	if (event.type !== 'checkout.session.completed') {
		// Acknowledge but ignore unrelated events.
		return Response.json({ received: true, ignored: true, type: event.type });
	}

	const session = event.data.object;
	if (!session.id) {
		return errorResponse(400, 'malformed_session', 'Missing session id.');
	}

	// Idempotent — a previous retry of this event already credited the wallet.
	const dup = await c.env.NVOOS_DB.prepare(
		'SELECT id FROM topup_sessions WHERE event_id = ?1 LIMIT 1'
	)
		.bind(event.id)
		.first<{ id: string }>();
	if (dup) {
		return Response.json({ received: true, idempotent: true });
	}

	const row = await c.env.NVOOS_DB.prepare(
		'SELECT id, wallet_id, amount_micro_usd, status FROM topup_sessions WHERE id = ?1 LIMIT 1'
	)
		.bind(session.id)
		.first<{ id: string; wallet_id: string; amount_micro_usd: number; status: string }>();

	if (!row) {
		// Could be a session created outside our flow; ignore safely.
		return Response.json({ received: true, ignored: true, reason: 'unknown_session' });
	}

	if (row.status === 'completed') {
		return Response.json({ received: true, idempotent: true });
	}

	await c.env.NVOOS_DB.prepare(
		`UPDATE topup_sessions
		 SET status = 'completed', completed_at = ?1, event_id = ?2
		 WHERE id = ?3`
	)
		.bind(Date.now(), event.id, row.id)
		.run();

	await creditWallet(c.env, row.wallet_id, row.amount_micro_usd);

	return Response.json({ received: true, credited_micro_usd: row.amount_micro_usd });
});

// 404 fallback.
app.notFound((c) =>
	Response.json(
		{ error: { code: 'not_found', message: `No route for ${c.req.method} ${c.req.path}` } },
		{ status: 404 }
	)
);

export default app;
