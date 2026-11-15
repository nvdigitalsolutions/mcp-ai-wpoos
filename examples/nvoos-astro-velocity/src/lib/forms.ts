// ---------------------------------------------------------------------------
// forms.ts — the pure half of the storefront's form endpoint (Proposal 138).
//
// Kept out of the route so every guard is unit-testable without a Request
// round-trip. The endpoint applies them in this order, cheapest first:
//   body cap → parse → honeypot → time trap → per-IP throttle → send
//
// "Per-IP" is `clientIp()` below, not the socket peer: behind the platform
// proxy the peer is the proxy for every visitor.
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

/** How many addresses the throttle will remember before it prunes. */
const MAX_TRACKED_IPS = 5000;

/** A bare IPv4/IPv6 literal — X-Forwarded-For is attacker-adjacent text. */
function isIpAddress(value: string): boolean {
	// Colons first: an IPv4-mapped address (`::ffff:127.0.0.1`) also splits into
	// four dot-groups, so a dot-count test alone would misread it.
	if (value.includes(':')) {
		return /^[0-9a-f:.]+$/i.test(value);
	}
	const parts = value.split('.');
	return (
		parts.length === 4 &&
		parts.every((part) => /^[0-9]{1,3}$/.test(part) && Number(part) <= 255)
	);
}

/** Loopback / RFC1918 / link-local — i.e. something is proxying for us. */
function isLocalPeer(address: string): boolean {
	if (!isIpAddress(address)) {
		return false;
	}
	const mapped = address.startsWith('::ffff:') ? address.slice(7) : address;
	if (mapped.includes(':')) {
		return mapped === '::1' || mapped === '::' || /^(?:fc|fd|fe[89ab])/i.test(mapped);
	}
	const [a, b] = mapped.split('.').map(Number);
	return (
		a === 10 ||
		a === 127 ||
		(a === 192 && b === 168) ||
		(a === 172 && b >= 16 && b <= 31) ||
		(a === 169 && b === 254)
	);
}

/**
 * The visitor's address, for the per-IP throttle.
 *
 * Behind a reverse proxy (Cloudways nginx, the managed tier) the socket peer is
 * the PROXY, so keying the throttle on `clientAddress` alone silently becomes
 * one site-wide bucket: the sixth enquiry in ten minutes from anybody is
 * refused. That is not hypothetical — it was measured on a managed deploy
 * (2026-11-15).
 *
 * The proxy APPENDS the address it saw to X-Forwarded-For, so the RIGHTMOST hop
 * is the value a client cannot forge: a visitor-supplied header is prepended,
 * never replaced. Trust that hop only when the peer is itself loopback/private
 * (proof a proxy is in front); otherwise the peer IS the visitor.
 */
export function clientIp(request: Request, clientAddress?: string | null): string {
	const peer = (clientAddress ?? '').trim();
	if (peer !== '' && !isLocalPeer(peer)) {
		return peer;
	}
	const hops = (request.headers.get('x-forwarded-for') ?? '')
		.split(',')
		.map((hop) => hop.trim())
		.filter((hop) => hop !== '');
	const rightmost = hops.length > 0 ? hops[hops.length - 1] : '';
	return isIpAddress(rightmost) ? rightmost : peer || 'unknown';
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
		// Keep the newest `limit + 1` stamps: still over the limit (so a flooded
		// bucket stays closed) without letting one key grow without bound.
		if (recent.length > limit + 1) {
			recent.splice(0, recent.length - (limit + 1));
		}
		hits.set(ip, recent);
		// Per-address keys make the map itself unbounded, so a unique-address
		// flood is pruned once it grows: drop only fully-expired buckets.
		if (hits.size > MAX_TRACKED_IPS) {
			for (const [key, stamps] of hits) {
				if (stamps.every((at) => now - at >= windowMs)) {
					hits.delete(key);
				}
			}
		}
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
