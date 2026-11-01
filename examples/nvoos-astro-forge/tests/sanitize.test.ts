// ---------------------------------------------------------------------------
// sanitize.test.ts — the XSS gate: allowlist works, vectors die.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { SANITIZE_VERSION, SANITIZE_OPTIONS, sanitizeHtml } from '../lib/sanitize.ts';

describe('sanitizeHtml — content passes', () => {
	it('keeps Gutenberg block markup', () => {
		const html = [
			'<h2 id="intro">Intro</h2>',
			'<p>Hello <strong>world</strong> with a <a href="https://example.com" title="Example">link</a>.</p>',
			'<ul><li>one</li><li>two</li></ul>',
			'<blockquote class="wp-block-quote"><p>Cited wisdom</p><cite>Someone</cite></blockquote>',
			'<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="A" width="640" height="360" loading="lazy" /><figcaption>Cap</figcaption></figure>',
			'<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>D</td></tr></tbody></table>',
			'<pre class="wp-block-code"><code>const x = 1;</code></pre>',
		].join('\n');

		const out = sanitizeHtml(html);

		expect(out).toContain('<h2>Intro</h2>');
		expect(out).toContain('<strong>world</strong>');
		expect(out).toContain('href="https://example.com"');
		expect(out).toContain('blockquote');
		expect(out).toContain('alt="A"');
		expect(out).toContain('<table>');
		expect(out).toContain('<code>const x = 1;</code>');
	});

	it('keeps allowlisted embeds', () => {
		const out = sanitizeHtml(
			'<iframe src="https://www.youtube-nocookie.com/embed/abc" title="Video" width="560" height="315" frameborder="0" allowfullscreen></iframe>'
		);
		expect(out).toContain('youtube-nocookie.com/embed/abc');
	});

	it('adds noopener noreferrer to target=_blank links', () => {
		const out = sanitizeHtml('<a href="https://example.com" target="_blank">go</a>');
		expect(out).toContain('target="_blank"');
		expect(out).toContain('rel="noopener noreferrer"');
	});

	it('keeps mailto links', () => {
		const out = sanitizeHtml('<a href="mailto:hi@example.com">mail</a>');
		expect(out).toContain('href="mailto:hi@example.com"');
	});
});

describe('sanitizeHtml — vectors die', () => {
	it('strips script tags entirely', () => {
		const out = sanitizeHtml('<p>ok</p><script>alert("xss")</script>');
		expect(out).not.toContain('script');
		expect(out).not.toContain('alert');
		expect(out).toContain('<p>ok</p>');
	});

	it('strips event-handler attributes', () => {
		const out = sanitizeHtml('<img src="https://example.com/a.jpg" onerror="steal()" alt="x" />');
		expect(out).not.toContain('onerror');
		expect(out).toContain('example.com/a.jpg');
	});

	it('strips javascript: URLs', () => {
		const out = sanitizeHtml('<a href="javascript:alert(1)">click</a>');
		expect(out).not.toContain('javascript:');
	});

	it('strips style attributes (CSS-injection vector)', () => {
		const out = sanitizeHtml('<p style="color:red">styled</p>');
		expect(out).not.toContain('style=');
		expect(out).toContain('styled');
	});

	it('drops iframe src from unknown hosts (tag left inert)', () => {
		const out = sanitizeHtml('<iframe src="https://evil.example.com/embed" title="x"></iframe>');
		expect(out).not.toContain('evil.example.com');
		expect(out).not.toContain('src=');
	});

	it('returns empty for empty input', () => {
		expect(sanitizeHtml('')).toBe('');
	});
});

describe('sanitize contract', () => {
	it('exposes a versioned allowlist for stale-copy detection', () => {
		expect(SANITIZE_VERSION).toBeGreaterThan(0);
		expect(SANITIZE_OPTIONS.allowedTags).toContain('figure');
		expect(SANITIZE_OPTIONS.allowedTags).not.toContain('script');
	});
});
