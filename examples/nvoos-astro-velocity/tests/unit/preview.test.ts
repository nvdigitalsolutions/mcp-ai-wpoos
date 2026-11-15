// ---------------------------------------------------------------------------
// preview.test.ts — Proposal 107 §3.2: signed preview tokens + the session
// cookie predicate. Constant-time verification, expiry-first ordering,
// type/id binding, and the cookie regex that the content routes gate on.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import {
	hasPreviewSession,
	PREVIEW_COOKIE,
	signPreviewToken,
	verifyPreviewToken,
} from '../../src/lib/content/preview';

describe('preview tokens', () => {
	it('round-trips a signed token', () => {
		const expiry = Date.now() + 60_000;
		const token = signPreviewToken('post', 42, expiry);
		expect(verifyPreviewToken('post', 42, token)).toBe(true);
	});

	it('binds the token to type and id (no cross-doc replay)', () => {
		const expiry = Date.now() + 60_000;
		const token = signPreviewToken('post', 42, expiry);
		expect(verifyPreviewToken('page', 42, token)).toBe(false);
		expect(verifyPreviewToken('post', 43, token)).toBe(false);
	});

	it('rejects tampered signatures', () => {
		const expiry = Date.now() + 60_000;
		const token = signPreviewToken('post', 42, expiry);
		const [exp, sig] = token.split('.');
		const flipped = sig[0] === 'A' ? `B${sig.slice(1)}` : `A${sig.slice(1)}`;
		expect(verifyPreviewToken('post', 42, `${exp}.${flipped}`)).toBe(false);
	});

	it('rejects expired tokens before touching the HMAC', () => {
		const expiry = Date.now() - 1;
		const token = signPreviewToken('post', 42, expiry);
		expect(verifyPreviewToken('post', 42, token)).toBe(false);
	});

	it('rejects malformed tokens', () => {
		expect(verifyPreviewToken('post', 42, '')).toBe(false);
		expect(verifyPreviewToken('post', 42, 'nope')).toBe(false);
		expect(verifyPreviewToken('post', 42, 'abc.def')).toBe(false);
		expect(verifyPreviewToken('post', 42, '12345')).toBe(false);
	});

	it('tokens from a different secret never verify', () => {
		const previous = process.env.NVOOS_PREVIEW_SECRET;
		try {
			const expiry = Date.now() + 60_000;
			const token = signPreviewToken('post', 42, expiry);
			process.env.NVOOS_PREVIEW_SECRET = 'another-secret';
			expect(verifyPreviewToken('post', 42, token)).toBe(false);
		} finally {
			if (previous === undefined) {
				delete process.env.NVOOS_PREVIEW_SECRET;
			} else {
				process.env.NVOOS_PREVIEW_SECRET = previous;
			}
		}
	});
});

describe('hasPreviewSession', () => {
	it('detects the session cookie in any position', () => {
		expect(hasPreviewSession(`${PREVIEW_COOKIE}=1`)).toBe(true);
		expect(hasPreviewSession(`a=1; ${PREVIEW_COOKIE}=1; b=2`)).toBe(true);
	});

	it('ignores lookalikes and other cookies', () => {
		expect(hasPreviewSession(`${PREVIEW_COOKIE}=0`)).toBe(false);
		expect(hasPreviewSession(`x-${PREVIEW_COOKIE}=1`)).toBe(false);
		expect(hasPreviewSession('other=1')).toBe(false);
		expect(hasPreviewSession(null)).toBe(false);
		expect(hasPreviewSession(undefined)).toBe(false);
		expect(hasPreviewSession('')).toBe(false);
	});
});
