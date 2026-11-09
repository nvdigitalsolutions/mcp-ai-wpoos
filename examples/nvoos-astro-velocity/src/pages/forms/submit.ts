// ---------------------------------------------------------------------------
// /forms/submit — the storefront's own public form endpoint (Proposal 138).
//
// Deliberately NOT under /api/*: that prefix is the commerce-contract proxy
// (scripts/serve.mjs owns the backend URL — see its header), so an Astro route
// there would never be reached. Same reason as /internal/revalidate.
//
// Delivery is the provider HTTP API (src/lib/mail.ts) — the SAME module the
// forge vendors into a generated site for `forms.delivery: 'direct'`,
// byte-identical and guarded by bin/check-astro-app-parity.mjs. Credentials
// are read at request time and never committed or shipped to the browser.
//
// A native form post (no JS) gets a 303 back to /contact/ with a status flag;
// a fetch/JSON caller gets a JSON body. Both run the same guards.
// ---------------------------------------------------------------------------

import type { APIRoute } from 'astro';
import { readMailConfig, runtimeEnv, sendMail } from '../../lib/mail';
import {
	buildText,
	createThrottle,
	idempotencyKey,
	isBot,
	readFields,
	replyToOf,
	visibleRows,
} from '../../lib/forms';

export const prerender = false;

// One managed site runs one Node process, so an in-memory bucket is the right
// app-layer control here; a CDN/WAF rate rule is the real edge.
const throttled = createThrottle();

const RETURN_PATH = '/contact/';

/** 303 so the browser follows with a GET and the POST is not replayed. */
function seeOther(origin: string, path: string): Response {
	return new Response(null, {
		status: 303,
		headers: {
			location: new URL(path, origin).toString(),
			'cache-control': 'no-store',
		},
	});
}

function wantsDocument(request: Request): boolean {
	return (request.headers.get('accept') ?? '').includes('text/html');
}

export const POST: APIRoute = async ({ request, clientAddress, url }) => {
	const asDocument = wantsDocument(request);
	const fail = (reason: string, status: number): Response => {
		console.error(`[forms] ${reason}`);
		return asDocument
			? seeOther(url.origin, `${RETURN_PATH}?error=1`)
			: new Response('Delivery failed', { status });
	};

	const fields = await readFields(request);
	if (!fields) {
		return asDocument
			? seeOther(url.origin, `${RETURN_PATH}?error=1`)
			: new Response('Bad request', { status: 400 });
	}

	// Accept silently: a rejection teaches the sender what to change.
	if (isBot(fields)) {
		return asDocument ? seeOther(url.origin, `${RETURN_PATH}?sent=1`) : Response.json({ ok: true });
	}

	const ip =
		clientAddress ??
		request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ??
		'unknown';
	if (throttled(ip)) {
		return asDocument
			? seeOther(url.origin, `${RETURN_PATH}?error=1`)
			: new Response('Too many requests', { status: 429 });
	}

	const rows = visibleRows(fields);
	if (rows.length === 0) {
		return asDocument
			? seeOther(url.origin, `${RETURN_PATH}?error=1`)
			: new Response('Empty submission', { status: 400 });
	}

	const result = await sendMail(readMailConfig(runtimeEnv()), {
		text: buildText(rows),
		replyTo: replyToOf(fields),
		idempotencyKey: idempotencyKey(rows),
	});

	if (!result.ok) {
		return fail(`delivery failed (${result.status}): ${result.detail ?? ''}`, 502);
	}

	return asDocument ? seeOther(url.origin, `${RETURN_PATH}?sent=1`) : Response.json({ ok: true });
};
