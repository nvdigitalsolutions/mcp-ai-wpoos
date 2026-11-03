// ---------------------------------------------------------------------------
// portable-text.test.ts — the Proposal 107 block-content schema: accept/
// reject matrices for every custom type, the link-href safety walk (D3),
// the raw-HTML sanitizer allowlist (D2), and isSafeHref.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import {
	isSafeHref,
	parsePortableText,
	sanitizeContentHtml,
} from '../../src/lib/content/portable-text';

describe('portableText schema — accept', () => {
	it('accepts a standard block with strong/em/link marks and safe markDefs', () => {
		const blocks = parsePortableText([
			{
				_type: 'block',
				style: 'normal',
				children: [
					{ _type: 'span', text: 'Hello ', marks: [] },
					{ _type: 'span', text: 'world', marks: ['strong'] },
					{ _type: 'span', text: 'link', marks: ['a1'] },
				],
				markDefs: [{ _key: 'a1', _type: 'link', href: 'https://example.com' }],
			},
		]);
		expect(blocks).toHaveLength(1);
	});

	it('accepts every allowlisted custom type', () => {
		const blocks = parsePortableText([
			{ _type: 'hero', title: 'Hero', lede: 'A lede' },
			{ _type: 'image', src: 'https://example.com/a.png', alt: 'alt' },
			{ _type: 'productGrid', ids: [1, 2, 3] },
			{ _type: 'promotion', promotionId: 4 },
			{ _type: 'testimonial', quote: 'Great', author: 'A' },
			{ _type: 'faq', question: 'Q?', answer: 'A.' },
			{ _type: 'stat', value: '99%', label: 'uptime' },
		]);
		expect(blocks).toHaveLength(7);
	});
});

describe('portableText schema — reject', () => {
	it('rejects unknown custom types loudly (never silently dropped)', () => {
		expect(() => parsePortableText([{ _type: 'carousel', images: [] }])).toThrow(
			/invalid blocks/
		);
	});

	it('rejects javascript: hrefs in link markDefs', () => {
		expect(() =>
			parsePortableText([
				{
					_type: 'block',
					children: [{ _type: 'span', text: 'x', marks: ['a1'] }],
					markDefs: [{ _key: 'a1', _type: 'link', href: 'javascript:alert(1)' }],
				},
			])
		).toThrow(/unsafe link href/);
	});

	it('rejects data: hrefs in link markDefs', () => {
		expect(() =>
			parsePortableText([
				{
					_type: 'block',
					children: [{ _type: 'span', text: 'x', marks: ['a1'] }],
					markDefs: [{ _key: 'a1', _type: 'link', href: 'data:text/html,hi' }],
				},
			])
		).toThrow(/unsafe link href/);
	});

	it('rejects more than 100 blocks', () => {
		const blocks = Array.from({ length: 101 }, () => ({
			_type: 'stat' as const,
			value: '1',
			label: 'x',
		}));
		expect(() => parsePortableText(blocks)).toThrow(/100 blocks/);
	});

	it('rejects a block with no children', () => {
		expect(() => parsePortableText([{ _type: 'block', children: [] }])).toThrow(
			/invalid blocks/
		);
	});

	it('rejects an empty productGrid', () => {
		expect(() => parsePortableText([{ _type: 'productGrid', ids: [] }])).toThrow(
			/invalid blocks/
		);
	});
});

describe('sanitizeContentHtml — raw-HTML allowlist (D2)', () => {
	it('strips script blocks and inline handlers', () => {
		const out = sanitizeContentHtml(
			'<p onclick="steal()">hi</p><script>alert(1)</script><img src="https://x/y.png" onerror="alert(2)">'
		);
		expect(out).not.toContain('<script');
		expect(out).not.toContain('onclick');
		expect(out).not.toContain('onerror');
		expect(out).toContain('<p>hi</p>');
	});

	it('strips javascript: hrefs but keeps https links', () => {
		const out = sanitizeContentHtml(
			'<a href="javascript:alert(1)">bad</a><a href="https://example.com">good</a>'
		);
		expect(out).not.toContain('javascript:');
		expect(out).toContain('href="https://example.com"');
	});

	it('keeps the semantic allowlist and drops unknown tags', () => {
		const out = sanitizeContentHtml(
			'<h2>Head</h2><iframe src="https://x"></iframe><ul><li>one</li></ul><blockquote>q</blockquote>'
		);
		expect(out).toContain('<h2>Head</h2>');
		expect(out).not.toContain('<iframe');
		expect(out).toContain('<ul><li>one</li></ul>');
		expect(out).toContain('<blockquote>q</blockquote>');
	});

	it('forces rel=noopener on anchors', () => {
		const out = sanitizeContentHtml('<a href="https://example.com">x</a>');
		expect(out).toContain('rel="noopener noreferrer"');
	});
});

describe('isSafeHref', () => {
	it('accepts http/https/mailto and relative urls', () => {
		expect(isSafeHref('https://example.com')).toBe(true);
		expect(isSafeHref('http://example.com')).toBe(true);
		expect(isSafeHref('mailto:a@b.c')).toBe(true);
		expect(isSafeHref('/local/path')).toBe(true);
	});

	it('rejects dangerous schemes; garbage parses as a relative URL', () => {
		expect(isSafeHref('javascript:alert(1)')).toBe(false);
		expect(isSafeHref('data:text/html,hi')).toBe(false);
		expect(isSafeHref('file:///etc/passwd')).toBe(false);
		expect(isSafeHref('ftp://example.com')).toBe(false);
		// Anything without an explicit scheme resolves against the base URL —
		// a relative href is safe by construction (same-origin navigation).
		expect(isSafeHref('not a url at all')).toBe(true);
	});
});
