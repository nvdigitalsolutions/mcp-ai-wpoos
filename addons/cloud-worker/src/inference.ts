/**
 * Inference proxy — forwards OpenAI-compatible requests to the Cloudflare AI
 * Gateway → OpenRouter, captures wholesale cost, applies markup, debits the
 * wallet, and returns the response with billing headers.
 *
 * Streaming (SSE) responses are passed through with a TransformStream that
 * watches for the final `data: [DONE]` chunk to reconcile the cost.
 */

import { Hono, type Context } from 'hono';
import { getAuth, requireToken, type AuthEnv } from './auth';
import { billingHeaders, computeBilling, debitAndLog } from './billing';
import type { PricingMath } from './types';
import { errorResponse, getRequestId, microToUsd } from './utils';

const inferenceApp = new Hono<AuthEnv>();

inferenceApp.use('*', requireToken);

/** Pre-flight balance check — refuses requests when balance ≤ 0. */
function assertBalance(walletBalanceMicroUsd: number): Response | null {
	if (walletBalanceMicroUsd <= 0) {
		return errorResponse(
			402,
			'insufficient_balance',
			'Wallet balance is empty. Please top up at https://nvoos.cloud/.'
		);
	}
	return null;
}

/**
 * POST /v1/chat/completions
 * POST /v1/embeddings
 * GET  /v1/models
 *
 * All three are forwarded to the AI Gateway with the master OpenRouter key.
 */
inferenceApp.all('/v1/chat/completions', (c) => proxy(c, '/chat/completions'));
inferenceApp.all('/v1/embeddings', (c) => proxy(c, '/embeddings'));
inferenceApp.all('/v1/models', (c) => proxy(c, '/models'));

async function proxy(ctx: Context<AuthEnv>, path: string): Promise<Response> {
	const auth = getAuth(ctx);

	if (path !== '/models') {
		const refusal = assertBalance(auth.wallet.balance_micro_usd);
		if (refusal) {
			return refusal;
		}
	}

	const upstreamBase = ctx.env.CF_AI_GATEWAY_URL.replace(/\/+$/, '');
	const upstreamUrl = `${upstreamBase}${path}`;

	// Read body once so we can reuse it (and inspect `model` for the ledger).
	const method = ctx.req.method;
	let bodyText: string | null = null;
	let model: string | null = null;
	if (method !== 'GET' && method !== 'HEAD') {
		bodyText = await ctx.req.text();
		try {
			const parsed = JSON.parse(bodyText) as { model?: unknown; stream?: unknown };
			if (typeof parsed.model === 'string') {
				model = parsed.model;
			}
		} catch {
			// Not JSON — let upstream return a 400.
		}
	}

	const headers = new Headers();
	headers.set('Authorization', `Bearer ${ctx.env.OPENROUTER_API_KEY}`);
	headers.set('Content-Type', 'application/json');
	// Identify ourselves to OpenRouter for revenue-share attribution.
	headers.set('HTTP-Referer', 'https://nvoos.cloud');
	headers.set('X-Title', 'NV oOS Cloud');

	const upstreamReq = new Request(upstreamUrl, {
		method,
		headers,
		body: bodyText,
	});

	const upstreamRes = await fetch(upstreamReq);

	const requestId = getRequestId(ctx.req.raw);
	const isStream = upstreamRes.headers.get('content-type')?.includes('text/event-stream');

	if (path === '/models' || method === 'GET') {
		// No billing for catalogue fetches.
		return passthrough(upstreamRes, ctx.env.WORKER_VERSION);
	}

	if (isStream) {
		return handleStream(ctx, upstreamRes, model, requestId);
	}

	return handleJson(ctx, upstreamRes, model, requestId);
}

/**
 * Non-streaming JSON response: parse `usage.cost` (OpenRouter convention) or
 * the AI Gateway `cf-aig-cost-usd` header, debit, and return.
 */
async function handleJson(
	ctx: Context<AuthEnv>,
	upstreamRes: Response,
	model: string | null,
	requestId: string
): Promise<Response> {
	const auth = getAuth(ctx);
	const bodyText = await upstreamRes.text();

	let promptTokens = 0;
	let completionTokens = 0;
	let wholesaleUsd = 0;

	if (upstreamRes.ok) {
		try {
			const parsed = JSON.parse(bodyText) as {
				usage?: {
					prompt_tokens?: number;
					completion_tokens?: number;
					cost?: number;
					total_cost?: number;
				};
			};
			promptTokens = parsed.usage?.prompt_tokens ?? 0;
			completionTokens = parsed.usage?.completion_tokens ?? 0;
			wholesaleUsd = parsed.usage?.cost ?? parsed.usage?.total_cost ?? 0;
		} catch {
			// Body wasn't JSON — fall back to header.
		}
		if (wholesaleUsd === 0) {
			const headerCost = upstreamRes.headers.get('cf-aig-cost-usd');
			if (headerCost) {
				const parsedCost = parseFloat(headerCost);
				if (Number.isFinite(parsedCost)) {
					wholesaleUsd = parsedCost;
				}
			}
		}
	}

	const pricing = computeBilling(ctx.env, wholesaleUsd);
	const status = upstreamRes.ok ? 'ok' : 'error';

	try {
		await debitAndLog(ctx.env, {
			wallet: auth.wallet,
			tokenId: auth.token.id,
			requestId,
			model,
			promptTokens,
			completionTokens,
			pricing,
			status,
		});
	} catch (err) {
		if ((err as { code?: string }).code === 'insufficient_balance') {
			return errorResponse(402, 'insufficient_balance', 'Wallet drained mid-request.');
		}
		throw err;
	}

	const headers = new Headers(upstreamRes.headers);
	for (const [k, v] of Object.entries(billingHeaders(pricing))) {
		headers.set(k, v);
	}
	headers.set('X-NV-Worker-Version', ctx.env.WORKER_VERSION);
	headers.set('X-NV-Request-Id', requestId);

	return new Response(bodyText, {
		status: upstreamRes.status,
		statusText: upstreamRes.statusText,
		headers,
	});
}

/**
 * Streaming SSE: tee the upstream body so the client sees raw chunks while we
 * scan for the final `data: [DONE]` and the optional `usage` chunk to capture
 * the wholesale cost. The debit happens after the stream closes.
 */
function handleStream(
	ctx: Context<AuthEnv>,
	upstreamRes: Response,
	model: string | null,
	requestId: string
): Response {
	const auth = getAuth(ctx);
	if (!upstreamRes.body) {
		return passthrough(upstreamRes, ctx.env.WORKER_VERSION);
	}

	const decoder = new TextDecoder();
	let buffer = '';
	let promptTokens = 0;
	let completionTokens = 0;
	let wholesaleUsd = 0;

	const transform = new TransformStream<Uint8Array, Uint8Array>({
		transform(chunk, controller) {
			controller.enqueue(chunk);
			buffer += decoder.decode(chunk, { stream: true });
			// SSE messages are separated by blank lines.
			let nlIdx: number;
			while ((nlIdx = buffer.indexOf('\n\n')) !== -1) {
				const event = buffer.slice(0, nlIdx);
				buffer = buffer.slice(nlIdx + 2);
				const dataLine = event.split('\n').find((l) => l.startsWith('data:'));
				if (!dataLine) continue;
				const payload = dataLine.slice(5).trim();
				if (payload === '[DONE]' || payload === '') continue;
				try {
					const parsed = JSON.parse(payload) as {
						usage?: {
							prompt_tokens?: number;
							completion_tokens?: number;
							cost?: number;
							total_cost?: number;
						};
					};
					if (parsed.usage) {
						promptTokens = parsed.usage.prompt_tokens ?? promptTokens;
						completionTokens = parsed.usage.completion_tokens ?? completionTokens;
						wholesaleUsd =
							parsed.usage.cost ?? parsed.usage.total_cost ?? wholesaleUsd;
					}
				} catch {
					// Ignore non-JSON payloads.
				}
			}
		},
		async flush() {
			if (wholesaleUsd === 0) {
				const headerCost = upstreamRes.headers.get('cf-aig-cost-usd');
				if (headerCost) {
					const parsedCost = parseFloat(headerCost);
					if (Number.isFinite(parsedCost)) {
						wholesaleUsd = parsedCost;
					}
				}
			}
			const pricing = computeBilling(ctx.env, wholesaleUsd);
			try {
				await debitAndLog(ctx.env, {
					wallet: auth.wallet,
					tokenId: auth.token.id,
					requestId,
					model,
					promptTokens,
					completionTokens,
					pricing,
					status: upstreamRes.ok ? 'ok' : 'error',
				});
			} catch {
				// Insufficient-balance during stream — the client has already
				// received the data; we log a 0-balance ledger row in the
				// fallback below. Surfacing a mid-stream 402 is not possible.
			}
		},
	});

	const headers = new Headers(upstreamRes.headers);
	headers.set('X-NV-Worker-Version', ctx.env.WORKER_VERSION);
	headers.set('X-NV-Request-Id', requestId);

	// We can't know the final wholesale cost at header-write time; the plugin
	// falls back to its locally-computed estimate when these headers are
	// missing on a streamed response.
	return new Response(upstreamRes.body.pipeThrough(transform), {
		status: upstreamRes.status,
		statusText: upstreamRes.statusText,
		headers,
	});
}

function passthrough(upstreamRes: Response, workerVersion: string): Response {
	const headers = new Headers(upstreamRes.headers);
	headers.set('X-NV-Worker-Version', workerVersion);
	return new Response(upstreamRes.body, {
		status: upstreamRes.status,
		statusText: upstreamRes.statusText,
		headers,
	});
}

export default inferenceApp;
