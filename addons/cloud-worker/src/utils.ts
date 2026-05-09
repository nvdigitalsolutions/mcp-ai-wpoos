/**
 * Generic helpers — JSON / error responses, micro-USD math, hashing,
 * site-URL normalization, request IDs.
 *
 * Kept dependency-free so it's trivially unit-testable.
 */

import type { PricingMath } from './types';

/** Convert a USD amount (e.g. 0.0123) to integer micro-USD. */
export function usdToMicro(usd: number): number {
	if (!Number.isFinite(usd) || usd < 0) {
		return 0;
	}
	// Round half-away-from-zero; 6 decimal places of fidelity.
	return Math.round(usd * 1_000_000);
}

/** Convert integer micro-USD back to USD as a JS number. */
export function microToUsd(micro: number): number {
	if (!Number.isFinite(micro)) {
		return 0;
	}
	return Math.round(micro) / 1_000_000;
}

/**
 * Apply the 7% markup. Returns integer micro-USD across all three columns
 * (wholesale, fee, total). Must match the plugin's `apply_markup()` to the
 * cent.
 */
export function applyMarkup(wholesaleUsd: number, markupRate: number): PricingMath {
	const wholesale = usdToMicro(wholesaleUsd);
	const fee = Math.round(wholesale * markupRate);
	return {
		wholesale_micro_usd: wholesale,
		fee_micro_usd: fee,
		total_micro_usd: wholesale + fee,
	};
}

/** SHA-256 hex digest using the Web Crypto API (available in Workers). */
export async function sha256Hex(input: string): Promise<string> {
	const data = new TextEncoder().encode(input);
	const buf = await crypto.subtle.digest('SHA-256', data);
	const bytes = new Uint8Array(buf);
	let hex = '';
	for (let i = 0; i < bytes.length; i += 1) {
		hex += bytes[i]!.toString(16).padStart(2, '0');
	}
	return hex;
}

/**
 * Normalize a site URL for site-binding comparison. Lowercases the host,
 * strips any path/query/hash, removes trailing slash. Throws on invalid input.
 */
export function normalizeSiteUrl(url: string): string {
	const parsed = new URL(url);
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		throw new Error('site_url must be http or https');
	}
	const host = parsed.host.toLowerCase();
	return `${parsed.protocol}//${host}`;
}

/**
 * Generate a high-entropy Connect Token. 32 bytes of random data, base64url.
 * The plaintext is shown to the customer once; only the SHA-256 hash is stored.
 */
export function generateConnectToken(): string {
	const bytes = new Uint8Array(32);
	crypto.getRandomValues(bytes);
	return 'nvc_' + base64UrlEncode(bytes);
}

function base64UrlEncode(bytes: Uint8Array): string {
	let str = '';
	for (let i = 0; i < bytes.length; i += 1) {
		str += String.fromCharCode(bytes[i]!);
	}
	return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Get a stable request id from the CF-Ray header, or fall back to a uuid. */
export function getRequestId(req: Request): string {
	const ray = req.headers.get('cf-ray');
	if (ray) {
		return `ray_${ray}`;
	}
	return crypto.randomUUID();
}

/** Generic JSON error response. */
export function errorResponse(status: number, code: string, message: string): Response {
	return Response.json(
		{
			error: { code, message },
		},
		{ status }
	);
}

/**
 * Constant-time string equality. Used for sensitive comparisons so we don't
 * leak signature/token bytes via timing.
 */
export function timingSafeEqual(a: string, b: string): boolean {
	if (a.length !== b.length) {
		return false;
	}
	let diff = 0;
	for (let i = 0; i < a.length; i += 1) {
		diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
	}
	return diff === 0;
}
