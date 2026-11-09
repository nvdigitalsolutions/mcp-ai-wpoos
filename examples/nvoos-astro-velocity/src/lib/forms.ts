// ---------------------------------------------------------------------------
// forms.ts — the pure half of the storefront's form endpoint (Proposal 138).
//
// Kept out of the route so every guard is unit-testable without a Request
// round-trip. The endpoint applies them in this order, cheapest first:
//   body cap → parse → honeypot → time trap → per-IP throttle → send
//
// A bot that trips the honeypot or the time trap is ACCEPTED SILENTLY. A
// rejection teaches the sender which field to change; silence teaches nothing.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';

/** Bodies larger than this are refused before parsing. */
export const MAX_BYTES = 64 * 1024;

/** A human needs at least this long to fill a form (seconds). */
export const MIN_SECONDS = 2;

export const RATE_LIMIT = 5;
export const RATE_WINDOW_MS = 10 * 60 * 1000;

/** Fields the form itself owns — never echoed into the email. */
export const INTERNAL_FIELDS = new Set([
	'honeypot',
	'timestamp',
	'_redirect',
	'form',
	'post_id',
]);

export type Fields = Record<string, string>;

/**
 * Normalise a JSON or urlencoded payload to string pairs.
 *
 * Returns undefined for an oversized, unparseable or non-object body. The
 * declared length is checked first (cheap) and then measured, because
 * content-length is advisory and absent on a chunked body.
 */
export async function readFields(request: Request): Promise<Fields | undefined> {
	const declared = Number(request.headers.get('content-length') ?? 0);
	if (declared > MAX_BYTES) {
		return undefined;
	}
	const raw = await request.text();
	if (Buffer.byteLength(raw, 'utf8') > MAX_BYTES) {
		return undefined;
	}
	const type = request.headers.get('content-type') ?? '';
	try {
		if (type.includes('application/json')) {
			const parsed = JSON.parse(raw) as Record<string, unknown>;
			if (parsed === null || typeof parsed !== 'object') {
				return undefined;
			}
			const out: Fields = {};
			for (const [key, value] of Object.entries(parsed)) {
				if (typeof value === 'string' || typeof value === 'number') {
					out[key] = String(value);
				}
			}
			return out;
		}
		const form = await new Response(raw, { headers: { 'content-type': type } }).formData();
		const out: Fields = {};
		for (const [key, value] of form.entries()) {
			if (typeof value === 'string') {
				out[key] = value;
			}
		}
		return out;
	} catch {
		return undefined;
	}
}

/**
 * A filled honeypot, or a submit too fast for a human.
 *
 * `now` is injectable so the tests do not have to sleep. A missing timestamp
 * is NOT treated as a bot: some clients drop hidden fields, and a false
 * positive here would silently discard a real enquiry.
 */
export function isBot(fields: Fields, now: number = Date.now()): boolean {
	if ((fields.honeypot ?? '').trim() !== '') {
		return true;
	}
	const stamp = Number(fields.timestamp ?? 0);
	return stamp > 0 && (now - stamp) / 1000 < MIN_SECONDS;
}

/**
 * Per-IP fixed-window throttle.
 *
 * A closure rather than module state so each caller — and each test — owns its
 * own buckets. In production one managed site runs one Node process, so this
 * is the app layer; a CDN/WAF rate rule is the real edge.
 */
export function createThrottle(
	limit: number = RATE_LIMIT,
	windowMs: number = RATE_WINDOW_MS
): (ip: string, now?: number) => boolean {
	const hits = new Map<string, number[]>();
	return (ip: string, now: number = Date.now()): boolean => {
		const recent = (hits.get(ip) ?? []).filter((at) => now - at < windowMs);
		recent.push(now);
		hits.set(ip, recent);
		return recent.length > limit;
	};
}

/** `key: value` lines for the fields the form does not own. */
export function visibleRows(fields: Fields): string[] {
	return Object.entries(fields)
		.filter(([key]) => !INTERNAL_FIELDS.has(key))
		.map(([key, value]) => `${key}: ${value.slice(0, 2000)}`);
}

/**
 * The same payload inside one bucket is one email, so a double-click or a
 * retried request cannot deliver twice.
 */
export function idempotencyKey(rows: string[]): string {
	return createHash('sha256').update(rows.join('\n')).digest('hex').slice(0, 32);
}

/** Plain-text body. A text part always beats HTML-only for deliverability. */
export function buildText(rows: string[]): string {
	return `${rows.join('\n')}\n\n--\nSent from a form on the website.`;
}

/** The visitor's address, when they left a usable one. */
export function replyToOf(fields: Fields): string | undefined {
	const value = (fields.email ?? fields['e-mail'] ?? '').trim();
	return value.includes('@') ? value : undefined;
}
