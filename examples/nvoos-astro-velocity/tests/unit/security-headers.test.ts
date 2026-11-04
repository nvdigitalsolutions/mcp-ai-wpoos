// security-headers.test.ts — Proposal 104 D12: the per-response CSP builder
// and the static header set for Astro-handled documents.
import { describe, expect, it } from 'vitest';
import { createHash } from 'node:crypto';
import { buildCsp, STATIC_SECURITY_HEADERS } from '../../scripts/lib/security-headers.mjs';

const SAMPLE_HTML = [
	'<!doctype html><html><head>',
	'<script>console.log("inline a")</script>',
	'<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product"}</script>',
	'<script type="module" src="/_astro/client.js"></script>',
	'<script type="module">console.log("inline module b")</script>',
	'</head><body></body></html>',
].join('');

describe('buildCsp', () => {
	it('allows only self plus hashes of inline executable scripts', () => {
		const csp = buildCsp(SAMPLE_HTML);
		expect(csp).toContain("script-src 'self'");
		expect(csp).toContain("'sha256-");
		// The blanket is forbidden — scripts stay hash-only.
		expect(csp).not.toContain("script-src 'self' 'unsafe-inline'");
		// JSON-LD data blocks and external modules contribute no hashes.
		const hashes = (csp.match(/'sha256-[^']+'/g) ?? []).length;
		expect(hashes).toBe(2);
	});

	it('hashes a script identically to an independent computation', () => {
		const inline = 'console.log("inline a")';
		const expected = `'sha256-${createHash('sha256').update(inline).digest('base64')}'`;
		expect(buildCsp(`<script>${inline}</script>`)).toContain(expected);
	});

	it('covers style attributes, images, frames and base-uri', () => {
		const csp = buildCsp('<script>a()</script>');
		expect(csp).toContain("style-src 'self' 'unsafe-inline'");
		expect(csp).toContain("img-src 'self' data:");
		expect(csp).toContain("frame-ancestors 'none'");
		expect(csp).toContain("base-uri 'self'");
	});

	it('degrades to self-only when the document has no inline scripts', () => {
		const csp = buildCsp('<html><head></head><body>plain</body></html>');
		expect(csp).toContain("script-src 'self'");
		expect(csp).not.toContain("'sha256-");
	});

	it('skips application/json data blocks (the 105 theme-policy tag)', () => {
		const html = [
			'<script>console.log(1)</script>',
			'<script type="application/json" data-theme-policy>{"doc":{"policy":"auto"}}</script>',
		].join('');
		const hashes = (buildCsp(html).match(/'sha256-[^']+'/g) ?? []).length;
		expect(hashes).toBe(1); // the JSON data block contributes no hash
	});

	it('mirrors the Stripe csp-nonce meta into the payment-scoped script-src', () => {
		const html = '<html><head><meta property="csp-nonce" nonce="abc123def" /><script>a()</script></head></html>';
		const csp = buildCsp(html, { 'x-nvoos-csp-payment': '1' });
		expect(csp).toContain("'nonce-abc123def'");
		expect(csp).toContain('https://js.stripe.com');
		expect(csp).toContain("script-src 'self'");
	});

	it('ignores the csp-nonce meta outside the payment scope', () => {
		const csp = buildCsp('<meta property="csp-nonce" nonce="abc123def" /><script>a()</script>');
		expect(csp).not.toContain("'nonce-abc123def'");
		expect(csp).not.toContain('https://js.stripe.com');
	});

	it('does not mint a nonce source when the checkout page omits the meta', () => {
		const csp = buildCsp('<script>a()</script>', { 'x-nvoos-csp-payment': '1' });
		expect(csp).not.toContain("'nonce-");
		expect(csp).toContain('https://js.stripe.com');
	});

	it('allows the Stripe connect-src hosts on the payment scope', () => {
		const csp = buildCsp('<script>a()</script>', { 'x-nvoos-csp-payment': '1' });
		expect(csp).toContain('https://api.stripe.com');
		expect(csp).toContain('https://m.stripe.com');
		expect(csp).toContain('https://m.stripe.network');
	});

	it('still never allows blanket unsafe-inline scripts', () => {
		const csp = buildCsp('<meta property="csp-nonce" nonce="abc" /><script>a()</script>', {
			'x-nvoos-csp-payment': '1',
		});
		expect(csp).not.toContain("script-src 'self' 'unsafe-inline'");
	});
});

describe('static security headers', () => {
	it('ships the OWASP baseline', () => {
		expect(STATIC_SECURITY_HEADERS['x-frame-options']).toBe('DENY');
		expect(STATIC_SECURITY_HEADERS['x-content-type-options']).toBe('nosniff');
		expect(STATIC_SECURITY_HEADERS['referrer-policy']).toBe('strict-origin-when-cross-origin');
		expect(STATIC_SECURITY_HEADERS['permissions-policy']).toContain('geolocation=()');
	});
});
