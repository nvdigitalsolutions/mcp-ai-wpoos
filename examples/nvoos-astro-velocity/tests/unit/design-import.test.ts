// ---------------------------------------------------------------------------
// design-import.test.ts — Phase 0 gates for the design-language import
// pipeline (Proposal 098): deterministic analyzer fixtures + the SSRF fetcher
// guard matrix. No network: every fetch test injects its fetch + DNS impl.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { analyzeDesignLanguage } from '../../src/lib/design-import/analyzer';
import { detectMode, fetchDesignSource, FetchGuardError } from '../../src/lib/design-import/fetcher';

const PUBLIC_IP = '93.184.216.34';

/** Injected DNS: every hostname resolves to a public address. */
const publicLookup = (async () => [{ address: PUBLIC_IP, family: 4 }]) as unknown as typeof import('node:dns/promises').lookup;

const EDITORIAL_HTML = `
<!doctype html>
<html>
<head>
<style>
:root {
	--color-accent: #b0413e;
	--color-background: #faf6f1;
	--color-text: #2b2b2b;
	--color-border: #e0d8cd;
}
body { font-family: 'Playfair Display', Georgia, serif; font-size: 16px; color: var(--color-text); background-color: var(--color-background); }
h1 { font-size: 40px; }
h2 { font-size: 28px; }
p { line-height: 1.6; }
.card { border-radius: 6px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); padding: 16px; background-color: #0a0a0a; }
.panel { background-color: #0a0a0a; }
.btn { padding: 8px 16px; border-radius: 4px; }
</style>
</head>
<body class="page">
<nav class="nav">Navigation</nav>
<div class="card">Card</div>
<div class="panel">Panel</div>
<button class="btn btn-primary">Button</button>
</body>
</html>
`;

describe('analyzer', () => {
	it('labels semantic CSS custom properties as high-confidence roles', () => {
		const { designLanguage } = analyzeDesignLanguage(EDITORIAL_HTML, [], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(designLanguage.palette.roles.accent).toMatchObject({
			value: '#b0413e',
			confidence: 'high',
			source: 'css:--color-accent',
		});
		expect(designLanguage.palette.roles.surface?.value).toBe('#faf6f1');
		expect(designLanguage.palette.roles.text?.value).toBe('#2b2b2b');
		expect(designLanguage.palette.roles['field.border']?.value).toBe('#e0d8cd');
	});

	it('keeps non-role colors in the fail-loud unassigned pool', () => {
		const { designLanguage } = analyzeDesignLanguage(EDITORIAL_HTML, [], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		// #0a0a0a appears twice as a background and gets no role — it must sit
		// in the pool, not be force-assigned. (#ffffff is claimed by the
		// derived accent-ink role, so it is NOT pool material.)
		expect(designLanguage.palette.unassigned).toContain('#0a0a0a');
	});

	it('extracts typography, spacing, radii, shadows, and vocabulary', () => {
		const { designLanguage } = analyzeDesignLanguage(EDITORIAL_HTML, [], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(designLanguage.typography.families[0]?.family).toBe('Playfair Display');
		expect(designLanguage.typography.scalePx).toContain(16);
		expect(designLanguage.typography.scalePx).toContain(40);
		expect(designLanguage.spacing.scalePx).toContain(16);
		expect(designLanguage.radii.valuesPx).toContain(6);
		expect(designLanguage.shadows.count).toBeGreaterThan(0);
		expect(designLanguage.components.vocabulary.card).toBeGreaterThan(0);
		expect(designLanguage.components.vocabulary.nav).toBeGreaterThan(0);
		expect(designLanguage.components.vocabulary.button).toBeGreaterThan(0);
	});

	it('emits a tokensDraft with confident roles only and derived lows excluded', () => {
		const { designLanguage } = analyzeDesignLanguage(EDITORIAL_HTML, [], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(designLanguage.tokensDraft.semantic.accent).toBe('#b0413e');
		// accent-ink is luminance-derived (low confidence) — fail loud, leave it out.
		expect(designLanguage.tokensDraft.semantic['accent-ink']).toBeUndefined();
	});

	it('parses rgba() CSS variables without NaN channels (the /g capture bug)', () => {
		// Regression: String.match() with a /g regex drops capture groups, so
		// rgba() variables produced #NaNNaNNaN roles on the first live run.
		const css = ':root { --wc-primary: rgba(114, 14, 236, 1); --wc-form-border-color: rgba(32, 7, 7, 0.8); }';
		const { designLanguage } = analyzeDesignLanguage('<body></body>', [css], {
			url: 'https://example.com/',
			mode: 'wordpress',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(designLanguage.palette.roles.accent?.value).toBe('#720eec');
		expect(designLanguage.palette.roles['field.border']?.value).toBe('#200707');
		expect(JSON.stringify(designLanguage.palette)).not.toContain('NaN');
	});

	it('labels on-accent text vars as accent-ink, never body text or accent', () => {
		// Regression: --wc-primary-text is the text ON the accent; the old
		// hint table grabbed it for `text` (and `primary` for `accent`).
		const css = ':root { --wc-primary: #720eec; --wc-primary-text: #fcfbfe; --wc-content-text: #333333; --wc-content-bg: #ffffff; }';
		const { designLanguage } = analyzeDesignLanguage('<body></body>', [css], {
			url: 'https://example.com/',
			mode: 'wordpress',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(designLanguage.palette.roles.accent?.value).toBe('#720eec');
		expect(designLanguage.palette.roles['accent-ink']?.value).toBe('#fcfbfe');
		expect(designLanguage.palette.roles['accent-ink']?.source).toBe('css:--wc-primary-text');
		expect(designLanguage.palette.roles.text?.value).toBe('#333333');
	});

	it('never lets malformed var values become roles', () => {
		const css = ':root { --brand-accent: rgb(var(--missing), 0, 0); }';
		const { designLanguage } = analyzeDesignLanguage('<body></body>', [css], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(JSON.stringify(designLanguage.palette)).not.toContain('NaN');
		expect(designLanguage.palette.roles.accent).toBeUndefined();
	});

	it('normalizes rgb, hsl, and short-hex values to six-digit hex', () => {
		const css = '.a { color: rgb(255, 0, 0); color: rgb(255,0,0); } .b { background-color: hsl(120, 100%, 25%); background-color: hsl(120,100%,25%); } .c { color: #abc; color: #abc; }';
		const { designLanguage } = analyzeDesignLanguage('<body></body>', [css], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		// The most frequent color/background declarations earn roles; the
		// tie-loser stays in the fail-loud pool. All values are normalized hex.
		expect(designLanguage.palette.roles.text?.value).toBe('#ff0000');
		expect(designLanguage.palette.roles.surface?.value).toBe('#008000');
		expect(designLanguage.palette.unassigned).toContain('#aabbcc');
	});

	it('survives empty and hostile input without crashing', () => {
		for (const html of ['', '<div>plain text</div>', '<style>unterminated { color: rgb(999, 0, 0) }</style>']) {
			const { designLanguage } = analyzeDesignLanguage(html, [], {
				url: 'https://example.com/',
				mode: 'generic',
				fetchedAt: '2026-10-31T00:00:00.000Z',
			});
			expect(typeof designLanguage.concept).toBe('string');
			expect(designLanguage.concept.length).toBeGreaterThan(0);
			expect(Array.isArray(designLanguage.palette.unassigned)).toBe(true);
		}
	});

	it('always ships the inspired-by adaptation brief and licensing note', () => {
		const { designLanguage } = analyzeDesignLanguage(EDITORIAL_HTML, [], {
			url: 'https://example.com/',
			mode: 'generic',
			fetchedAt: '2026-10-31T00:00:00.000Z',
		});
		expect(designLanguage.adaptationBrief.licensingNote).toContain('Inspired-by');
		expect(designLanguage.adaptationBrief.attribution.join(' ')).toContain('https://example.com/');
		expect(designLanguage.concept).toContain('palette');
	});
});

describe('mode detection', () => {
	it('classifies github.com as astro-theme', () => {
		expect(detectMode('https://github.com/some/astro-theme', '')).toBe('astro-theme');
	});
	it('classifies wp markers as wordpress', () => {
		expect(detectMode('https://shop.example.com/', '<link rel="stylesheet" href="/wp-content/themes/x/style.css">')).toBe('wordpress');
	});
	it('defaults to generic', () => {
		expect(detectMode('https://example.com/', '<html></html>')).toBe('generic');
	});
});

function htmlResponse(body: string, status = 200, headers: Record<string, string> = { 'content-type': 'text/html' }): Response {
	return new Response(body, { status, headers });
}

describe('fetcher SSRF guard', () => {
	it('rejects credentials-in-URL and non-http schemes before any network', async () => {
		const fetchImpl = async () => {
			throw new Error('must not be called');
		};
		await expect(fetchDesignSource('http://user:pass@example.com/', { fetchImpl, lookupImpl: publicLookup })).rejects.toMatchObject({
			code: 'credentials_rejected',
		});
		await expect(fetchDesignSource('ftp://example.com/', { fetchImpl, lookupImpl: publicLookup })).rejects.toMatchObject({
			code: 'unsupported_scheme',
		});
	});

	it('blocks loopback and private literals without the opt-in', async () => {
		const calls: string[] = [];
		const fetchImpl = async (input: string | URL | Request) => {
			calls.push(String(input));
			return htmlResponse('<html></html>');
		};
		for (const url of ['http://127.0.0.1/', 'http://10.0.0.5/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/']) {
			await expect(fetchDesignSource(url, { fetchImpl, lookupImpl: publicLookup })).rejects.toMatchObject({
				code: 'blocked_private_host',
			});
		}
		expect(calls).toHaveLength(0);
	});

	it('blocks hostnames that resolve to private addresses, and allows them with the opt-in', async () => {
		const localLookup = (async () => [{ address: '192.168.1.10', family: 4 }]) as unknown as typeof import('node:dns/promises').lookup;
		const fetchImpl = async () => htmlResponse('<html></html>');
		await expect(fetchDesignSource('http://local.example/', { fetchImpl, lookupImpl: localLookup })).rejects.toMatchObject({
			code: 'blocked_private_host',
		});
		await expect(
			fetchDesignSource('http://local.example/', { fetchImpl, lookupImpl: localLookup, allowPrivate: true })
		).resolves.toMatchObject({ finalUrl: 'http://local.example/' });
	});

	it('re-validates every redirect hop', async () => {
		let hops = 0;
		const fetchImpl = async () => {
			hops++;
			if (hops === 1) {
				return new Response(null, { status: 302, headers: { location: 'http://10.0.0.5/next' } });
			}
			throw new Error('must not reach hop 2');
		};
		await expect(fetchDesignSource('http://example.com/', { fetchImpl, lookupImpl: publicLookup })).rejects.toMatchObject({
			code: 'blocked_private_host',
		});
		expect(hops).toBe(1);
	});

	it('caps redirects, body size, and rejects non-HTML content types', async () => {
		const alwaysRedirect = async () => new Response(null, { status: 302, headers: { location: '/again' } });
		await expect(
			fetchDesignSource('http://example.com/', { fetchImpl: alwaysRedirect, lookupImpl: publicLookup, maxRedirects: 2 })
		).rejects.toMatchObject({ code: 'too_many_redirects' });

		const oversized = async () => htmlResponse('<html>' + 'x'.repeat(4096) + '</html>');
		await expect(
			fetchDesignSource('http://example.com/', { fetchImpl: oversized, lookupImpl: publicLookup, maxBytes: 1024 })
		).rejects.toMatchObject({ code: 'body_too_large' });

		const jsonPage = async () => new Response('{"ok":true}', { status: 200, headers: { 'content-type': 'application/json' } });
		await expect(
			fetchDesignSource('http://example.com/', { fetchImpl: jsonPage, lookupImpl: publicLookup })
		).rejects.toMatchObject({ code: 'unsupported_content_type' });
	});

	it('fetches the page plus bounded same-origin stylesheets, skipping cross-origin', async () => {
		const requested: string[] = [];
		const fetchImpl = async (input: string | URL | Request) => {
			const url = String(input);
			requested.push(url);
			if (url.endsWith('/style.css')) {
				return new Response(':root { --color-accent: #123456; }', { status: 200, headers: { 'content-type': 'text/css' } });
			}
			return htmlResponse(
				'<html><head><link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="https://other.example/x.css"></head><body></body></html>'
			);
		};
		const result = await fetchDesignSource('http://example.com/', { fetchImpl, lookupImpl: publicLookup });
		expect(result.stylesheets).toEqual([':root { --color-accent: #123456; }']);
		expect(requested).toHaveLength(2);
		expect(requested[1]).toBe('http://example.com/style.css');
		// Cross-origin stylesheets are never fetched in v1.
		expect(requested.some((url) => url.includes('other.example'))).toBe(false);
	});

	it('surfaces typed guard errors with machine-readable codes', async () => {
		const failing = async () => new Response(null, { status: 404 });
		try {
			await fetchDesignSource('http://example.com/', { fetchImpl: failing, lookupImpl: publicLookup });
			expect.unreachable();
		} catch (error) {
			expect(error).toBeInstanceOf(FetchGuardError);
			expect((error as FetchGuardError).code).toBe('http_error');
		}
	});
});
