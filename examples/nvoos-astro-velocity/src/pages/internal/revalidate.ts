// ---------------------------------------------------------------------------
// /internal/revalidate — Proposal 104 §4.1 (owner Q3, now approved):
// same-deploy tag-based cache invalidation for the Astro 7 route cache.
//
// Deliberately NOT under /api/* (that prefix is the commerce contract
// proxy). Auth is a shared secret bearer (NVOOS_REVALIDATE_TOKEN, hash
// compared in constant time) — fail closed: unset token = 503, bad token =
// 401. The bridge webhook (or any CI hook) can POST here instead of
// triggering a full mirror redeploy for content changes that must be
// visible within a deployment's lifetime.
//
// NOTE: with the memoryCache provider, invalidation is per-process — the
// route purges the instance that serves the request. Fleet-wide invalidation
// remains the redeploy loop (Velocity redeploys on every mirror sync).
// ---------------------------------------------------------------------------

import type { APIRoute } from 'astro';
import { createHash, timingSafeEqual } from 'node:crypto';

const DEFAULT_TAGS = ['products'];

function tokenMatches(provided: string, expected: string): boolean {
	const a = createHash('sha256').update(provided).digest();
	const b = createHash('sha256').update(expected).digest();
	return timingSafeEqual(a, b);
}

export const POST: APIRoute = async ({ request, cache }) => {
	const token = process.env.NVOOS_REVALIDATE_TOKEN;
	if (!token) {
		return new Response('revalidation disabled', { status: 503 });
	}
	const auth = request.headers.get('authorization') ?? '';
	const provided = auth.startsWith('Bearer ') ? auth.slice(7).trim() : '';
	if (!provided || !tokenMatches(provided, token)) {
		return new Response('unauthorized', { status: 401 });
	}
	if (!cache.enabled) {
		return new Response('route cache disabled', { status: 503 });
	}

	let tags: string[] = [];
	try {
		const body = (await request.json()) as { tags?: unknown };
		if (Array.isArray(body?.tags)) {
			tags = body.tags.map((tag) => String(tag));
		}
	} catch {
		// Empty/invalid body → the default tag set.
	}
	const purge = tags.length > 0 ? tags : DEFAULT_TAGS;
	await cache.invalidate({ tags: purge });

	return new Response(JSON.stringify({ purged: purge }), {
		status: 200,
		headers: {
			'content-type': 'application/json; charset=utf-8',
			'cache-control': 'no-store',
		},
	});
};
