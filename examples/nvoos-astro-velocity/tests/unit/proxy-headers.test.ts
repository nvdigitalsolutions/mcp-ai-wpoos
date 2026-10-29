// ---------------------------------------------------------------------------
// BFF proxy header transforms — unit tests for scripts/lib/proxy-headers.mjs.
//
// Pins the admin-proxy contract: same-origin Location rewriting (no backend
// origin leaks), host-only auth cookies, and noindex on the proxied admin
// surface (incident follow-up 2026-10-29: unauthenticated /wp-admin requests
// redirected to the backend origin and broke the login loop).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import type { ProxyRequestLike } from '../../scripts/lib/proxy-headers.d.mts';
import {
	applyAdminResponseHeaders,
	requestOrigin,
	rewriteLocation,
	stripDomainFromSetCookie,
} from '../../scripts/lib/proxy-headers.mjs';

const TARGET = 'https://nvoos.pro';
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

describe('rewriteLocation', () => {
	it('rewrites absolute backend URLs onto the request origin', () => {
		expect(
			rewriteLocation('https://nvoos.pro/wp-login.php?redirect_to=%2Fwp-admin%2F&reauth=1', TARGET, ORIGIN)
		).toBe('https://astro.nvoos.cloud/wp-login.php?redirect_to=%2Fwp-admin%2F&reauth=1');
	});

	it('rewrites protocol-relative backend URLs', () => {
		expect(rewriteLocation('//nvoos.pro/wp-admin/', TARGET, ORIGIN)).toBe(
			'https://astro.nvoos.cloud/wp-admin/'
		);
	});

	it('leaves relative paths untouched (they stay same-origin through the proxy)', () => {
		expect(rewriteLocation('/wp-admin/', TARGET, ORIGIN)).toBe('/wp-admin/');
	});

	it('leaves foreign origins untouched', () => {
		expect(rewriteLocation('https://evil.example/phish', TARGET, ORIGIN)).toBe(
			'https://evil.example/phish'
		);
	});

	it('passes through empty values and missing targets', () => {
		expect(rewriteLocation(undefined, TARGET, ORIGIN)).toBeUndefined();
		expect(rewriteLocation('https://nvoos.pro/x', '', ORIGIN)).toBe('https://nvoos.pro/x');
	});
});

describe('stripDomainFromSetCookie', () => {
	it('strips Domain attributes from every cookie', () => {
		expect(
			stripDomainFromSetCookie([
				'wordpress_logged_in_abc=value; path=/; Domain=nvoos.pro; HttpOnly',
				'wordpress_sec_abc=value; path=/wp-admin; domain=.nvoos.pro',
			])
		).toEqual([
			'wordpress_logged_in_abc=value; path=/; HttpOnly',
			'wordpress_sec_abc=value; path=/wp-admin',
		]);
	});

	it('passes through non-array values unchanged', () => {
		expect(stripDomainFromSetCookie(undefined)).toBeUndefined();
		expect(stripDomainFromSetCookie('single=value')).toBe('single=value');
	});
});

describe('applyAdminResponseHeaders', () => {
	it('rewrites Location and adds noindex without dropping other headers', () => {
		const out = applyAdminResponseHeaders(
			{
				location: 'https://nvoos.pro/wp-login.php?reauth=1',
				'content-type': 'text/html; charset=UTF-8',
			},
			{ targetSite: TARGET, origin: ORIGIN }
		);

		expect(out.location).toBe('https://astro.nvoos.cloud/wp-login.php?reauth=1');
		expect(out['x-robots-tag']).toBe('noindex, nofollow');
		expect(out['content-type']).toBe('text/html; charset=UTF-8');
	});

	it('appends noindex to an existing robots tag instead of clobbering it', () => {
		const out = applyAdminResponseHeaders({ 'x-robots-tag': 'none' }, {
			targetSite: TARGET,
			origin: ORIGIN,
		});
		expect(out['x-robots-tag']).toBe('none, noindex');
	});
});
