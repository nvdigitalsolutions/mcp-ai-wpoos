// ---------------------------------------------------------------------------
// Preview tokens (Proposal 107 §3.2, D3) — the Next.js Draft Mode pattern:
// a short-lived HMAC token minted per preview, exchanged once at
// /preview/{type}/{id} for a host-only cookie session, never persisted in
// URLs afterwards.
//
// Shared by the content_* tool slice (minting) and the /preview route
// (verification) so both sides hold one implementation. Fail-open default
// secret for local dev — production mounts MUST set NVOOS_PREVIEW_SECRET
// (the ops table in the implementation plan §3.2).
// ---------------------------------------------------------------------------

import { createHmac, timingSafeEqual } from 'node:crypto';

const DEFAULT_PREVIEW_TTL_MS = 30 * 60 * 1000;

export const PREVIEW_COOKIE = 'nvoos-preview';

export function previewTtlMs(): number {
	const ttl = Number(process.env.NVOOS_PREVIEW_TTL_MS);
	return Number.isFinite(ttl) && ttl > 0 ? ttl : DEFAULT_PREVIEW_TTL_MS;
}

export function previewSecret(): string {
	return process.env.NVOOS_PREVIEW_SECRET || 'nvoos-dev-preview-secret';
}

export function signPreviewToken(type: string, id: number, expiry: number): string {
	const payload = `${type}:${id}:${expiry}`;
	const sig = createHmac('sha256', previewSecret()).update(payload).digest('base64url');
	return `${expiry}.${sig}`;
}

/** Constant-time token verification — expiry then HMAC. */
export function verifyPreviewToken(type: string, id: number, token: string): boolean {
	const parts = token.split('.');
	if (parts.length !== 2 || !/^\d+$/.test(parts[0])) {
		return false;
	}
	const expiry = Number(parts[0]);
	if (expiry < Date.now()) {
		return false;
	}
	const payload = `${type}:${id}:${expiry}`;
	const expected = createHmac('sha256', previewSecret()).update(payload).digest('base64url');
	const a = Buffer.from(parts[1]);
	const b = Buffer.from(expected);
	return a.length === b.length && timingSafeEqual(a, b);
}

/** Whether the request carries an active preview session cookie. */
export function hasPreviewSession(cookieHeader: string | null | undefined): boolean {
	return new RegExp(`(?:^|;\\s*)${PREVIEW_COOKIE}=1\\b`).test(cookieHeader ?? '');
}
