// ---------------------------------------------------------------------------
// BFF proxy header transforms — unit tests for scripts/lib/proxy-headers.mjs.
//
// Pins the storefront proxy contract:
//   - host-only auth cookies (Domain stripped) — the commerce auth flow
//     (POST /api/auth/login) sets WordPress cookies through the proxy
//   - spoof-safe forwarded headers (overwritten, not echoed)
//   - no admin/login-page surface: wp-admin and wp-login.php are not
//     proxied (2026-10-29 decision — the admin is the standalone /manage SPA)
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import type { ProxyRequestLike } from '../../scripts/lib/proxy-headers.d.mts';
import {
	HOP_BY_HOP,
	buildUpstreamHeaders,
	requestOrigin,
	stripDomainFromSetCookie,
} from '../../scripts/lib/proxy-headers.mjs';

const ORIGIN = 'https://astro.nvoos.cloud';

function fakeRequest(headers: Record<string, string> = {}): ProxyRequestLike {
	return { headers, socket: { encrypted: false } };
}

describe('requestOrigin', () => {
	it('prefers x-forwarded-proto/host when the platform proxy terminates TLS', () => {
		const origin = requestOrigin(
			fakeRequest({
				host: 'astro.nvoos.cloud',
				'x-forwarded-proto': 'https, http',
				'x-forwarded-host': 'astro.nvoos.cloud',
			})
		);
		expect(origin).toBe('https://astro.nvoos.cloud');
	});

	it('falls back to the Host header and plain http without forwarded headers', () => {
		const origin = requestOrigin(fakeRequest({ host: 'localhost:4321' }));
		expect(origin).toBe('http://localhost:4321');
	});
});

describe('buildUpstreamHeaders', () => {
	it('replaces the client host with the upstream host and sets spoof-safe forwarded headers', () => {
		const out = buildUpstreamHeaders(
			{
				host: 'astro.nvoos.cloud',
				cookie: 'wordpress_logged_in_abc=value',
				'user-agent': 'Playwright',
				'x-forwarded-for': '203.0.113.7, 10.0.0.1',
			},
			{ targetHost: 'nvoos.pro', origin: ORIGIN }
		);

		expect(out.host).toBe('nvoos.pro');
		expect(out['x-forwarded-proto']).toBe('https');
		expect(out['x-forwarded-host']).toBe('astro.nvoos.cloud');
		expect(out['x-forwarded-port']).toBeUndefined();
		// The platform proxy's client chain passes through unchanged.
		expect(out['x-forwarded-for']).toBe('203.0.113.7, 10.0.0.1');
		// Session + identity headers pass through.
		expect(out.cookie).toBe('wordpress_logged_in_abc=value');
		expect(out['user-agent']).toBe('Playwright');
	});

	it('overwrites client-supplied forwarded proto/host (spoofable — never echoed)', () => {
		const out = buildUpstreamHeaders(
			{
				host: 'astro.nvoos.cloud',
				'x-forwarded-proto': 'http',
				'x-forwarded-host': 'evil.example',
			},
			{ targetHost: 'nvoos.pro', origin: ORIGIN }
		);

		expect(out['x-forwarded-proto']).toBe('https');
		expect(out['x-forwarded-host']).toBe('astro.nvoos.cloud');
	});

	it('drops origin and referer (no app-origin leak into backend logs) and hop-by-hop headers', () => {
		const out = buildUpstreamHeaders(
			{
				host: 'astro.nvoos.cloud',
				origin: 'https://astro.nvoos.cloud',
				referer: 'https://astro.nvoos.cloud/shop',
				connection: 'keep-alive',
				'transfer-encoding': 'chunked',
				'content-type': 'application/json',
			},
			{ targetHost: 'nvoos.pro', origin: ORIGIN }
		);

		expect(out.origin).toBeUndefined();
		expect(out.referer).toBeUndefined();
		for (const name of HOP_BY_HOP) {
			expect(out[name]).toBeUndefined();
		}
		expect(out['content-type']).toBe('application/json');
	});

	it('carries the port through when the origin has one', () => {
		const out = buildUpstreamHeaders({}, { targetHost: 'nvoos.pro', origin: 'http://localhost:4321' });
		expect(out['x-forwarded-proto']).toBe('http');
		expect(out['x-forwarded-host']).toBe('localhost:4321');
		expect(out['x-forwarded-port']).toBe('4321');
	});
});

describe('stripDomainFromSetCookie', () => {
	it('strips Domain attributes from every cookie', () => {
		expect(
			stripDomainFromSetCookie([
				'wordpress_logged_in_abc=value; path=/; Domain=nvoos.pro; HttpOnly',
				'wordpress_sec_abc=value; path=/; domain=.nvoos.pro',
			])
		).toEqual([
			'wordpress_logged_in_abc=value; path=/; HttpOnly',
			'wordpress_sec_abc=value; path=/',
		]);
	});

	it('strips Domain from a single string cookie', () => {
		expect(stripDomainFromSetCookie('auth=value; path=/; Domain=nvoos.pro; Secure')).toBe(
			'auth=value; path=/; Secure'
		);
	});

	it('passes through undefined unchanged', () => {
		expect(stripDomainFromSetCookie(undefined)).toBeUndefined();
	});
});
