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

	it('keeps the builder CLASS on headings (its own type/spacing scale)', () => {
		// A builder styles by class: `panel-title` carries the accordion heading's
		// box. Losing it left the rebuilt heading at the template's prose defaults
		// (virtuallyjamaica, 2026-11-11).
		const out = sanitizeHtml(
			'<h4 class="panel-title" style="color:#262626 !important">Title</h4>'
		);

		expect(out).toContain('class="panel-title"');
		// The class survives; every other attribute on the tag still dies.
		expect(out).not.toContain('style=');
	});

	it('STILL strips the class on <p> — a known gap, not an oversight', () => {
		// Restoring paragraph classes needs a per-device paragraph type fact first;
		// without it the measured margins/scale overshoot (see sanitize.ts). This
		// test documents the current contract so the change is deliberate.
		const out = sanitizeHtml('<p class="text-extra-large">Body</p>');
		expect(out).toBe('<p>Body</p>');
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

	it('strips style attributes except flat surface facts on bands', () => {
		const out = sanitizeHtml('<p style="color:red">styled</p>');
		expect(out).not.toContain('style=');
		expect(out).toContain('styled');

		// Surface facts for outline-only sources (their markup IS the rebuild).
		const panel = sanitizeHtml('<div class="panel" style="background-color:#dd3333;">panel</div>');
		expect(panel).toContain('background-color:#dd3333');

		// The `background` shorthand is normalized to the longhand.
		const photo = sanitizeHtml('<div style="min-height:700px; background: url(/wp-content/a.jpg) no-repeat;">x</div>');
		expect(photo).toContain('background-image:url(/wp-content/a.jpg)');
		expect(photo).not.toContain('min-height');

		// Everything else dies: layout, text colour, and non-http image schemes.
		const hostile = sanitizeHtml(
			'<div style="background-color:url(https://evil.test/x.png);width:100vw;color:red;background-image:url(javascript:alert(1))">x</div>'
		);
		expect(hostile).not.toContain('width');
		expect(hostile).not.toContain('color:red');
		expect(hostile).not.toContain('javascript');
	});

	it('derives a legible ink for a kept panel colour', () => {
		// The source's own `color` declarations are dropped, but a dark panel
		// without a derived ink painted body copy #626262 on #dd3333 (2.6:1).
		const dark = sanitizeHtml('<div style="background-color:#dd3333">panel</div>');
		expect(dark).toContain('background-color:#dd3333');
		expect(dark).toContain('color:#ffffff');

		const light = sanitizeHtml('<div style="background-color:#ededed">panel</div>');
		expect(light).toContain('color:#1a1a1a');
	});

	it('drops shortcode artifacts from copy', () => {
		const out = sanitizeHtml('<p>Intro</p><p>[ultimate_spacer height="25"]</p><p>[citation needed] stays</p>');
		expect(out).not.toContain('ultimate_spacer');
		expect(out).not.toContain('[citation needed]');
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

describe('sanitizeHtml — forms survive, attack surface stays closed (114 §3.9)', () => {
	it('keeps the functional form allowlist', () => {
		const out = sanitizeHtml(
			'<form class="elementor-form" name="contact">' +
				'<label for="f-name">Name</label>' +
				'<input type="text" name="form_fields[name]" id="f-name" placeholder="Jane" required="required" />' +
				'<textarea name="form_fields[msg]" rows="4" cols="30"></textarea>' +
				'<select name="form_fields[topic]"><option value="a" selected="selected">Alpha</option><option value="b">Beta</option></select>' +
				'<button type="submit">Send</button>' +
				'</form>'
		);
		expect(out).toContain('<form');
		expect(out).toContain('name="form_fields[name]"');
		expect(out).toContain('required');
		expect(out).toContain('<textarea');
		expect(out).toContain('<select');
		expect(out).toContain('value="a"');
		expect(out).toContain('<button type="submit">Send</button>');
	});

	it('strips action, method, onsubmit and formaction from forms', () => {
		const out = sanitizeHtml(
			'<form action="https://evil.example.com/steal" method="post" onsubmit="alert(1)">' +
				'<input type="text" name="a" formaction="javascript:alert(2)" onfocus="alert(3)" />' +
				'</form>'
		);
		expect(out).not.toContain('action');
		expect(out).not.toContain('method');
		expect(out).not.toContain('onsubmit');
		expect(out).not.toContain('formaction');
		expect(out).not.toContain('onfocus');
		expect(out).toContain('name="a"');
	});

	it('drops javascript: values on form controls', () => {
		const out = sanitizeHtml('<input type="text" name="a" value="javascript:alert(1)" />');
		expect(out).not.toContain('javascript:');
		expect(out).not.toContain('value=');
	});

	it('keeps hidden ID carriers (form_id, JFB id) for the classifier', () => {
		const out = sanitizeHtml(
			'<form><input type="hidden" name="form_id" value="abc123" />' +
				'<input type="hidden" name="_jet_engine_booking_form_id" value="7" /></form>'
		);
		expect(out).toContain('name="form_id"');
		expect(out).toContain('value="abc123"');
		expect(out).toContain('name="_jet_engine_booking_form_id"');
	});
});

describe('sanitize contract', () => {
	it('exposes a versioned allowlist for stale-copy detection', () => {
		expect(SANITIZE_VERSION).toBe(3); // v3 keeps a flat band `background-color`.
		expect(SANITIZE_OPTIONS.allowedTags).toContain('figure');
		expect(SANITIZE_OPTIONS.allowedTags).toContain('form');
		expect(SANITIZE_OPTIONS.allowedTags).not.toContain('script');
	});
});

describe( 'sanitizeHtml — one-pager anchor ids', () => {
	// The source's `id` on a section/div is the in-page nav contract, so it
	// survives — but only as a plain identifier that cannot shadow an id the
	// forge owns.
	it( 'keeps a plain anchor id on a section and a div', () => {
		const html = sanitizeHtml('<section id="about" class="s"><p>Hi</p></section><div id="wifi" class="w"></div>');
		expect(html).toContain('id="about"');
		expect(html).toContain('id="wifi"');
	});

	it( 'drops an id that is not a plain identifier', () => {
		const html = sanitizeHtml('<section id="a b\x22onclick=\x22x"><p>Hi</p></section>');
		expect(html).not.toContain('id="a b');
	});

	it( 'drops an id that would shadow a forge-owned id', () => {
		const html = sanitizeHtml('<div id="nvoos-consent-title"></div><div id="pagefind"></div><div id="ds-hero"></div>');
		expect(html).not.toContain('nvoos-consent-title');
		expect(html).not.toContain('pagefind');
		expect(html).not.toContain('ds-hero');
	} );

	it( 'still strips ids from tags that do not carry anchors', () => {
		const html = sanitizeHtml('<p id="legacy">text</p>');
		expect(html).not.toContain('id="legacy"');
	} );
} );
