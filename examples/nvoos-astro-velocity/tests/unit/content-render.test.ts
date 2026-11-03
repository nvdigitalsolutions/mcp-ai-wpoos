// ---------------------------------------------------------------------------
// content-render.test.ts — the DECISION-2 body precedence: blocks >
// contentRendered (trusted) > content (sanitized) > empty. Loud failure on
// malformed blocks, and the agent-HTML sanitizer as the render-side backstop.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { contentBody } from '../../src/lib/content/render';
import type { ContentDoc } from '../../src/lib/commerce/types';

function doc(partial: Partial<ContentDoc>): ContentDoc {
	return {
		id: 1,
		slug: 'x',
		title: 'X',
		type: 'page',
		createdAt: '2026-01-01 00:00:00',
		updatedAt: '2026-01-01 00:00:00',
		status: 'publish',
		...partial,
	} as ContentDoc;
}

describe('contentBody precedence (DECISION-2)', () => {
	it('renders blocks when present (and ignores content)', () => {
		const body = contentBody(
			doc({
				blocks: [{ _type: 'stat', value: '42', label: 'widgets' }],
				content: '<p>ignored</p>',
			})
		);
		expect(body.blocks).toHaveLength(1);
		expect(body.html).toBe('');
	});

	it('trusts contentRendered for legacy docs (no sanitizer)', () => {
		const html = '<p>WP <script>trusted upstream</script> output</p>';
		const body = contentBody(doc({ contentRendered: html }));
		expect(body.blocks).toBeNull();
		expect(body.html).toBe(html);
	});

	it('sanitizes raw agent content on render (backstop)', () => {
		const body = contentBody(doc({ content: '<p>hi</p><script>alert(1)</script>' }));
		expect(body.blocks).toBeNull();
		expect(body.html).toContain('<p>hi</p>');
		expect(body.html).not.toContain('<script');
	});

	it('renders empty when nothing is present', () => {
		const body = contentBody(doc({}));
		expect(body.blocks).toBeNull();
		expect(body.html).toBe('');
	});

	it('throws loudly on malformed blocks (D3 — never a silent drop)', () => {
		const malformed = [{ _type: 'carousel' }] as unknown as ContentDoc['blocks'];
		expect(() => contentBody(doc({ blocks: malformed }))).toThrow(/invalid blocks/);
	});

	it('prefers contentRendered over content on legacy docs', () => {
		const body = contentBody(
			doc({ contentRendered: '<p>rendered</p>', content: '<p>raw</p>' })
		);
		expect(body.html).toBe('<p>rendered</p>');
	});
});
