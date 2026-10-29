// ---------------------------------------------------------------------------
// BFF proxy header transforms — unit tests for scripts/lib/proxy-headers.mjs.
//
// Pins the admin-proxy contract:
//   - same-origin login flow: wp-login.php bodies re-scoped to the app
//     origin, backend Locations become relative with redirect_to re-scoped
//   - host-only auth cookies (Domain stripped)
//   - spoof-safe forwarded headers (overwritten, not echoed)
//   - noindex on the proxied admin surface
// (2026-10-29 incidents: unauthenticated /wp-admin redirected to the backend
// origin and broke the login loop; the login form POSTed straight to the
// backend so the app origin never saw the session.)
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import type { ProxyRequestLike } from '../../scripts/lib/proxy-headers.d.mts';
import {
	HOP_BY_HOP,
	applyAdminResponseHeaders,
	buildUpstreamHeaders,
	requestOrigin,
	rewriteLocation,
	rewriteLoginPageHtml,
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

describe('buildUpstreamHeaders', () => {
	it('replaces the client host with the upstream host and sets spoof-safe forwarded headers', () => {
		const out = buildUpstreamHeaders(
			{
				host: 'astro.nvoos.cloud',
				cookie: 'wordpress_logged_in_abc=value',
				'user-agent': 'Playwright',
				'x-forwarded-for': '203.0.113.7, 10.0.0.1',
			},
			{ targetHost: 'nvoos.pro', origin: 'https://astro.nvoos.cloud' }
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
			{ targetHost: 'nvoos.pro', origin: 'https://astro.nvoos.cloud' }
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
				'content-type': 'application/x-www-form-urlencoded',
			},
			{ targetHost: 'nvoos.pro', origin: 'https://astro.nvoos.cloud' }
		);

		expect(out.origin).toBeUndefined();
		expect(out.referer).toBeUndefined();
		for (const name of HOP_BY_HOP) {
			expect(out[name]).toBeUndefined();
		}
		expect(out['content-type']).toBe('application/x-www-form-urlencoded');
	});

	it('carries the port through when the origin has one', () => {
		const out = buildUpstreamHeaders({}, { targetHost: 'nvoos.pro', origin: 'http://localhost:4321' });
		expect(out['x-forwarded-proto']).toBe('http');
		expect(out['x-forwarded-host']).toBe('localhost:4321');
		expect(out['x-forwarded-port']).toBe('4321');
	});
});

describe('rewriteLocation', () => {
	it('rewrites absolute backend URLs to relative paths', () => {
		expect(rewriteLocation('https://nvoos.pro/wp-login.php?reauth=1', TARGET)).toBe(
			'/wp-login.php?reauth=1'
		);
	});

	it('re-scopes an encoded absolute redirect_to onto the app origin', () => {
		expect(
			rewriteLocation(
				'https://nvoos.pro/wp-login.php?redirect_to=https%3A%2F%2Fnvoos.pro%2Fwp-admin%2F&reauth=1',
				TARGET
			)
		).toBe('/wp-login.php?redirect_to=%2Fwp-admin%2F&reauth=1');
	});

	it('re-scopes a raw (unencoded) absolute redirect_to', () => {
		expect(
			rewriteLocation('https://nvoos.pro/wp-login.php?redirect_to=https://nvoos.pro/wp-admin/', TARGET)
		).toBe('/wp-login.php?redirect_to=%2Fwp-admin%2F');
	});

	it('handles a bare-backend-origin redirect_to value', () => {
		expect(
			rewriteLocation(
				'https://nvoos.pro/wp-login.php?redirect_to=https%3A%2F%2Fnvoos.pro',
				TARGET
			)
		).toBe('/wp-login.php?redirect_to=%2F');
	});

	it('leaves foreign redirect_to values untouched (backend wp_safe_redirect gates them)', () => {
		const loc =
			'https://nvoos.pro/wp-login.php?redirect_to=https%3A%2F%2Fevil.example%2Fphish';
		expect(rewriteLocation(loc, TARGET)).toBe(
			'/wp-login.php?redirect_to=https%3A%2F%2Fevil.example%2Fphish'
		);
	});

	it('rewrites protocol-relative backend URLs to relative paths', () => {
		expect(rewriteLocation('//nvoos.pro/wp-admin/', TARGET)).toBe('/wp-admin/');
	});

	it('leaves relative paths untouched (they stay same-origin through the proxy)', () => {
		expect(rewriteLocation('/wp-admin/', TARGET)).toBe('/wp-admin/');
	});

	it('leaves foreign origins untouched', () => {
		expect(rewriteLocation('https://evil.example/phish', TARGET)).toBe(
			'https://evil.example/phish'
		);
	});

	it('passes through empty values and missing targets', () => {
		expect(rewriteLocation(undefined, TARGET)).toBeUndefined();
		expect(rewriteLocation('https://nvoos.pro/x', '')).toBe('https://nvoos.pro/x');
	});
});

describe('rewriteLoginPageHtml', () => {
	it('re-scopes the absolute form action and login links to the app origin', () => {
		const html = `<form name="loginform" id="loginform" action="https://nvoos.pro/wp-login.php" method="post">
<a href="https://nvoos.pro/wp-login.php?action=lostpassword">Lost your password?</a>`;
		const out = rewriteLoginPageHtml(html);
		expect(out).toContain('action="/wp-login.php"');
		expect(out).toContain('href="/wp-login.php?action=lostpassword"');
		expect(out).not.toContain('nvoos.pro/wp-login.php');
	});

	it('re-scopes the hidden redirect_to input value', () => {
		const html = `<input type="hidden" name="redirect_to" value="https://nvoos.pro/wp-admin/" />`;
		expect(rewriteLoginPageHtml(html)).toContain('name="redirect_to" value="/wp-admin/"');
	});

	it('leaves unrelated absolute URLs untouched', () => {
		const html = `<a href="https://nvoos.pro/">← Go to site</a>
<img src="https://nvoos.pro/wp-content/uploads/logo.png">`;
		const out = rewriteLoginPageHtml(html);
		expect(out).toContain('href="https://nvoos.pro/"');
		expect(out).toContain('src="https://nvoos.pro/wp-content/uploads/logo.png"');
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

	it('strips Domain from a single string cookie', () => {
		expect(stripDomainFromSetCookie('auth=value; path=/; Domain=nvoos.pro; Secure')).toBe(
			'auth=value; path=/; Secure'
		);
	});

	it('passes through undefined unchanged', () => {
		expect(stripDomainFromSetCookie(undefined)).toBeUndefined();
	});
});

describe('applyAdminResponseHeaders', () => {
	it('rewrites Location to a relative path and adds noindex without dropping other headers', () => {
		const out = applyAdminResponseHeaders(
			{
				location: 'https://nvoos.pro/wp-login.php?reauth=1',
				'content-type': 'text/html; charset=UTF-8',
			},
			{ targetSite: TARGET }
		);

		expect(out.location).toBe('/wp-login.php?reauth=1');
		expect(out['x-robots-tag']).toBe('noindex, nofollow');
		expect(out['content-type']).toBe('text/html; charset=UTF-8');
	});

	it('appends noindex to an existing robots tag instead of clobbering it', () => {
		const out = applyAdminResponseHeaders({ 'x-robots-tag': 'none' }, {
			targetSite: TARGET,
		});
		expect(out['x-robots-tag']).toBe('none, noindex');
	});
});
